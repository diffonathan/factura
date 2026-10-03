<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le carnet d'adresses, en lecture.
 *
 * Il est là parce que créer un document exige un `client_id`, et qu'un
 * programme doit pouvoir le retrouver sans qu'on le lui souffle. L'écriture
 * n'est pas exposée : un client se saisit une fois, à la main, avec son ICE —
 * c'est une donnée légale qu'on ne veut pas voir créée en masse par une boucle.
 */
final class ClientApiController extends Controller
{
    public function index(Request $requete): JsonResponse
    {
        $requete->validate([
            'recherche' => ['nullable', 'string', 'max:100'],
            'par_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $clients = Client::query()
            ->when(
                $requete->filled('recherche'),
                fn ($q) => $q->whereRaw('nom ILIKE ?', ['%'.$requete->string('recherche').'%']),
            )
            ->orderBy('nom')
            ->paginate($requete->integer('par_page', 25));

        return response()->json([
            'donnees' => array_map(fn (Client $client) => [
                'id' => $client->id,
                'nom' => $client->nom,
                'est_particulier' => $client->est_particulier,
                'ice' => $client->ice,
                'identifiant_fiscal' => $client->identifiant_fiscal,
                'ville' => $client->ville,
                'delai_paiement_jours' => $client->delai_paiement_jours,
            ], $clients->items()),
            'pagination' => [
                'page' => $clients->currentPage(),
                'par_page' => $clients->perPage(),
                'total' => $clients->total(),
                'pages' => $clients->lastPage(),
            ],
        ]);
    }
}
