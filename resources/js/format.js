/**
 * Le formatage partagé.
 *
 * Rassemblé ici parce qu'un montant mal formaté dans un seul écran suffit à
 * faire douter de tous les autres.
 *
 * `fr-MA` et non `fr-FR` : le français du Maroc groupe les milliers par un
 * POINT et sépare les décimales par une virgule — « 10.200,00 ». Le français
 * de France mettrait une espace insécable. Les deux se valent, mais un seul
 * est celui que le lecteur attend sur une facture marocaine, et les deux sont
 * très différents de « 10,200.00 ».
 */

const MONNAIE = new Intl.NumberFormat('fr-MA', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})

const DATE = new Intl.DateTimeFormat('fr-MA', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
})

const DATE_LONGUE = new Intl.DateTimeFormat('fr-MA', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
})

/**
 * Les montants arrivent du serveur en CHAÎNES, pas en nombres : ils viennent
 * de colonnes `numeric`, que PHP rend telles quelles pour ne pas passer par le
 * flottant. La conversion en nombre n'a lieu qu'ici, pour l'affichage, où une
 * erreur au milliardième de centime n'a aucune conséquence.
 */
export function montant(valeur, devise = null) {
    const nombre = Number.parseFloat(valeur ?? 0)
    const texte = MONNAIE.format(Number.isNaN(nombre) ? 0 : nombre)

    return devise ? `${texte} ${devise}` : texte
}

/** Sans décimales : pour les grands totaux d'un tableau de bord, où « 1 284 500 »
 *  se compare d'un coup d'œil et « 1 284 500,00 » ne se lit pas. */
export function montantCourt(valeur) {
    const nombre = Number.parseFloat(valeur ?? 0)

    return new Intl.NumberFormat('fr-MA', { maximumFractionDigits: 0 })
        .format(Number.isNaN(nombre) ? 0 : nombre)
}

export function date(valeur) {
    if (!valeur) return '—'

    // `T00:00:00` force l'interprétation en heure locale. Sans lui, une date
    // seule est lue en UTC, et le 1er mars s'affiche « 28/02 » à l'ouest de
    // Greenwich — ce qui, sur une échéance, change le nombre de jours de retard.
    return DATE.format(new Date(`${valeur}T00:00:00`))
}

export function dateLongue(valeur) {
    if (!valeur) return '—'

    return DATE_LONGUE.format(new Date(`${valeur}T00:00:00`))
}

export function moisCourt(valeur) {
    const [annee, mois] = valeur.split('-')

    return new Intl.DateTimeFormat('fr-MA', { month: 'short' })
        .format(new Date(Number(annee), Number(mois) - 1, 1))
}

/** Les couleurs d'état. Trois significations, jamais interchangées. */
export function tonStatut(statut, retard = 0) {
    if (statut === 'SOLDE') return 'caisse'
    if (statut === 'ANNULE' || statut === 'REFUSE' || statut === 'EXPIRE') return 'neutre'
    if (statut === 'BROUILLON') return 'neutre'
    if (retard > 0) return 'impaye'
    if (statut === 'EMIS') return 'echeance'

    return 'neutre'
}

export const CLASSES_TON = {
    caisse: 'bg-caisse-clair text-caisse',
    echeance: 'bg-echeance-clair text-echeance',
    impaye: 'bg-impaye-clair text-impaye',
    neutre: 'bg-papier-creux text-encre-douce',
}
