<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les clients d'une entreprise.
 *
 * Le client porte son propre ICE : sur une facture entre professionnels, celui
 * du destinataire est exigé lui aussi. Un particulier n'en a pas, d'où le
 * drapeau `est_particulier` — et la contrainte qui empêche de le cocher tout
 * en renseignant un ICE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();

            $table->string('nom', 200);
            $table->boolean('est_particulier')->default(false);

            $table->char('ice', 15)->nullable();
            $table->string('identifiant_fiscal', 20)->nullable();

            $table->string('adresse', 255)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('email', 180)->nullable();

            // Délai de règlement par défaut, en jours. La loi marocaine 69-21
            // plafonne à 60 jours, ou 120 par accord écrit entre les parties.
            $table->smallInteger('delai_paiement_jours')->default(30);

            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE clients
                ADD CONSTRAINT clients_ice_format CHECK (ice IS NULL OR ice ~ '^[0-9]{15}$'),
                ADD CONSTRAINT clients_particulier_sans_ice CHECK (NOT (est_particulier AND ice IS NOT NULL)),
                ADD CONSTRAINT clients_delai_paiement CHECK (delai_paiement_jours BETWEEN 0 AND 120)
        SQL);

        // L'unicité est TOUJOURS cadrée par l'entreprise. Deux entreprises
        // distinctes ont parfaitement le droit d'avoir le même client.
        DB::statement('CREATE UNIQUE INDEX clients_nom_unique ON clients (entreprise_id, lower(nom))');
        DB::statement('CREATE UNIQUE INDEX clients_ice_unique ON clients (entreprise_id, ice) WHERE ice IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
