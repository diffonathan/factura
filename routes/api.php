<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ClientApiController;
use App\Http\Controllers\Api\DocumentApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| L'API de Factura
|--------------------------------------------------------------------------
|
| Destinée à brancher un outil : logiciel de caisse, site marchand, export
| comptable. Elle agit au nom d'une ENTREPRISE, identifiée par un jeton
| porteur — pas au nom d'un utilisateur, parce qu'une intégration ne doit pas
| tomber le jour où la personne qui l'a mise en place quitte le cabinet.
|
| Toutes les routes passent par `jeton.api`, qui authentifie ET pose
| l'entreprise courante. À partir de là, le cloisonnement est celui des
| modèles : il n'y a pas de filtre à écrire dans les contrôleurs, et donc pas
| de filtre à oublier.
|
| Version dans le chemin plutôt que dans un en-tête : un intégrateur colle une
| adresse dans un navigateur pour essayer, et il doit voir tout de suite à quoi
| il s'adresse.
*/

Route::middleware('jeton.api')->prefix('v1')->group(function (): void {
    Route::get('/documents', [DocumentApiController::class, 'index']);
    Route::post('/documents', [DocumentApiController::class, 'store']);
    Route::get('/documents/{document}', [DocumentApiController::class, 'show']);
    Route::get('/documents/{document}/pdf', [DocumentApiController::class, 'pdf']);
    Route::post('/documents/{document}/emettre', [DocumentApiController::class, 'emettre']);

    Route::get('/clients', [ClientApiController::class, 'index']);
});
