<?php

declare(strict_types=1);

namespace Tests\Feature\Facturation;

use App\Facturation\ModePaiement;
use App\Facturation\ServiceEncaissement;
use App\Facturation\ServiceRelance;
use App\Facturation\TypeDocument;
use App\Jobs\EnvoyerRelance;
use App\Mail\RelanceImpaye;
use App\Models\Client;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\ConstruitDesDocuments;
use Tests\TestCase;

/**
 * Les relances d'impayés.
 *
 * Le test central est `le_travail_rejoue_nenvoie_quun_seul_courriel`. Il existe
 * à cause d'un défaut vécu sur un projet précédent : une garde d'idempotence
 * écrite avec un `save()` sur une entité à identifiant assigné. Elle passait
 * par un UPDATE, ne déclenchait jamais la contrainte d'unicité, et n'a donc
 * rien empêché pendant des semaines. On ne l'a découvert qu'en rejouant les
 * messages à la main.
 *
 * D'où la forme de ces tests : on ne vérifie pas que la garde existe, on
 * rejoue le travail et on compte les courriels.
 */
final class RelanceTest extends TestCase
{
    use ConstruitDesDocuments;
    use RefreshDatabase;

    private ServiceRelance $relances;

    protected function setUp(): void
    {
        parent::setUp();

        $this->relances = app(ServiceRelance::class);
        Mail::fake();
    }

    /** Une facture émise et échue depuis `$retard` jours. */
    private function factureEnRetard(int $retard): Document
    {
        $client = $this->clientDe($this->entrepriseEnRegle(), [
            // Délai nul : l'échéance tombe le jour de l'émission, et le retard
            // se lit directement sur la date choisie.
            'delai_paiement_jours' => 0,
            'email' => 'comptabilite@client-exemple.ma',
        ]);

        return $this->emettre($this->brouillon($client, attributs: [
            'date_emission' => now()->subDays($retard)->toDateString(),
        ]));
    }

    #[Test]
    public function le_bareme_choisit_le_niveau_selon_le_retard(): void
    {
        $this->assertNull($this->relances->niveauAttendu($this->factureEnRetard(3)));
        $this->assertSame(1, $this->relances->niveauAttendu($this->factureEnRetard(7)));
        $this->assertSame(1, $this->relances->niveauAttendu($this->factureEnRetard(15)));
        $this->assertSame(2, $this->relances->niveauAttendu($this->factureEnRetard(21)));
        $this->assertSame(3, $this->relances->niveauAttendu($this->factureEnRetard(60)));
    }

    #[Test]
    public function une_facture_oubliee_longtemps_ne_recoit_pas_trois_courriers_en_trois_jours(): void
    {
        // Facture retrouvée après trois mois. Le barème est parcouru du plus
        // grave au plus léger : elle passe directement à la mise en demeure,
        // au lieu d'enchaîner rappel, relance et mise en demeure en trois
        // jours — ce qu'aucun client ne comprendrait.
        $facture = $this->factureEnRetard(95);

        $this->assertSame(3, $this->relances->niveauAttendu($facture));
    }

    #[Test]
    public function un_niveau_ne_se_reserve_quune_fois(): void
    {
        $facture = $this->factureEnRetard(30);

        $premiere = $this->relances->reserverNiveau($facture, 1);
        $seconde = $this->relances->reserverNiveau($facture, 1);

        $this->assertNotNull($premiere);

        // `null`, et non une exception : un travail rejoué est le cas normal
        // d'une file d'attente, pas un incident à remonter.
        $this->assertNull($seconde);
        $this->assertSame(1, $facture->relances()->count());
    }

    /**
     * LE test. Le même travail, exécuté deux fois — exactement ce que fait une
     * file d'attente quand un accusé de réception se perd.
     */
    #[Test]
    public function le_travail_rejoue_nenvoie_quun_seul_courriel(): void
    {
        $facture = $this->factureEnRetard(30);

        $travail = new EnvoyerRelance($facture->id, 2);

        $travail->handle($this->relances);
        $travail->handle($this->relances);
        $travail->handle($this->relances);

        Mail::assertSent(RelanceImpaye::class, 1);
        $this->assertSame(1, $facture->relances()->count());
    }

    #[Test]
    public function le_courriel_part_au_bon_destinataire_avec_le_bon_niveau(): void
    {
        $facture = $this->factureEnRetard(50);

        (new EnvoyerRelance($facture->id, 3))->handle($this->relances);

        Mail::assertSent(RelanceImpaye::class, function (RelanceImpaye $courriel) use ($facture) {
            return $courriel->hasTo('comptabilite@client-exemple.ma')
                && $courriel->niveau === 3
                && $courriel->facture->is($facture);
        });
    }

