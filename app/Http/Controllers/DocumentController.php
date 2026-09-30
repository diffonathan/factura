<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Facturation\Exceptions\ConflitFacturation;
use App\Facturation\ModePaiement;
use App\Facturation\ServiceEmission;
use App\Facturation\ServiceEncaissement;
use App\Facturation\ServiceRelance;
use App\Facturation\StatutDocument;
use App\Facturation\TypeDocument;
use App\Models\Client;
use App\Models\Document;
use App\Models\Ligne;
use App\Support\EntrepriseCourante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

    /**
     * Le formulaire de création.
     *
     * Les clients sont chargés ici plutôt que cherchés à la frappe : une TPE
     * en a quelques dizaines, une liste déroulante suffit, et elle montre
     * d'emblée ceux dont l'ICE manque — l'information qui bloquera l'émission.
     */
    public function create(): Response
    {
        return Inertia::render('Documents/Creer', [
            'clients' => Client::orderBy('nom')->get()->map(fn (Client $client) => [
                'id' => $client->id,
                'nom' => $client->nom,
                'ville' => $client->ville,
                'delai' => $client->delai_paiement_jours,
                'iceManquant' => $client->iceManquant(),
            ])->all(),
            'types' => self::typesFiltrables(),
            'tauxTva' => [20, 14, 10, 7, 0],
            'aujourdhui' => now()->toDateString(),
        ]);
    }

    /**
     * Crée un BROUILLON, avec ses lignes s'il en a déjà.
     *
     * Toujours un brouillon, jamais un document émis : créer et émettre sont
     * deux gestes distincts parce que le second est irréversible. Émettre à la
     * saisie consommerait un numéro de la série légale à chaque essai.
     */
    public function store(Request $requete, ServiceEmission $emission): RedirectResponse
    {
        $donnees = $this->validerDocument($requete, avecLignes: true);

        $document = DB::transaction(function () use ($donnees): Document {
            $document = Document::create(Arr::except($donnees, 'lignes'));

            foreach ($donnees['lignes'] ?? [] as $ligne) {
                Ligne::creerPour($document, $ligne);
            }

            return $document->refresh();
        });

        // « Créer et émettre » en un geste, pour la saisie courante : on relit
        // rarement une facture de trois lignes qu'on vient de taper.
        if ($requete->boolean('emettre')) {
            $emis = $emission->emettre($document);

            return redirect()
                ->route('documents.show', $emis)
                ->with('succes', "{$emis->type->libelle()} {$emis->reference} émis.");
        }

        return redirect()
            ->route('documents.show', $document)
            ->with('succes', 'Brouillon créé. Relisez-le, puis émettez-le.');
    }

    /** Modifie l'en-tête d'un brouillon. */
    public function update(Request $requete, Document $document): RedirectResponse
    {
        $this->refuserSiEmis($document);

        $document->update($this->validerDocument($requete, avecLignes: false, avecType: false));

        return back()->with('succes', 'Brouillon mis à jour.');
    }

    public function destroy(Document $document): RedirectResponse
    {
        $this->refuserSiEmis($document);

        $document->delete();

        return redirect()
            ->route('documents.index')
            ->with('succes', 'Brouillon supprimé.');
    }

    // ------------------------------------------------------------------
    // Les lignes d'un brouillon
    // ------------------------------------------------------------------

    public function ajouterLigne(Request $requete, Document $document): RedirectResponse
    {
        $this->refuserSiEmis($document);

        Ligne::creerPour($document, $requete->validate($this->reglesLigne()));

        return back()->with('succes', 'Ligne ajoutée.');
    }

    public function modifierLigne(Request $requete, Document $document, Ligne $ligne): RedirectResponse
    {
        $this->refuserSiEmis($document);
        $this->refuserSiEtrangere($document, $ligne);

        $ligne->update($requete->validate($this->reglesLigne()));

        return back()->with('succes', 'Ligne modifiée.');
    }

    public function supprimerLigne(Document $document, Ligne $ligne): RedirectResponse
    {
        $this->refuserSiEmis($document);
        $this->refuserSiEtrangere($document, $ligne);

        $ligne->delete();

        return back()->with('succes', 'Ligne supprimée.');
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function validerDocument(Request $requete, bool $avecLignes, bool $avecType = true): array
    {
        $entrepriseId = app(EntrepriseCourante::class)->idObligatoire();

        $regles = [
            // Le client doit appartenir À CETTE entreprise. Un simple
            // `exists:clients,id` laisserait facturer au nom du client d'une
            // autre société en changeant un identifiant dans la requête.
            'client_id' => [
                'required',
                Rule::exists('clients', 'id')->where('entreprise_id', $entrepriseId),
            ],
            'date_emission' => ['required', 'date'],
            'date_echeance' => ['nullable', 'date', 'after_or_equal:date_emission'],
            'objet' => ['nullable', 'string', 'max:200'],
            'conditions' => ['nullable', 'string', 'max:2000'],
            'notes_internes' => ['nullable', 'string', 'max:2000'],
        ];

        if ($avecType) {
            $regles['type'] = ['required', Rule::enum(TypeDocument::class)];
        }

        if ($avecLignes) {
            $regles['lignes'] = ['array'];

            foreach ($this->reglesLigne() as $champ => $contraintes) {
                $regles["lignes.*.{$champ}"] = $contraintes;
            }
        }

        return $requete->validate($regles);
    }

    /** @return array<string, list<mixed>> */
    private function reglesLigne(): array
    {
        return [
            'designation' => ['required', 'string', 'max:255'],
            'unite' => ['nullable', 'string', 'max:16'],
            'quantite' => ['required', 'numeric', 'gt:0'],
            'prix_unitaire_ht' => ['required', 'numeric', 'min:0'],
            'remise_pct' => ['nullable', 'numeric', 'between:0,100'],

            // Les cinq taux marocains, et rien d'autre. La base pose la même
            // contrainte ; la répéter ici sert seulement à rendre un message
            // de formulaire plutôt qu'une erreur de base de données.
            'taux_tva' => ['required', 'numeric', Rule::in([0, 7, 10, 14, 20])],
        ];
    }

    /**
     * La base refuserait de toute façon — triggers `documents_immuables` et
     * `lignes_figees`. On vérifie ici pour rendre un message lisible, pas pour
     * remplacer la garantie.
     */
    private function refuserSiEmis(Document $document): void
    {
        if ($document->estEmis()) {
            throw new ConflitFacturation(
                "Le document {$document->reference} est émis : il se corrige par un avoir."
            );
        }
    }

    /** Une ligne appartient à son document. L'identifiant d'une ligne d'un
     *  autre document glissé dans l'URL ne doit rien pouvoir modifier. */
    private function refuserSiEtrangere(Document $document, Ligne $ligne): void
    {
        abort_unless($ligne->document_id === $document->id, 404);
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
                    .($document->entreprise->forme_juridique ? ' '.$document->entreprise->forme_juridique : ''),
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
