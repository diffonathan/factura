<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Devis, factures et avoirs dans une seule table.
 *
 * Ils partagent tout ce qui compte : un client, des lignes, des totaux, une
 * TVA, une mise en page. Ce qui les distingue est leur série de numérotation
 * et leur cycle de vie — deux choses que `type` et `statut` portent très bien.
 * Trois tables quasi identiques auraient triplé chaque requête de tableau de
 * bord sans rien garantir de plus.
 *
 * `origine_id` relie un document à celui dont il découle : la facture issue
 * d'un devis accepté, l'avoir qui corrige une facture. La chaîne reste
 * vérifiable, ce qui est le premier réflexe d'un contrôleur.
 *
 * Les montants sont en `numeric`, jamais en flottant : 0,1 + 0,2 ne fait pas
 * 0,3 en binaire, et une facture fausse d'un centime est une facture fausse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete : on ne supprime pas un client qui a été facturé.
            // La facture doit rester lisible dix ans, destinataire compris.
            $table->foreignId('client_id')->constrained()->restrictOnDelete();

            $table->string('type', 8);
            $table->string('statut', 16)->default('BROUILLON');

            $table->smallInteger('annee');

            // Nul tant que le document est un brouillon. Un brouillon ne
            // consomme pas de numéro : il peut être jeté sans laisser de trou.
            $table->integer('numero')->nullable();

            $table->date('date_emission');
            $table->date('date_echeance')->nullable();

            $table->string('objet', 200)->nullable();
            $table->text('conditions')->nullable();
            $table->text('notes_internes')->nullable();

            $table->foreignId('origine_id')->nullable()->constrained('documents')->nullOnDelete();

            $table->decimal('montant_ht', 14, 2)->default(0);
            $table->decimal('montant_tva', 14, 2)->default(0);
            $table->decimal('montant_ttc', 14, 2)->default(0);
            $table->decimal('montant_paye', 14, 2)->default(0);

            $table->char('devise', 3)->default('MAD');

            $table->timestampTz('emis_le')->nullable();
            $table->timestampsTz();
        });

        // La référence affichée est calculée par la base, donc jamais
        // désynchronisée de ses composants : FA-2026-0001. Nulle pour un
        // brouillon, comme le numéro dont elle dépend.
        DB::statement(<<<'SQL'
            ALTER TABLE documents
                ADD COLUMN reference varchar(24)
                GENERATED ALWAYS AS (
                    CASE "type"
                        WHEN 'DEVIS'   THEN 'DV'
                        WHEN 'FACTURE' THEN 'FA'
                        ELSE                'AV'
                    END || '-' || annee::text || '-' || lpad(numero::text, 4, '0')
                ) STORED
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE documents
                ADD CONSTRAINT documents_type CHECK ("type" IN ('DEVIS', 'FACTURE', 'AVOIR')),

                ADD CONSTRAINT documents_numero_si_emis
                    CHECK ((statut = 'BROUILLON') = (numero IS NULL)),

                ADD CONSTRAINT documents_numero_positif CHECK (numero IS NULL OR numero >= 1),
                ADD CONSTRAINT documents_annee CHECK (annee BETWEEN 2000 AND 2200),

                -- L'année de la série est celle de la date du document. Une
                -- facture datée du 31 décembre appartient à la série de
                -- l'exercice qui se ferme, même si elle est émise le 2 janvier.
                ADD CONSTRAINT documents_annee_coherente
                    CHECK (annee = extract(year FROM date_emission)),

                ADD CONSTRAINT documents_statut CHECK (
                    ("type" = 'DEVIS'   AND statut IN ('BROUILLON', 'EMIS', 'ACCEPTE', 'REFUSE', 'EXPIRE'))
                 OR ("type" = 'FACTURE' AND statut IN ('BROUILLON', 'EMIS', 'SOLDE', 'ANNULE'))
                 OR ("type" = 'AVOIR'   AND statut IN ('BROUILLON', 'EMIS', 'SOLDE'))
                ),

                ADD CONSTRAINT documents_total_coherent CHECK (montant_ttc = montant_ht + montant_tva),
                ADD CONSTRAINT documents_montants_positifs CHECK (montant_ht >= 0 AND montant_tva >= 0),

                ADD CONSTRAINT documents_paiement_borne
                    CHECK (montant_paye >= 0 AND montant_paye <= montant_ttc),

                ADD CONSTRAINT documents_echeance_apres_emission
                    CHECK (date_echeance IS NULL OR date_echeance >= date_emission),

                ADD CONSTRAINT documents_devise CHECK (devise = upper(devise)),

                ADD CONSTRAINT documents_origine_differente
                    CHECK (origine_id IS NULL OR origine_id <> id)
        SQL);

        // L'unicité de la série. Partielle, parce que tous les brouillons ont
        // `numero` à NULL et qu'un index complet les tiendrait pour distincts :
        // NULL n'est égal à rien, pas même à lui-même.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX documents_serie_unique
                ON documents (entreprise_id, "type", annee, numero)
                WHERE numero IS NOT NULL
        SQL);

        // La liste des documents d'une entreprise, du plus récent au plus
        // ancien : la requête la plus fréquente de l'application.
        DB::statement(<<<'SQL'
            CREATE INDEX documents_entreprise_recents
                ON documents (entreprise_id, date_emission DESC, id DESC)
        SQL);

        // Les impayés : ce que le relanceur balaie à chaque passage. Index
        // partiel, donc sans les brouillons, les devis ni les factures soldées.
        DB::statement(<<<'SQL'
            CREATE INDEX documents_impayes
                ON documents (entreprise_id, date_echeance)
                WHERE "type" = 'FACTURE' AND statut = 'EMIS'
        SQL);

        // Un devis ne se facture qu'une fois. La vérification en PHP ne suffit
        // pas : entre la lecture et l'écriture, un double-clic passe. Cet index
        // ferme la course. Il ne vise que les factures — une facture peut
        // parfaitement recevoir plusieurs avoirs.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX documents_une_facture_par_devis
                ON documents (origine_id)
                WHERE "type" = 'FACTURE' AND origine_id IS NOT NULL
        SQL);

        DB::statement('CREATE INDEX documents_client ON documents (client_id)');
        DB::statement('CREATE INDEX documents_origine ON documents (origine_id) WHERE origine_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
