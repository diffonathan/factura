<?php

declare(strict_types=1);

namespace App\Facturation;

use App\Facturation\Exceptions\EncaissementRefuse;
use App\Models\Document;
use App\Models\Paiement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * L'enregistrement des règlements.
 *
 * La classe est courte, et c'est le signe que le reste est au bon endroit :
 * le solde et le statut du document sont tenus par un trigger, le dépassement
 * est arrêté par une contrainte. Il ne reste ici qu'à insérer la ligne et à
 * traduire les refus de la base en messages compréhensibles.
 *
 * Traduire plutôt que vérifier soi-même n'est pas de la paresse. Une
 * vérification en PHP — « le montant tient-il dans le solde ? » — lit une
 * valeur, puis écrit : deux encaissements saisis en même temps la passent tous
 * les deux. La contrainte, elle, est évaluée à l'écriture, donc sous le verrou
 * de la ligne. Le contrôle est au seul endroit où il ne peut pas être doublé.
 */
final class ServiceEncaissement
{
    /** @param array<string, mixed> $attributs */
    public function enregistrer(Document $document, array $attributs): Paiement
    {
        try {
            // Transaction autour d'une seule écriture : ce n'est pas pour
            // l'atomicité, c'est pour le point de sauvegarde. PostgreSQL
            // abandonne toute la transaction dès la première erreur, et le
            // refus attendu ici — un surpaiement — en est une. Sans ce point,
            // un appelant qui encadre plusieurs encaissements verrait le
            // premier refus condamner les suivants, tous corrects.
            $paiement = DB::transaction(fn (): Paiement => $document->paiements()->create($attributs + [
                'date_paiement' => now()->toDateString(),
                'mode' => ModePaiement::Virement->value,
            ]));
        } catch (QueryException $erreur) {
            throw $this->traduire($erreur, $document, (string) ($attributs['montant'] ?? '0'));
        }

        $document->refresh();

        return $paiement;
    }

    /** Solde la facture en un seul règlement du montant restant. */
    public function solder(Document $document, ModePaiement $mode, ?string $reference = null): Paiement
    {
        return $this->enregistrer($document, [
            'montant' => $document->resteAPayer(),
            'mode' => $mode->value,
            'reference' => $reference,
        ]);
    }

    /**
     * Retire un règlement : chèque sans provision, erreur de saisie.
     *
     * Rend le document à jour, et ne se contente pas de `void`. La raison est
     * concrète : le solde est recalculé par un trigger, donc toute instance de
     * ce document que l'appelant garde en mémoire devient périmée à l'instant
     * de la suppression, et rien ne l'en avertit. Renvoyer l'objet frais rend
     * le piège visible dans la signature — l'appelant qui l'ignore travaille
     * sciemment sur une vue ancienne.
     */
    public function annuler(Paiement $paiement): Document
    {
        $document = $paiement->loadMissing('document')->document;

        $paiement->delete();

        // Le trigger a déjà recalculé le solde et redescendu le statut de
        // SOLDE à EMIS si besoin ; il n'y a rien à défaire à la main.
        return $document->refresh();
    }

    private function traduire(QueryException $erreur, Document $document, string $montant): EncaissementRefuse
    {
        // 23514 : violation de CHECK — ici `documents_paiement_borne`.
        if ($erreur->getCode() === '23514') {
            return new EncaissementRefuse(sprintf(
                'Ce règlement de %s %s dépasse le solde dû, qui est de %s %s.',
                $montant,
                $document->devise,
                $document->resteAPayer(),
                $document->devise,
            ));
        }

        // 90005 : le trigger `paiements_document_valide`.
        if ($erreur->getCode() === '90005') {
            return new EncaissementRefuse(
                $document->estBrouillon()
                    ? 'Ce document est encore un brouillon : émettez-le avant d\'encaisser.'
                    : 'Un devis n\'est pas une créance : rien à encaisser dessus.'
            );
        }

        throw $erreur;
    }
}
