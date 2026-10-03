<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un jeton d'accès à l'API, rattaché à une entreprise.
 *
 * Ce modèle n'emploie PAS le trait `AppartientAEntreprise`. Ce n'est pas un
 * oubli : la portée globale filtre sur l'entreprise courante, or c'est
 * justement ce jeton qui la détermine. Le filtre ne trouverait rien, puisqu'au
 * moment de la recherche aucune entreprise n'est encore posée.
 *
 * C'est l'exception qui confirme la règle, et elle est contenue : la seule
 * lecture non filtrée du modèle est celle qui résout un jeton, et elle se fait
 * par empreinte — une valeur de 64 caractères tirée au hasard.
 */
class JetonApi extends Model
{
    protected $table = 'jetons_api';

    protected $fillable = ['nom'];

    protected function casts(): array
    {
        return [
            'dernier_usage_le' => 'datetime',
            'revoque_le' => 'datetime',
        ];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }

    /**
     * Crée un jeton et rend sa valeur EN CLAIR, une seule fois.
     *
     * Le retour est un couple : le modèle enregistré, et la valeur à remettre à
     * l'intégrateur. Elle n'est stockée nulle part — la relire est impossible,
     * par construction.
     *
     * @return array{0: self, 1: string}
     */
    public static function creerPour(Entreprise $entreprise, string $nom): array
    {
        // 40 octets en base36 : assez long pour qu'un tirage au hasard soit
        // hors de portée, assez court pour tenir dans un en-tête HTTP.
        $valeur = 'fct_'.Str::random(48);

        $jeton = new self(['nom' => $nom]);
        $jeton->entreprise_id = $entreprise->id;
        $jeton->empreinte = self::empreinteDe($valeur);
        $jeton->save();

        return [$jeton, $valeur];
    }

    public static function empreinteDe(string $valeur): string
    {
        return hash('sha256', $valeur);
    }

    /**
     * Le jeton vivant correspondant à cette valeur, ou null.
     *
     * La recherche porte sur l'empreinte, jamais sur la valeur : il n'y a donc
     * aucune comparaison de chaîne à faire côté PHP, et la base travaille sur
     * un index unique.
     */
    public static function resoudre(string $valeur): ?self
    {
        return self::query()
            ->with('entreprise')
            ->where('empreinte', self::empreinteDe($valeur))
            ->vivants()
            ->first();
    }

    public function scopeVivants(Builder $requete): Builder
    {
        return $requete->whereNull('revoque_le');
    }

    public function revoquer(): void
    {
        $this->revoque_le = now();
        $this->save();
    }
}
