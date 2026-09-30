<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de document.
 *
 * `montant_ht` et `montant_tva` sont calculés par PostgreSQL. Conséquence
 * pratique à connaître : après un `create()` ou un `update()`, Eloquent ne
 * les connaît pas — il n'a envoyé que ce qu'on lui a donné. Il faut un
 * `refresh()` pour les lire, ce que `creerPour()` fait à votre place.
 */
class Ligne extends Model
{
    use HasFactory;

    protected $table = 'lignes';

    protected $fillable = [
        'document_id', 'position',
        'designation', 'unite',
        'quantite', 'prix_unitaire_ht', 'remise_pct', 'taux_tva',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantite' => 'decimal:3',
            'prix_unitaire_ht' => 'decimal:2',
            'remise_pct' => 'decimal:2',
            'taux_tva' => 'decimal:2',
            'montant_ht' => 'decimal:2',
            'montant_tva' => 'decimal:2',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Ajoute une ligne à la fin d'un document et rend l'objet complet,
     * montants calculés compris.
     *
     * La position est déduite des lignes existantes plutôt que demandée à
     * l'appelant : le cas courant est « ajouter à la fin », et l'index unique
     * (document_id, position) punirait la moindre erreur de comptage.
     */
    public static function creerPour(Document $document, array $attributs): self
    {
        $ligne = $document->lignes()->create($attributs + [
            'position' => ((int) $document->lignes()->max('position')) + 1,
        ]);

        // Les colonnes calculées n'existent qu'en base : sans ce relevé,
        // `$ligne->montant_ht` serait nul jusqu'à la prochaine lecture.
        $ligne->refresh();

        return $ligne;
    }

    public function montantTtc(): string
    {
        return bcadd((string) $this->montant_ht, (string) $this->montant_tva, 2);
    }
}
