<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La trace d'une relance, et surtout la réservation d'un niveau.
 *
 * Le niveau est réservé par `ServiceRelance::reserverNiveau()`, qui s'appuie
 * sur l'index unique (document_id, niveau) : c'est cette ligne qui empêche un
 * client de recevoir deux fois la même mise en demeure quand la file
 * d'attente rejoue un travail.
 */
class Relance extends Model
{
    use HasFactory;

    protected $table = 'relances';

    public const NIVEAUX = [
        1 => 'Rappel courtois',
        2 => 'Relance ferme',
        3 => 'Mise en demeure',
    ];

    protected $fillable = [
        'document_id', 'niveau', 'statut', 'canal', 'destinataire', 'erreur',
    ];

    protected function casts(): array
    {
        return [
            'niveau' => 'integer',
            'traitee_le' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function libelleNiveau(): string
    {
        return self::NIVEAUX[$this->niveau] ?? 'Relance';
    }
}
