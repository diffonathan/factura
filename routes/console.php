<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/**
 * Les travaux planifiés.
 *
 * Un seul point d'entrée côté système : `php artisan schedule:run` toutes les
 * minutes. C'est le planificateur de Laravel qui décide ensuite quoi lancer,
 * ce qui met la planification dans le dépôt — relisible, testable, déployée
 * avec le code — plutôt que dans un crontab que personne ne retrouve.
 */

/**
 * Le balayage des impayés, chaque matin à 8 h.
 *
 * L'heure n'est pas indifférente : un client qui reçoit une relance à 3 h du
 * matin la trouve noyée dans ses courriels du matin. Et le balayage tourne
 * après l'heure à laquelle les virements de la veille sont enregistrés, pour
 * ne pas relancer quelqu'un qui vient de payer.
 *
 * `withoutOverlapping` protège du cas où un balayage dure plus longtemps que
 * prévu : deux exécutions simultanées mettraient les mêmes relances en file
 * deux fois. La réservation en base l'arrêterait, mais autant ne pas produire
 * le travail inutile.
 */
Schedule::command('factura:balayer-impayes')
    ->dailyAt('08:00')
    ->timezone('Africa/Casablanca')
    ->weekdays()
    ->withoutOverlapping()
    ->onOneServer();
