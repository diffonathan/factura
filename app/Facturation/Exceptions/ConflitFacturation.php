<?php

declare(strict_types=1);

namespace App\Facturation\Exceptions;

/**
 * Une autre opération est passée avant. Le cas d'école : l'utilisateur
 * double-clique sur « Émettre », ou son navigateur rejoue la requête sur un
 * réseau instable.
 *
 * 409 et non 500 : rien n'a échoué, l'état du monde a simplement changé entre
 * la lecture et l'écriture. La réponse correcte est de recharger le document
 * et de montrer qu'il est déjà émis.
 */
final class ConflitFacturation extends ErreurFacturation
{
    public function codeHttp(): int
    {
        return 409;
    }
}
