<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les relances d'impayés, une ligne par niveau atteint.
 *
 * Cette table n'est pas un journal : c'est le GARDE-FOU du relanceur
 * automatique. L'index unique (document_id, niveau) fait qu'un niveau ne peut
 * être franchi qu'une fois. Le travail en file d'attente commence par tenter
 * d'y insérer sa ligne ; si la ligne existe déjà, il s'arrête sans rien
 * envoyer.
 *
 * C'est nécessaire parce qu'une file d'attente ne promet pas « au plus une
 * fois » : elle promet « au moins une fois ». Un travail dont l'accusé de
 * réception se perd est simplement rejoué. Sans cette contrainte, un client
 * recevrait deux fois la même mise en demeure — et c'est le genre de détail
 * qui coûte un client.
 *
 * Conséquence assumée : si l'envoi échoue APRÈS que le niveau a été réservé,
 * il ne repart pas tout seul ; la ligne reste en ÉCHEC, visible dans
 * l'application, et un humain décide. Entre « peut-être pas envoyée, et ça se
 * voit » et « peut-être envoyée deux fois », le premier risque est le bon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            // 1 : rappel courtois — 2 : relance ferme — 3 : mise en demeure.
            $table->smallInteger('niveau');

            $table->string('statut', 12)->default('ENVOYEE');
            $table->string('canal', 12)->default('EMAIL');
            $table->string('destinataire', 180)->nullable();
            $table->text('erreur')->nullable();

            $table->timestampTz('traitee_le')->useCurrent();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE relances
                ADD CONSTRAINT relances_niveau CHECK (niveau BETWEEN 1 AND 3),
                ADD CONSTRAINT relances_statut CHECK (statut IN ('ENVOYEE', 'ECHEC')),
                ADD CONSTRAINT relances_canal CHECK (canal IN ('EMAIL', 'SMS', 'COURRIER')),
                ADD CONSTRAINT relances_erreur_si_echec
                    CHECK ((statut = 'ECHEC') OR erreur IS NULL)
        SQL);

        DB::statement('CREATE UNIQUE INDEX relances_niveau_unique ON relances (document_id, niveau)');
    }

    public function down(): void
    {
        Schema::dropIfExists('relances');
    }
};
