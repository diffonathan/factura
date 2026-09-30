<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * L'entreprise qui facture. Racine du cloisonnement : elle n'est jamais
 * cloisonnée elle-même, c'est elle qui cloisonne les autres.
 */
class Entreprise extends Model
{
    use HasFactory;

    protected $table = 'entreprises';

    protected $fillable = [
        'raison_sociale', 'forme_juridique',
        'ice', 'identifiant_fiscal', 'registre_commerce', 'taxe_professionnelle', 'cnss',
        'adresse', 'ville', 'telephone', 'email', 'site_web',
        'banque', 'rib', 'regime_tva',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function utilisateurs(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'entreprise_utilisateur')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Les mentions que la loi exige sur chaque document émis. Rassemblées ici
     * pour qu'il n'y ait qu'un endroit à corriger le jour où l'administration
     * en ajoute une — et un seul endroit à tester.
     */
    public function mentionsLegales(): array
    {
        return array_values(array_filter([
            $this->ice ? 'ICE : '.$this->ice : null,
            $this->identifiant_fiscal ? 'IF : '.$this->identifiant_fiscal : null,
            $this->registre_commerce ? 'RC : '.$this->registre_commerce : null,
            $this->taxe_professionnelle ? 'TP : '.$this->taxe_professionnelle : null,
            $this->cnss ? 'CNSS : '.$this->cnss : null,
        ]));
    }

    /**
     * Ce qui manque pour qu'un document émis soit régulier. Un tableau vide
     * vaut « rien ne manque » ; l'appelant n'a pas à connaître la liste.
     */
    public function mentionsManquantes(): array
    {
        $exigees = [
            'ice' => 'ICE',
            'identifiant_fiscal' => 'identifiant fiscal',
            'registre_commerce' => 'registre de commerce',
            'adresse' => 'adresse',
        ];

        $manquantes = [];

        foreach ($exigees as $champ => $libelle) {
            if (blank($this->{$champ})) {
                $manquantes[] = $libelle;
            }
        }

        return $manquantes;
    }
}
