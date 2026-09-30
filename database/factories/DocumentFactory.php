<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Facturation\TypeDocument;
use App\Models\Client;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 *
 * Produit toujours un BROUILLON. L'émission passe par `ServiceEmission` —
 * une usine qui saurait poser un numéro elle-même contournerait le compteur
 * et rendrait les tests de numérotation sans valeur.
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            // `client_id` d'abord : les fermetures qui suivent reçoivent les
            // attributs déjà résolus, donc l'entreprise se déduit du client au
            // lieu d'être tirée au hasard — sans quoi on créerait des documents
            // facturant le client d'une autre entreprise.
            'client_id' => Client::factory(),
            'entreprise_id' => fn (array $attributs) => Client::withoutGlobalScopes()
                ->findOrFail($attributs['client_id'])
                ->entreprise_id,

            'type' => TypeDocument::Facture->value,
            'date_emission' => now()->toDateString(),
            'objet' => $this->faker->sentence(4),
            'devise' => 'MAD',
        ];
    }

    public function devis(): static
    {
        return $this->state(fn () => ['type' => TypeDocument::Devis->value]);
    }

    public function avoir(): static
    {
        return $this->state(fn () => ['type' => TypeDocument::Avoir->value]);
    }

    /** Rattache le document à un client donné, et donc à son entreprise. */
    public function pour(Client $client): static
    {
        return $this->state(fn () => [
            'client_id' => $client->id,
            'entreprise_id' => $client->entreprise_id,
        ]);
    }

    public function emisLe(string $date): static
    {
        return $this->state(fn () => [
            'date_emission' => $date,
            'annee' => (int) substr($date, 0, 4),
        ]);
    }
}
