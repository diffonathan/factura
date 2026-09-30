<?php

declare(strict_types=1);

namespace Tests\Feature\Facturation;

use App\Models\Ligne;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConstruitDesDocuments;
use Tests\TestCase;

/**
 * Les montants et la TVA.
 *
 * Tout est calculé par PostgreSQL : les lignes par des colonnes GENERATED, les
 * totaux du document par un trigger. Le but de ces tests est de vérifier que
 * les chiffres sont justes AU CENTIME, et qu'aucun chemin ne permet d'écrire
 * un total qui contredise ses lignes.
 */
final class TotauxEtTvaTest extends TestCase
{
    use ConstruitDesDocuments;
    use RefreshDatabase;

    #[Test]
    public function la_ligne_calcule_son_montant_et_sa_tva(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()), lignes: [[
            'designation' => 'Journées d\'intégration',
            'unite' => 'jour',
            'quantite' => 3,
            'prix_unitaire_ht' => 1250.75,
            'taux_tva' => 20,
        ]]);

        $ligne = $brouillon->lignes->first();

        $this->assertSame('3752.25', $ligne->montant_ht);
        $this->assertSame('750.45', $ligne->montant_tva);
        $this->assertSame('4502.70', $ligne->montantTtc());
    }

    #[Test]
    public function la_remise_sapplique_avant_la_tva_et_sarrondit_au_centime(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()), lignes: [[
            'designation' => 'Maintenance annuelle',
            'quantite' => 1,
            'prix_unitaire_ht' => 99.99,
            'remise_pct' => 33.33,
            'taux_tva' => 20,
        ]]);

        $ligne = $brouillon->lignes->first();

        // 99,99 × (1 − 0,3333) = 66,663333… → 66,66
        // 66,66 × 20 %                        → 13,332 → 13,33
        $this->assertSame('66.66', $ligne->montant_ht);
        $this->assertSame('13.33', $ligne->montant_tva);
    }

    #[Test]
    public function les_totaux_du_document_suivent_ses_lignes(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()), lignes: [
            ['designation' => 'Conception', 'quantite' => 1, 'prix_unitaire_ht' => 1000, 'taux_tva' => 20],
            ['designation' => 'Hébergement', 'quantite' => 12, 'prix_unitaire_ht' => 150, 'taux_tva' => 20],
        ]);

        $this->assertSame('2800.00', $brouillon->montant_ht);
        $this->assertSame('560.00', $brouillon->montant_tva);
        $this->assertSame('3360.00', $brouillon->montant_ttc);

        // Une ligne retirée, et les totaux reculent d'eux-mêmes.
        $brouillon->lignes->last()->delete();

        $this->assertSame('1000.00', $brouillon->refresh()->montant_ht);
        $this->assertSame('1200.00', $brouillon->montant_ttc);
    }

    #[Test]
    public function la_tva_est_ventilee_par_taux(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()), lignes: [
            ['designation' => 'Prestation de service', 'quantite' => 1, 'prix_unitaire_ht' => 1000, 'taux_tva' => 20],
            ['designation' => 'Fournitures scolaires', 'quantite' => 1, 'prix_unitaire_ht' => 500, 'taux_tva' => 7],
            ['designation' => 'Transport', 'quantite' => 1, 'prix_unitaire_ht' => 300, 'taux_tva' => 14],
        ]);

        // Mention obligatoire dès qu'une facture mélange plusieurs taux — et
        // c'est le cas courant du bâtiment comme du commerce.
        $this->assertSame([
            ['taux' => 20.0, 'base' => '1000.00', 'tva' => '200.00'],
            ['taux' => 14.0, 'base' => '300.00', 'tva' => '42.00'],
            ['taux' => 7.0, 'base' => '500.00', 'tva' => '35.00'],
        ], $brouillon->ventilationTva());

        $this->assertSame('1800.00', $brouillon->montant_ht);
        $this->assertSame('277.00', $brouillon->montant_tva);
    }

    #[Test]
    public function un_taux_de_tva_inexistant_est_refuse(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()));

        // 15 % n'existe pas au Maroc. Refusé à l'écriture, pas signalé plus
        // tard par un rapport que personne ne lit.
        $this->expectException(QueryException::class);

        Ligne::creerPour($brouillon, [
            'designation' => 'Taux inventé',
            'quantite' => 1,
            'prix_unitaire_ht' => 100,
            'taux_tva' => 15,
        ]);
    }

    #[Test]
    public function un_total_ne_peut_pas_etre_ecrit_a_la_main(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()));

        // Écriture directe en base, avec des totaux cohérents entre eux mais
        // qui ne correspondent pas aux lignes. La contrainte
        // `documents_total_coherent` ne l'attrape pas — il faut donc que le
        // recalcul reprenne la main dès la prochaine écriture de ligne.
        DB::update(
            'UPDATE documents SET montant_ht = 1, montant_tva = 0, montant_ttc = 1 WHERE id = :id',
            ['id' => $brouillon->id]
        );

        Ligne::creerPour($brouillon, [
            'designation' => 'Ligne suivante',
            'quantite' => 1,
            'prix_unitaire_ht' => 500,
            'taux_tva' => 20,
        ]);

        // Recalcul complet, donc retour à la vérité : 1 000 + 500 HT.
        $this->assertSame('1500.00', $brouillon->refresh()->montant_ht);
        $this->assertSame('1800.00', $brouillon->montant_ttc);
    }

    #[Test]
    public function un_total_incoherent_est_refuse_par_la_base(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()));

        $this->expectException(QueryException::class);

        DB::update(
            'UPDATE documents SET montant_ht = 1000, montant_tva = 200, montant_ttc = 9999 WHERE id = :id',
            ['id' => $brouillon->id]
        );
    }

    #[Test]
    public function une_quantite_nulle_ou_negative_est_refusee(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()));

        $this->expectException(QueryException::class);

        Ligne::creerPour($brouillon, [
            'designation' => 'Quantité impossible',
            'quantite' => 0,
            'prix_unitaire_ht' => 100,
            'taux_tva' => 20,
        ]);
    }

    #[Test]
    public function une_ligne_exoneree_ne_porte_pas_de_tva(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()), lignes: [[
            'designation' => 'Prestation exportée',
            'quantite' => 1,
            'prix_unitaire_ht' => 2500,
            'taux_tva' => 0,
        ]]);

        $this->assertSame('2500.00', $brouillon->montant_ht);
        $this->assertSame('0.00', $brouillon->montant_tva);
        $this->assertSame('2500.00', $brouillon->montant_ttc);
    }
}
