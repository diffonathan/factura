<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Facturation\StatutDocument;
use App\Facturation\TypeDocument;
use App\Models\Client;
use App\Support\EntrepriseCourante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
    public function create(): Response
    {
        return Inertia::render('Clients/Creer');
    }

    public function store(Request $requete): RedirectResponse
    {
        $entrepriseId = app(EntrepriseCourante::class)->idObligatoire();

        $donnees = $requete->validate([
            'nom' => [
                'required', 'string', 'max:200',
                // L'unicité est cadrée par l'entreprise, comme l'index en
                // base : deux sociétés ont parfaitement le droit d'avoir le
                // même client.
                Rule::unique('clients', 'nom')->where('entreprise_id', $entrepriseId),
            ],
            'est_particulier' => ['boolean'],
            // Quinze chiffres, exactement. Le CHECK en base dit la même chose ;
            // ici c'est pour rendre un message de formulaire.
            'ice' => ['nullable', 'string', 'regex:/^[0-9]{15}$/'],
            'identifiant_fiscal' => ['nullable', 'string', 'max:20'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'ville' => ['nullable', 'string', 'max:100'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:180'],
            // 60 jours, ou 120 par accord écrit : la loi 69-21 plafonne là.
            'delai_paiement_jours' => ['required', 'integer', 'between:0,120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'ice.regex' => "L'ICE compte exactement quinze chiffres.",
            'nom.unique' => 'Vous avez déjà un client portant ce nom.',
        ]);

        // Un particulier n'a pas d'ICE, et la base le refuse. On nettoie plutôt
        // que de laisser passer une contradiction que l'utilisateur ne
        // comprendrait pas.
        if ($donnees['est_particulier'] ?? false) {
            $donnees['ice'] = null;
            $donnees['identifiant_fiscal'] = null;
        }

        $client = Client::create($donnees);

        return redirect()
            ->route('clients.index')
            ->with('succes', "Client « {$client->nom} » enregistré.");
    }

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
