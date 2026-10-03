<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\JetonApi;
use App\Support\EntrepriseCourante;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie une requête d'API par jeton porteur, et pose l'entreprise.
 *
 * Une seule pièce fait les deux, et c'est voulu : séparer « qui appelle » de
 * « sur quelles données » laisserait la porte ouverte à une route authentifiée
 * mais non cloisonnée. Ici, être authentifié SIGNIFIE être rattaché à une
 * entreprise — il n'existe pas d'état intermédiaire.
 */
final class AuthentifierJetonApi
{
    public function handle(Request $requete, Closure $suivant): Response
    {
        $valeur = $requete->bearerToken();

        if ($valeur === null || $valeur === '') {
            return $this->refus(
                'Jeton absent.',
                'Ajoutez un en-tête « Authorization: Bearer <votre-jeton> ».',
            );
        }

        $jeton = JetonApi::resoudre($valeur);

        if ($jeton === null) {
            // Le même message pour « inconnu » et « révoqué ». Les distinguer
            // dirait à qui tâtonne si une valeur a existé — une information
            // qu'on ne doit rien à l'appelant.
            return $this->refus(
                'Jeton invalide ou révoqué.',
                'Créez-en un nouveau depuis l\'application.',
            );
        }

        app(EntrepriseCourante::class)->definir($jeton->entreprise_id);
        $requete->attributes->set('jeton_api', $jeton);

        // La date d'usage n'est écrite qu'une fois par minute. Sans ce frein,
        // chaque lecture d'API deviendrait aussi une écriture : la table des
        // jetons serait le point le plus sollicité de la base, pour une
        // information dont personne n'a besoin à la seconde près.
        if ($jeton->dernier_usage_le === null || $jeton->dernier_usage_le->diffInSeconds(now()) > 60) {
            $jeton->forceFill(['dernier_usage_le' => now()])->saveQuietly();
        }

        return $suivant($requete);
    }

    private function refus(string $message, string $indice): Response
    {
        return response()->json([
            'message' => $message,
            'indice' => $indice,
        ], 401, ['WWW-Authenticate' => 'Bearer']);
    }
}
