/**
 * La visite guidée de Factura.
 *
 * Le contenu est séparé du composant qui l'affiche : on corrige un texte sans
 * relire une ligne de mécanique d'affichage, et l'ordre des étapes se lit d'un
 * coup d'œil.
 *
 * TROIS RÈGLES D'ÉCRITURE, et elles ne sont pas négociables :
 *
 *   1. On commence par LE PROBLÈME, pas par l'application. Quelqu'un qui ne
 *      sait pas ce qu'est un ICE doit comprendre en trois phrases pourquoi
 *      cette application existe.
 *
 *   2. AUCUN terme technique dans les explications. Pas de « transaction »,
 *      pas de « contrainte », pas de « trigger ». Le lecteur visé est un
 *      recruteur ou un dirigeant de TPE, pas un développeur.
 *
 *   3. Les technologies sont NOMMÉES, mais à part, en fin de visite, et
 *      repérables d'un coup d'œil. Quelqu'un qui cherche « est-ce qu'il a fait
 *      du Laravel ? » doit trouver la réponse sans lire un paragraphe.
 *
 * `cible` est un sélecteur CSS. S'il ne correspond à rien — écran étroit,
 * élément absent de la page en cours — l'étape s'affiche au centre plutôt que
 * de pointer dans le vide.
 */
export const ETAPES = [
    {
        titre: 'Le problème',
        corps: [
            'Au Maroc, la plupart des très petites entreprises facturent encore sous Word ou Excel. Un fichier par facture, un dossier par année.',
            'Trois choses finissent toujours par arriver : on saute un numéro ou on en réutilise un, on oublie une mention obligatoire, et on ne sait plus qui n’a pas payé.',
            'Les deux premières coûtent cher lors d’un contrôle fiscal. La troisième coûte de la trésorerie tous les mois.',
        ],
        marque: true,
    },
    {
        titre: 'La règle qu’un tableur ne peut pas tenir',
        corps: [
            'La loi exige que les factures soient numérotées à la suite, sans trou. Facture 41, 42, 43 — jamais 41, 43.',
            'Un numéro manquant est présumé être une facture qu’on a fait disparaître. C’est au chef d’entreprise de prouver le contraire.',
            'Dans un tableur, rien n’empêche d’effacer une ligne. Ici, le numéro est attribué au moment de l’émission et ne peut plus bouger : le document ne se supprime pas, ne se réécrit pas, et se corrige par un avoir — exactement comme la loi le demande.',
        ],
    },
    {
        titre: 'Ce que vous voyez en arrivant',
        cible: '[data-visite="chiffres"]',
        corps: [
            'Quatre chiffres, et trois couleurs qui ne changent jamais de sens : le vert est ce qui est rentré, le rouge ce qui manque, le gris ce qu’on attend encore sans inquiétude.',
            'L’or est réservé à ce sur quoi on clique. Un montant n’est donc jamais doré : on ne clique pas sur un total.',
        ],
    },
    {
        titre: 'Les relances partent toutes seules',
        cible: '[data-visite="relances"]',
        corps: [
            'Chaque matin, l’application regarde les factures échues et envoie le courrier qui convient : un rappel courtois à une semaine, une relance ferme à trois, une mise en demeure à six.',
            'Le point délicat est ailleurs : un envoi automatique qui repart deux fois fait perdre un client. Ici, chaque niveau est réservé avant d’être envoyé — si le système rejoue l’opération, il trouve la place prise et n’envoie rien.',
        ],
    },
    {
        titre: 'Du devis à la facture, et à l’avoir',
        cible: '[data-visite="documents"]',
        corps: [
            'Un devis accepté se transforme en facture en un clic, lignes comprises — mais en brouillon, parce qu’entre l’accord et la facturation une quantité change presque toujours.',
            'Une fois la facture émise, elle est figée. Pour corriger, on établit un avoir : la facture d’origine reste lisible, et la correction se voit. C’est ce qu’un contrôleur cherche en premier.',
        ],
    },
    {
        titre: 'Ce qu’il y a derrière',
        corps: [
            'Tout ce que vous venez de voir est garanti par la base de données elle-même, et non par le programme qui l’utilise.',
            'La différence est concrète : même en écrivant directement dans la base, on ne peut pas créer deux factures au même numéro, ni modifier une facture émise, ni encaisser plus que le montant dû. La base refuse.',
            'Cette discipline est vérifiée par 78 tests automatiques qui tournent à chaque modification du code.',
        ],
        technologies: [
            'PHP 8.4', 'Laravel 13', 'Vue 3', 'Inertia', 'Tailwind CSS',
            'PostgreSQL 17', 'Docker', 'Files d’attente', 'GitHub Actions', 'PHPUnit',
        ],
    },
    {
        titre: 'Vous recrutez ?',
        corps: [
            'Cette application est une démonstration. Les entreprises et les clients sont inventés ; tout le reste fonctionne vraiment — vous pouvez émettre une facture, encaisser, établir un avoir.',
            'Elle a été construite pour montrer une façon de travailler : poser les règles du métier là où rien ne peut les contourner, et le prouver par des tests plutôt que l’affirmer.',
        ],
        liens: [
            { texte: 'Le code source', url: 'https://github.com/diffonathan/factura' },
            { texte: 'Mes autres projets', url: 'https://diffonathan.github.io/' },
        ],
        final: true,
    },
]

const CLE = 'factura:visite-vue'

/** Vrai si la visite n'a jamais été terminée sur ce navigateur. */
export function visiteJamaisFaite() {
    try {
        return localStorage.getItem(CLE) !== 'oui'
    } catch {
        // Navigation privée, stockage bloqué : on montre la visite. Mieux vaut
        // la proposer une fois de trop que jamais à un visiteur qui découvre.
        return true
    }
}

export function marquerVisiteFaite() {
    try {
        localStorage.setItem(CLE, 'oui')
    } catch {
        /* sans stockage, la visite se reproposera : ce n'est pas grave */
    }
}
