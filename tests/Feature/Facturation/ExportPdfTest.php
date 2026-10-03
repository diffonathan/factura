<?php

declare(strict_types=1);

namespace Tests\Feature\Facturation;

use App\Facturation\GenerateurPdf;
use App\Facturation\ModePaiement;
use App\Facturation\ServiceEncaissement;
use App\Facturation\TypeDocument;
use App\Models\Document;
use App\Support\EntrepriseCourante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConstruitDesDocuments;
use Tests\TestCase;

/**
 * Le document remis au client.
 *
 * Ce qui est vérifié ici n'est pas « la bibliothèque produit un PDF » — elle le
 * fait — mais que le document DIT ce que la loi exige et ne dit pas ce qu'elle
 * interdit. Les deux fautes coûtent cher lors d'un contrôle : une mention
 * absente, et un brouillon qui passe pour une facture.
 *
 * Le contenu est vérifié sur le gabarit rendu plutôt que sur le binaire : le
 * PDF compresse ses flux de texte, et chercher une chaîne dedans testerait
 * surtout l'algorithme de compression. Le gabarit testé est le même fichier
 * que celui qu'emploie le générateur — il n'y a pas de seconde vérité.
 *
 * Le cloisonnement, lui, n'est pas retesté ici : la portée globale du modèle
 * le garantit pour toute résolution de route, et `CloisonnementTest` le prouve
 * là où il est implémenté. Le répéter donnerait l'illusion que chaque
 * contrôleur doit s'en charger.
 */
final class ExportPdfTest extends TestCase
{
    use ConstruitDesDocuments;
    use RefreshDatabase;

    private function rendu(Document $document): string
    {
        $document->loadMissing(['client', 'entreprise', 'lignes', 'paiements']);

        return View::make('pdf.document', [
            'document' => $document,
            'emetteur' => $document->entreprise,
            'client' => $document->client,
            'ventilation' => $document->ventilationTva(),
        ])->render();
    }

    #[Test]
    public function un_document_emis_produit_un_pdf_valide(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        app(EntrepriseCourante::class)->definir($entreprise->id);
        $facture = $this->emettre($this->brouillon($this->clientDe($entreprise)));

        $pdf = app(GenerateurPdf::class)->rendre($facture);

        // La signature d'un PDF, et sa marque de fin : un fichier tronqué
        // porterait quand même l'en-tête.
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
    }

    #[Test]
    public function le_fichier_porte_le_numero_legal_du_document(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        app(EntrepriseCourante::class)->definir($entreprise->id);
        $facture = $this->emettre($this->brouillon($this->clientDe($entreprise)));

        $this->assertSame($facture->reference.'.pdf', app(GenerateurPdf::class)->nomDeFichier($facture));
    }

    #[Test]
    public function un_brouillon_ne_porte_aucun_numero_et_s_annonce_sans_valeur_legale(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        app(EntrepriseCourante::class)->definir($entreprise->id);
        $brouillon = $this->brouillon($this->clientDe($entreprise));

        $html = $this->rendu($brouillon);

        // Le numéro n'est attribué qu'à l'émission : un brouillon qui en
        // afficherait un laisserait croire à une place prise dans la série.
        $this->assertNull($brouillon->numero);
        $this->assertStringContainsString('BROUILLON', $html);
        $this->assertStringContainsString('sans valeur légale', $html);

        // Et le fichier lui-même ne doit pas ressembler à une facture.
        $this->assertSame(
            'brouillon-'.$brouillon->id.'.pdf',
            app(GenerateurPdf::class)->nomDeFichier($brouillon),
        );
    }

    #[Test]
    public function les_mentions_legales_de_l_emetteur_figurent_sur_le_document(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        app(EntrepriseCourante::class)->definir($entreprise->id);
        $facture = $this->emettre($this->brouillon($this->clientDe($entreprise)));

        $html = $this->rendu($facture);

        // Chaque mention exigée, une par une : un `implode` testé en bloc
        // passerait encore si l'une d'elles disparaissait du modèle.
        foreach ($entreprise->mentionsLegales() as $mention) {
            $this->assertStringContainsString($mention, $html);
        }
        $this->assertNotEmpty($entreprise->mentionsLegales());
    }

    #[Test]
    public function la_ventilation_de_tva_apparait_taux_par_taux(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        app(EntrepriseCourante::class)->definir($entreprise->id);

        // Deux taux marocains sur le même document : c'est précisément le cas
        // où la ventilation devient obligatoire, et celui qu'un total unique
        // masquerait.
        $facture = $this->emettre($this->brouillon(
            $this->clientDe($entreprise),
            TypeDocument::Facture,
            [
                ['designation' => 'Prestation', 'quantite' => 1, 'prix_unitaire_ht' => 1000, 'taux_tva' => 20],
                ['designation' => 'Transport', 'quantite' => 1, 'prix_unitaire_ht' => 500, 'taux_tva' => 14],
            ],
        ));

        $html = $this->rendu($facture);

        $this->assertCount(2, $facture->ventilationTva());
        $this->assertStringContainsString('20 %', $html);
        $this->assertStringContainsString('14 %', $html);
        // 200,00 de TVA à 20 % et 70,00 à 14 % — les deux doivent être lisibles
        // séparément, pas seulement additionnées.
        $this->assertStringContainsString('200,00', $html);
        $this->assertStringContainsString('70,00', $html);
    }

    #[Test]
    public function les_reglements_recus_figurent_sur_une_facture_partiellement_payee(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        app(EntrepriseCourante::class)->definir($entreprise->id);
        $facture = $this->emettre($this->brouillon($this->clientDe($entreprise)));

        app(ServiceEncaissement::class)->enregistrer($facture, [
            'montant' => '400.00',
            'mode' => ModePaiement::Virement->value,
            'reference' => 'VIR-ESSAI-1',
        ]);

        $html = $this->rendu($facture->refresh());

        $this->assertStringContainsString('Règlements reçus', $html);
        $this->assertStringContainsString('VIR-ESSAI-1', $html);
        // Le reste dû doit apparaître : c'est le chiffre que le client regarde.
        $this->assertStringContainsString('Reste à payer', $html);
    }
}
