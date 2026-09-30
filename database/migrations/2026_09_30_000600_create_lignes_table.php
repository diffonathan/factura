<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les lignes d'un devis, d'une facture ou d'un avoir.
 *
 * Les deux montants d'une ligne sont des colonnes CALCULÉES par PostgreSQL,
 * pas des valeurs écrites par l'application. La différence n'est pas
 * cosmétique : une colonne `GENERATED ALWAYS ... STORED` ne peut pas être
 * renseignée de l'extérieur, même par une requête écrite à la main. Quantité,
 * prix, remise et taux sont donc la seule source de vérité, et aucun chemin
 * de code — pas même un import ou une correction en base — ne peut produire
 * une ligne dont le total contredit ses composants.
 *
 * L'arrondi est fait ligne par ligne, à deux décimales, avant la somme.
 * C'est l'usage marocain et c'est ce que le client retrouve en additionnant
 * lui-même les lignes imprimées. Arrondir seulement le total final donnerait
 * parfois un centime d'écart avec la facture qu'il a sous les yeux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            // Ordre d'affichage. Choisi par l'utilisateur, pas déduit de l'id :
            // on doit pouvoir insérer une ligne oubliée au milieu.
            $table->smallInteger('position');

            $table->string('designation', 255);
            $table->string('unite', 16)->default('unité');

            $table->decimal('quantite', 12, 3);
            $table->decimal('prix_unitaire_ht', 14, 2);
            $table->decimal('remise_pct', 5, 2)->default(0);

            // Les quatre taux marocains, plus 0 pour les opérations exonérées
            // ou hors champ (export, certaines prestations médicales).
            $table->decimal('taux_tva', 4, 2);

            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE lignes
                ADD COLUMN montant_ht numeric(14, 2)
                    GENERATED ALWAYS AS (
                        round(quantite * prix_unitaire_ht * (1 - remise_pct / 100), 2)
                    ) STORED,
                ADD COLUMN montant_tva numeric(14, 2)
                    GENERATED ALWAYS AS (
                        round(
                            round(quantite * prix_unitaire_ht * (1 - remise_pct / 100), 2)
                            * taux_tva / 100,
                            2
                        )
                    ) STORED
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE lignes
                ADD CONSTRAINT lignes_quantite CHECK (quantite > 0),
                ADD CONSTRAINT lignes_prix CHECK (prix_unitaire_ht >= 0),
                ADD CONSTRAINT lignes_remise CHECK (remise_pct >= 0 AND remise_pct <= 100),
                ADD CONSTRAINT lignes_position CHECK (position >= 1),

                -- Un taux inventé est refusé par la base. Pas « signalé plus
                -- tard par un rapport » : refusé à l'écriture.
                ADD CONSTRAINT lignes_taux_tva CHECK (taux_tva IN (0, 7, 10, 14, 20))
        SQL);

        DB::statement('CREATE UNIQUE INDEX lignes_position_unique ON lignes (document_id, position)');
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes');
    }
};
