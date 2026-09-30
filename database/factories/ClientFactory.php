<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\Entreprise;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'entreprise_id' => Entreprise::factory(),
            'nom' => $this->faker->unique()->company(),
            'est_particulier' => false,
            'ice' => (string) $this->faker->numerify('###############'),
            'identifiant_fiscal' => (string) $this->faker->numerify('########'),
            'adresse' => $this->faker->streetAddress(),
            'ville' => $this->faker->randomElement(['Casablanca', 'Rabat', 'Marrakech', 'Tanger']),
            'telephone' => '+212 6' . $this->faker->numerify('## ## ## ##'),
            'email' => $this->faker->safeEmail(),
            'delai_paiement_jours' => 30,
        ];
    }

    /** Un particulier n'a pas d'ICE — et la base refuse qu'il en ait un. */
    public function particulier(): static
    {
        return $this->state(fn () => [
            'nom' => $this->faker->unique()->name(),
            'est_particulier' => true,
            'ice' => null,
            'identifiant_fiscal' => null,
            'delai_paiement_jours' => 0,
        ]);
    }

    public function sansIce(): static
    {
        return $this->state(fn () => ['ice' => null]);
    }
}
