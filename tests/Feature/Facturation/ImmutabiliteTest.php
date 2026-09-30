<?php

declare(strict_types=1);

namespace Tests\Feature\Facturation;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConstruitDesDocuments;
use Tests\TestCase;

/**
 * Un document émis est figé.
 *
 * Ces tests attaquent la base directement, sans passer par les services. Ce
 * n'est pas de la paranoïa : le jour où il faudra une commande d'import, une
 * reprise de données ou un correctif appliqué à la main, ce chemin-là existera
 * pour de vrai. Ce qu'on vérifie ici, c'est que la protection tient même quand
 * l'application est contournée.
 */
final class ImmutabiliteTest extends TestCase
{
    use ConstruitDesDocuments;
    use RefreshDatabase;

    #[Test]
    public function un_brouillon_se_modifie_librement(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()));

        $brouillon->update(['objet' => 'Objet corrigé']);

        $this->assertSame('Objet corrigé', $brouillon->refresh()->objet);
    }

    #[Test]
    public function un_document_emis_ne_se_reecrit_pas(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        // Relevé avant la tentative : `update()` pose la valeur sur l'objet en
        // mémoire AVANT d'écrire, et la garde même quand l'écriture est
        // refusée. Comparer `$facture->objet` après coup comparerait la
        // tentative à elle-même.
        $objetOrigine = $facture->objet;

        $this->assertRefuseParLaBase(
            '90002',
            fn () => $facture->update(['objet' => 'Objet réécrit après coup']),
            'L\'objet d\'une facture émise a pu être modifié.',
        );

        $this->assertSame($objetOrigine, $facture->fresh()->objet);
    }

    #[Test]
    public function un_document_emis_ne_se_supprime_pas(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        $this->assertRefuseParLaBase(
            '90003',
            fn () => $facture->delete(),
            'Une facture émise a pu être supprimée : la série aurait un trou.',
        );

        $this->assertDatabaseHas('documents', ['id' => $facture->id]);
    }

    #[Test]
    public function les_lignes_dun_document_emis_sont_figees(): void
    {
        $client = $this->clientDe($this->entrepriseEnRegle());
        $facture = $this->emettre($this->brouillon($client));
        $ligne = $facture->lignes->first();

        // Sans cette protection, l'immutabilité des totaux serait contournable
        // en une requête : modifier une ligne, et laisser le trigger de
        // recalcul réécrire les montants de la facture.
        $tentatives = [
            'modifier une ligne' => fn () => $ligne->update(['prix_unitaire_ht' => 1]),
            'supprimer une ligne' => fn () => $ligne->delete(),
            'ajouter une ligne' => fn () => $facture->lignes()->create([
                'position' => 99,
                'designation' => 'Ligne ajoutée après émission',
                'quantite' => 1,
                'prix_unitaire_ht' => 5000,
                'taux_tva' => 20,
            ]),
        ];

        foreach ($tentatives as $quoi => $tentative) {
            $this->assertRefuseParLaBase('90004', $tentative, "La base a laissé {$quoi} d'une facture émise.");
        }

        $this->assertSame('1200.00', $facture->refresh()->montant_ttc);
    }

    #[Test]
    public function le_statut_et_lecheance_restent_modifiables(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        // Une facture se solde, un report d'échéance se négocie, une note
        // interne n'est pas imprimée : rien de tout cela ne change ce que le
        // client a reçu.
        $facture->update([
            'date_echeance' => $facture->date_emission->copy()->addDays(90),
            'notes_internes' => 'Report accordé par téléphone le 12.',
        ]);

        $facture->refresh();

        $this->assertSame(90, (int) $facture->date_emission->diffInDays($facture->date_echeance));
        $this->assertSame('Report accordé par téléphone le 12.', $facture->notes_internes);
    }

    #[Test]
    public function le_numero_dun_document_emis_ne_se_renumerote_pas(): void
    {
        $client = $this->clientDe($this->entrepriseEnRegle());
        $this->emettre($this->brouillon($client));
        $seconde = $this->emettre($this->brouillon($client));

        // Les deux protections se recouvrent ici, et c'est voulu : le trigger
        // de contiguïté attend 3, celui d'immutabilité refuse qu'on touche au
        // numéro. Le premier à parler suffit.
        $this->assertRefuseParLaBase(
            ['90001', '90002'],
            fn () => DB::update('UPDATE documents SET numero = 7 WHERE id = :id', ['id' => $seconde->id]),
            'Le numéro d\'une facture émise a pu être changé.',
        );

        $this->assertSame(2, $seconde->refresh()->numero);
    }

    #[Test]
    public function un_client_facture_ne_se_supprime_pas(): void
    {
        $client = $this->clientDe($this->entrepriseEnRegle());
        $this->emettre($this->brouillon($client));

        // Une facture doit rester lisible dix ans, destinataire compris. La
        // clé étrangère est en RESTRICT, pas en CASCADE : supprimer le client
        // emporterait ses factures avec lui.
        $this->assertRefuseParLaBase(
            '23503',
            fn () => $client->delete(),
            'Un client qui a été facturé a pu être supprimé.',
        );

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    #[Test]
    public function un_brouillon_se_supprime_avec_ses_lignes(): void
    {
        $brouillon = $this->brouillon($this->clientDe($this->entrepriseEnRegle()));
        $idLigne = $brouillon->lignes->first()->id;

        $brouillon->delete();

        // La cascade traverse le trigger `lignes_figees` sans se faire
        // arrêter : le document parent est déjà parti, donc sans numéro.
        $this->assertDatabaseMissing('documents', ['id' => $brouillon->id]);
        $this->assertDatabaseMissing('lignes', ['id' => $idLigne]);
    }

    #[Test]
    public function la_reference_reste_calculee_et_non_modifiable(): void
    {
        $facture = $this->emettre($this->brouillon($this->clientDe($this->entrepriseEnRegle())));

        $this->expectException(QueryException::class);

        DB::update('UPDATE documents SET reference = :faux WHERE id = :id', [
            'faux' => 'FA-1999-0001',
            'id' => $facture->id,
        ]);
    }
}
