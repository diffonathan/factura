<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La documentation technique, derrière un mot de passe.
 *
 * Elle s'adresse à un recruteur ou à un développeur qui veut aller plus loin
 * que la visite guidée : les choix de conception, ce que la base garantit et
 * comment, les pièges rencontrés. Elle est donc plus technique que le reste de
 * l'application — et séparée des comptes de facturation, qui n'ont rien à voir
 * avec elle.
 *
 * TROIS PRÉCAUTIONS, et chacune répond à une façon concrète de forcer la porte
 * ou de faire fuiter le mot de passe :
 *
 *   1. Le mot de passe n'est nulle part dans le dépôt. Il vient de `.env`, qui
 *      n'est pas versionné. Ce dépôt est public : une valeur écrite dans le
 *      code serait lisible par tout le monde.
 *
 *   2. La comparaison passe par `hash_equals`, qui prend le même temps quel
 *      que soit l'endroit où les deux chaînes divergent. Un `===` s'arrête au
 *      premier caractère différent, et cette différence de durée — quelques
 *      microsecondes, mesurables sur des milliers d'essais — laisse deviner le
 *      mot de passe caractère par caractère.
 *
 *   3. Cinq essais par minute et par adresse. Sans cela, un mot de passe même
 *      long tombe : une machine en essaie des millions par heure.
 */
final class DocumentationController extends Controller
{
    private const CLE_SESSION = 'documentation_ouverte';

    private const ESSAIS_PAR_MINUTE = 5;

    public function index(Request $requete): Response
    {
        if (! $requete->session()->get(self::CLE_SESSION)) {
            return Inertia::render('Documentation/Verrou');
        }

        return Inertia::render('Documentation/Index');
    }

    public function ouvrir(Request $requete): RedirectResponse
    {
        $requete->validate(['mot_de_passe' => ['required', 'string']]);

        $cle = 'documentation:'.$requete->ip();

        if (RateLimiter::tooManyAttempts($cle, self::ESSAIS_PAR_MINUTE)) {
            throw ValidationException::withMessages([
                'mot_de_passe' => sprintf(
                    'Trop d\'essais. Réessayez dans %d secondes.',
                    RateLimiter::availableIn($cle),
                ),
            ]);
        }

        $attendu = (string) config('factura.documentation.mot_de_passe');

        // Un mot de passe non configuré n'ouvre pas la porte : il la ferme.
        // L'inverse — accepter une chaîne vide — transformerait une variable
        // oubliée au déploiement en accès libre, sans aucun signe visible.
        $correct = $attendu !== ''
            && hash_equals($attendu, (string) $requete->input('mot_de_passe'));

        if (! $correct) {
            RateLimiter::hit($cle, 60);

            throw ValidationException::withMessages([
                'mot_de_passe' => 'Mot de passe incorrect.',
            ]);
        }

        RateLimiter::clear($cle);

        // Nouvel identifiant de session après un contrôle réussi : un
        // identifiant obtenu avant resterait valable après, et servirait à
        // usurper l'accès.
        $requete->session()->regenerate();
        $requete->session()->put(self::CLE_SESSION, true);

        return redirect()->route('documentation');
    }

    public function fermer(Request $requete): RedirectResponse
    {
        $requete->session()->forget(self::CLE_SESSION);

        return redirect()->route('documentation');
    }
}
