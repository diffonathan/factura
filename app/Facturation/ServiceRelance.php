<?php

declare(strict_types=1);

namespace App\Facturation;

use App\Models\Document;
use App\Models\Relance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Les relances d'impayés.
 *
 * Le barème est délibérément doux au début et ferme à la fin : la plupart des
 * retards sont des oublis, et traiter un bon client comme un mauvais payeur au
 * huitième jour coûte plus que la facture.
 *
 * Le point technique est ailleurs. Une file d'attente ne promet pas « au plus
 * une fois » mais « au moins une fois » : un travail dont l'accusé de réception
 * se perd est rejoué. Le travail ne peut donc pas se contenter de vérifier
 * avant d'envoyer — la vérification serait rejouée elle aussi. Il doit
 * RÉSERVER, et c'est la base qui tranche, par l'index unique
 * (document_id, niveau).
 *
 * C'est exactement la leçon d'un projet précédent où la garde d'idempotence
 * était écrite avec un `save()` : elle passait par un UPDATE et ne déclenchait
 * jamais la contrainte. Elle n'a rien empêché pendant des semaines, et on ne
 * l'a vu qu'en rejouant les messages à la main. D'où l'`ON CONFLICT DO
 * NOTHING` explicite ci-dessous, et le test qui rejoue le travail.
 */
final class ServiceRelance
{
    /** Jours de retard à partir desquels chaque niveau se déclenche. */
    public const BAREME = [
        1 => 7,
        2 => 21,
        3 => 45,
    ];

    /**
     * Réserve un niveau de relance pour cette facture.
     *
     * Rend la relance si le niveau vient d'être pris, et `null` s'il l'était
     * déjà — ce qui est la réponse normale à un travail rejoué, pas une erreur.
     * L'appelant n'envoie que s'il a obtenu la réservation.
     */
    public function reserverNiveau(Document $facture, int $niveau): ?Relance
    {
        $facture->loadMissing('client');

        $prises = DB::affectingStatement(<<<'SQL'
            INSERT INTO relances (document_id, niveau, statut, canal, destinataire,
                                  traitee_le, created_at, updated_at)
                 VALUES (:document, :niveau, 'ENVOYEE', 'EMAIL', :destinataire,
                         now(), now(), now())
            ON CONFLICT (document_id, niveau) DO NOTHING
        SQL, [
            'document' => $facture->id,
            'niveau' => $niveau,
            'destinataire' => $facture->client->email,
        ]);

        if ($prises === 0) {
            return null;
        }

        return Relance::where('document_id', $facture->id)
            ->where('niveau', $niveau)
            ->first();
    }

    /**
     * Le niveau que cette facture mérite aujourd'hui, ou null si elle n'a rien
     * à recevoir : pas en retard, déjà soldée, ou niveau déjà atteint.
     */
    public function niveauAttendu(Document $facture): ?int
    {
        if (! $facture->estEnRetard()) {
            return null;
        }

        $facture->loadMissing('relances');

        $retard = $facture->joursDeRetard();
        $dejaFaits = $facture->relances()->where('statut', 'ENVOYEE')->pluck('niveau')->all();

        // Du plus grave au plus léger : une facture oubliée trois mois passe
        // directement à la mise en demeure plutôt que d'enchaîner trois
        // courriers en trois jours.
        foreach (array_reverse(self::BAREME, true) as $niveau => $seuil) {
            if ($retard >= $seuil && ! in_array($niveau, $dejaFaits, true)) {
                return $niveau;
            }
        }

        return null;
    }

    /**
     * Les factures à relancer, toutes entreprises confondues.
     *
     * `sansCloisonnement()` est ici légitime et nommé : ce balayage tourne dans
     * le planificateur, sans utilisateur ni entreprise courante. C'est le seul
     * endroit du projet qui traverse les cloisons, et il le dit.
     *
     * @return Collection<int, Document>
     */
    public function facturesARelancer(): Collection
    {
        return Document::sansCloisonnement()
            ->enRetard(min(self::BAREME))
            ->with(['client', 'relances', 'entreprise'])
            ->get()
            ->filter(fn (Document $facture) => $this->niveauAttendu($facture) !== null)
            ->values();
    }

    /** Consigne un échec d'envoi sur un niveau déjà réservé. */
    public function marquerEnEchec(Relance $relance, string $raison): void
    {
        $relance->update([
            'statut' => 'ECHEC',
            'erreur' => mb_substr($raison, 0, 2000),
        ]);
    }
}
