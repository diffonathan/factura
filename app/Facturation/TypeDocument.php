<?php

declare(strict_types=1);

namespace App\Facturation;

/**
 * Les trois documents qu'une entreprise émet.
 *
 * Énumération plutôt que constantes de chaîne : une faute de frappe sur
 * « FACUTRE » devient une erreur à l'analyse du code, pas une requête qui
 * ne remonte silencieusement aucune ligne.
 */
enum TypeDocument: string
{
    case Devis = 'DEVIS';
    case Facture = 'FACTURE';
    case Avoir = 'AVOIR';

    /** Le préfixe de la référence — doit rester aligné sur la colonne calculée
     *  `documents.reference`, qui est la seule à l'écrire réellement. */
    public function prefixe(): string
    {
        return match ($this) {
            self::Devis => 'DV',
            self::Facture => 'FA',
            self::Avoir => 'AV',
        };
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Devis => 'Devis',
            self::Facture => 'Facture',
            self::Avoir => 'Avoir',
        };
    }

    /** Un devis n'est pas une créance : rien à encaisser dessus. */
    public function estEncaissable(): bool
    {
        return $this !== self::Devis;
    }
}
