<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppartientAEntreprise;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un client d'une entreprise. Cloisonné : le carnet d'adresses de l'un n'est
 * jamais visible depuis l'autre.
 */
class Client extends Model
{
    use AppartientAEntreprise;
    use HasFactory;

    protected $table = 'clients';

    protected $fillable = [
        'nom', 'est_particulier',
        'ice', 'identifiant_fiscal',
        'adresse', 'ville', 'telephone', 'email',
        'delai_paiement_jours', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'est_particulier' => 'boolean',
            'delai_paiement_jours' => 'integer',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Un professionnel doit porter son ICE sur la facture qu'il reçoit, sans
     * quoi il ne peut pas déduire la TVA. Le signaler avant l'émission évite
     * d'avoir à émettre un avoir pour un champ oublié.
     */
    public function iceManquant(): bool
    {
        return ! $this->est_particulier && blank($this->ice);
    }
}
