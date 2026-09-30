<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Facturation\ServiceRelance;
use App\Mail\RelanceImpaye;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envoie UNE relance pour UNE facture.
 *
 * L'ordre des opérations est tout l'intérêt de cette classe :
 *
 *   1. réserver le niveau en base ;
 *   2. n'envoyer que si la réservation a été obtenue.
 *
 * Et non l'inverse. Une file d'attente promet « au moins une fois », pas « au
 * plus une fois » : un travail dont l'accusé de réception se perd — conteneur
 * tué, réseau coupé, délai dépassé — est remis dans la file et rejoué. Un
 * travail qui envoie d'abord et note ensuite enverrait deux fois.
 *
 * Vérifier « ai-je déjà envoyé ? » avant d'envoyer ne suffit pas non plus : la
 * vérification est rejouée avec le reste, et deux exemplaires du travail la
 * passent tous les deux. Il faut une écriture que la base puisse refuser, ce
 * que fait `ServiceRelance::reserverNiveau()` avec son `ON CONFLICT DO
 * NOTHING`.
 *
 * Le document est transporté par son IDENTIFIANT, pas par l'objet. Un objet
 * sérialisé dans la file porterait l'état du monde au moment de la mise en
 * file ; le travail lira l'état au moment où il tourne. Entre les deux, le
 * client a peut-être payé.
 */
final class EnvoyerRelance implements ShouldQueue
{
    use Queueable;

    /** Trois essais, espacés : une panne de serveur d'envoi dure rarement plus. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        private readonly int $documentId,
        private readonly int $niveau,
    ) {}

    public function handle(ServiceRelance $relances): void
    {
        $facture = Document::sansCloisonnement()
            ->with(['client', 'entreprise'])
            ->find($this->documentId);

        if ($facture === null) {
            return;   // supprimée entre-temps : rien à faire, et surtout pas d'erreur
        }

        // L'état a pu changer depuis la mise en file : le client a payé, un
        // avoir a annulé la facture. On relit plutôt que de faire confiance.
        if (! $facture->estEnRetard()) {
            return;
        }

        $relance = $relances->reserverNiveau($facture, $this->niveau);

        if ($relance === null) {
            // Niveau déjà pris : ce travail est un doublon. Ce n'est pas une
            // erreur, c'est le cas normal d'une file qui rejoue.
            Log::info('Relance déjà partie, travail ignoré', [
                'document' => $facture->id,
                'niveau' => $this->niveau,
            ]);

            return;
        }

        try {
            Mail::to($facture->client->email)->send(new RelanceImpaye($facture, $this->niveau));
        } catch (Throwable $erreur) {
            // Le niveau reste réservé, délibérément. Le relâcher ferait repartir
            // le travail, et si l'échec s'est produit APRÈS que le courriel a
            // quitté le serveur, le client en recevrait deux. On préfère « peut-
            // être pas envoyée, et ça se voit dans l'application » à « peut-être
            // envoyée deux fois ».
            $relances->marquerEnEchec($relance, $erreur->getMessage());

            Log::warning('Envoi de relance en échec', [
                'document' => $facture->id,
                'niveau' => $this->niveau,
                'erreur' => $erreur->getMessage(),
            ]);
        }
    }

    /** Une clé par (facture, niveau) : la file elle-même refuse le doublon. */
    public function uniqueId(): string
    {
        return "relance:{$this->documentId}:{$this->niveau}";
    }
}
