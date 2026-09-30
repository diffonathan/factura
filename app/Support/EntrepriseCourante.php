<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * L'entreprise sur laquelle porte la requête en cours.
 *
 * Service unique (singleton) enregistré par requête : un utilisateur qui tient
 * les comptes de trois sociétés travaille dans une seule à la fois, et tout ce
 * qu'il lit ou écrit doit être cadré par celle-là.
 *
 * L'intérêt de passer par cet objet plutôt que de filtrer à la main dans
 * chaque requête est simple : un filtre oublié dans un contrôleur sur dix
 * suffit à montrer le chiffre d'affaires d'un client à un autre. Ici, le
 * filtre est appliqué par les modèles eux-mêmes ; l'oublier demande un effort
 * conscient et nommé (`sansCloisonnement()`).
 */
final class EntrepriseCourante
{
    private ?int $id = null;

    public function definir(int $entrepriseId): void
    {
        $this->id = $entrepriseId;
    }

    public function oublier(): void
    {
        $this->id = null;
    }

    public function estDefinie(): bool
    {
        return $this->id !== null;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    /**
     * Pour le code qui ne peut pas fonctionner sans contexte : un contrôleur
     * qui crée une facture, par exemple. Échouer bruyamment vaut mieux que
     * créer la facture dans l'entreprise n° 1 par défaut.
     */
    public function idObligatoire(): int
    {
        if ($this->id === null) {
            throw new RuntimeException(
                'Aucune entreprise courante : cette opération a besoin d\'un contexte d\'entreprise.'
            );
        }

        return $this->id;
    }
}
