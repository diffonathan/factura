<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Facturation\ModePaiement;
use App\Facturation\StatutDocument;
use App\Facturation\TypeDocument;
use App\Models\Entreprise;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La logique qui ne touche pas à la base.
 *
 * Ces tests héritent de `PHPUnit\Framework\TestCase` et non de celui de
 * Laravel : ils n'amorcent pas l'application, ne se connectent à rien, et
 * tournent en quelques millisecondes. Tout le reste de la suite est dans
 * `tests/Feature`, contre un vrai PostgreSQL, parce que l'essentiel de ce que
 * ce projet garantit est écrit en base et ne peut pas être vérifié autrement.
 *
 * La séparation est délibérée : elle dit où chercher. Un échec ici est une
 * erreur de raisonnement ; un échec là-bas peut aussi être un schéma qui a
 * bougé.
 */
final class LogiqueMetierTest extends TestCase
{
    #[Test]
    public function les_prefixes_correspondent_a_ceux_que_la_base_calcule(): void
    {
        // La colonne calculée `documents.reference` écrit ces trois préfixes.
        // Si l'un change d'un côté sans l'autre, une référence affichée dans
        // l'interface ne correspondrait plus à celle imprimée sur le document.
        $this->assertSame('DV', TypeDocument::Devis->prefixe());
        $this->assertSame('FA', TypeDocument::Facture->prefixe());
        $this->assertSame('AV', TypeDocument::Avoir->prefixe());
    }

    #[Test]
    public function un_devis_nest_pas_encaissable(): void
    {
        // Un devis n'est pas une créance. Le trigger `paiements_document_valide`
        // dit la même chose en base ; ce test garde les deux d'accord.
        $this->assertFalse(TypeDocument::Devis->estEncaissable());
        $this->assertTrue(TypeDocument::Facture->estEncaissable());
        $this->assertTrue(TypeDocument::Avoir->estEncaissable());
    }

    /**
     * Le pendant de la contrainte `documents_statut`.
     *
     * Les deux listes doivent rester identiques : la base est l'autorité, mais
     * l'application doit pouvoir vérifier AVANT d'écrire plutôt que de
     * traduire une erreur après coup.
     */
    #[Test]
    public function chaque_type_naccepte_que_ses_propres_statuts(): void
    {
        $attendus = [
            'DEVIS' => ['BROUILLON', 'EMIS', 'ACCEPTE', 'REFUSE', 'EXPIRE'],
            'FACTURE' => ['BROUILLON', 'EMIS', 'SOLDE', 'ANNULE'],
            'AVOIR' => ['BROUILLON', 'EMIS', 'SOLDE'],
        ];

        foreach (TypeDocument::cases() as $type) {
            $applicables = array_values(array_map(
                fn (StatutDocument $statut) => $statut->value,
                array_filter(
                    StatutDocument::cases(),
                    fn (StatutDocument $statut) => $statut->applicableA($type),
                ),
            ));

            sort($applicables);
            $voulus = $attendus[$type->value];
            sort($voulus);

            $this->assertSame($voulus, $applicables, "Statuts de {$type->value}");
        }
    }

    #[Test]
    public function une_facture_ne_sannule_pas_et_un_devis_ne_se_solde_pas(): void
    {
        // Les deux confusions naturelles, nommées : une facture se corrige par
        // un avoir et ne « s'annule » que lorsqu'un avoir la couvre entièrement ;
        // un devis n'a rien à encaisser, donc rien à solder.
        $this->assertFalse(StatutDocument::Solde->applicableA(TypeDocument::Devis));
        $this->assertFalse(StatutDocument::Accepte->applicableA(TypeDocument::Facture));
        $this->assertFalse(StatutDocument::Annule->applicableA(TypeDocument::Avoir));
    }

    #[Test]
    public function les_mentions_legales_sassemblent_dans_lordre_attendu(): void
    {
        $entreprise = new Entreprise([
            'raison_sociale' => 'Atlas Numérique',
            'ice' => '002458971000064',
            'identifiant_fiscal' => '40287531',
            'registre_commerce' => '418725',
        ]);

        $this->assertSame([
            'ICE : 002458971000064',
            'IF : 40287531',
            'RC : 418725',
        ], $entreprise->mentionsLegales());
    }

    #[Test]
    public function une_mention_absente_ne_laisse_pas_de_trou(): void
    {
        $entreprise = new Entreprise([
            'raison_sociale' => 'Atelier sans patente',
            'ice' => '002458971000064',
        ]);

        // Pas de « IF :  » vide sur le pied de page. `array_values` après le
        // filtrage garantit aussi une liste sans index manquant, sans quoi la
        // sérialisation JSON produirait un objet au lieu d'un tableau.
        $this->assertSame(['ICE : 002458971000064'], $entreprise->mentionsLegales());
    }

    #[Test]
    public function les_mentions_manquantes_sont_nommees_pour_letre_humain(): void
    {
        $incomplete = new Entreprise(['raison_sociale' => 'Nouvelle entreprise']);

        $this->assertSame([
            'ICE',
            'identifiant fiscal',
            'registre de commerce',
            'adresse',
        ], $incomplete->mentionsManquantes());

        $complete = new Entreprise([
            'raison_sociale' => 'Atlas Numérique',
            'ice' => '002458971000064',
            'identifiant_fiscal' => '40287531',
            'registre_commerce' => '418725',
            'adresse' => '14, rue Ibn Batouta',
        ]);

        $this->assertSame([], $complete->mentionsManquantes());
    }

    #[Test]
    public function les_modes_qui_portent_une_reference_sont_ceux_quon_retrouve_en_banque(): void
    {
        // Chèque, virement et effet laissent une trace sur le relevé : leur
        // référence permet le rapprochement. Les espèces, non.
        $this->assertTrue(ModePaiement::Cheque->attendUneReference());
        $this->assertTrue(ModePaiement::Virement->attendUneReference());
        $this->assertTrue(ModePaiement::Effet->attendUneReference());
        $this->assertFalse(ModePaiement::Especes->attendUneReference());
    }
}