    #[Test]
    public function une_facture_payee_entre_temps_nest_plus_relancee(): void
    {
        $facture = $this->factureEnRetard(30);

        // Le travail a été mis en file hier ; le client a payé ce matin.
        app(ServiceEncaissement::class)->solder($facture, ModePaiement::Virement);

        (new EnvoyerRelance($facture->id, 2))->handle($this->relances);

        // C'est la raison pour laquelle le travail transporte un identifiant et
        // relit la facture : un objet sérialisé dans la file porterait l'état
        // d'hier, et le client recevrait une relance pour une facture soldée.
        Mail::assertNothingSent();
        $this->assertSame(0, $facture->relances()->count());
    }

    #[Test]
    public function une_facture_supprimee_ne_fait_pas_echouer_le_travail(): void
    {
        $facture = $this->factureEnRetard(30);
        $id = $facture->id;

        Document::sansCloisonnement()->where('id', $id)->update(['statut' => 'ANNULE']);

        (new EnvoyerRelance($id, 2))->handle($this->relances);

        Mail::assertNothingSent();
    }

    #[Test]
    public function un_brouillon_et_un_devis_ne_sont_jamais_relances(): void
    {
        $client = $this->clientDe($this->entrepriseEnRegle(), ['delai_paiement_jours' => 0]);

        $brouillon = $this->brouillon($client, attributs: [
            'date_emission' => now()->subDays(60)->toDateString(),
        ]);

        $devis = $this->emettre($this->brouillon($client, TypeDocument::Devis, attributs: [
            'date_emission' => now()->subDays(60)->toDateString(),
        ]));

        // Un brouillon n'existe pas pour le client, et un devis n'est pas une
        // créance : ni l'un ni l'autre ne se relance.
        $this->assertNull($this->relances->niveauAttendu($brouillon));
        $this->assertNull($this->relances->niveauAttendu($devis));
        $this->assertSame(0, $this->relances->facturesARelancer()->count());
    }

    #[Test]
    public function le_balayage_ne_retient_que_ce_qui_merite_une_relance(): void
    {
        $aJour = $this->factureEnRetard(2);
        $enRetard = $this->factureEnRetard(30);
        $soldee = $this->factureEnRetard(40);

        app(ServiceEncaissement::class)->solder($soldee, ModePaiement::Especes);

        $retenues = $this->relances->facturesARelancer();

        $this->assertSame([$enRetard->id], $retenues->pluck('id')->all());
        $this->assertNotContains($aJour->id, $retenues->pluck('id')->all());
    }

    #[Test]
    public function le_balayage_traverse_toutes_les_entreprises(): void
    {
        $this->factureEnRetard(30);
        $this->factureEnRetard(30);

        // Le relanceur tourne dans le planificateur, sans utilisateur ni
        // entreprise courante. Il doit voir les impayés de tout le monde — et
        // il le demande explicitement, par `sansCloisonnement()`.
        $this->assertSame(2, $this->relances->facturesARelancer()->count());
    }

    #[Test]
    public function un_echec_denvoi_laisse_le_niveau_reserve_et_la_trace_visible(): void
    {
        $facture = $this->factureEnRetard(30);

        // On fait échouer l'envoi pour de vrai. `Mail::fake()` ne peut pas
        // servir ici : il accepte tout, y compris un destinataire absent — il
        // enregistre les envois, il n'en tente aucun. Pour éprouver le chemin
        // d'échec il faut un expéditeur qui lève, donc un mock.
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(
            new RuntimeException('Connection to smtp.exemple.ma:587 refused')
        );

        (new EnvoyerRelance($facture->id, 2))->handle($this->relances);

        $relance = $facture->relances()->first();

        // Le niveau reste pris, et l'échec est consigné. Le relâcher ferait
        // repartir le travail, et si la panne s'est produite après le départ du
        // courriel, le client en recevrait deux. « Peut-être pas envoyée, et ça
        // se voit » vaut mieux que « peut-être envoyée deux fois ».
        $this->assertNotNull($relance);
        $this->assertSame('ECHEC', $relance->statut);
        $this->assertStringContainsString('smtp.exemple.ma', $relance->erreur);
        $this->assertNull($this->relances->reserverNiveau($facture->refresh(), 2));
    }
}
