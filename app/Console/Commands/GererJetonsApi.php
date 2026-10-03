<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Entreprise;
use App\Models\JetonApi;
use Illuminate\Console\Command;

/**
 * Crée, liste et révoque les jetons d'accès à l'API.
 *
 * En ligne de commande et non dans l'interface, et c'est délibéré pour
 * l'instant : un jeton d'API se pose une fois, par la personne qui branche
 * l'outil. L'exposer dans l'application demanderait de décider qui a le droit
 * d'en créer — une question de rôles que ce projet ne tranche pas encore, et
 * qu'il vaut mieux laisser ouverte que trancher à la va-vite.
 *
 *   php artisan factura:jeton creer 1 "caisse boutique"
 *   php artisan factura:jeton lister 1
 *   php artisan factura:jeton revoquer 7
 */
final class GererJetonsApi extends Command
{
    protected $signature = 'factura:jeton
        {action : creer, lister ou revoquer}
        {cible : identifiant de l\'entreprise (creer, lister) ou du jeton (revoquer)}
        {nom? : à quoi sert ce jeton — obligatoire pour « creer »}';

    protected $description = 'Gère les jetons d\'accès à l\'API';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'creer' => $this->creer(),
            'lister' => $this->lister(),
            'revoquer' => $this->revoquer(),
            default => $this->erreur('Action inconnue. Attendu : creer, lister ou revoquer.'),
        };
    }

    private function creer(): int
    {
        $entreprise = Entreprise::find((int) $this->argument('cible'));

        if ($entreprise === null) {
            return $this->erreur('Entreprise introuvable.');
        }

        $nom = $this->argument('nom');

        if ($nom === null || trim($nom) === '') {
            return $this->erreur('Donnez un nom au jeton : « caisse boutique », « export comptable ». Sans lui, révoquer le bon devient un pari.');
        }

        [$jeton, $valeur] = JetonApi::creerPour($entreprise, trim($nom));

        $this->newLine();
        $this->info("Jeton n°{$jeton->id} créé pour {$entreprise->raison_sociale} — « {$jeton->nom} »");
        $this->newLine();
        $this->line('  '.$valeur);
        $this->newLine();
        // Le dire fort : seule l'empreinte est stockée, la valeur ne se relit
        // nulle part. Quelqu'un qui ferme ce terminal sans copier devra en
        // créer un autre — ce qui est le comportement correct, pas un défaut.
        $this->warn('  Copiez-la maintenant : elle n\'est stockée nulle part et ne sera plus affichée.');
        $this->newLine();
        $this->line('  Essai :');
        $this->line('    curl -H "Authorization: Bearer '.$valeur.'" \\');
        $this->line('         '.rtrim((string) config('app.url'), '/').'/api/v1/documents');
        $this->newLine();

        return self::SUCCESS;
    }

    private function lister(): int
    {
        $entreprise = Entreprise::find((int) $this->argument('cible'));

        if ($entreprise === null) {
            return $this->erreur('Entreprise introuvable.');
        }

        $jetons = JetonApi::where('entreprise_id', $entreprise->id)->orderBy('id')->get();

        if ($jetons->isEmpty()) {
            $this->line('Aucun jeton pour '.$entreprise->raison_sociale.'.');

            return self::SUCCESS;
        }

        $this->table(
            ['n°', 'nom', 'dernier usage', 'état'],
            $jetons->map(fn (JetonApi $j) => [
                $j->id,
                $j->nom,
                $j->dernier_usage_le?->diffForHumans() ?? 'jamais',
                $j->revoque_le ? 'révoqué' : 'actif',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function revoquer(): int
    {
        $jeton = JetonApi::find((int) $this->argument('cible'));

        if ($jeton === null) {
            return $this->erreur('Jeton introuvable.');
        }

        if ($jeton->revoque_le !== null) {
            $this->line("Le jeton n°{$jeton->id} était déjà révoqué.");

            return self::SUCCESS;
        }

        $jeton->revoquer();
        $this->info("Jeton n°{$jeton->id} « {$jeton->nom} » révoqué. Les appels qui l'emploient reçoivent désormais 401.");

        return self::SUCCESS;
    }

    private function erreur(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
