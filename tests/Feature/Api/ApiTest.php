<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Entreprise;
use App\Models\JetonApi;
use App\Support\EntrepriseCourante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConstruitDesDocuments;
use Tests\TestCase;

/**
 * L'API, vue d'un programme qui s'y branche.
 *
 * Deux choses sont vérifiées ici, et la seconde est la seule qui compte
 * vraiment : qu'un jeton ouvre la porte, et qu'il n'ouvre QUE la sienne. Une
 * fuite entre entreprises par l'API serait exactement aussi grave que par le
 * navigateur, avec en plus la discrétion d'un appel automatisé.
 *
 * Ce sont les premiers tests HTTP du projet : jusqu'ici tout se vérifiait au
 * niveau du métier, parce que c'est là que vivent les garanties. L'API, elle,
 * a une surface — en-têtes, codes de retour, forme du JSON — et cette surface
 * est un contrat envers quelqu'un d'extérieur.
 */
final class ApiTest extends TestCase
{
    use ConstruitDesDocuments;
    use RefreshDatabase;

    /** @return array{0: Entreprise, 1: string} */
    private function entrepriseAvecJeton(): array
    {
        $entreprise = $this->entrepriseEnRegle();
        [, $valeur] = JetonApi::creerPour($entreprise, 'essai');

        return [$entreprise, $valeur];
    }

    private function enTetes(string $jeton): array
    {
        return ['Authorization' => 'Bearer '.$jeton, 'Accept' => 'application/json'];
    }

    #[Test]
    public function sans_jeton_la_porte_reste_fermee(): void
    {
        $this->getJson('/api/v1/documents')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Jeton absent.');
    }

    #[Test]
    public function un_jeton_inconnu_est_refuse(): void
    {
        $this->getJson('/api/v1/documents', $this->enTetes('fct_valeur-inventee'))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Jeton invalide ou révoqué.');
    }

    #[Test]
    public function un_jeton_revoque_ne_fonctionne_plus(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        [$jeton, $valeur] = JetonApi::creerPour($entreprise, 'à révoquer');

        $this->getJson('/api/v1/documents', $this->enTetes($valeur))->assertOk();

        $jeton->revoquer();

        $this->getJson('/api/v1/documents', $this->enTetes($valeur))->assertStatus(401);
    }

    #[Test]
    public function la_valeur_du_jeton_n_est_pas_stockee(): void
    {
        $entreprise = $this->entrepriseEnRegle();
        [$jeton, $valeur] = JetonApi::creerPour($entreprise, 'essai');

        // Seule l'empreinte est en base. Une fuite de la table ne donne donc
        // rien d'utilisable : on ne remonte pas d'un SHA-256 à son entrée.
        $this->assertNotSame($valeur, $jeton->empreinte);
        $this->assertSame(hash('sha256', $valeur), $jeton->empreinte);
        $this->assertDatabaseMissing('jetons_api', ['empreinte' => $valeur]);
    }

    #[Test]
    public function un_jeton_ne_voit_que_les_documents_de_son_entreprise(): void
    {
        [$atlas, $jetonAtlas] = $this->entrepriseAvecJeton();
        $concurrent = $this->entrepriseEnRegle();

        app(EntrepriseCourante::class)->definir($atlas->id);
        $this->emettre($this->brouillon($this->clientDe($atlas)));

        app(EntrepriseCourante::class)->definir($concurrent->id);
        $cheIlConcurrent = $this->emettre($this->brouillon($this->clientDe($concurrent)));

        $reponse = $this->getJson('/api/v1/documents', $this->enTetes($jetonAtlas))->assertOk();

        $this->assertCount(1, $reponse->json('donnees'));

        // Et l'identifiant du voisin, demandé nommément, reste introuvable :
        // la portée globale ne résout pas le document, donc la route rend 404.
        $this->getJson('/api/v1/documents/'.$cheIlConcurrent->id, $this->enTetes($jetonAtlas))
            ->assertStatus(404);
    }

