<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les encaissements. Plusieurs par facture : un acompte, un solde, parfois
 * trois chèques à trente jours d'intervalle.
 *
 * Le montant restant dû n'est pas stocké ici. Il se déduit — `montant_ttc`
 * moins `montant_paye` — et `montant_paye` est lui-même maintenu par la base
 * à partir de ces lignes. Une seule vérité, tenue au même endroit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            $table->date('date_paiement');
            $table->decimal('montant', 14, 2);

            $table->string('mode', 16);

            // Numéro de chèque, référence de virement, numéro d'effet : ce qui
            // permet de retrouver l'encaissement sur le relevé bancaire.
            $table->string('reference', 60)->nullable();
            $table->text('notes')->nullable();

            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE paiements
                ADD CONSTRAINT paiements_montant CHECK (montant > 0),
                ADD CONSTRAINT paiements_mode CHECK (
                    mode IN ('ESPECES', 'VIREMENT', 'CHEQUE', 'EFFET', 'CARTE', 'PRELEVEMENT', 'AUTRE')
                )
        SQL);

        DB::statement('CREATE INDEX paiements_document ON paiements (document_id)');
        DB::statement('CREATE INDEX paiements_date ON paiements (date_paiement)');
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
    }
};
