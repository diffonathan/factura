<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Facturation\ModePaiement;
use App\Facturation\ServiceEmission;
use App\Facturation\ServiceEncaissement;
use App\Facturation\ServiceRelance;
use App\Facturation\StatutDocument;
use App\Facturation\TypeDocument;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Les devis, factures et avoirs.
 *
 * Le contrôleur ne connaît aucune règle de facturation : il valide ce qui
 * arrive, appelle un service, et rend une page. La numérotation, les bornes de
 * l'avoir, l'immutabilité — tout cela vit dans `App\Facturation` et dans la
 * base, où un import ou une commande y a droit aussi.
 *
 * Aucune méthode ne filtre sur l'entreprise : le filtre global s'en charge, y
 * compris sur `Document::findOrFail()`. Un document d'une autre entreprise
 * n'est pas « interdit », il est introuvable — ce qui ne renseigne même pas
 * sur son existence.
 */
final class DocumentController extends Controller
{
    public function index(Request $requete): Response
    {
        $type = $requete->string('type')->toString();
        $statut = $requete->string('statut')->toString();
        $recherche = trim($requete->string('recherche')->toString());

        $documents = Document::query()
            ->with('client:id,nom,ville')
            ->when($type !== '', fn ($q) => $q->where('type', $type))
            ->when($statut === 'BROUILLON', fn ($q) => $q->whereNull('numero'))
            ->when($statut === 'RETARD', fn ($q) => $q->enRetard())
            ->when(
                $statut !== '' && ! in_array($statut, ['BROUILLON', 'RETARD'], true),
                fn ($q) => $q->where('statut', $statut)
            )
            ->when($recherche !== '', function ($q) use ($recherche) {
                // La référence et l'objet du document, ou le nom du client.
                // `ilike` plutôt que `like` : personne ne tape « SARL » avec
                // les bonnes majuscules dans un champ de recherche.
                $q->where(function ($sous) use ($recherche) {
                    $sous->where('reference', 'ilike', "%{$recherche}%")
                        ->orWhere('objet', 'ilike', "%{$recherche}%")
                        ->orWhereHas('client', fn ($c) => $c->where('nom', 'ilike', "%{$recherche}%"));
                });
            })
            ->orderByRaw('numero IS NULL DESC')   // les brouillons d'abord : c'est là qu'il y a du travail
            ->orderByDesc('date_emission')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Document $document) => [
                'id' => $document->id,
                'reference' => $document->reference,
                'type' => $document->type->value,
                'statut' => $document->statut->value,
                'client' => $document->client->nom,
                'ville' => $document->client->ville,
                'objet' => $document->objet,
                'date' => $document->date_emission->toDateString(),
                'echeance' => $document->date_echeance?->toDateString(),
                'ttc' => (string) $document->montant_ttc,
                'reste' => $document->resteAPayer(),
                'retard' => $document->joursDeRetard(),
            ]);

        return Inertia::render('Documents/Index', [
            'documents' => $documents,
            'filtres' => ['type' => $type, 'statut' => $statut, 'recherche' => $recherche],

            // Les listes déroulantes sont remplies par le serveur, depuis les
            // énumérations : elles ne peuvent pas proposer un état qui
            // n'existe pas, ni oublier celui qu'on vient d'ajouter.
            'types' => self::typesFiltrables(),
            'statuts' => self::statutsFiltrables(),
        ]);
    }

    public function show(Document $document, ServiceRelance $relances): Response
    {
        $document->load([
            'client', 'entreprise', 'lignes', 'paiements', 'relances',
            'origine:id,type,annee,numero', 'derives:id,origine_id,type,annee,numero,statut,montant_ttc',
        ]);

        return Inertia::render('Documents/Show', [
            'document' => [
                'id' => $document->id,
                'reference' => $document->reference,
                'type' => $document->type->value,
                'type_libelle' => $document->type->libelle(),
                'statut' => $document->statut->value,
                'statut_libelle' => $document->statut->libelle(),
                'objet' => $document->objet,
                'conditions' => $document->conditions,
                'notes_internes' => $document->notes_internes,
                'date' => $document->date_emission->toDateString(),
                'echeance' => $document->date_echeance?->toDateString(),
                'emis_le' => $document->emis_le?->toIso8601String(),
                'devise' => $document->devise,
                'ht' => (string) $document->montant_ht,
                'tva' => (string) $document->montant_tva,
                'ttc' => (string) $document->montant_ttc,
                'paye' => (string) $document->montant_paye,
                'reste' => $document->resteAPayer(),
                'retard' => $document->joursDeRetard(),
                'est_brouillon' => $document->estBrouillon(),
                'ventilation' => $document->ventilationTva(),
                'origine' => $document->origine ? [
                    'id' => $document->origine->id,
                    'reference' => $document->origine->reference,
                ] : null,
                'derives' => $document->derives->map(fn (Document $d) => [
                    'id' => $d->id,
                    'reference' => $d->reference,
                    'type' => $d->type->value,
                    'statut' => $d->statut->value,
                    'ttc' => (string) $d->montant_ttc,
                ])->all(),
            ],

            'client' => [
                'id' => $document->client->id,
                'nom' => $document->client->nom,
                'ice' => $document->client->ice,
                'identifiant_fiscal' => $document->client->identifiant_fiscal,
                'adresse' => $document->client->adresse,
                'ville' => $document->client->ville,
                'email' => $document->client->email,
                'telephone' => $document->client->telephone,
            ],

            'emetteur' => [
                'raison_sociale' => $document->entreprise->raison_sociale
                    . ($document->entreprise->forme_juridique ? ' ' . $document->entreprise->forme_juridique : ''),
                'adresse' => $document->entreprise->adresse,
                'ville' => $document->entreprise->ville,
                'telephone' => $document->entreprise->telephone,
                'email' => $document->entreprise->email,
                'banque' => $document->entreprise->banque,
                'rib' => $document->entreprise->rib,
                'mentions' => $document->entreprise->mentionsLegales(),
            ],

            'lignes' => $document->lignes->map(fn ($ligne) => [
                'id' => $ligne->id,
                'position' => $ligne->position,
                'designation' => $ligne->designation,
                'unite' => $ligne->unite,
                'quantite' => (string) $ligne->quantite,
                'prix' => (string) $ligne->prix_unitaire_ht,
                'remise' => (string) $ligne->remise_pct,
                'taux' => (string) $ligne->taux_tva,
                'ht' => (string) $ligne->montant_ht,
                'tva' => (string) $ligne->montant_tva,
            ])->all(),

            'paiements' => $document->paiements->map(fn ($paiement) => [
                'id' => $paiement->id,
                'date' => $paiement->date_paiement->toDateString(),
                'montant' => (string) $paiement->montant,
                'mode' => $paiement->mode->libelle(),
                'reference' => $paiement->reference,
            ])->all(),

            'relances' => $document->relances->map(fn ($relance) => [
                'niveau' => $relance->niveau,
                'libelle' => $relance->libelleNiveau(),
                'statut' => $relance->statut,
                'le' => $relance->traitee_le?->toIso8601String(),
                'erreur' => $relance->erreur,
            ])->all(),

            'prochaineRelance' => $relances->niveauAttendu($document),
            'modesPaiement' => array_map(
                fn (ModePaiement $mode) => ['valeur' => $mode->value, 'libelle' => $mode->libelle()],
                ModePaiement::cases(),
            ),
        ]);
    }

    public function emettre(Document $document, ServiceEmission $emission): RedirectResponse
    {
        $emis = $emission->emettre($document);

        return back()->with('succes', "{$emis->type->libelle()} {$emis->reference} émis.");
    }

    public function convertir(Document $devis, ServiceEmission $emission): RedirectResponse
    {
        $facture = $emission->convertirEnFacture($devis);

        return redirect()
            ->route('documents.show', $facture)
            ->with('succes', 'Brouillon de facture créé à partir du devis. Relisez-le avant de l\'émettre.');
    }

    public function avoir(Document $facture, ServiceEmission $emission): RedirectResponse
    {
        $avoir = $emission->preparerAvoir($facture);

        return redirect()
            ->route('documents.show', $avoir)
            ->with('succes', 'Brouillon d\'avoir créé. Ajustez les lignes puis émettez-le.');
    }

    public function encaisser(
        Request $requete,
        Document $document,
        ServiceEncaissement $encaissement,
    ): RedirectResponse {
        $donnees = $requete->validate([
            'montant' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', 'string'],
            'date_paiement' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:60'],
        ]);

        $encaissement->enregistrer($document, $donnees);

        return back()->with('succes', 'Règlement enregistré.');
    }

    /** Les statuts proposés dans le filtre, pour ne pas les écrire en dur côté Vue. */
    public static function statutsFiltrables(): array
    {
        return array_merge(
            [
                ['valeur' => 'BROUILLON', 'libelle' => 'Brouillons'],
                ['valeur' => 'RETARD', 'libelle' => 'En retard'],
            ],
            array_map(
                fn (StatutDocument $statut) => ['valeur' => $statut->value, 'libelle' => $statut->libelle()],
                array_values(array_filter(
                    StatutDocument::cases(),
                    fn (StatutDocument $s) => $s !== StatutDocument::Brouillon,
                )),
            ),
        );
    }

    /** Les types proposés dans le filtre. */
    public static function typesFiltrables(): array
    {
        return array_map(
            fn (TypeDocument $type) => ['valeur' => $type->value, 'libelle' => $type->libelle()],
            TypeDocument::cases(),
        );
    }
}
