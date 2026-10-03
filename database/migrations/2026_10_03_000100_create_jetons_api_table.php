<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les jetons d'accès à l'API.
 *
 * Le jeton appartient à une ENTREPRISE, pas à un utilisateur. C'est une
 * décision de conception, pas un raccourci : l'API sert à brancher un outil —
 * un logiciel de caisse, un tableur, un site marchand — et cet outil agit au
 * nom de l'entreprise, pas d'une personne. Le rattacher à un compte ferait
 * tomber l'intégration le jour où la personne quitte le cabinet.
 *
 * Conséquence heureuse : le cloisonnement existant s'applique tel quel. Le
 * middleware pose l'entreprise courante à partir du jeton, et à partir de là
 * les modèles filtrent seuls — exactement comme pour une visite au navigateur.
 * Il n'y a pas deux mécanismes d'isolation à garder en accord.
 *
 * La valeur du jeton n'est JAMAIS stockée : seule son empreinte l'est. Une
 * copie de la base, un journal de requêtes ou une sauvegarde qui fuite ne
 * donnent alors rien d'utilisable. C'est aussi pourquoi la valeur n'est
 * affichée qu'une fois, à la création — la perdre oblige à en créer un autre,
 * ce qui est le comportement correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jetons_api', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();

            // À quoi sert ce jeton : « caisse boutique », « export comptable ».
            // Sans ce nom, révoquer le bon devient un pari.
            $table->string('nom', 100);

            // SHA-256 en hexadécimal : 64 caractères, longueur fixe.
            // Pas de bcrypt ici, et c'est délibéré : un hachage lent protège un
            // mot de passe choisi par un humain, donc devinable. Un jeton est
            // tiré au hasard sur 40 octets — il n'y a rien à deviner, et un
            // hachage lent ferait payer sa lenteur à CHAQUE requête d'API.
            $table->char('empreinte', 64)->unique();

            $table->timestampTz('dernier_usage_le')->nullable();
            $table->timestampTz('revoque_le')->nullable();
            $table->timestampsTz();

            // La recherche se fait toujours par empreinte, et seuls les jetons
            // vivants comptent.
            $table->index(['entreprise_id', 'revoque_le']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE jetons_api
                ADD CONSTRAINT jetons_api_empreinte_format
                CHECK (empreinte ~ '^[0-9a-f]{64}$')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('jetons_api');
    }
};
