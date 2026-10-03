<?php

use App\Facturation\Exceptions\DocumentNonEmissible;
use App\Facturation\Exceptions\ErreurFacturation;
use App\Http\Middleware\AuthentifierJetonApi;
use App\Http\Middleware\DefinirEntrepriseCourante;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // L'API vit dans son propre fichier, sans session ni cookie : un jeton
        // porteur suffit, et le groupe « web » ajouterait une protection CSRF
        // qui n'a aucun sens pour un appel de serveur à serveur.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Inertia s'insère dans le groupe « web » : c'est lui qui transforme
        // une réponse de contrôleur en page Vue, et qui transporte les données
        // partagées (utilisateur connecté, messages flash) à chaque visite.
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // L'entreprise courante, posée avant tout contrôleur. C'est ce qui
        // rend le cloisonnement automatique : à partir d'ici les modèles
        // filtrent seuls, et un contrôleur ne peut plus l'oublier.
        $middleware->alias([
            'entreprise' => DefinirEntrepriseCourante::class,
            // L'équivalent pour l'API : il authentifie le jeton ET pose
            // l'entreprise. Les deux en une pièce, pour qu'il n'existe pas
            // d'état « authentifié mais non cloisonné ».
            'jeton.api' => AuthentifierJetonApi::class,
        ]);

        // Sans cela, un visiteur non connecté reçoit la page de connexion de
        // Laravel par défaut, à une route `login` qui n'existe pas ici.
        $middleware->redirectGuestsTo(fn () => route('connexion'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Les erreurs métier de la facturation portent leur propre code HTTP.
         *
         * Un devis déjà facturé n'est pas une panne : rien n'a échoué, l'état
         * du monde a changé entre la lecture et l'écriture. Le rendre en 500
         * déclencherait une alerte pour une situation normale, et noierait les
         * vraies pannes dans le bruit. 409 dit « rechargez », 422 dit « il
         * manque quelque chose » — et l'interface sait quoi en faire.
         */
        $exceptions->render(function (ErreurFacturation $erreur, Request $requete) {
            $charge = ['message' => $erreur->getMessage()];

            if ($erreur instanceof DocumentNonEmissible && $erreur->manques !== []) {
                $charge['manques'] = $erreur->manques;
            }

            return $requete->expectsJson()
                ? response()->json($charge, $erreur->codeHttp())
                : back()->withErrors($charge)->setStatusCode(303);
        });
    })->create();
