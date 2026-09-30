<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\EntrepriseCourante;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pose l'entreprise courante pour toute la requête.
 *
 * C'est la pièce qui rend le cloisonnement automatique : à partir d'ici, les
 * modèles filtrent tout seuls, et aucun contrôleur n'a plus à y penser — donc
 * aucun contrôleur ne peut plus l'oublier.
 *
 * L'entreprise retenue est celle gardée en session, à condition que
 * l'utilisateur y ait encore accès. Cette condition n'est pas théorique : un
 * comptable dont on retire l'accès garderait sinon l'identifiant dans sa
 * session et continuerait de voir les factures. On revérifie à chaque requête
 * plutôt que de faire confiance à ce que le navigateur renvoie.
 */
final class DefinirEntrepriseCourante
{
    public const CLE_SESSION = 'entreprise_courante';

    public function handle(Request $requete, Closure $suivant): Response
    {
        $utilisateur = $requete->user();

        if ($utilisateur === null) {
            return $suivant($requete);
        }

        // Chargée une fois, explicitement : `Model::preventLazyLoading()` est
        // actif hors production, et la relation est relue plus loin par les
        // données partagées d'Inertia.
        $accessibles = $utilisateur->loadMissing('entreprises')->entreprises;

        if ($accessibles->isEmpty()) {
            abort(403, 'Votre compte n\'est rattaché à aucune entreprise.');
        }

        $demandee = $requete->session()->get(self::CLE_SESSION);
        $retenue = $accessibles->firstWhere('id', $demandee) ?? $accessibles->first();

        $requete->session()->put(self::CLE_SESSION, $retenue->id);
        app(EntrepriseCourante::class)->definir($retenue->id);

        return $suivant($requete);
    }
}
