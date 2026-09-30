<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Entreprise;
use App\Models\Scopes\ScopeEntreprise;
use App\Support\EntrepriseCourante;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cloisonne un modèle par entreprise.
 *
 * Deux effets, et le second compte autant que le premier :
 *
 *   - toute lecture est filtrée sur l'entreprise courante ;
 *   - toute création reçoit son `entreprise_id` automatiquement.
 *
 * Sans le second, on écrirait des lignes correctement invisibles à la lecture
 * mais attachées à la mauvaise entreprise — le pire des deux mondes, puisque
 * le défaut ne se voit nulle part avant qu'on change de contexte.
 *
 * Le filtre ne s'applique que si une entreprise courante est définie. Les
 * travaux d'arrière-plan qui balaient les impayés de tous les clients n'en
 * ont pas, et c'est légitime : ils ne rendent rien à un navigateur. Le code
 * qui veut délibérément traverser les cloisons doit le dire à voix haute,
 * avec `sansCloisonnement()`.
 */
trait AppartientAEntreprise
{
    public static function bootAppartientAEntreprise(): void
    {
        static::addGlobalScope(new ScopeEntreprise());

        static::creating(function (self $modele): void {
            if ($modele->entreprise_id === null) {
                $modele->entreprise_id = app(EntrepriseCourante::class)->idObligatoire();
            }
        });
    }

    /**
     * Lève le cloisonnement, explicitement. Réservé aux travaux planifiés et
     * aux commandes d'administration — jamais à un contrôleur.
     */
    public static function sansCloisonnement(): Builder
    {
        return static::query()->withoutGlobalScope(ScopeEntreprise::class);
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }
}
