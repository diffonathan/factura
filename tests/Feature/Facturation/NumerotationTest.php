<?php

declare(strict_types=1);

namespace Tests\Feature\Facturation;

use App\Facturation\ServiceNumerotation;
use App\Facturation\TypeDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\ConstruitDesDocuments;
use Tests\TestCase;

/**
 * La numérotation légale.
 *
 * Ces tests ne vérifient pas seulement que le code marche : ils montrent
 * POURQUOI il est écrit ainsi. Le test de la séquence PostgreSQL, en
 * particulier, n'existe que pour exhiber le comportement qu'on a refusé —
 * c'est la justification du compteur, rendue exécutable.
 */
final class NumerotationTest extends TestCase
{
    use ConstruitDesDocuments;
    use RefreshDatabase;

    private ServiceNumerotation $numerotation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->numerotation = app(ServiceNumerotation::class);
    }

    #[Test]
    public function la_serie_commence_a_un_et_se_suit_sans_trou(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        $client = $this->clientDe($entreprise);

        $numeros = $this->emettreDesFactures($client, 12);

        $this->assertSame(range(1, 12), $numeros);
        $this->assertSame([], $this->numerotation->trousDeLaSerie(
            $entreprise->id, TypeDocument::Facture, (int) now()->year
        ));
    }

    #[Test]
    public function la_reference_est_calculee_par_la_base(): void
    {
        $client = $this->clientDe($this->entrepriseEnRegle());

        $facture = $this->emettre($this->brouillon($client));
        $devis = $this->emettre($this->brouillon($client, TypeDocument::Devis));

        $annee = now()->year;

        $this->assertSame("FA-{$annee}-0001", $facture->reference);
        $this->assertSame("DV-{$annee}-0001", $devis->reference);

        // La colonne est GENERATED ALWAYS : même une requête écrite à la main
        // ne peut pas la renseigner. La référence ne peut donc pas contredire
        // le type, l'année et le numéro dont elle est faite.
        $this->expectException(QueryException::class);

        DB::update('UPDATE documents SET reference = :truque WHERE id = :id', [
            'truque' => 'FA-2000-9999',
            'id' => $facture->id,
        ]);
    }

    #[Test]
    public function un_brouillon_na_pas_de_reference(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()));

        $this->assertNull($brouillon->numero);
        $this->assertNull($brouillon->reference);
    }

    #[Test]
    public function chaque_type_de_document_a_sa_propre_serie(): void
    {
        $client = $this->clientDe($this->entrepriseEnRegle());

        $this->emettreDesFactures($client, 3);

        $devis = $this->emettre($this->brouillon($client, TypeDocument::Devis));

        // Le devis ne reprend pas la suite des factures : les séries sont
        // indépendantes, comme l'administration les attend.
        $this->assertSame(1, $devis->numero);
    }

    #[Test]
    public function chaque_entreprise_a_sa_propre_serie(): void
    {
        $premiere = $this->clientDe($this->entrepriseEnRegle());
        $seconde = $this->clientDe($this->entrepriseEnRegle());

        $this->emettreDesFactures($premiere, 5);

        $this->assertSame([1], $this->emettreDesFactures($seconde, 1));
    }

    #[Test]
    public function un_brouillon_jete_ne_laisse_pas_de_trou(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        $client = $this->clientDe($entreprise);

        $this->emettre($this->brouillon($client));

        // Trois brouillons créés puis supprimés sans être émis : ils n'ont
        // jamais touché au compteur.
        for ($i = 0; $i < 3; $i++) {
            $this->brouillon($client)->delete();
        }

        $this->assertSame(2, $this->emettre($this->brouillon($client))->numero);
        $this->assertSame([], $this->numerotation->trousDeLaSerie(
            $entreprise->id, TypeDocument::Facture, (int) now()->year
        ));
    }

    /**
     * LE test du projet.
     *
     * Une panne au milieu d'une émission — base indisponible, erreur PHP,
     * conteneur tué — ne doit pas consommer de numéro. Le compteur étant une
     * ligne de table, il revient en arrière avec la transaction.
     */
    #[Test]
    public function une_transaction_annulee_ne_consomme_aucun_numero(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        $client = $this->clientDe($entreprise);

        $this->emettre($this->brouillon($client));

        $condamne = $this->brouillon($client);

        try {
            DB::transaction(function () use ($condamne): void {
                $this->emettre($condamne);

                throw new RuntimeException('panne simulée après l\'émission');
            });
            $this->fail('La panne simulée aurait dû remonter.');
        } catch (RuntimeException) {
            // attendu
        }

        $this->assertSame(1, $this->numerotation->dernierNumero(
            $entreprise->id, TypeDocument::Facture, (int) now()->year
        ), 'Le compteur a avancé malgré l\'annulation : la série aurait un trou.');

        // Et la facture suivante reprend bien au numéro 2.
        $this->assertSame(2, $this->emettre($this->brouillon($client))->numero);
        $this->assertSame([], $this->numerotation->trousDeLaSerie(
            $entreprise->id, TypeDocument::Facture, (int) now()->year
        ));
    }

    /**
     * La contre-épreuve : ce qui se passerait avec une séquence PostgreSQL.
     *
     * Ce test n'exerce aucun code de Factura. Il documente le piège, et il
     * échouera le jour où quelqu'un remplacera le compteur par un `nextval()`
     * en trouvant ça plus simple.
     */
    #[Test]
    public function une_sequence_postgresql_laisserait_un_trou(): void
    {
        DB::statement('CREATE SEQUENCE serie_de_demonstration');

        try {
            DB::transaction(function (): void {
                DB::selectOne("SELECT nextval('serie_de_demonstration')");

                throw new RuntimeException('panne simulée');
            });
        } catch (RuntimeException) {
            // attendu
        }

        $suivant = (int) DB::selectOne(
            "SELECT nextval('serie_de_demonstration') AS valeur"
        )->valeur;

        // 2, et non 1 : le numéro 1 est perdu à jamais. C'est le comportement
        // voulu d'une séquence — et exactement ce que la loi interdit.
        $this->assertSame(
            2,
            $suivant,
            'Une séquence qui reviendrait en arrière rendrait ce test inutile ; '
            . 'le compteur transactionnel n\'aurait plus de raison d\'être.'
        );
    }

    #[Test]
    public function reserver_hors_transaction_est_refuse(): void
    {
        $entreprise = $this->entrepriseEnRegle();

        // La suite de tests tourne dans une transaction (RefreshDatabase), donc
        // on descend au niveau zéro pour reproduire les conditions réelles.
        DB::rollBack();

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessageMatches('/trou dans la série/');

            $this->numerotation->reserver(
                $entreprise->id, TypeDocument::Facture, (int) now()->year
            );
        } finally {
            DB::beginTransaction();
        }
    }

    /**
     * Le filet du trigger. Un numéro posé à la main, sauté, est refusé net.
     * En conditions normales le compteur ne peut pas produire cela — ce test
     * vérifie le filet, pas le mécanisme.
     */
    #[Test]
    public function la_base_refuse_un_numero_non_contigu(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()));

        try {
            DB::update(
                "UPDATE documents SET numero = 5, statut = 'EMIS', emis_le = now() WHERE id = :id",
                ['id' => $brouillon->id]
            );
            $this->fail('La base a accepté un numéro non contigu.');
        } catch (QueryException $erreur) {
            $this->assertSame('90001', $erreur->getCode());
            $this->assertStringContainsString('Numerotation non contigue', $erreur->getMessage());
        }
    }

    #[Test]
    public function la_base_refuse_deux_documents_au_meme_numero(): void
    {
        $client = $this->clientDe($this->entrepriseEnRegle());

        $this->emettre($this->brouillon($client));
        $second = $this->brouillon($client);

        try {
            DB::update(
                "UPDATE documents SET numero = 1, statut = 'EMIS', emis_le = now() WHERE id = :id",
                ['id' => $second->id]
            );
            $this->fail('La base a accepté un doublon dans la série.');
        } catch (QueryException $erreur) {
            // Le trigger de contiguïté parle le premier : il attendait 2.
            $this->assertSame('90001', $erreur->getCode());
        }
    }

    #[Test]
    public function la_serie_reprend_a_un_la_nouvelle_annee(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        $client = $this->clientDe($entreprise);

        $ancienne = $this->emettre($this->brouillon(
            $client, attributs: ['date_emission' => '2025-12-31']
        ));
        $nouvelle = $this->emettre($this->brouillon(
            $client, attributs: ['date_emission' => '2026-01-02']
        ));

        $this->assertSame('FA-2025-0001', $ancienne->reference);
        $this->assertSame('FA-2026-0001', $nouvelle->reference);
    }

    #[Test]
    public function lannee_de_la_serie_suit_la_date_du_document(): void
    {
        $client = $this->clientDe($this->entrepriseEnRegle());
        $brouillon = $this->brouillon($client, attributs: ['date_emission' => '2026-03-15']);

        $this->expectException(QueryException::class);

        // Une année de série qui ne correspond pas à la date du document
        // fabriquerait deux factures « n° 1 » dans le même exercice.
        DB::update('UPDATE documents SET annee = 2024 WHERE id = :id', ['id' => $brouillon->id]);
    }
}
