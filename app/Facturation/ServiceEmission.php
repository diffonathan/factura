<?php

declare(strict_types=1);

namespace App\Facturation;

use App\Facturation\Exceptions\ConflitFacturation;
use App\Facturation\Exceptions\DocumentNonEmissible;
use App\Models\Document;
use App\Models\Ligne;
use Illuminate\Support\Facades\DB;

/**
 * Le passage du brouillon au document officiel.
 *
 * C'est l'acte irréversible de l'application : avant, on corrige librement ;
 * après, le document porte un numéro de la série légale, il est figé, et la
 * seule façon de le rectifier est d'émettre un avoir. Tout ce qui doit être
 * vérifié l'est donc ici, avant que le numéro soit consommé.
 */
final class ServiceEmission
{
    public function __construct(
        private readonly ServiceNumerotation $numerotation,
    ) {}

    /**
     * Émet un brouillon : lui attribue son numéro et le fige.
     *
     * @throws DocumentNonEmissible s'il manque quelque chose au document
     * @throws ConflitFacturation   s'il a déjà été émis entre-temps
     */
    public function emettre(Document $document): Document
    {
        $this->verifierEmissible($document);

        return DB::transaction(function () use ($document): Document {
            // Dans la transaction, donc annulé avec elle si la suite échoue.
            $numero = $this->numerotation->reserver(
                $document->entreprise_id,
                $document->type,
                $document->annee,
            );

            // `AND numero IS NULL` n'est pas une précaution de style : c'est ce
            // qui rend l'émission idempotente. Deux requêtes concurrentes sur
            // le même brouillon prennent chacune un numéro au compteur, puis la
            // seconde attend le verrou de ligne. Un simple `WHERE id = ?`
            // écraserait alors le numéro du premier, et son numéro à lui serait
            // perdu — un trou. Avec cette condition, la seconde ne touche
            // aucune ligne, lève un conflit, et sa transaction rend le numéro
            // qu'elle avait pris.
            $touchees = DB::update(<<<'SQL'
                UPDATE documents
                   SET numero        = :numero,
                       statut        = 'EMIS',
                       emis_le       = now(),
                       date_echeance = :echeance,
                       updated_at    = now()
                 WHERE id     = :id
                   AND numero IS NULL
            SQL, [
                'numero' => $numero,
                'echeance' => ($document->date_echeance ?? $this->echeanceParDefaut($document))
                    ->format('Y-m-d'),
                'id' => $document->id,
            ]);

            if ($touchees !== 1) {
                throw new ConflitFacturation(
                    'Ce document vient d\'être émis par une autre opération. Rechargez-le.'
                );
            }

            $document->refresh();

            // Un avoir qui couvre la totalité d'une facture l'annule. La
            // facture reste en base, numérotée et lisible : c'est l'avoir qui
            // porte la correction, et la piste reste vérifiable.
            if ($document->type === TypeDocument::Avoir && $document->origine_id !== null) {
                $this->annulerFactureSiEntierementAvoiree($document);
            }

            return $document;
        });
    }

