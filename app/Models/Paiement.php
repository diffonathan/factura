<?php

declare(strict_types=1);

namespace App\Models;

use App\Facturation\ModePaiement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un encaissement. Enregistrer la ligne suffit : le solde et le statut du
 * document suivent, tenus par un trigger. Rien à recalculer côté PHP, donc
 * rien à oublier de recalculer.
 */
class Paiement extends Model
{
    use HasFactory;

    protected $table = 'paiements';

    protected $fillable = [
        'document_id', 'date_paiement', 'montant', 'mode', 'reference', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_paiement' => 'date',
            'montant' => 'decimal:2',
            'mode' => ModePaiement::class,
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
