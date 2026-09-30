<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Facturation\ModePaiement;
use App\Facturation\ServiceEmission;
use App\Facturation\ServiceEncaissement;
use App\Facturation\TypeDocument;
use App\Models\Client;
use App\Models\Document;
use App\Models\Entreprise;
use App\Models\Ligne;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Le jeu de démonstration.
 *
 * Toutes les dates sont calculées DEPUIS AUJOURD'HUI, jamais écrites en dur.
 * La raison est vécue : sur un projet précédent, les données de démonstration
 * plaçaient les créneaux entre 9 h et 13 h, et une démonstration faite l'après-
 * midi s'ouvrait sur un écran vide. Ici, il y a toujours des factures en
 * retard de 8, 25 et 60 jours — donc toujours les trois niveaux de relance à
 * montrer, quel que soit le jour où l'on ouvre l'application.
 *
 * Les documents passent par `ServiceEmission`, pas par des écritures directes.
 * Le jeu de démonstration emprunte donc exactement le chemin de production :
 * s'il se construit, c'est que la numérotation, les contraintes et les
 * triggers l'ont tous accepté. C'est un test de bout en bout déguisé en
 * commande de confort.
 */
final class DemonstrationSeeder extends Seeder
{
    private ServiceEmission $emission;

    private ServiceEncaissement $encaissement;

    public function run(): void
    {
        $this->emission = app(ServiceEmission::class);
        $this->encaissement = app(ServiceEncaissement::class);

        $entreprise = $this->entreprise();
        $this->utilisateurs($entreprise);
        $clients = $this->clients($entreprise);

        $this->factureSoldee($clients['ocp']);
        $this->facturePartiellementPayee($clients['riad']);
        $this->factureEnRetard($clients['menuiserie'], 8);
        $this->factureEnRetard($clients['pharmacie'], 25);
        $this->factureEnRetard($clients['transport'], 62);
        $this->factureAJour($clients['ocp']);
        $this->devisEnAttente($clients['riad']);
        $this->devisConvertiEnFacture($clients['transport']);
        $this->factureAvecAvoir($clients['pharmacie']);
        $this->brouillonEnCours($clients['menuiserie']);

        $this->command?->info('Jeu de démonstration prêt : '.$entreprise->raison_sociale);
        $this->command?->line('  Connexion : demo@factura.ma / demonstration');
    }

    private function entreprise(): Entreprise
    {
        return Entreprise::create([
            'raison_sociale' => 'Atlas Numérique',
            'forme_juridique' => 'SARL',
            'ice' => '002458971000064',
            'identifiant_fiscal' => '40287531',
            'registre_commerce' => '418725',
            'taxe_professionnelle' => '30124587',
            'cnss' => '9845127',
            'adresse' => '14, rue Ibn Batouta, résidence Al Manar, 3e étage',
            'ville' => 'Casablanca',
            'telephone' => '+212 522 47 18 93',
            'email' => 'facturation@atlas-numerique.ma',
            'site_web' => 'https://atlas-numerique.ma',
            'banque' => 'Attijariwafa Bank — agence Maârif',
            'rib' => '007780000123456789012345',
            'regime_tva' => 'DEBIT',
        ]);
    }

    private function utilisateurs(Entreprise $entreprise): void
    {
        $proprietaire = User::create([
            'name' => 'Salma Bennani',
            'email' => 'demo@factura.ma',
            'password' => Hash::make('demonstration'),
            'email_verified_at' => now(),
        ]);

        $comptable = User::create([
            'name' => 'Youssef El Amrani',
            'email' => 'comptable@factura.ma',
            'password' => Hash::make('demonstration'),
            'email_verified_at' => now(),
        ]);

        $entreprise->utilisateurs()->attach([
            $proprietaire->id => ['role' => 'PROPRIETAIRE'],
            $comptable->id => ['role' => 'COMPTABLE'],
        ]);
    }

