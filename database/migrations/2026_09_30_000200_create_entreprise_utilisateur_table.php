<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qui travaille dans quelle entreprise, et à quel titre.
 *
 * Table de liaison plutôt qu'une colonne `entreprise_id` sur l'utilisateur :
 * un comptable indépendant tient les livres de plusieurs clients, et son rôle
 * n'est pas le même partout. Un propriétaire chez l'un peut être simple
 * lecteur chez l'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entreprise_utilisateur', function (Blueprint $table) {
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // PROPRIETAIRE : tout, y compris inviter et paramétrer l'entreprise.
            // COMPTABLE    : facturer, encaisser, relancer.
            // LECTEUR      : consulter, sans rien émettre.
            $table->string('role', 20);

            $table->timestampsTz();

            $table->primary(['entreprise_id', 'user_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE entreprise_utilisateur
                ADD CONSTRAINT entreprise_utilisateur_role
                CHECK (role IN ('PROPRIETAIRE', 'COMPTABLE', 'LECTEUR'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('entreprise_utilisateur');
    }
};
