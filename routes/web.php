<?php

declare(strict_types=1);

use App\Http\Controllers\ClientController;
use App\Http\Controllers\ConnexionController;
use App\Http\Controllers\DocumentationController;
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

/*
 * La documentation technique est HORS authentification, délibérément : elle
 * s'adresse à quelqu'un qui découvre le projet et n'a aucune raison d'avoir un
 * compte de facturation. Son propre mot de passe suffit à la protéger.
 */
Route::get('/documentation', [DocumentationController::class, 'index'])->name('documentation');
Route::post('/documentation', [DocumentationController::class, 'ouvrir'])->name('documentation.ouvrir');
Route::post('/documentation/fermer', [DocumentationController::class, 'fermer'])->name('documentation.fermer');

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

    // « nouveau » AVANT « {document} » : sans cela, Laravel prend « nouveau »
    // pour un identifiant de document et rend un 404 au lieu du formulaire.
    Route::get('/documents/nouveau', [DocumentController::class, 'create'])->name('documents.create');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');

    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::patch('/documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    Route::post('/documents/{document}/lignes', [DocumentController::class, 'ajouterLigne'])
        ->name('documents.lignes.store');
    Route::patch('/documents/{document}/lignes/{ligne}', [DocumentController::class, 'modifierLigne'])
        ->name('documents.lignes.update');
    Route::delete('/documents/{document}/lignes/{ligne}', [DocumentController::class, 'supprimerLigne'])
        ->name('documents.lignes.destroy');

    Route::post('/documents/{document}/emettre', [DocumentController::class, 'emettre'])
        ->name('documents.emettre');
    Route::post('/documents/{document}/convertir', [DocumentController::class, 'convertir'])
        ->name('documents.convertir');
    Route::post('/documents/{document}/avoir', [DocumentController::class, 'avoir'])
        ->name('documents.avoir');
    Route::post('/documents/{document}/encaisser', [DocumentController::class, 'encaisser'])
        ->name('documents.encaisser');

    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('/clients/nouveau', [ClientController::class, 'create'])->name('clients.create');
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
});
