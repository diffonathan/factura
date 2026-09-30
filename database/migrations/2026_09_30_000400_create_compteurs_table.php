<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le compteur de numérotation : une ligne par entreprise, par type de document
 * et par année.
 *
 * POURQUOI PAS UNE SÉQUENCE POSTGRESQL
 * ------------------------------------
 * `nextval()` est volontairement hors transaction : il ne revient jamais en
 * arrière. Si la transaction qui a pris le numéro 42 échoue, 42 est perdu et
 * la série passe de 41 à 43. C'est le comportement voulu pour une clé
 * technique — et exactement ce que la loi interdit pour une facture.
 *
 * Le Code Général des Impôts marocain impose une série « continue et sans
 * rupture ». Un trou dans la numérotation est présumé être une facture
 * soustraite au fisc, et se paie lors d'un contrôle.
 *
 * Une ligne de table, elle, est transactionnelle. On l'incrémente avec un
 * verrou de ligne : deux émissions simultanées s'attendent au lieu de se
 * marcher dessus, et si la transaction échoue, l'incrément disparaît avec
 * elle. Le numéro n'est jamais consommé pour rien.
 *
 * Le prix à payer est la sérialisation : deux factures de la MÊME entreprise
 * ne peuvent pas être émises en parallèle. C'est un prix acceptable — la loi
 * exige justement un ordre total — et il reste local, puisque le verrou ne
 * porte que sur une ligne : les autres entreprises continuent sans attendre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compteurs', function (Blueprint $table) {
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8);
            $table->smallInteger('annee');
            $table->integer('dernier_numero')->default(0);

            $table->timestampsTz();

            $table->primary(['entreprise_id', 'type', 'annee']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE compteurs
                ADD CONSTRAINT compteurs_type CHECK (type IN ('DEVIS', 'FACTURE', 'AVOIR')),
                ADD CONSTRAINT compteurs_annee CHECK (annee BETWEEN 2000 AND 2200),
                ADD CONSTRAINT compteurs_dernier_numero CHECK (dernier_numero >= 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('compteurs');
    }
};
