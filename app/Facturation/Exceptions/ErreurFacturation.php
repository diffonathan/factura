<?php

declare(strict_types=1);

namespace App\Facturation\Exceptions;

use RuntimeException;

/**
 * Racine des erreurs métier de la facturation.
 *
 * Elles se distinguent des pannes : une base injoignable est un incident à
 * corriger, un devis déjà facturé est une situation normale à expliquer à
 * l'utilisateur. Les deux ne méritent ni le même code HTTP, ni la même
 * alerte, ni le même message.
 */
abstract class ErreurFacturation extends RuntimeException
{
    abstract public function codeHttp(): int;
}
