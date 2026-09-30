<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use App\Models\Ligne;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ligne>
 */
class LigneFactory extends Factory
{
    protected $model = Ligne::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'position' => 1,
            'designation' => $this->faker->sentence(3),
            'unite' => 'unité',
            'quantite' => 1,
            'prix_unitaire_ht' => $this->faker->randomFloat(2, 100, 5000),
            'remise_pct' => 0,
            'taux_tva' => 20,
        ];
    }
}
