<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les garde-fous de la facturation, posés dans la base et non dans le code.
 *
 * L'application est un client de la base parmi d'autres : il y aura un jour
 * une commande d'import, une reprise de données, un correctif appliqué à la
 * main un soir d'urgence. Une règle écrite dans un service PHP ne protège que
 * les chemins qui passent par ce service. Une règle écrite ici protège tout,
 * y compris contre moi.
 *
 * Cinq engagements :
 *
 *   1. Les totaux d'un document sont la somme de ses lignes. Toujours.
 *   2. La numérotation est contiguë : pas de trou, pas de doublon.
 *   3. Un document émis est immuable — on le corrige par un avoir, jamais
 *      en le réécrivant.
 *   4. Un document émis ne se supprime pas.
 *   5. Un encaissement ne s'attache qu'à une facture ou un avoir émis, et
 *      ne peut pas dépasser le dû.
 *
 * Les codes SQLSTATE 900xx sont maison : ils permettent au code PHP de
 * distinguer un conflit métier (à traduire en 409) d'une vraie panne, sans
 * avoir à lire un message d'erreur au petit bonheur.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // 1. Les totaux d'un document suivent ses lignes
        // ------------------------------------------------------------------
        // Recalcul complet plutôt qu'incrémental : une somme refaite à zéro
        // ne peut pas dériver, alors qu'un `+= delta` accumule les erreurs de
        // tous les cas oubliés. Le coût est négligeable — une facture a
        // rarement plus de quelques dizaines de lignes.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION documents_recalculer_totaux()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                cible bigint := coalesce(NEW.document_id, OLD.document_id);
            BEGIN
                UPDATE documents d
                   SET montant_ht  = t.ht,
                       montant_tva = t.tva,
                       montant_ttc = t.ht + t.tva,
                       updated_at  = now()
                  FROM (
                        SELECT coalesce(sum(montant_ht),  0) AS ht,
                               coalesce(sum(montant_tva), 0) AS tva
                          FROM lignes
                         WHERE document_id = cible
                       ) t
                 WHERE d.id = cible;

                RETURN NULL;   -- trigger AFTER : la valeur retournée est ignorée
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER lignes_maj_totaux
                AFTER INSERT OR UPDATE OR DELETE ON lignes
                FOR EACH ROW EXECUTE FUNCTION documents_recalculer_totaux();
        SQL);

        // ------------------------------------------------------------------
        // 2. La numérotation est contiguë
        // ------------------------------------------------------------------
        // Ce n'est PAS le mécanisme de numérotation : c'est son filet. Le
        // mécanisme est le verrou de ligne sur `compteurs`, qui sérialise les
        // émissions concurrentes d'une même entreprise. Ce trigger ne fait
        // que vérifier, sous ce verrou, que le numéro proposé est bien le
        // suivant. Sans le verrou il serait insuffisant — deux transactions
        // liraient le même `max()`. Avec lui, il transforme un bug de code en
        // erreur immédiate au lieu d'un trou découvert lors d'un contrôle.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION documents_verifier_contiguite()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                attendu integer;
            BEGIN
                IF NEW.numero IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT coalesce(max(numero), 0) + 1
                  INTO attendu
                  FROM documents
                 WHERE entreprise_id = NEW.entreprise_id
                   AND type          = NEW.type
                   AND annee         = NEW.annee
                   AND numero IS NOT NULL
                   AND id           <> NEW.id;

                IF NEW.numero <> attendu THEN
                    RAISE EXCEPTION
                        'Numerotation non contigue : % attendu, % propose (entreprise %, % %)',
                        attendu, NEW.numero, NEW.entreprise_id, NEW.type, NEW.annee
                        USING ERRCODE = '90001';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER documents_contiguite
                BEFORE INSERT OR UPDATE OF numero ON documents
                FOR EACH ROW EXECUTE FUNCTION documents_verifier_contiguite();
        SQL);

        // ------------------------------------------------------------------
        // 3 et 4. Un document émis est immuable, et ne se supprime pas
        // ------------------------------------------------------------------
        // Restent modifiables : le statut (une facture se solde, un devis
        // s'accepte), le montant encaissé, l'échéance (un report se négocie)
        // et les notes internes, qui ne sont pas imprimées.
        //
        // Tout le reste est figé : ce qui figure sur le document remis au
        // client ne peut plus changer. La correction d'une facture émise
        // passe par un avoir — c'est la loi, et c'est aussi la seule façon
        // de garder une piste vérifiable.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION documents_proteger_emis()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.numero IS NOT NULL THEN
                        RAISE EXCEPTION
                            'Le document % ne peut pas etre supprime : il est emis',
                            OLD.reference
                            USING ERRCODE = '90003';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.numero IS NULL THEN
                    RETURN NEW;    -- encore un brouillon : tout est permis
                END IF;

                IF ROW(NEW.entreprise_id, NEW.client_id, NEW.type, NEW.annee, NEW.numero,
                       NEW.date_emission, NEW.montant_ht, NEW.montant_tva, NEW.montant_ttc,
                       NEW.objet, NEW.conditions, NEW.origine_id, NEW.devise, NEW.emis_le)
                   IS DISTINCT FROM
                   ROW(OLD.entreprise_id, OLD.client_id, OLD.type, OLD.annee, OLD.numero,
                       OLD.date_emission, OLD.montant_ht, OLD.montant_tva, OLD.montant_ttc,
                       OLD.objet, OLD.conditions, OLD.origine_id, OLD.devise, OLD.emis_le)
                THEN
                    RAISE EXCEPTION
                        'Le document % est emis : il se corrige par un avoir, pas en le reecrivant',
                        OLD.reference
                        USING ERRCODE = '90002';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER documents_immuables
                BEFORE UPDATE OR DELETE ON documents
                FOR EACH ROW EXECUTE FUNCTION documents_proteger_emis();
        SQL);

        // Corollaire : les lignes d'un document émis sont figées elles aussi.
        // Sans cela, l'immutabilité des totaux serait contournable en une
        // requête — il suffirait de modifier une ligne et de laisser le
        // trigger de recalcul faire le travail.
        //
        // Le SELECT ne trouve rien quand le document parent vient d'être
        // supprimé en cascade ; `numero` vaut alors NULL et la suppression
        // des lignes passe. C'est le comportement voulu : seul un brouillon
        // peut être supprimé, et ses lignes doivent partir avec lui.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lignes_proteger_document_emis()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                doc record;
            BEGIN
                SELECT reference, numero
                  INTO doc
                  FROM documents
                 WHERE id = coalesce(NEW.document_id, OLD.document_id);

                IF doc.numero IS NOT NULL THEN
                    RAISE EXCEPTION
                        'Les lignes du document % sont figees : il est emis',
                        doc.reference
                        USING ERRCODE = '90004';
                END IF;

                RETURN coalesce(NEW, OLD);
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER lignes_figees
                BEFORE INSERT OR UPDATE OR DELETE ON lignes
                FOR EACH ROW EXECUTE FUNCTION lignes_proteger_document_emis();
        SQL);

        // ------------------------------------------------------------------
        // 5. Les encaissements
        // ------------------------------------------------------------------
        // Un paiement ne s'attache pas à un devis — un devis n'est pas une
        // créance — ni à un brouillon, qui n'existe pas encore pour le client.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION paiements_verifier_document()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                doc record;
            BEGIN
                SELECT type, numero, reference
                  INTO doc
                  FROM documents
                 WHERE id = NEW.document_id;

                IF doc.numero IS NULL THEN
                    RAISE EXCEPTION
                        'Un encaissement ne peut pas etre rattache a un brouillon'
                        USING ERRCODE = '90005';
                END IF;

                IF doc.type NOT IN ('FACTURE', 'AVOIR') THEN
                    RAISE EXCEPTION
                        'Un encaissement ne peut pas etre rattache a un % (%)',
                        doc.type, doc.reference
                        USING ERRCODE = '90005';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER paiements_document_valide
                BEFORE INSERT OR UPDATE ON paiements
                FOR EACH ROW EXECUTE FUNCTION paiements_verifier_document();
        SQL);

        // Le solde suit les encaissements, et le statut suit le solde. Le
        // dépassement, lui, est arrêté par la contrainte
        // `documents_paiement_borne` posée sur la table : ce trigger n'a pas
        // à le vérifier, il suffit qu'il écrive la vérité.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION documents_recalculer_solde()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                cible bigint := coalesce(NEW.document_id, OLD.document_id);
            BEGIN
                UPDATE documents d
                   SET montant_paye = t.paye,
                       statut       = CASE
                                          WHEN d.statut = 'ANNULE'        THEN 'ANNULE'
                                          WHEN t.paye >= d.montant_ttc
                                           AND d.montant_ttc > 0          THEN 'SOLDE'
                                          ELSE 'EMIS'
                                      END,
                       updated_at   = now()
                  FROM (
                        SELECT coalesce(sum(montant), 0) AS paye
                          FROM paiements
                         WHERE document_id = cible
                       ) t
                 WHERE d.id = cible;

                RETURN NULL;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER paiements_maj_solde
                AFTER INSERT OR UPDATE OR DELETE ON paiements
                FOR EACH ROW EXECUTE FUNCTION documents_recalculer_solde();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS paiements_maj_solde       ON paiements;
            DROP TRIGGER IF EXISTS paiements_document_valide ON paiements;
            DROP TRIGGER IF EXISTS lignes_figees             ON lignes;
            DROP TRIGGER IF EXISTS documents_immuables       ON documents;
            DROP TRIGGER IF EXISTS documents_contiguite      ON documents;
            DROP TRIGGER IF EXISTS lignes_maj_totaux         ON lignes;

            DROP FUNCTION IF EXISTS documents_recalculer_solde();
            DROP FUNCTION IF EXISTS paiements_verifier_document();
            DROP FUNCTION IF EXISTS lignes_proteger_document_emis();
            DROP FUNCTION IF EXISTS documents_proteger_emis();
            DROP FUNCTION IF EXISTS documents_verifier_contiguite();
            DROP FUNCTION IF EXISTS documents_recalculer_totaux();
        SQL);
    }
};
