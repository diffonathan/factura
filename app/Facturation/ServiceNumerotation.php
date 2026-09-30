<?php

declare(strict_types=1);

namespace App\Facturation;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Attribue les numéros de la série légale.
 *
 * Tout le projet tient sur cette classe, alors autant dire clairement ce
 * qu'elle garantit et à quelle condition.
 *
 * CE QU'ELLE GARANTIT
 *   Deux documents d'une même entreprise, même type, même année, ne portent
 *   jamais le même numéro, et la série ne saute jamais un numéro.
 *
 * COMMENT
 *   Un `INSERT ... ON CONFLICT DO UPDATE` sur la ligne du compteur. Cette
 *   requête fait trois choses en une, de façon atomique : elle crée la ligne
 *   si c'est le premier document de l'année, elle l'incrémente sinon, et elle
 *   pose un verrou sur cette ligne pour la durée de la transaction. Une
 *   deuxième transaction qui arrive attend ; quand elle repart, elle relit la
 *   valeur validée et incrémente à partir de là. Pas de lecture périmée, donc
 *   pas de doublon.
 *
 * À QUELLE CONDITION
 *   L'appel DOIT se trouver dans la même transaction que l'écriture du
 *   document. C'est la moitié du raisonnement, et la plus facile à perdre de
 *   vue : si le compteur est incrémenté dans sa propre transaction, il est
 *   validé tout seul, et l'échec de l'écriture qui suit laisse un numéro
 *   consommé pour rien — un trou, exactement ce qu'on cherchait à éviter.
 *   D'où la vérification à l'entrée de `reserver()`, qui refuse de travailler
 *   hors transaction plutôt que de produire un trou silencieux.
 *
 * POURQUOI PAS UNE SÉQUENCE
 *   `nextval()` ne revient jamais en arrière, par conception. C'est ce qu'on
 *   veut d'une clé technique et l'inverse de ce que la loi demande d'une
 *   facture. Voir la migration `create_compteurs_table`.
 */
final class ServiceNumerotation
{
    /**
     * Réserve le numéro suivant de la série et le rend.
     *
     * @throws LogicException si l'appel n'est pas dans une transaction
     */
    public function reserver(int $entrepriseId, TypeDocument $type, int $annee): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'reserver() doit être appelé dans une transaction : hors transaction, '
                . 'un numéro réservé puis abandonné laisse un trou dans la série.'
            );
        }

        $ligne = DB::selectOne(<<<'SQL'
            INSERT INTO compteurs (entreprise_id, type, annee, dernier_numero, created_at, updated_at)
                 VALUES (:entreprise, :type, :annee, 1, now(), now())
            ON CONFLICT (entreprise_id, type, annee)
            DO UPDATE SET dernier_numero = compteurs.dernier_numero + 1,
                          updated_at     = now()
              RETURNING dernier_numero
        SQL, [
            'entreprise' => $entrepriseId,
            'type' => $type->value,
            'annee' => $annee,
        ]);

        return (int) $ligne->dernier_numero;
    }

    /**
     * Le dernier numéro attribué, 0 si la série n'a pas commencé. Pour
     * l'affichage et les contrôles — jamais pour deviner le suivant, ce qui
     * rouvrirait la course que `reserver()` ferme.
     */
    public function dernierNumero(int $entrepriseId, TypeDocument $type, int $annee): int
    {
        $ligne = DB::selectOne(
            'SELECT dernier_numero FROM compteurs WHERE entreprise_id = :entreprise AND type = :type AND annee = :annee',
            ['entreprise' => $entrepriseId, 'type' => $type->value, 'annee' => $annee]
        );

        return (int) ($ligne->dernier_numero ?? 0);
    }

    /**
     * Cherche un trou dans une série émise. Sert au test de bout en bout et à
     * une future page de contrôle : en cas de vérification fiscale, pouvoir
     * montrer que la série est intacte vaut mieux que l'affirmer.
     *
     * @return list<int> les numéros manquants, vide si la série est intacte
     */
    public function trousDeLaSerie(int $entrepriseId, TypeDocument $type, int $annee): array
    {
        $lignes = DB::select(<<<'SQL'
            SELECT attendu.numero
              FROM generate_series(
                       1,
                       coalesce((SELECT max(numero)
                                   FROM documents
                                  WHERE entreprise_id = :entreprise
                                    AND type          = :type
                                    AND annee         = :annee), 0)
                   ) AS attendu(numero)
             WHERE NOT EXISTS (
                       SELECT 1
                         FROM documents d
                        WHERE d.entreprise_id = :entreprise2
                          AND d.type          = :type2
                          AND d.annee         = :annee2
                          AND d.numero        = attendu.numero
                   )
             ORDER BY attendu.numero
        SQL, [
            'entreprise' => $entrepriseId, 'type' => $type->value, 'annee' => $annee,
            'entreprise2' => $entrepriseId, 'type2' => $type->value, 'annee2' => $annee,
        ]);

        return array_map(static fn (object $l): int => (int) $l->numero, $lignes);
    }
}