    #[Test]
    public function on_cree_un_brouillon_puis_on_l_emet(): void
    {
        [$entreprise, $jeton] = $this->entrepriseAvecJeton();
        app(EntrepriseCourante::class)->definir($entreprise->id);
        $client = $this->clientDe($entreprise);

        $creation = $this->postJson('/api/v1/documents', [
            'type' => 'FACTURE',
            'client_id' => $client->id,
            'date_emission' => now()->toDateString(),
            'objet' => 'Prestation de conseil',
            'lignes' => [
                ['designation' => 'Audit', 'quantite' => 2, 'prix_unitaire_ht' => 3000, 'taux_tva' => 20],
            ],
        ], $this->enTetes($jeton))->assertStatus(201);

        // Un brouillon n'a pas de numéro : il n'a pas encore pris sa place.
        $creation->assertJsonPath('donnees.numero', null);
        $creation->assertJsonPath('donnees.statut', 'BROUILLON');
        // Les montants voyagent en texte, pas en flottant.
        $creation->assertJsonPath('donnees.montant_ht', '6000.00');
        $creation->assertJsonPath('donnees.montant_ttc', '7200.00');

        $id = $creation->json('donnees.id');

        $emission = $this->postJson('/api/v1/documents/'.$id.'/emettre', [], $this->enTetes($jeton))
            ->assertOk();

        $this->assertNotNull($emission->json('donnees.numero'));
        $this->assertSame('EMIS', $emission->json('donnees.statut'));
    }

    #[Test]
    public function creer_et_emettre_tient_en_un_seul_appel(): void
    {
        [$entreprise, $jeton] = $this->entrepriseAvecJeton();
        app(EntrepriseCourante::class)->definir($entreprise->id);
        $client = $this->clientDe($entreprise);

        $this->postJson('/api/v1/documents', [
            'type' => 'FACTURE',
            'client_id' => $client->id,
            'date_emission' => now()->toDateString(),
            'emettre' => true,
            'lignes' => [
                ['designation' => 'Vente comptoir', 'quantite' => 1, 'prix_unitaire_ht' => 250, 'taux_tva' => 20],
            ],
        ], $this->enTetes($jeton))
            ->assertStatus(201)
            ->assertJsonPath('donnees.statut', 'EMIS')
            ->assertJsonPath('donnees.montant_ttc', '300.00');
    }

    #[Test]
    public function on_ne_peut_pas_facturer_le_client_d_une_autre_entreprise(): void
    {
        [$atlas, $jetonAtlas] = $this->entrepriseAvecJeton();
        $concurrent = $this->entrepriseEnRegle();

        app(EntrepriseCourante::class)->definir($concurrent->id);
        $clientDuConcurrent = $this->clientDe($concurrent);

        // Identifiant valide, mais d'un carnet d'adresses qui n'est pas le
        // nôtre : c'est la faille que `exists:clients,id` seul laisserait.
        $this->postJson('/api/v1/documents', [
            'type' => 'FACTURE',
            'client_id' => $clientDuConcurrent->id,
            'date_emission' => now()->toDateString(),
            'lignes' => [
                ['designation' => 'Essai', 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 20],
            ],
        ], $this->enTetes($jetonAtlas))
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_id');
    }

    #[Test]
    public function un_taux_de_tva_hors_bareme_est_refuse(): void
    {
        [$entreprise, $jeton] = $this->entrepriseAvecJeton();
        app(EntrepriseCourante::class)->definir($entreprise->id);
        $client = $this->clientDe($entreprise);

        $this->postJson('/api/v1/documents', [
            'type' => 'FACTURE',
            'client_id' => $client->id,
            'date_emission' => now()->toDateString(),
            'lignes' => [
                ['designation' => 'Essai', 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 18],
            ],
        ], $this->enTetes($jeton))
            ->assertStatus(422)
            ->assertJsonValidationErrors('lignes.0.taux_tva');
    }

    #[Test]
    public function le_pdf_se_telecharge_par_l_api(): void
    {
        [$entreprise, $jeton] = $this->entrepriseAvecJeton();
        app(EntrepriseCourante::class)->definir($entreprise->id);
        $facture = $this->emettre($this->brouillon($this->clientDe($entreprise)));

        $reponse = $this->get('/api/v1/documents/'.$facture->id.'/pdf', $this->enTetes($jeton))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString($facture->reference.'.pdf', $reponse->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $reponse->getContent());
    }

    #[Test]
    public function le_carnet_d_adresses_est_lisible_et_cloisonne(): void
    {
        [$atlas, $jetonAtlas] = $this->entrepriseAvecJeton();
        $concurrent = $this->entrepriseEnRegle();

        app(EntrepriseCourante::class)->definir($atlas->id);
        $this->clientDe($atlas, ['nom' => 'Client d\'Atlas']);

        app(EntrepriseCourante::class)->definir($concurrent->id);
        $this->clientDe($concurrent, ['nom' => 'Client du concurrent']);

        $reponse = $this->getJson('/api/v1/clients', $this->enTetes($jetonAtlas))->assertOk();

        $this->assertSame(['Client d\'Atlas'], array_column($reponse->json('donnees'), 'nom'));
    }
}
