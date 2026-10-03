<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Facturation\GenerateurPdf;
use App\Facturation\ReglesDocument;
use App\Facturation\ServiceEmission;
use App\Facturation\StatutDocument;
use App\Facturation\TypeDocument;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Ligne;
use App\Support\EntrepriseCourante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Les documents, pour un programme.
 *
 * Aucune vérification d'appartenance n'est écrite ici. Le middleware du jeton
 * pose l'entreprise, la portée globale des modèles filtre ensuite : un document
 * d'une autre entreprise n'est jamais résolu, et la route rend 404 avant
 * d'entrer dans la méthode. C'est le MÊME mécanisme que pour une visite au
 * navigateur — il n'y a pas une isolation « web » et une isolation « API » à
 * garder en accord.
 *
 * Les montants sortent en CHAÎNES, jamais en nombres flottants. « 94560.00 »
 * tient exactement ; 94560.00 en virgule flottante ne tient pas toujours, et
 * un client JavaScript qui additionne des totaux finirait par afficher un
 * centime de travers. Les décimales d'argent voyagent en texte, comme elles
 * sont stockées.
 */
final class DocumentApiController extends Controller
{
    public function index(Request $requete): JsonResponse
    {
        $requete->validate([
            'type' => ['nullable', Rule::enum(TypeDocument::class)],
            'statut' => ['nullable', Rule::enum(StatutDocument::class)],
            'client_id' => ['nullable', 'integer'],
            'par_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $documents = Document::query()
            ->with(['client:id,nom'])
            ->when($requete->filled('type'), fn ($q) => $q->where('type', $requete->string('type')))
            ->when($requete->filled('statut'), fn ($q) => $q->where('statut', $requete->string('statut')))
            ->when($requete->filled('client_id'), fn ($q) => $q->where('client_id', $requete->integer('client_id')))
            ->orderByDesc('date_emission')
            ->orderByDesc('id')
            // Pagination par défaut, et plafonnée : sans elle, un cabinet avec
            // dix ans d'historique renverrait tout à chaque appel.
            ->paginate($requete->integer('par_page', 25));

        return response()->json([
            'donnees' => array_map($this->enResume(...), $documents->items()),
            'pagination' => [
                'page' => $documents->currentPage(),
                'par_page' => $documents->perPage(),
                'total' => $documents->total(),
                'pages' => $documents->lastPage(),
            ],
        ]);
    }

    public function show(Document $document): JsonResponse
    {
        $document->load(['client', 'lignes', 'paiements']);

        return response()->json(['donnees' => $this->enDetail($document)]);
    }

    /**
     * Crée un brouillon, et l'émet si on le demande.
     *
     * L'émission est un paramètre et non une route séparée parce que c'est le
     * cas courant d'une intégration : un logiciel de caisse qui facture une
     * vente ne veut pas relire son brouillon. Mais elle reste FACULTATIVE —
     * créer puis relire avant d'émettre doit rester possible, sinon une erreur
     * de saisie consomme un numéro de la série légale.
     */
    public function store(Request $requete, ServiceEmission $emission): JsonResponse
    {
        $entrepriseId = app(EntrepriseCourante::class)->idObligatoire();
        $donnees = $requete->validate(ReglesDocument::complet($entrepriseId) + [
            'emettre' => ['nullable', 'boolean'],
        ]);

        $document = DB::transaction(function () use ($donnees): Document {
            $document = Document::create(Arr::except($donnees, ['lignes', 'emettre']));

            foreach ($donnees['lignes'] ?? [] as $ligne) {
                Ligne::creerPour($document, $ligne);
            }

            return $document->refresh();
        });

        if ($requete->boolean('emettre')) {
            $document = $emission->emettre($document);
        }

        return response()->json(
            ['donnees' => $this->enDetail($document->load(['client', 'lignes', 'paiements']))],
            201,
        );
    }

    /**
     * Attribue le numéro de la série légale et fige le document.
     *
     * Irréversible, et c'est le point : la numérotation sans trou n'a de sens
     * que si l'on ne peut pas revenir en arrière. Une erreur se corrige par un
     * avoir, pas par une réécriture.
     */
    public function emettre(Document $document, ServiceEmission $emission): JsonResponse
    {
        $emis = $emission->emettre($document);

        return response()->json([
            'donnees' => $this->enDetail($emis->load(['client', 'lignes', 'paiements'])),
        ]);
    }

    public function pdf(Document $document, GenerateurPdf $generateur): HttpResponse
    {
        return response($generateur->rendre($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$generateur->nomDeFichier($document).'"',
        ]);
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function enResume(Document $document): array
    {
        return [
            'id' => $document->id,
            'reference' => $document->reference,
            'numero' => $document->numero,
            'type' => $document->type->value,
            'statut' => $document->statut->value,
            'client' => ['id' => $document->client->id, 'nom' => $document->client->nom],
            'date_emission' => $document->date_emission->toDateString(),
            'date_echeance' => $document->date_echeance?->toDateString(),
            'montant_ht' => (string) $document->montant_ht,
            'montant_tva' => (string) $document->montant_tva,
            'montant_ttc' => (string) $document->montant_ttc,
            'montant_paye' => (string) $document->montant_paye,
            'reste_a_payer' => (string) $document->resteAPayer(),
            'devise' => $document->devise,
        ];
    }

    /** @return array<string, mixed> */
    private function enDetail(Document $document): array
    {
        return $this->enResume($document) + [
            'objet' => $document->objet,
            'conditions' => $document->conditions,
            'emis_le' => $document->emis_le?->toIso8601String(),
            'jours_de_retard' => $document->joursDeRetard(),
            'ventilation_tva' => $document->ventilationTva(),
            'lignes' => $document->lignes->map(fn (Ligne $ligne) => [
                'id' => $ligne->id,
                'position' => $ligne->position,
                'designation' => $ligne->designation,
                'unite' => $ligne->unite,
                'quantite' => (string) $ligne->quantite,
                'prix_unitaire_ht' => (string) $ligne->prix_unitaire_ht,
                'remise_pct' => (string) $ligne->remise_pct,
                'taux_tva' => (string) $ligne->taux_tva,
                'montant_ht' => (string) $ligne->montant_ht,
                'montant_tva' => (string) $ligne->montant_tva,
            ])->all(),
            'paiements' => $document->paiements->map(fn ($paiement) => [
                'id' => $paiement->id,
                'date_paiement' => $paiement->date_paiement->toDateString(),
                'montant' => (string) $paiement->montant,
                'mode' => $paiement->mode->value,
                'reference' => $paiement->reference,
            ])->all(),
        ];
    }
}
