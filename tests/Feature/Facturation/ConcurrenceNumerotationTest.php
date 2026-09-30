<?php

declare(strict_types=1);

namespace Tests\Feature\Facturation;

use App\Facturation\Exceptions\ConflitFacturation;
use App\Facturation\ServiceNumerotation;
use App\Facturation\TypeDocument;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConstruitDesDocuments;
use Tests\TestCase;

/**
 * La numérotation sous concurrence.
 *
 * Deux vraies sessions PostgreSQL, pas deux appels dans la même : c'est la
 * seule façon d'observer un verrou. Le reste de la suite tourne dans une
 * transaction (RefreshDatabase), qui rendrait les écritures invisibles à
 * l'autre session — d'où `DatabaseMigrations` ici, plus lent mais honnête.
 *
 * La méthode mérite un mot. Lancer des processus parallèles et espérer qu'ils
 * se télescopent donne un test qui passe le plus souvent : il ne prouve rien,
 * il observe. On fait l'inverse — la seconde session reçoit un `lock_timeout`
 * court et on VÉRIFIE qu'elle expire. Le résultat est le même à chaque
 * exécution, et il dit précisément ce qu'on voulait savoir : cette ligne est
 * bien verrouillée.
 */
final class ConcurrenceNumerotationTest extends TestCase
{
    use ConstruitDesDocuments;
    use DatabaseMigrations;

    private const SQL_COMPTEUR = <<<'SQL'
        INSERT INTO compteurs (entreprise_id, type, annee, dernier_numero, created_at, updated_at)
             VALUES (:entreprise, :type, :annee, 1, now(), now())
        ON CONFLICT (entreprise_id, type, annee)
        DO UPDATE SET dernier_numero = compteurs.dernier_numero + 1,
                      updated_at     = now()
          RETURNING dernier_numero
    SQL;

    #[Test]
    public function la_seconde_session_attend_le_verrou_du_compteur(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        $annee = (int) now()->year;
        $numerotation = app(ServiceNumerotation::class);

        $autreSession = $this->secondeSession();
        $autreSession->exec("SET lock_timeout = '400ms'");

        // Session 1 : prend le numéro 1 et garde le verrou, transaction ouverte.
        DB::beginTransaction();
        $premier = $numerotation->reserver($entreprise->id, TypeDocument::Facture, $annee);
        $this->assertSame(1, $premier);

        // Session 2 : la même ligne de compteur. Elle doit attendre — et son
        // délai d'attente expire, ce qui prouve qu'elle attendait vraiment.
        $autreSession->beginTransaction();

        try {
            $this->executerCompteur($autreSession, $entreprise->id, $annee);
            $this->fail(
                'La seconde session a incrémenté le compteur sans attendre : '
                .'deux factures pourraient porter le même numéro.'
            );
        } catch (PDOException $erreur) {
            // 55P03 = lock_not_available : le délai a expiré sur un verrou tenu.
            $this->assertSame('55P03', $erreur->getCode(), $erreur->getMessage());
        }

        $autreSession->rollBack();

        // Session 1 valide : le verrou tombe.
        DB::commit();

        // Session 2 repart et obtient 2, pas 1. Elle a relu la valeur validée.
        $autreSession->beginTransaction();
        $second = $this->executerCompteur($autreSession, $entreprise->id, $annee);
        $autreSession->commit();

        $this->assertSame(2, $second, 'La seconde session a relu une valeur périmée.');
    }

    /**
     * Le double-clic sur « Émettre ».
     *
     * Deux requêtes partent avec le même brouillon. La première l'émet. La
     * seconde arrive avec une vue périmée du document — pour elle, il est
     * encore un brouillon. C'est le `AND numero IS NULL` de la requête de mise
     * à jour qui l'arrête, et non un test de lecture préalable, qui serait
     * passé lui aussi.
     *
     * Ce qu'on vérifie derrière compte autant : le numéro que la seconde
     * tentative avait réservé est RENDU. Sinon le refus lui-même laisserait un
     * trou, et le remède serait la maladie.
     */
    #[Test]
    public function une_seconde_emission_du_meme_brouillon_est_refusee_sans_trouer_la_serie(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        $client = $this->clientDe($entreprise);
        $annee = (int) now()->year;

        $brouillon = $this->brouillon($client);

        // La vue qu'en garde la requête concurrente : capturée avant émission.
        $vuePerimee = $brouillon->replicate();
        $vuePerimee->id = $brouillon->id;
        $vuePerimee->exists = true;

        $emis = $this->emettre($brouillon);
        $this->assertSame(1, $emis->numero);

        try {
            $this->emettre($vuePerimee);
            $this->fail('La seconde émission est passée : le numéro du premier est perdu.');
        } catch (ConflitFacturation $erreur) {
            // Le message importe : il distingue les deux refus possibles. Celui
            // d'une lecture préalable (« déjà émis », levé par
            // `verifierEmissible`) n'aurait rien prouvé — la vue périmée passe
            // ce contrôle sans broncher, puisque pour elle le numéro est nul.
            // Celui-ci vient de la mise à jour conditionnelle, donc de la seule
            // vérification qui tienne sous concurrence.
            $this->assertStringContainsString('autre opération', $erreur->getMessage());
        }

        $numerotation = app(ServiceNumerotation::class);

        $this->assertSame(
            1,
            $numerotation->dernierNumero($entreprise->id, TypeDocument::Facture, $annee),
            'Le refus a laissé le compteur avancé : la facture suivante sautera un numéro.'
        );

        // La preuve par la suite : la facture d'après est bien la n° 2.
        $this->assertSame(2, $this->emettre($this->brouillon($client))->numero);
        $this->assertSame([], $numerotation->trousDeLaSerie(
            $entreprise->id, TypeDocument::Facture, $annee
        ));
    }

    #[Test]
    public function deux_entreprises_nattendent_pas_lune_lautre(): void
    {
        $premiere = $this->entrepriseEnRegle();
        $seconde = $this->entrepriseEnRegle();
        $annee = (int) now()->year;

        $autreSession = $this->secondeSession();
        $autreSession->exec("SET lock_timeout = '400ms'");

        DB::beginTransaction();
        app(ServiceNumerotation::class)->reserver($premiere->id, TypeDocument::Facture, $annee);

        // Le verrou porte sur UNE ligne de compteur. L'entreprise voisine
        // travaille sans attendre : la sérialisation que la loi impose reste
        // cantonnée à la série concernée.
        $autreSession->beginTransaction();
        $numero = $this->executerCompteur($autreSession, $seconde->id, $annee);
        $autreSession->commit();

        DB::commit();

        $this->assertSame(1, $numero);
    }

    // ------------------------------------------------------------------

    /**
     * Une connexion PostgreSQL réellement indépendante de celle de Laravel.
     *
     * `DB::connection()` rendrait la même instance PDO, donc la même session :
     * elle ne s'attendrait jamais elle-même, et le test passerait sans rien
     * démontrer.
     */
    private function secondeSession(): PDO
    {
        $config = config('database.connections.pgsql');

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function executerCompteur(PDO $session, int $entrepriseId, int $annee): int
    {
        $requete = $session->prepare(self::SQL_COMPTEUR);
        $requete->execute([
            'entreprise' => $entrepriseId,
            'type' => TypeDocument::Facture->value,
            'annee' => $annee,
        ]);

        return (int) $requete->fetchColumn();
    }
}
