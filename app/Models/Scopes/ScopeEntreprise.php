<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\EntrepriseCourante;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Ajoute `where entreprise_id = <entreprise courante>` à toute requête, sans
 * que le code appelant ait à y penser.
 *
 * La colonne est qualifiée par le nom de la table : sans cela, une jointure
 * entre deux tables qui portent toutes deux `entreprise_id` rendrait la
 * requête ambiguë et PostgreSQL la refuserait.
 */
final class ScopeEntreprise implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $courante = app(EntrepriseCourante::class);

        if ($courante->estDefinie()) {
            $builder->where($model->qualifyColumn('entreprise_id'), $courante->id());
        }
    }
}
