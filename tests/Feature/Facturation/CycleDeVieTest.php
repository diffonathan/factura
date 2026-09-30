<?php

declare(strict_types=1);

namespace Tests\Feature\Facturation;

use App\Facturation\Exceptions\ConflitFacturation;
use App\Facturation\Exceptions\DocumentNonEmissible;
use App\Facturation\ServiceEmission;
use App\Facturation\StatutDocument;
use App\Facturation\TypeDocument;
use App\Models\Document;
use App\Models\Entreprise;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConstruitDesDocuments;
use Tests\TestCase;

/**
 * Le parcours complet : devis → facture → avoir.
 *
 * C'est le scénario que vit un utilisateur, et donc celui qui attrape les
 * erreurs que les tests unitaires laissent passer — un statut oublié, une
 * ligne non recopiée, un lien d'origine dans le mauvais sens.
 */
final class CycleDeVieTest extends TestCase
{
    use ConstruitDesDocuments;
    use RefreshDatabase;

    private ServiceEmission $emission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emission = app(ServiceEmission::class);
    }

    #[Test]
    public function un_devis_accepte_devient_un_brouillon_de_facture(): void
    {
        $client = $this->clientDe($this->entrepriseEnRegle());

        $devis = $this->emettre($this->brouillon($client, TypeDocument::Devis, lignes: [
            ['designation' => 'Audit', 'quantite' => 2, 'prix_unitaire_ht' => 4000, 'taux_tva' => 20],
            ['designation' => 'Formation', 'quantite' => 3, 'prix_unitaire_ht' => 2500, 'taux_tva' => 20],
        ]));

        $facture = $this->emission->convertirEnFacture($devis);

        // Un BROUILLON, pas une facture émise : entre l'accord du client et la
        // facturation, une quantité change presque toujours.
        $this->assertTrue($facture->estBrouillon());
        $this->assertSame(TypeDocument::Facture, $facture->type);
        $this->assertSame($devis->id, $facture->origine_id);
        $this->assertSame($client->id, $facture->client_id);

        // Les lignes sont recopiées, donc les totaux recalculés à l'identique.
        $this->assertCount(2, $facture->lignes);
        $this->assertSame($devis->montant_ttc, $facture->montant_ttc);
        $this->assertSame('15500.00', $facture->montant_ht);

        // Et le devis porte désormais la trace de son acceptation.
        $this->assertSame(StatutDocument::Accepte, $devis->refresh()->statut);
    }

    #[Test]
    public function un_devis_ne_se_facture_quune_fois(): void
    {
        $devis = $this->emettre($this->brouillon(
            $this->clientDe($this->entrepriseEnRegle()),
            TypeDocument::Devis,
        ));

        $this->emission->convertirEnFacture($devis);

        // Le double-clic sur « Facturer ce devis ». La vérification en PHP
        // passerait ; c'est l'index unique qui tranche.
        $this->expectException(QueryException::class);
        $this->emission->convertirEnFacture($devis);
    }

    #[Test]
    public function un_devis_brouillon_ne_se_facture_pas(): void
    {
        $devis = $this->brouillon(
            $this->clientDe($this->entrepriseEnRegle()),
            TypeDocument::Devis,
        );

        $this->expectException(DocumentNonEmissible::class);
        $this->expectExceptionMessageMatches('/pas encore été émis/');

        $this->emission->convertirEnFacture($devis);
    }

    #[Test]
    public function une_facture_ne_se_convertit_pas_en_facture(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        $this->expectException(DocumentNonEmissible::class);
        $this->expectExceptionMessageMatches('/Seul un devis/');

        $this->emission->convertirEnFacture($facture);
    }

    #[Test]
    public function un_avoir_total_annule_la_facture_sans_la_supprimer(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        $avoir = $this->emettre($this->emission->preparerAvoir($facture));

        $this->assertSame(TypeDocument::Avoir, $avoir->type);
        $this->assertSame('AV-'.now()->year.'-0001', $avoir->reference);
        $this->assertSame('1200.00', $avoir->montant_ttc);
        $this->assertSame($facture->id, $avoir->origine_id);

        // La facture reste en base, numérotée et lisible : c'est l'avoir qui
        // porte la correction, et la piste reste vérifiable par un contrôleur.
        $facture->refresh();
        $this->assertSame(StatutDocument::Annule, $facture->statut);
        $this->assertNotNull($facture->numero);
    }

    #[Test]
    public function un_avoir_partiel_nannule_pas_la_facture(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        $avoir = $this->emettre($this->emission->preparerAvoir($facture, [[
            'designation' => 'Remise commerciale accordée après coup',
            'quantite' => 1,
            'prix_unitaire_ht' => 200,
            'taux_tva' => 20,
        ]]));

        $this->assertSame('240.00', $avoir->montant_ttc);
        $this->assertSame(StatutDocument::Emis, $facture->refresh()->statut);
    }

    #[Test]
    public function un_avoir_ne_peut_pas_rendre_plus_que_la_facture(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        // Premier avoir : la totalité.
        $this->emettre($this->emission->preparerAvoir($facture));

        // Second avoir sur la même facture : il ne reste rien à rendre. Sans
        // cette borne, on fabriquerait de la TVA déductible à partir de rien.
        $trop = $this->emission->preparerAvoir($facture);

        try {
            $this->emettre($trop);
            $this->fail('Un avoir dépassant la facture a été émis.');
        } catch (DocumentNonEmissible $erreur) {
            $this->assertStringContainsString('au plus 0.00', $erreur->getMessage());
        }
    }

    #[Test]
    public function un_avoir_ne_se_prepare_pas_sur_un_brouillon(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()));

        $this->expectException(DocumentNonEmissible::class);
        $this->expectExceptionMessageMatches('/corrigez-la directement/');

        $this->emission->preparerAvoir($brouillon);
    }

    #[Test]
    public function lemission_refuse_une_entreprise_aux_mentions_incompletes(): void
    {
        $entreprise = Entreprise::factory()->sansMentions()->create();
        $facture = $this->brouillon($this->clientDe($entreprise));

        try {
            $this->emettre($facture);
            $this->fail('Une facture sans les mentions légales a été émise.');
        } catch (DocumentNonEmissible $erreur) {
            // Les manques sont rassemblés et rendus d'un coup : corriger trois
            // champs signalés l'un après l'autre fait abandonner l'utilisateur.
            $this->assertSame([
                'votre ICE',
                'votre identifiant fiscal',
                'votre registre de commerce',
            ], $erreur->manques);
            $this->assertSame(422, $erreur->codeHttp());
        }

        $this->assertTrue($facture->refresh()->estBrouillon());
    }

    #[Test]
    public function lemission_refuse_une_facture_sans_lice_du_client(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        $client = $this->clientDe($entreprise, ['ice' => null]);

        try {
            $this->emettre($this->brouillon($client));
            $this->fail('Une facture sans l\'ICE du client a été émise.');
        } catch (DocumentNonEmissible $erreur) {
            $this->assertStringContainsString('ICE du client', $erreur->getMessage());
        }
    }

    #[Test]
    public function un_devis_semet_sans_lice_du_client(): void
    {
        $client = $this->clientDe($this->entrepriseEnRegle(), ['ice' => null]);

        // Un devis n'ouvre aucun droit à déduction : exiger l'ICE dès le devis
        // bloquerait une prospection qui n'a encore rien de fiscal.
        $devis = $this->emettre($this->brouillon($client, TypeDocument::Devis));

        $this->assertSame(1, $devis->numero);
    }

    #[Test]
    public function un_particulier_se_facture_sans_ice(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        $client = $this->clientDe($entreprise, [
            'nom' => 'Karim Benali',
            'est_particulier' => true,
            'ice' => null,
            'identifiant_fiscal' => null,
        ]);

        $facture = $this->emettre($this->brouillon($client));

        $this->assertSame(1, $facture->numero);
    }

    #[Test]
    public function un_document_sans_ligne_ne_semet_pas(): void
    {
        $client = $this->clientDe($this->entrepriseEnRegle());
        $vide = Document::factory()->pour($client)->create();

        try {
            $this->emettre($vide);
            $this->fail('Une facture vide a été émise.');
        } catch (DocumentNonEmissible $erreur) {
            $this->assertSame([
                'au moins une ligne',
                'un montant supérieur à zéro',
            ], $erreur->manques);
        }
    }

    #[Test]
    public function un_document_deja_emis_ne_se_reemet_pas(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        $this->expectException(ConflitFacturation::class);
        $this->expectExceptionMessageMatches('/déjà émis/');

        $this->emettre($facture);
    }

    #[Test]
    public function lecheance_suit_le_delai_de_paiement_du_client(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        $client = $this->clientDe($entreprise, ['delai_paiement_jours' => 60]);

        $facture = $this->emettre($this->brouillon($client, attributs: [
            'date_emission' => '2026-03-02',
        ]));

        $this->assertSame('2026-05-01', $facture->date_echeance->toDateString());
    }

    #[Test]
    public function un_devis_recoit_une_duree_de_validite_de_trente_jours(): void
    {
        $client = $this->clientDe($this->entrepriseEnRegle(), ['delai_paiement_jours' => 90]);

        $devis = $this->emettre($this->brouillon($client, TypeDocument::Devis, attributs: [
            'date_emission' => '2026-03-02',
        ]));

        // Le délai de paiement du client ne s'applique pas à un devis : ce
        // n'est pas une échéance, c'est une durée de validité.
        $this->assertSame('2026-04-01', $devis->date_echeance->toDateString());
    }
}