    /** @return array<string, Client> */
    private function clients(Entreprise $entreprise): array
    {
        $definitions = [
            'ocp' => [
                'nom' => 'Groupe Chérifien de Distribution',
                'ice' => '000078451000023',
                'identifiant_fiscal' => '10245876',
                'adresse' => 'Zone industrielle Sidi Maârouf, lot 42',
                'ville' => 'Casablanca',
                'email' => 'comptabilite@gcd-distribution.ma',
                'telephone' => '+212 522 58 74 12',
                'delai_paiement_jours' => 60,
            ],
            'riad' => [
                'nom' => 'Riad Dar Zaytoun',
                'ice' => '001547823000047',
                'identifiant_fiscal' => '25874196',
                'adresse' => '7, derb Sidi Bouloukat, médina',
                'ville' => 'Marrakech',
                'email' => 'direction@darzaytoun.ma',
                'telephone' => '+212 524 38 91 05',
                'delai_paiement_jours' => 30,
            ],
            'menuiserie' => [
                'nom' => 'Menuiserie Zerhouni',
                'ice' => '002145879000015',
                'identifiant_fiscal' => '18457236',
                'adresse' => '92, avenue Hassan II',
                'ville' => 'Fès',
                'email' => 'contact@menuiserie-zerhouni.ma',
                'telephone' => '+212 535 62 47 80',
                'delai_paiement_jours' => 30,
            ],
            'pharmacie' => [
                'nom' => 'Pharmacie Centrale Agdal',
                'ice' => '001987456000031',
                'identifiant_fiscal' => '31547829',
                'adresse' => '3, avenue de France, Agdal',
                'ville' => 'Rabat',
                'email' => 'gestion@pharmacie-agdal.ma',
                'telephone' => '+212 537 67 21 44',
                'delai_paiement_jours' => 30,
            ],
            'transport' => [
                'nom' => 'Sahara Logistics',
                'ice' => '002874159000078',
                'identifiant_fiscal' => '47821563',
                'adresse' => 'Route de Tiznit, km 4',
                'ville' => 'Agadir',
                'email' => 'admin@sahara-logistics.ma',
                'telephone' => '+212 528 84 17 62',
                'delai_paiement_jours' => 45,
            ],
        ];

        $clients = [];

        foreach ($definitions as $cle => $attributs) {
            $client = new Client($attributs);
            $client->entreprise_id = $entreprise->id;
            $client->save();

            $clients[$cle] = $client;
        }

        // Un particulier, pour montrer le cas sans ICE.
        $particulier = new Client([
            'nom' => 'Karim Benali',
            'est_particulier' => true,
            'ville' => 'Casablanca',
            'email' => 'k.benali@exemple.ma',
            'delai_paiement_jours' => 0,
        ]);
        $particulier->entreprise_id = $entreprise->id;
        $particulier->save();

        $clients['particulier'] = $particulier;

        return $clients;
    }

    // ------------------------------------------------------------------
    // Les scénarios
    // ------------------------------------------------------------------

    private function factureSoldee(Client $client): void
    {
        $facture = $this->emettre($client, TypeDocument::Facture, now()->subDays(75), [
            ['designation' => 'Refonte du site institutionnel', 'quantite' => 1, 'prix_unitaire_ht' => 68000, 'taux_tva' => 20],
            ['designation' => 'Rédaction des contenus', 'unite' => 'page', 'quantite' => 24, 'prix_unitaire_ht' => 450, 'taux_tva' => 20],
        ], 'Refonte du site institutionnel');

        $this->encaissement->enregistrer($facture, [
            'montant' => '30000.00',
            'mode' => ModePaiement::Virement->value,
            'reference' => 'VIR-AWB-884512',
            'date_paiement' => now()->subDays(60)->toDateString(),
        ]);

        $this->encaissement->solder($facture, ModePaiement::Virement, 'VIR-AWB-901337');
    }

    private function facturePartiellementPayee(Client $client): void
    {
        $facture = $this->emettre($client, TypeDocument::Facture, now()->subDays(18), [
            ['designation' => 'Module de réservation en ligne', 'quantite' => 1, 'prix_unitaire_ht' => 42000, 'taux_tva' => 20],
            ['designation' => 'Reportage photographique', 'unite' => 'journée', 'quantite' => 2, 'prix_unitaire_ht' => 3500, 'taux_tva' => 20],
        ], 'Module de réservation et reportage');

        // Un acompte de 40 %, réglé par chèque : l'usage le plus courant.
        $this->encaissement->enregistrer($facture, [
            'montant' => '21840.00',
            'mode' => ModePaiement::Cheque->value,
            'reference' => '4218773',
            'date_paiement' => now()->subDays(15)->toDateString(),
        ]);
    }

    private function factureEnRetard(Client $client, int $joursDeRetard): void
    {
        // La date d'émission est calculée pour que l'échéance tombe exactement
        // `$joursDeRetard` jours avant aujourd'hui, quel que soit le délai de
        // paiement du client. Les trois niveaux de relance sont donc toujours
        // représentés, sans qu'aucune date soit écrite en dur.
        $emission = now()->subDays($joursDeRetard + $client->delai_paiement_jours);

        $lignes = match (true) {
            $joursDeRetard > 45 => [
                ['designation' => 'Application de suivi de flotte', 'quantite' => 1, 'prix_unitaire_ht' => 95000, 'taux_tva' => 20],
                ['designation' => 'Formation des exploitants', 'unite' => 'journée', 'quantite' => 4, 'prix_unitaire_ht' => 4800, 'taux_tva' => 20],
            ],
            $joursDeRetard > 20 => [
                ['designation' => 'Logiciel de gestion d\'officine', 'quantite' => 1, 'prix_unitaire_ht' => 28000, 'taux_tva' => 20],
                ['designation' => 'Lecteurs de codes-barres', 'unite' => 'unité', 'quantite' => 3, 'prix_unitaire_ht' => 1250, 'taux_tva' => 20],
            ],
            default => [
                ['designation' => 'Catalogue en ligne et devis automatiques', 'quantite' => 1, 'prix_unitaire_ht' => 19500, 'taux_tva' => 20],
            ],
        };

        $this->emettre($client, TypeDocument::Facture, $emission, $lignes, 'Prestations de développement');
    }

