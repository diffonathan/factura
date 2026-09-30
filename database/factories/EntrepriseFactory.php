<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Entreprise;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entreprise>
 */
class EntrepriseFactory extends Factory
{
    protected $model = Entreprise::class;

    public function definition(): array
    {
        return [
            'raison_sociale' => $this->faker->company(),
            'forme_juridique' => $this->faker->randomElement(['SARL', 'SARL AU', 'SA', 'Auto-entrepreneur']),

            // Les identifiants sont complets par défaut : une entreprise dont
            // les mentions manquent est un cas particulier, et c'est au test
            // qui s'y intéresse de le demander (`sansMentions()`).
            'ice' => (string) $this->faker->numerify('###############'),
            'identifiant_fiscal' => (string) $this->faker->numerify('########'),
            'registre_commerce' => (string) $this->faker->numerify('######'),
            'taxe_professionnelle' => (string) $this->faker->numerify('########'),
            'adresse' => $this->faker->streetAddress(),
            'ville' => $this->faker->randomElement(['Casablanca', 'Rabat', 'Marrakech', 'Tanger', 'Agadir', 'Fès']),
            'telephone' => '+212 5'.$this->faker->numerify('## ## ## ##'),
            'email' => $this->faker->companyEmail(),
            'regime_tva' => 'DEBIT',
        ];
    }

    /** Une entreprise dont le profil est incomplet : l'émission doit la refuser. */
    public function sansMentions(): static
    {
        return $this->state(fn () => [
            'ice' => null,
            'identifiant_fiscal' => null,
            'registre_commerce' => null,
        ]);
    }
}
