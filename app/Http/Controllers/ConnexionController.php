<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ConnexionController extends Controller
{
    public function formulaire(): Response
    {
        return Inertia::render('Connexion');
    }

    public function connecter(Request $requete): RedirectResponse
    {
        $identifiants = $requete->validate([
            'email' => ['required', 'email'],
            'mot_de_passe' => ['required', 'string'],
        ]);

        $reussi = Auth::attempt([
            'email' => $identifiants['email'],
            'password' => $identifiants['mot_de_passe'],
        ], $requete->boolean('se_souvenir'));

        if (! $reussi) {
            // Un seul message pour les deux cas — adresse inconnue ou mot de
            // passe faux. Distinguer les deux dirait à un inconnu quelles
            // adresses existent chez nous.
            throw ValidationException::withMessages([
                'email' => 'Ces identifiants ne correspondent à aucun compte.',
            ]);
        }

        // Nouvel identifiant de session après authentification : sans cela, un
        // identifiant obtenu avant la connexion resterait valable après, et
        // servirait à usurper la session (fixation de session).
        $requete->session()->regenerate();

        return redirect()->intended(route('tableau-de-bord'));
    }

    public function deconnecter(Request $requete): RedirectResponse
    {
        Auth::logout();

        $requete->session()->invalidate();
        $requete->session()->regenerateToken();

        return redirect()->route('connexion');
    }
}