    /**
     * Transforme un devis accepté en brouillon de facture, lignes comprises.
     *
     * Rend un BROUILLON et non une facture émise : entre l'accord du client et
     * la facturation, une quantité change souvent. L'utilisateur relit, ajuste,
     * puis émet.
     */
    public function convertirEnFacture(Document $devis): Document
    {
        $devis->loadMissing(['lignes', 'entreprise']);

        if ($devis->type !== TypeDocument::Devis) {
            throw new DocumentNonEmissible('Seul un devis se convertit en facture.');
        }

        if ($devis->estBrouillon()) {
            throw new DocumentNonEmissible('Ce devis n\'a pas encore été émis.');
        }

        if ($devis->statut === StatutDocument::Refuse) {
            throw new DocumentNonEmissible('Ce devis a été refusé.');
        }

        return DB::transaction(function () use ($devis): Document {
            // Créé À TRAVERS l'entreprise du devis, et non par
            // `Document::create()`.
            //
            // La différence n'est pas de style. `Document::create()` laisserait
            // le trait poser l'entreprise COURANTE, celle du contexte de la
            // requête. Or la facture doit appartenir à l'entreprise du devis :
            // ce sont presque toujours les mêmes, et le jour où elles diffèrent
            // — un comptable qui gère trois sociétés et change d'onglet — on
            // facturerait au nom de la mauvaise. La relation, elle, pose la
            // clé étrangère depuis la source, sans se fier au contexte.
            $facture = $devis->entreprise->documents()->create([
                'client_id' => $devis->client_id,
                'type' => TypeDocument::Facture->value,
                'date_emission' => now()->toDateString(),
                'objet' => $devis->objet,
                'conditions' => $devis->conditions,
                'origine_id' => $devis->id,
                'devise' => $devis->devise,
            ]);

            // L'index unique `documents_une_facture_par_devis` a déjà rejeté la
            // seconde tentative si deux conversions partaient en parallèle : la
            // course est tranchée par la base, pas par un test de présence.
            $this->recopierLignes($devis, $facture);

            if ($devis->statut !== StatutDocument::Accepte) {
                // Affectation directe, pas `update([...])` : `statut` n'est pas
                // dans `$fillable`, et c'est voulu. Le cycle de vie d'un
                // document ne doit pas pouvoir être piloté par les champs d'un
                // formulaire — seuls les services d'ici le font avancer.
                $devis->statut = StatutDocument::Accepte;
                $devis->save();
            }

            return $facture->refresh();
        });
    }

    /**
     * Prépare l'avoir d'une facture émise.
     *
     * Sans `$lignes`, l'avoir reprend toute la facture — le cas de l'annulation
     * pure. Avec, il ne porte que ce qui est rendu ou remisé.
     *
     * @param  list<array<string, mixed>>|null  $lignes
     */
    public function preparerAvoir(Document $facture, ?array $lignes = null): Document
    {
        $facture->loadMissing(['lignes', 'entreprise']);

        if ($facture->type !== TypeDocument::Facture) {
            throw new DocumentNonEmissible('Un avoir corrige une facture.');
        }

        if ($facture->estBrouillon()) {
            throw new DocumentNonEmissible(
                'Cette facture est encore un brouillon : corrigez-la directement.'
            );
        }

        return DB::transaction(function () use ($facture, $lignes): Document {
            $avoir = $facture->entreprise->documents()->create([
                'client_id' => $facture->client_id,
                'type' => TypeDocument::Avoir->value,
                'date_emission' => now()->toDateString(),
                'objet' => 'Avoir sur ' . $facture->reference,
                'origine_id' => $facture->id,
                'devise' => $facture->devise,
            ]);

            if ($lignes === null) {
                $this->recopierLignes($facture, $avoir);
            } else {
                foreach ($lignes as $ligne) {
                    Ligne::creerPour($avoir, $ligne);
                }
            }

            return $avoir->refresh();
        });
    }

    // ------------------------------------------------------------------

    /**
     * Tout ce qui empêche une émission, vérifié avant de toucher au compteur.
     *
     * Les manques sont rassemblés puis rendus d'un coup : corriger quatre
     * champs signalés l'un après l'autre, à chaque tentative, est une façon
     * sûre de perdre un utilisateur.
     */
    private function verifierEmissible(Document $document): void
    {
        // Chargement explicite de tout ce que la vérification va lire.
        //
        // Deux raisons. La première est la performance : sans cela, chaque
        // relation part en requête séparée au moment où on la touche, et c'est
        // ainsi qu'un écran de liste finit par en faire deux cents. La seconde
        // est que `Model::preventLazyLoading()` est actif hors production et
        // transforme l'oubli en exception immédiate — un service qui lit une
        // relation non chargée ne passe pas les tests.
        $document->loadMissing(['entreprise', 'client', 'lignes', 'origine']);

        if ($document->estEmis()) {
            throw new ConflitFacturation(
                "Le document {$document->reference} est déjà émis."
            );
        }

        $manques = [];

        if ($document->lignes()->count() === 0) {
            $manques[] = 'au moins une ligne';
        }

        if (bccomp((string) $document->montant_ttc, '0', 2) <= 0) {
            $manques[] = 'un montant supérieur à zéro';
        }

        foreach ($document->entreprise->mentionsManquantes() as $mention) {
            $manques[] = "votre {$mention}";
        }

        // Sur un devis, l'ICE du client n'est pas exigé : le devis n'ouvre
        // aucun droit à déduction. Sur une facture, son absence empêche le
        // client de récupérer la TVA — et le fait découvrir trop tard.
        if ($document->type !== TypeDocument::Devis && $document->client->iceManquant()) {
            $manques[] = "l'ICE du client {$document->client->nom}";
        }

        if ($document->type === TypeDocument::Avoir) {
            $this->verifierMontantAvoir($document, $manques);
        }

        if ($manques !== []) {
            throw new DocumentNonEmissible(
                'Il manque ' . $this->enumerer($manques) . '.',
                $manques,
            );
        }
    }

