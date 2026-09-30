<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Facturation\StatutDocument;
use App\Facturation\TypeDocument;
use App\Models\Client;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le carnet de clients, avec l'encours de chacun.
 *
 * Les agrégats sont calculés par des sous-requêtes, en un aller-retour. Les
 * obtenir en parcourant les clients et en comptant leurs factures ferait une
 * requête par ligne — c'est le défaut N+1, et c'est pour l'empêcher que
 * `Model::preventLazyLoading()` est actif hors production : il le transforme
 * en exception au lieu d'une page qui ralentit sans qu'on sache pourquoi.
 */
final class ClientController extends Controller
{
    public function index(Request $requete): Response
    {
        $recherche = trim($requete->string('recherche')->toString());

        $clients = Client::query()
            ->withCount(['documents as factures_count' => fn ($q) => $q
                ->where('type', TypeDocument::Facture->value)
                ->whereNotNull('numero')])
            ->withSum(['documents as facture_total' => fn ($q) => $q
                ->where('type', TypeDocument::Facture->value)
                ->whereNotNull('numero')], 'montant_ttc')
            ->withSum(['documents as encours' => fn ($q) => $q
                ->where('type', TypeDocument::Facture->value)
                ->where('statut', StatutDocument::Emis->value)], 'montant_ttc')
            ->withSum(['documents as encaisse' => fn ($q) => $q
                ->where('type', TypeDocument::Facture->value)
                ->where('statut', StatutDocument::Emis->value)], 'montant_paye')
            ->when($recherche !== '', fn ($q) => $q
                ->where(fn ($sous) => $sous
                    ->where('nom', 'ilike', "%{$recherche}%")
                    ->orWhere('ice', 'ilike', "%{$recherche}%")
                    ->orWhere('ville', 'ilike', "%{$recherche}%")))
            ->orderBy('nom')
            ->get()
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'nom' => $client->nom,
                'est_particulier' => $client->est_particulier,
                'ice' => $client->ice,
                'ville' => $client->ville,
                'email' => $client->email,
                'telephone' => $client->telephone,
                'delai' => $client->delai_paiement_jours,
                'factures' => (int) $client->factures_count,
                'total' => (string) ($client->facture_total ?? '0'),
                'reste' => bcsub(
                    (string) ($client->encours ?? '0'),
                    (string) ($client->encaisse ?? '0'),
                    2,
                ),
            ])
            ->all();

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'recherche' => $recherche,
        ]);
    }
}
