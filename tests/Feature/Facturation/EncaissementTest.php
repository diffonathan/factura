<?php

declare(strict_types=1);

namespace Tests\Feature\Facturation;

use App\Facturation\Exceptions\EncaissementRefuse;
use App\Facturation\ModePaiement;
use App\Facturation\ServiceEncaissement;
use App\Facturation\StatutDocument;
use App\Facturation\TypeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConstruitDesDocuments;
use Tests\TestCase;

/**
 * Les encaissements et le solde.
 *
 * Aucun de ces tests ne vérifie un calcul fait en PHP : le solde et le statut
 * sont tenus par la base. Ce qu'ils vérifient, c'est que l'application n'a
 * effectivement rien à recalculer — et qu'elle ne peut pas contredire la base
 * même si elle essaie.
 */
final class EncaissementTest extends TestCase
{
    use ConstruitDesDocuments;
    use RefreshDatabase;

    private ServiceEncaissement $encaissement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encaissement = app(ServiceEncaissement::class);
    }

    #[Test]
    public function un_reglement_partiel_laisse_la_facture_ouverte(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        $this->encaissement->enregistrer($facture, [
            'montant' => 500,
            'mode' => ModePaiement::Cheque->value,
            'reference' => '4218773',
        ]);

        $this->assertSame('500.00', $facture->montant_paye);
        $this->assertSame('700.00', $facture->resteAPayer());
        $this->assertSame(StatutDocument::Emis, $facture->statut);
        $this->assertFalse($facture->estSolde());
    }

    #[Test]
    public function le_dernier_reglement_solde_la_facture(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        $this->encaissement->enregistrer($facture, ['montant' => 500]);
        $this->encaissement->solder($facture, ModePaiement::Virement, 'VIR-2026-0912');

        $this->assertSame('1200.00', $facture->montant_paye);
        $this->assertSame('0.00', $facture->resteAPayer());

        // Le statut est passé à SOLDE sans que le service y touche : c'est le
        // trigger qui l'a écrit, en même temps que le solde.
        $this->assertSame(StatutDocument::Solde, $facture->statut);
    }

    #[Test]
    public function plusieurs_reglements_sadditionnent_au_centime(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        // Trois tiers qui ne tombent pas rond. En arithmétique flottante, la
        // somme ne vaudrait pas exactement 1 200,00 et la facture ne se
        // solderait jamais tout à fait.
        foreach (['400.33', '400.33', '399.34'] as $montant) {
            $this->encaissement->enregistrer($facture, ['montant' => $montant]);
        }

        $this->assertSame('1200.00', $facture->montant_paye);
        $this->assertSame('0.00', $facture->resteAPayer());
        $this->assertSame(StatutDocument::Solde, $facture->statut);
    }

    #[Test]
    public function un_surpaiement_est_refuse_et_explique(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        $this->encaissement->enregistrer($facture, ['montant' => 1000]);

        try {
            $this->encaissement->enregistrer($facture, ['montant' => 500]);
            $this->fail('Un règlement dépassant le solde a été accepté.');
        } catch (EncaissementRefuse $erreur) {
            $this->assertStringContainsString('dépasse le solde', $erreur->getMessage());
            $this->assertStringContainsString('200.00', $erreur->getMessage());
            $this->assertSame(422, $erreur->codeHttp());
        }

        // Le refus n'a rien laissé derrière lui, et la suite reste utilisable :
        // c'est ce que garantit le point de sauvegarde du service.
        $this->assertSame('1000.00', $facture->refresh()->montant_paye);
        $this->assertCount(1, $facture->paiements);

        // Et le bon montant passe, juste après.
        $this->encaissement->enregistrer($facture, ['montant' => 200]);
        $this->assertSame(StatutDocument::Solde, $facture->refresh()->statut);
    }

    #[Test]
    public function un_encaissement_sur_brouillon_est_refuse(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()));

        try {
            $this->encaissement->enregistrer($brouillon, ['montant' => 100]);
            $this->fail('Un brouillon a pu recevoir un encaissement.');
        } catch (EncaissementRefuse $erreur) {
            $this->assertStringContainsString('brouillon', $erreur->getMessage());
        }
    }

    #[Test]
    public function un_encaissement_sur_devis_est_refuse(): void
    {
        $devis = $this->emettre($this->brouillon(
            $this->clientDe($this->entrepriseEnRegle()),
            TypeDocument::Devis,
        ));

        try {
            $this->encaissement->enregistrer($devis, ['montant' => 100]);
            $this->fail('Un devis a pu recevoir un encaissement.');
        } catch (EncaissementRefuse $erreur) {
            $this->assertStringContainsString('devis', $erreur->getMessage());
        }
    }

    #[Test]
    public function annuler_un_reglement_rouvre_la_facture(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        $paiement = $this->encaissement->solder($facture, ModePaiement::Especes);
        $this->assertSame(StatutDocument::Solde, $facture->statut);

        // Chèque sans provision, erreur de saisie : le règlement se retire, et
        // le statut redescend de lui-même. On lit le document RENDU par le
        // service : celui qu'on tenait avant l'appel est périmé, puisque c'est
        // un trigger qui a réécrit le solde.
        $rouverte = $this->encaissement->annuler($paiement);

        $this->assertSame('0.00', $rouverte->montant_paye);
        $this->assertSame(StatutDocument::Emis, $rouverte->statut);
        $this->assertSame('1200.00', $rouverte->resteAPayer());
    }

    #[Test]
    public function un_montant_nul_ou_negatif_est_refuse(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        // Un « règlement » de zéro n'est pas un règlement, et un négatif est un
        // avoir déguisé — la correction passe par un avoir, pas par un
        // encaissement inversé qui échapperait à la numérotation légale.
        $this->assertRefuseParLaBase(
            '23514',
            fn () => $facture->paiements()->create([
                'date_paiement' => now()->toDateString(),
                'montant' => -500,
                'mode' => ModePaiement::Virement->value,
            ]),
        );
    }
}