    /**
     * Un avoir ne peut pas rendre plus que la facture n'a facturé, avoirs
     * déjà émis compris. Sans cette borne, on fabriquerait de la TVA
     * déductible à partir de rien.
     *
     * @param  list<string>  $manques
     */
    private function verifierMontantAvoir(Document $avoir, array &$manques): void
    {
        if ($avoir->origine_id === null) {
            return;
        }

        $facture = $avoir->origine;

        if ($facture === null) {
            return;
        }

        $dejaAvoire = $this->totalAvoirsEmis($facture);
        $restant = bcsub((string) $facture->montant_ttc, $dejaAvoire, 2);

        if (bccomp((string) $avoir->montant_ttc, $restant, 2) > 0) {
            $manques[] = sprintf(
                'un montant d\'au plus %s %s (la facture %s a déjà %s %s d\'avoirs)',
                $restant,
                $facture->devise,
                $facture->reference,
                $dejaAvoire,
                $facture->devise,
            );
        }
    }

    /**
     * Le total des avoirs DÉJÀ ÉMIS sur cette facture.
     *
     * `whereNotNull('numero')` écarte les brouillons, y compris l'avoir en
     * cours de vérification : sans cela, il se compterait lui-même et se
     * déclarerait toujours excessif.
     *
     * Le `?? '0'` n'est pas décoratif : `sum()` sur un ensemble vide rend null,
     * et passer null à bcsub est déprécié depuis PHP 8.1.
     */
    private function totalAvoirsEmis(Document $facture): string
    {
        return (string) ($facture->derives()
            ->where('type', TypeDocument::Avoir->value)
            ->whereNotNull('numero')
            ->sum('montant_ttc') ?? '0');
    }

    private function annulerFactureSiEntierementAvoiree(Document $avoir): void
    {
        $facture = $avoir->loadMissing('origine')->origine;

        if ($facture === null || $facture->type !== TypeDocument::Facture) {
            return;
        }

        $avoire = $this->totalAvoirsEmis($facture);

        if (bccomp($avoire, (string) $facture->montant_ttc, 2) >= 0) {
            $facture->statut = StatutDocument::Annule;
            $facture->save();
        }
    }

    private function recopierLignes(Document $source, Document $cible): void
    {
        foreach ($source->loadMissing('lignes')->lignes as $ligne) {
            $cible->lignes()->create([
                'position' => $ligne->position,
                'designation' => $ligne->designation,
                'unite' => $ligne->unite,
                'quantite' => $ligne->quantite,
                'prix_unitaire_ht' => $ligne->prix_unitaire_ht,
                'remise_pct' => $ligne->remise_pct,
                'taux_tva' => $ligne->taux_tva,
            ]);
        }
    }

    private function echeanceParDefaut(Document $document): \Illuminate\Support\Carbon
    {
        return match ($document->type) {
            // Un devis a une durée de validité, pas une échéance de paiement.
            TypeDocument::Devis => $document->date_emission->copy()->addDays(30),

            TypeDocument::Facture => $document->date_emission->copy()
                ->addDays($document->loadMissing('client')->client->delai_paiement_jours),

            // Un avoir est dû tout de suite : c'est l'entreprise qui doit.
            TypeDocument::Avoir => $document->date_emission->copy(),
        };
    }

    /** @param list<string> $elements */
    private function enumerer(array $elements): string
    {
        if (count($elements) === 1) {
            return $elements[0];
        }

        $dernier = array_pop($elements);

        return implode(', ', $elements) . ' et ' . $dernier;
    }
}
