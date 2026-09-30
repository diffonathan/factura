<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Facturation\ServiceEmission;
use App\Facturation\TypeDocument;
use App\Models\Client;
use App\Models\Document;
use App\Models\Entreprise;
use App\Models\Ligne;

/**
 * Le décor commun des tests de facturation : une entreprise en règle, un
 * client professionnel, et des brouillons prêts à être émis.
 *
 * Réunir ce montage ici évite que chaque test le refasse à sa façon — et
 * qu'un test passe pour de mauvaises raisons parce que son décor différait
 * sans qu'on l'ait voulu.
 */
trait ConstruitDesDocuments
{
    protected function entrepriseEnRegle(): Entreprise
    {
        return Entreprise::factory()->create([
            'raison_sociale' => 'Atlas Numérique SARL',
            'ville' => 'Casablanca',
        ]);
    }

    protected function clientDe(Entreprise $entreprise, array $attributs = []): Client
    {
        return Client::factory()->create($attributs + ['entreprise_id' => $entreprise->id]);
    }

    /**
     * Un brouillon avec ses lignes. Par défaut une seule ligne à 1 000,00 HT
     * et 20 % de TVA, soit 1 200,00 TTC — des chiffres ronds, pour que
     * l'assertion se lise sans calculette.
     */
    protected function brouillon(
        Client $client,
        TypeDocument $type = TypeDocument::Facture,
        array $lignes = [],
        array $attributs = [],
    ): Document {
        $document = Document::factory()->pour($client)->create($attributs + [
            'type' => $type->value,
        ]);

        $lignes = $lignes !== [] ? $lignes : [[
            'designation' => 'Développement du site vitrine',
            'quantite' => 1,
            'prix_unitaire_ht' => 1000,
            'taux_tva' => 20,
        ]];

        foreach ($lignes as $ligne) {
            Ligne::creerPour($document, $ligne);
        }

        return $document->refresh();
    }

    protected function emettre(Document $document): Document
    {
        return app(ServiceEmission::class)->emettre($document);
    }

    /**
     * Vérifie que la base refuse une écriture, avec le code attendu, SANS
     * condamner la transaction du test.
     *
     * PostgreSQL abandonne une transaction entière dès la première erreur :
     * tout ce qui suit répond « current transaction is aborted » (25P02). Comme
     * la suite tourne elle-même dans une transaction (`RefreshDatabase`), un
     * simple try/catch rendrait toutes les assertions suivantes impossibles —
     * et on croirait à un second défaut là où il n'y en a qu'un.
     *
     * Le `DB::transaction` imbriqué pose un point de sauvegarde. L'erreur n'y
     * revient qu'à ce point, et le test continue de pouvoir interroger la base.
     *
     * @param  string|list<string>  $sqlstate  le ou les codes acceptables
     */
    protected function assertRefuseParLaBase(string|array $sqlstate, callable $ecriture, string $message = ''): void
    {
        $attendus = (array) $sqlstate;

        try {
            \Illuminate\Support\Facades\DB::transaction($ecriture);

            $this->fail($message !== '' ? $message : sprintf(
                'La base a accepté une écriture qu\'elle aurait dû refuser (%s attendu).',
                implode(' ou ', $attendus),
            ));
        } catch (\Illuminate\Database\QueryException $erreur) {
            $this->assertContains(
                (string) $erreur->getCode(),
                $attendus,
                'Refus obtenu, mais pas pour la raison attendue : ' . $erreur->getMessage(),
            );
        }
    }

    /** Émet `$combien` factures d'affilée et rend leurs numéros, dans l'ordre. */
    protected function emettreDesFactures(Client $client, int $combien): array
    {
        $numeros = [];

        for ($i = 0; $i < $combien; $i++) {
            $numeros[] = $this->emettre($this->brouillon($client))->numero;
        }

        return $numeros;
    }
}
