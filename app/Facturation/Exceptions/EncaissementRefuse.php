<?php

declare(strict_types=1);

namespace App\Facturation\Exceptions;

/**
 * L'encaissement ne peut pas être enregistré : il dépasse le solde dû, ou le
 * document ne peut rien recevoir (brouillon, devis).
 *
 * Le refus vient de la base — contrainte `documents_paiement_borne` ou trigger
 * `paiements_document_valide`. Cette classe ne fait que le traduire en quelque
 * chose d'affichable : un comptable n'a pas à lire un message PostgreSQL pour
 * comprendre qu'il a saisi 12 000 au lieu de 1 200.
 */
final class EncaissementRefuse extends ErreurFacturation
{
    public function codeHttp(): int
    {
        return 422;
    }
}
