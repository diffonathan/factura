<?php

declare(strict_types=1);

use App\Http\Controllers\ClientController;
use App\Http\Controllers\ConnexionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\TableauDeBordController;
use Illuminate\Support\Facades\Route;

/*
 * Les chemins sont en français, comme le reste du domaine. Mélanger
 * `/documents/{document}/emettre` et `/invoices/{invoice}/issue` dans la même
 * application oblige à traduire mentalement à chaque lecture.
 *
 * `middleware('entreprise')` accompagne `auth` partout : c'est lui qui pose
 * l'entreprise courante, donc le cloisonnement. Une route qui l'oublierait
 * verrait toutes les entreprises — d'où le groupe unique plutôt qu'une
 * déclaration route par route.
 */

Route::middleware('guest')->group(function (): void {
    Route::get('/connexion', [ConnexionController::class, 'formulaire'])->name('connexion');
    Route::post('/connexion', [ConnexionController::class, 'connecter']);
});

Route::post('/deconnexion', [ConnexionController::class, 'deconnecter'])
    ->middleware('auth')
    ->name('deconnexion');

Route::middleware(['auth', 'entreprise'])->group(function (): void {
    Route::get('/', [TableauDeBordController::class, 'index'])->name('tableau-de-bord');

    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');

    Route::post('/documents/{document}/emettre', [DocumentController::class, 'emettre'])
        ->name('documents.emettre');
    Route::post('/documents/{document}/convertir', [DocumentController::class, 'convertir'])
        ->name('documents.convertir');
    Route::post('/documents/{document}/avoir', [DocumentController::class, 'avoir'])
        ->name('documents.avoir');
    Route::post('/documents/{document}/encaisser', [DocumentController::class, 'encaisser'])
        ->name('documents.encaisser');

    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
});
