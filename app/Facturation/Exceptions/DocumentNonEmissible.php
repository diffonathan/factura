<?php

declare(strict_types=1);

namespace App\Facturation\Exceptions;

/**
 * Le document ne remplit pas les conditions pour être émis : pas de ligne,
 * mentions légales incomplètes, ICE du client absent.
 *
 * Rien n'est cassé — il manque des informations, et `$manques` dit
 * lesquelles pour que l'interface puisse pointer les champs au lieu
 * d'afficher un refus sans explication.
 */
final class DocumentNonEmissible extends ErreurFacturation
{
    /** @param list<string> $manques */
    public function __construct(
        string $message,
        public readonly array $manques = [],
    ) {
        parent::__construct($message);
    }

    public function codeHttp(): int
    {
        return 422;
    }
}
