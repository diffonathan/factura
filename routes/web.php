<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/**
 * Page d'accueil provisoire.
 *
 * Elle ne montre rien de métier : elle prouve que la chaîne complète répond —
 * Laravel rend une page Vue par Inertia, et les valeurs affichées viennent
 * réellement du serveur, pas du navigateur. Elle sera remplacée par le
 * tableau de bord de facturation.
 */
Route::get('/', function () {
    return Inertia::render('Accueil', [
        'laravel' => app()->version(),
        'php' => PHP_VERSION,
        'base' => DB::connection()->getDriverName(),
    ]);
})->name('accueil');
