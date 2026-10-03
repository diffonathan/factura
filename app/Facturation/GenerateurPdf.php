<?php

declare(strict_types=1);

namespace App\Facturation;

use App\Models\Document;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

/**
 * Rend un document en PDF, tel qu'il sera remis au client.
 *
 * Pourquoi un service et non trois lignes dans le contrôleur : le contrôleur
 * décide QUI a le droit de télécharger, ce qui est une question d'accès ; la
 * mise en page d'une facture est une question de métier, et elle a ses propres
 * règles — la ventilation de TVA par taux, les mentions obligatoires, la
 * distinction entre un brouillon et un document émis. Les deux évoluent pour
 * des raisons différentes.
 *
 * Le rendu passe par un gabarit Blade plutôt que par du dessin programmatique :
 * une facture est un document de texte, et la décrire en HTML la rend lisible
 * et modifiable par quelqu'un qui ne connaît pas la bibliothèque employée.
 */
final class GenerateurPdf
{
    /**
     * Le contenu binaire du PDF. On ne l'écrit pas sur disque : un hébergement
     * gratuit n'a pas de volume persistant, et un fichier temporaire oublié
     * finirait par remplir le conteneur. Le document se reconstruit à chaque
     * demande, à partir de la base — qui est la seule source de vérité.
     */
    public function rendre(Document $document): string
    {
        $document->loadMissing(['client', 'entreprise', 'lignes', 'paiements']);

        $options = new Options();
        // DejaVu Sans porte les accents français. La police par défaut de la
        // bibliothèque ne les rend pas tous : « émise » s'afficherait « mise ».
        $options->set('defaultFont', 'DejaVu Sans');
        // Aucune ressource distante : le gabarit n'en charge pas, et l'autoriser
        // ouvrirait une requête sortante déclenchée par le contenu d'un
        // document — c'est-à-dire par une donnée saisie par l'utilisateur.
        $options->set('isRemoteEnabled', false);

        $moteur = new Dompdf($options);
        $moteur->setPaper('A4', 'portrait');
        $moteur->loadHtml(View::make('pdf.document', [
            'document' => $document,
            'emetteur' => $document->entreprise,
            'client' => $document->client,
            'ventilation' => $document->ventilationTva(),
        ])->render(), 'UTF-8');
        $moteur->render();

        return (string) $moteur->output();
    }

    /**
     * Le nom du fichier téléchargé.
     *
     * Un document émis porte son numéro légal, qui est unique et parlant. Un
     * brouillon n'en a pas — par construction, puisque le numéro n'est attribué
     * qu'à l'émission. Lui en inventer un ferait croire à une facture.
     */
    public function nomDeFichier(Document $document): string
    {
        if ($document->numero === null) {
            return 'brouillon-'.$document->id.'.pdf';
        }

        return $document->reference.'.pdf';
    }
}
