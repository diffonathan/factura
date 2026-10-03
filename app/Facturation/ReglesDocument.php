<?php

declare(strict_types=1);

namespace App\Facturation;

use Illuminate\Validation\Rule;

/**
 * Les règles de validation d'un document, en un seul endroit.
 *
 * Extraites du contrôleur web le jour où l'API est arrivée. Deux portes
 * d'entrée pour le même métier, c'est deux occasions de diverger : il aurait
 * suffi qu'un taux de TVA soit ajouté d'un côté pour que l'autre refuse des
 * documents que la base, elle, accepte. Les règles vivent donc ici, et les
 * contrôleurs décident seulement ce qu'ils en font.
 *
 * Ce n'est pas la garantie — la base porte les mêmes contraintes et c'est elle
 * qui tient. Ce qui est ici sert à rendre un message lisible plutôt qu'une
 * erreur SQL.
 */
final class ReglesDocument
{
    /** @return array<string, mixed> */
    public static function enTete(int $entrepriseId, bool $avecType = true): array
    {
        $regles = [
            // Le client doit appartenir À CETTE entreprise. Un simple
            // `exists:clients,id` laisserait facturer au nom du client d'une
            // autre société en changeant un identifiant dans la requête.
            'client_id' => [
                'required',
                Rule::exists('clients', 'id')->where('entreprise_id', $entrepriseId),
            ],
            'date_emission' => ['required', 'date'],
            'date_echeance' => ['nullable', 'date', 'after_or_equal:date_emission'],
            'objet' => ['nullable', 'string', 'max:200'],
            'conditions' => ['nullable', 'string', 'max:2000'],
            'notes_internes' => ['nullable', 'string', 'max:2000'],
        ];

        if ($avecType) {
            $regles['type'] = ['required', Rule::enum(TypeDocument::class)];
        }

        return $regles;
    }

    /** @return array<string, list<mixed>> */
    public static function ligne(): array
    {
        return [
            'designation' => ['required', 'string', 'max:255'],
            'unite' => ['nullable', 'string', 'max:16'],
            'quantite' => ['required', 'numeric', 'gt:0'],
            'prix_unitaire_ht' => ['required', 'numeric', 'min:0'],
            'remise_pct' => ['nullable', 'numeric', 'between:0,100'],

            // Les cinq taux marocains, et rien d'autre. La base pose la même
            // contrainte ; la répéter ici sert seulement à rendre un message
            // de formulaire plutôt qu'une erreur de base de données.
            'taux_tva' => ['required', 'numeric', Rule::in([0, 7, 10, 14, 20])],
        ];
    }

    /**
     * L'en-tête ET les lignes, préfixées comme Laravel les attend.
     *
     * @return array<string, mixed>
     */
    public static function complet(int $entrepriseId, bool $avecType = true): array
    {
        $regles = self::enTete($entrepriseId, $avecType);
        $regles['lignes'] = ['array'];

        foreach (self::ligne() as $champ => $contraintes) {
            $regles["lignes.*.{$champ}"] = $contraintes;
        }

        return $regles;
    }
}
