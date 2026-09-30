<?php

declare(strict_types=1);

namespace App\Facturation;

/**
 * Les moyens de règlement en usage au Maroc. L'effet de commerce et le chèque
 * post-daté restent très présents entre entreprises, d'où leur place ici à
 * côté du virement.
 */
enum ModePaiement: string
{
    case Especes = 'ESPECES';
    case Virement = 'VIREMENT';
    case Cheque = 'CHEQUE';
    case Effet = 'EFFET';
    case Carte = 'CARTE';
    case Prelevement = 'PRELEVEMENT';
    case Autre = 'AUTRE';

    public function libelle(): string
    {
        return match ($this) {
            self::Especes => 'Espèces',
            self::Virement => 'Virement',
            self::Cheque => 'Chèque',
            self::Effet => 'Effet de commerce',
            self::Carte => 'Carte bancaire',
            self::Prelevement => 'Prélèvement',
            self::Autre => 'Autre',
        };
    }

    /** Ces modes portent une référence qu'on retrouve sur le relevé bancaire. */
    public function attendUneReference(): bool
    {
        return in_array($this, [self::Cheque, self::Virement, self::Effet], true);
    }
}
