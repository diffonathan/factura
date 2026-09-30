<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facturation\ServiceRelance;
use App\Jobs\EnvoyerRelance;
use Illuminate\Console\Command;

/**
 * Balaye les factures échues et met une relance en file pour chacune.
 *
 * La commande ne rédige ni n'envoie rien : elle décide QUI mérite QUOI, et
 * délègue. Ce découpage n'est pas décoratif — deux mille factures à relancer
 * en un seul processus finiraient par un délai dépassé ou une mémoire saturée,
 * et on ne saurait pas lesquelles sont parties. En file, chaque relance échoue
 * et se rejoue toute seule.
 */
final class BalayerImpayes extends Command
{
    protected $signature = 'factura:balayer-impayes {--a-blanc : affiche sans rien mettre en file}';

    protected $description = 'Met en file une relance pour chaque facture échue qui en mérite une';

    public function handle(ServiceRelance $relances): int
    {
        $factures = $relances->facturesARelancer();

        if ($factures->isEmpty()) {
            $this->info('Aucune facture à relancer.');

            return self::SUCCESS;
        }

        $lignes = [];

        foreach ($factures as $facture) {
            $niveau = $relances->niveauAttendu($facture);

            $lignes[] = [
                $facture->reference,
                $facture->client->nom,
                $facture->resteAPayer() . ' ' . $facture->devise,
                $facture->joursDeRetard() . ' j',
                $niveau . ' — ' . (\App\Models\Relance::NIVEAUX[$niveau] ?? ''),
            ];

            if (! $this->option('a-blanc')) {
                EnvoyerRelance::dispatch($facture->id, $niveau);
            }
        }

        $this->table(['Facture', 'Client', 'Reste dû', 'Retard', 'Niveau'], $lignes);

        $this->info($this->option('a-blanc')
            ? count($lignes) . ' relance(s) seraient mises en file.'
            : count($lignes) . ' relance(s) mises en file.');

        return self::SUCCESS;
    }
}
