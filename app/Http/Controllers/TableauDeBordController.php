<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Facturation\ServiceRelance;
use App\Facturation\StatutDocument;
use App\Facturation\TypeDocument;
use App\Models\Client;
use App\Models\Document;
use App\Support\EntrepriseCourante;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le tableau de bord.
 *
 * Les agrégats sont calculés par la base, en quelques requêtes, et non en
 * parcourant les documents en PHP. Ce n'est pas un détail de performance :
 * charger trois ans de factures pour en faire la somme marche très bien la
 * première année, et met huit secondes la troisième. `sum()` sur un index
 * coûte la même chose quel que soit le volume.
 *
 * Les avoirs sont soustraits du chiffre d'affaires. Ils sont stockés en
 * montants positifs — un avoir de 3 480 MAD est une ligne à 3 480, pas à
 * −3 480 — parce qu'un document négatif se lit mal et s'imprime encore plus
 * mal. Le signe est donc appliqué ici, à la lecture, et à un seul endroit.
 */
final class TableauDeBordController extends Controller
{
    public function index(ServiceRelance $relances): Response
    {
        $entrepriseId = app(EntrepriseCourante::class)->idObligatoire();
        $debutExercice = now()->startOfYear();

        $facture = $this->somme(TypeDocument::Facture, $debutExercice);
        $avoirs = $this->somme(TypeDocument::Avoir, $debutExercice);

        return Inertia::render('TableauDeBord', [
            'exercice' => (int) now()->year,

            'chiffres' => [
                // Facturé net : les factures émises, moins les avoirs.
                'facture_ht' => bcsub($facture['ht'], $avoirs['ht'], 2),
                'facture_ttc' => bcsub($facture['ttc'], $avoirs['ttc'], 2),
                'encaisse' => bcsub($facture['paye'], $avoirs['paye'], 2),
                'tva_collectee' => bcsub($facture['tva'], $avoirs['tva'], 2),
                'reste_du' => $this->resteDu(),
                'en_retard' => $this->resteDu(echuSeulement: true),
                'clients' => Client::count(),
                'brouillons' => Document::whereNull('numero')->count(),
            ],

            'impayes' => $this->impayes(),
            'aRelancer' => $this->aRelancer($relances, $entrepriseId),
            'recents' => $this->recents(),
            'mensuel' => $this->chiffreParMois($entrepriseId),
        ]);
    }

    /** @return array{ht: string, tva: string, ttc: string, paye: string} */
    private function somme(TypeDocument $type, \DateTimeInterface $depuis): array
    {
        $ligne = Document::deType($type)
            ->emis()
            ->where('date_emission', '>=', $depuis)
            ->selectRaw('coalesce(sum(montant_ht), 0) AS ht')
            ->selectRaw('coalesce(sum(montant_tva), 0) AS tva')
            ->selectRaw('coalesce(sum(montant_ttc), 0) AS ttc')
            ->selectRaw('coalesce(sum(montant_paye), 0) AS paye')
            ->first();

        return [
            'ht' => (string) $ligne->ht,
            'tva' => (string) $ligne->tva,
            'ttc' => (string) $ligne->ttc,
            'paye' => (string) $ligne->paye,
        ];
    }

    /** Le total encore dû sur les factures ouvertes. */
    private function resteDu(bool $echuSeulement = false): string
    {
        $requete = Document::deType(TypeDocument::Facture)
            ->where('statut', StatutDocument::Emis->value);

        if ($echuSeulement) {
            $requete->whereDate('date_echeance', '<', now());
        }

        return (string) ($requete
            ->selectRaw('coalesce(sum(montant_ttc - montant_paye), 0) AS du')
            ->value('du') ?? '0');
    }

    /** Les cinq plus gros impayés : c'est là qu'est l'argent à récupérer. */
    private function impayes(): array
    {
        return Document::deType(TypeDocument::Facture)
            ->where('statut', StatutDocument::Emis->value)
            ->whereColumn('montant_paye', '<', 'montant_ttc')
            ->with('client:id,nom,ville')
            ->orderByRaw('(montant_ttc - montant_paye) DESC')
            ->limit(5)
            ->get()
            ->map(fn (Document $facture) => [
                'id' => $facture->id,
                'reference' => $facture->reference,
                'client' => $facture->client->nom,
                'ville' => $facture->client->ville,
                'reste' => $facture->resteAPayer(),
                'echeance' => $facture->date_echeance?->toDateString(),
                'retard' => $facture->joursDeRetard(),
            ])
            ->all();
    }

    /** Ce que le balayage de 8 h mettrait en file s'il tournait maintenant. */
    private function aRelancer(ServiceRelance $relances, int $entrepriseId): array
    {
        return $relances->facturesARelancer()
            ->where('entreprise_id', $entrepriseId)
            ->map(fn (Document $facture) => [
                'id' => $facture->id,
                'reference' => $facture->reference,
                'client' => $facture->client->nom,
                'reste' => $facture->resteAPayer(),
                'retard' => $facture->joursDeRetard(),
                'niveau' => $relances->niveauAttendu($facture),
            ])
            ->values()
            ->all();
    }

    private function recents(): array
    {
        return Document::with('client:id,nom')
            ->orderByDesc('date_emission')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (Document $document) => [
                'id' => $document->id,
                'reference' => $document->reference,
                'type' => $document->type->value,
                'statut' => $document->statut->value,
                'client' => $document->client->nom,
                'date' => $document->date_emission->toDateString(),
                'ttc' => (string) $document->montant_ttc,
            ])
            ->all();
    }

    /**
     * Le facturé des douze derniers mois, avoirs déduits.
     *
     * `generate_series` produit les douze mois côté base, y compris ceux sans
     * la moindre facture. Sans cela, un mois creux disparaîtrait du graphique
     * et les colonnes se décaleraient — le lecteur comparerait mars à mai en
     * croyant comparer mars à avril.
     */
    private function chiffreParMois(int $entrepriseId): array
    {
        $lignes = DB::select(<<<'SQL'
            SELECT to_char(mois.debut, 'YYYY-MM') AS mois,
                   coalesce(sum(
                       CASE d.type WHEN 'AVOIR' THEN -d.montant_ht ELSE d.montant_ht END
                   ), 0) AS ht
              FROM generate_series(
                       date_trunc('month', now()) - interval '11 months',
                       date_trunc('month', now()),
                       interval '1 month'
                   ) AS mois(debut)
              LEFT JOIN documents d
                     ON d.entreprise_id = :entreprise
                    AND d.numero IS NOT NULL
                    AND d.type IN ('FACTURE', 'AVOIR')
                    AND date_trunc('month', d.date_emission) = mois.debut
             GROUP BY mois.debut
             ORDER BY mois.debut
        SQL, ['entreprise' => $entrepriseId]);

        return array_map(static fn (object $l): array => [
            'mois' => $l->mois,
            'ht' => (string) $l->ht,
        ], $lignes);
    }
}
