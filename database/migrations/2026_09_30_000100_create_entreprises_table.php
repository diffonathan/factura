<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'entreprise qui facture. C'est la racine du cloisonnement : toute donnée
 * métier lui appartient, et rien ne doit traverser d'une entreprise à l'autre.
 *
 * Les identifiants marocains ne sont pas décoratifs. Une facture qui ne porte
 * pas l'ICE, l'identifiant fiscal et le registre de commerce de son émetteur
 * est irrégulière, et son destinataire ne peut pas déduire la TVA. Ils sont
 * donc stockés ici, une seule fois, et repris par chaque document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entreprises', function (Blueprint $table) {
            $table->id();

            $table->string('raison_sociale', 200);
            $table->string('forme_juridique', 30)->nullable();

            // ICE : Identifiant Commun de l'Entreprise, exactement 15 chiffres.
            $table->char('ice', 15)->nullable();
            $table->string('identifiant_fiscal', 20)->nullable();
            $table->string('registre_commerce', 30)->nullable();
            $table->string('taxe_professionnelle', 30)->nullable();
            $table->string('cnss', 30)->nullable();

            $table->string('adresse', 255)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('site_web', 180)->nullable();

            // Banque : obligatoire sur la facture dès qu'on attend un virement.
            $table->string('banque', 100)->nullable();
            $table->string('rib', 24)->nullable();

            // Régime de TVA : sur les DÉBITS (la TVA est due à la facturation)
            // ou sur les ENCAISSEMENTS (elle est due au paiement). Le choix
            // change la date de déclaration, pas le calcul de la facture.
            $table->string('regime_tva', 20)->default('DEBIT');

            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE entreprises
                ADD CONSTRAINT entreprises_ice_format CHECK (ice IS NULL OR ice ~ '^[0-9]{15}$'),
                ADD CONSTRAINT entreprises_rib_format CHECK (rib IS NULL OR rib ~ '^[0-9]{24}$'),
                ADD CONSTRAINT entreprises_regime_tva CHECK (regime_tva IN ('DEBIT', 'ENCAISSEMENT'))
        SQL);

        // Deux entreprises ne peuvent pas déclarer le même ICE : il identifie
        // une personne morale unique auprès de l'administration.
        DB::statement('CREATE UNIQUE INDEX entreprises_ice_unique ON entreprises (ice) WHERE ice IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('entreprises');
    }
};
