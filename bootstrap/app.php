<?php

use App\Facturation\Exceptions\DocumentNonEmissible;
use App\Facturation\Exceptions\ErreurFacturation;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
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
