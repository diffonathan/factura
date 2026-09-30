<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\EntrepriseCourante;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /**
         * Instance UNIQUE pour la durée de la requête.
         *
         * `singleton` et non une simple résolution automatique : sans lui, le
         * conteneur fabrique un objet neuf à chaque `app(EntrepriseCourante)`.
         * Le middleware écrirait l'entreprise dans le sien, et le filtre des
         * modèles lirait un autre objet, vide — donc aucun cloisonnement, sans
         * le moindre message d'erreur. Le défaut le plus coûteux du projet
         * serait passé inaperçu si les tests d'isolation n'existaient pas.
         */
        $this->app->singleton(EntrepriseCourante::class);
    }

    public function boot(): void
    {
        /**
         * Interdit l'accès à une relation qui n'a pas été chargée.
         *
         * En développement et en test seulement : ce qui se manifeste en
         * production par une page lente se manifeste ici par une exception, au
         * moment précis où la requête de trop est écrite. Une liste de cent
         * factures qui charge son client ligne par ligne fait cent-une
         * requêtes ; le tableau de bord finit par mettre huit secondes, et
         * personne ne sait laquelle des cent vues en est la cause.
         */
        Model::preventLazyLoading(! $this->app->isProduction());

        // Une affectation en masse sur un attribut non déclaré est une faute de
        // frappe, pas une valeur à ignorer en silence.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
