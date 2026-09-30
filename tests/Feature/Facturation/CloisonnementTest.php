<?php

declare(strict_types=1);

namespace Tests\Feature\Facturation;

use App\Models\Client;
use App\Models\Document;
use App\Support\EntrepriseCourante;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\ConstruitDesDocuments;
use Tests\TestCase;

/**
 * Le cloisonnement entre entreprises.
 *
 * C'est le test qu'on n'a pas envie d'écrire et qu'il faut écrire : une fuite
 * ici, et le chiffre d'affaires d'un cabinet s'affiche chez un concurrent. Le
 * filtre est appliqué par les modèles eux-mêmes, donc un contrôleur ne peut pas
 * l'oublier ; ces tests vérifient qu'il n'y a pas de porte de côté.
 */
final class CloisonnementTest extends TestCase
{
    use ConstruitDesDocuments;
    use RefreshDatabase;

    #[Test]
    public function une_entreprise_ne_voit_que_ses_clients(): void
    {
        $atlas = $this->entrepriseEnRegle();
        $concurrent = $this->entrepriseEnRegle();

        $this->clientDe($atlas, ['nom' => 'Client d\'Atlas']);
        $this->clientDe($concurrent, ['nom' => 'Client du concurrent']);

        app(EntrepriseCourante::class)->definir($atlas->id);

        $this->assertSame(['Client d\'Atlas'], Client::pluck('nom')->all());
    }

    #[Test]
    public function une_entreprise_ne_voit_que_ses_documents(): void
    {
        $atlas = $this->entrepriseEnRegle();
        $concurrent = $this->entrepriseEnRegle();

        $sienne = $this->emettre($this->brouillon($this->clientDe($atlas)));
        $autre = $this->emettre($this->brouillon($this->clientDe($concurrent)));

        app(EntrepriseCourante::class)->definir($atlas->id);

        $this->assertSame([$sienne->id], Document::pluck('id')->all());

        // Et l'accès direct par identifiant ne contourne rien : c'est le cas
        // qu'on oublie, parce qu'on croit que seule la liste est filtrée.
        $this->expectException(ModelNotFoundException::class);
        Document::findOrFail($autre->id);
    }

    #[Test]
    public function une_creation_recoit_lentreprise_courante(): void
    {
        $atlas = $this->entrepriseEnRegle();

        app(EntrepriseCourante::class)->definir($atlas->id);

        // `entreprise_id` n'est volontairement PAS dans `$fillable` : une
        // requête HTTP qui l'enverrait pourrait déplacer un client chez une
        // autre entreprise. Le trait le pose lui-même, ce que ce test vérifie.
        $client = Client::create([
            'nom' => 'Menuiserie Zerhouni',
            'ice' => '001234567000078',
        ]);

        // L'appelant n'a pas fourni `entreprise_id`, et pourtant la ligne est
        // au bon endroit. Sans cela on créerait des données correctement
        // invisibles mais rattachées à la mauvaise entreprise.
        $this->assertSame($atlas->id, $client->entreprise_id);
    }

    #[Test]
    public function une_creation_sans_contexte_est_refusee(): void
    {
        app(EntrepriseCourante::class)->oublier();

        // Échouer bruyamment vaut mieux que créer la facture dans
        // l'entreprise n° 1 par défaut.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/contexte d\'entreprise/');

        Client::create(['nom' => 'Client sans entreprise']);
    }

    #[Test]
    public function le_balayage_planifie_traverse_les_cloisons_explicitement(): void
    {
        $atlas = $this->entrepriseEnRegle();
        $concurrent = $this->entrepriseEnRegle();

        $this->emettre($this->brouillon($this->clientDe($atlas)));
        $this->emettre($this->brouillon($this->clientDe($concurrent)));

        app(EntrepriseCourante::class)->definir($atlas->id);

        $this->assertSame(1, Document::count());

        // Le relanceur nocturne doit voir les impayés de tout le monde. Il le
        // demande par son nom, ce qui rend l'exception visible en relecture.
        $this->assertSame(2, Document::sansCloisonnement()->count());
    }

    #[Test]
    public function deux_entreprises_peuvent_avoir_le_meme_client(): void
    {
        $premiere = $this->entrepriseEnRegle();
        $seconde = $this->entrepriseEnRegle();

        // L'unicité du nom et de l'ICE est cadrée par l'entreprise. Un gros
        // donneur d'ordre est le client de tout le monde — l'unicité globale
        // rendrait le produit inutilisable dès le deuxième inscrit.
        $this->clientDe($premiere, ['nom' => 'OCP SA', 'ice' => '000123456000011']);
        $this->clientDe($seconde, ['nom' => 'OCP SA', 'ice' => '000123456000011']);

        $this->assertSame(2, Client::sansCloisonnement()->where('nom', 'OCP SA')->count());
    }

    #[Test]
    public function un_client_ne_peut_pas_etre_cree_deux_fois_dans_la_meme_entreprise(): void
    {
        $atlas = $this->entrepriseEnRegle();
        $this->clientDe($atlas, ['nom' => 'Sonacos']);

        // Casse différente, même client : l'index est posé sur `lower(nom)`.
        $this->assertRefuseParLaBase(
            '23505',
            fn () => $this->clientDe($atlas, ['nom' => 'SONACOS']),
            'Le même client a pu être créé deux fois dans la même entreprise.',
        );
    }

    #[Test]
    public function un_particulier_ne_peut_pas_porter_un_ice(): void
    {
        $atlas = $this->entrepriseEnRegle();

        $this->assertRefuseParLaBase(
            '23514',
            fn () => Client::factory()->create([
                'entreprise_id' => $atlas->id,
                'nom' => 'Karim Benali',
                'est_particulier' => true,
                'ice' => '001234567000078',
            ]),
        );
    }

    #[Test]
    public function un_ice_mal_forme_est_refuse(): void
    {
        $atlas = $this->entrepriseEnRegle();

        // L'ICE fait exactement quinze chiffres. Quatorze, des lettres, ou
        // seize : dans les trois cas la facture serait irrégulière, et mieux
        // vaut l'arrêter à la saisie qu'à un contrôle fiscal.
        //
        // Deux codes acceptés, parce que deux protections se recouvrent : le
        // CHECK `clients_ice_format` (23514) attrape la longueur courte et les
        // lettres, et le type `character(15)` refuse le seizième caractère
        // avant même d'arriver au CHECK (22001).
        foreach (['00123456700007', 'ABC234567000078', '0012345670000789'] as $mauvais) {
            $this->assertRefuseParLaBase(
                ['23514', '22001'],
                fn () => Client::factory()->create([
                    'entreprise_id' => $atlas->id,
                    'nom' => 'Client '.$mauvais,
                    'ice' => $mauvais,
                ]),
                "L'ICE « {$mauvais} » a été accepté.",
            );
        }
    }
}
