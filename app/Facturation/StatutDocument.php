<?php

declare(strict_types=1);

namespace App\Facturation;

/**
 * Le cycle de vie d'un document.
 *
 * Tous les statuts ne s'appliquent pas à tous les types — un devis se refuse,
 * une facture s'encaisse. La contrainte `documents_statut` en base est
 * l'autorité sur les combinaisons valides ; `applicableA()` la reflète pour
 * que le code puisse vérifier avant d'écrire plutôt qu'après.
 */
enum StatutDocument: string
{
    case Brouillon = 'BROUILLON';
    case Emis = 'EMIS';

    // Propres au devis
    case Accepte = 'ACCEPTE';
    case Refuse = 'REFUSE';
    case Expire = 'EXPIRE';

    // Propres à la facture et à l'avoir
    case Solde = 'SOLDE';
    case Annule = 'ANNULE';

    public function libelle(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Emis => 'Émis',
            self::Accepte => 'Accepté',
            self::Refuse => 'Refusé',
            self::Expire => 'Expiré',
            self::Solde => 'Soldé',
            self::Annule => 'Annulé',
        };
    }

    public function applicableA(TypeDocument $type): bool
    {
        return match ($type) {
            TypeDocument::Devis => in_array($this, [
                self::Brouillon, self::Emis, self::Accepte, self::Refuse, self::Expire,
            ], true),
            TypeDocument::Facture => in_array($this, [
                self::Brouillon, self::Emis, self::Solde, self::Annule,
            ], true),
            TypeDocument::Avoir => in_array($this, [
                self::Brouillon, self::Emis, self::Solde,
            ], true),
        };
    }
}