    private function factureAJour(Client $client): void
    {
        $this->emettre($client, TypeDocument::Facture, now()->subDays(5), [
            ['designation' => 'Maintenance applicative', 'unite' => 'mois', 'quantite' => 3, 'prix_unitaire_ht' => 4500, 'taux_tva' => 20],
            ['designation' => 'Hébergement et sauvegardes', 'unite' => 'mois', 'quantite' => 3, 'prix_unitaire_ht' => 850, 'taux_tva' => 20],
        ], 'Maintenance du premier trimestre');
    }

    private function devisEnAttente(Client $client): void
    {
        $this->emettre($client, TypeDocument::Devis, now()->subDays(4), [
            ['designation' => 'Refonte de l\'identité visuelle', 'quantite' => 1, 'prix_unitaire_ht' => 22000, 'taux_tva' => 20],
            ['designation' => 'Déclinaison sur supports imprimés', 'unite' => 'support', 'quantite' => 6, 'prix_unitaire_ht' => 1800, 'taux_tva' => 20],
        ], 'Identité visuelle du riad');
    }

    private function devisConvertiEnFacture(Client $client): void
    {
        $devis = $this->emettre($client, TypeDocument::Devis, now()->subDays(40), [
            ['designation' => 'Portail client extranet', 'quantite' => 1, 'prix_unitaire_ht' => 54000, 'taux_tva' => 20],
            ['designation' => 'Passerelle avec le logiciel de transit', 'quantite' => 1, 'prix_unitaire_ht' => 18000, 'taux_tva' => 20],
        ], 'Portail client extranet');

        // La conversion rend un brouillon : c'est le comportement voulu. On
        // l'émet ensuite, comme le ferait l'utilisateur après relecture.
        $facture = $this->emission->convertirEnFacture($devis);
        $this->emission->emettre($facture);
    }

    private function factureAvecAvoir(Client $client): void
    {
        $facture = $this->emettre($client, TypeDocument::Facture, now()->subDays(30), [
            ['designation' => 'Terminaux de paiement', 'unite' => 'unité', 'quantite' => 4, 'prix_unitaire_ht' => 2900, 'taux_tva' => 20],
            ['designation' => 'Mise en service', 'quantite' => 1, 'prix_unitaire_ht' => 1600, 'taux_tva' => 20],
        ], 'Équipement d\'encaissement');

        // Un terminal rendu : l'avoir ne porte que la partie annulée. La
        // facture d'origine reste intacte et numérotée.
        $avoir = $this->emission->preparerAvoir($facture, [[
            'designation' => 'Retour d\'un terminal de paiement',
            'unite' => 'unité',
            'quantite' => 1,
            'prix_unitaire_ht' => 2900,
            'taux_tva' => 20,
        ]]);

        $this->emission->emettre($avoir);
    }

    private function brouillonEnCours(Client $client): void
    {
        $brouillon = $this->document($client, TypeDocument::Devis, now(), 'Extension de l\'atelier — chiffrage en cours');

        Ligne::creerPour($brouillon, [
            'designation' => 'Étude de faisabilité',
            'quantite' => 1,
            'prix_unitaire_ht' => 8500,
            'taux_tva' => 20,
        ]);
    }

    // ------------------------------------------------------------------

    /** @param list<array<string, mixed>> $lignes */
    private function emettre(
        Client $client,
        TypeDocument $type,
        Carbon $emission,
        array $lignes,
        string $objet,
    ): Document {
        $document = $this->document($client, $type, $emission, $objet);

        foreach ($lignes as $ligne) {
            Ligne::creerPour($document, $ligne);
        }

        return $this->emission->emettre($document->refresh());
    }

    private function document(Client $client, TypeDocument $type, Carbon $emission, string $objet): Document
    {
        $document = new Document([
            'client_id' => $client->id,
            'type' => $type->value,
            'date_emission' => $emission->toDateString(),
            'objet' => $objet,
            'conditions' => $type === TypeDocument::Devis
                ? 'Devis valable 30 jours. Acompte de 40 % à la commande.'
                : 'Paiement par virement ou chèque à l\'ordre d\'Atlas Numérique.',
        ]);

        $document->entreprise_id = $client->entreprise_id;
        $document->save();

        return $document;
    }
}
