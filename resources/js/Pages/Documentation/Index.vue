<script setup>
/**
 * La documentation technique.
 *
 * Le contenu est structuré en données plutôt qu'en balisage : la table des
 * matières se construit toute seule, l'ordre se lit d'un coup d'œil, et
 * corriger un texte ne demande pas de toucher à la mise en page.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import Marque from '../../Marque.vue'

const SECTIONS = [
    {
        id: 'probleme',
        titre: 'Le problème, et pourquoi il est technique',
        blocs: [
            { p: 'Le Code Général des Impôts marocain impose que les factures portent une numérotation « continue et sans rupture ». Un numéro manquant est présumé être une facture soustraite au fisc, et c’est au chef d’entreprise de prouver le contraire. Une facture émise ne peut pas davantage être modifiée ni supprimée : elle se corrige par un avoir.' },
            { p: 'Ces deux règles paraissent administratives. Elles sont en réalité des contraintes de concurrence et de durabilité : il faut garantir qu’aucune panne, aucun double-clic, aucun import et aucune correction en base ne puisse trouer la série ou réécrire un document. C’est ce qui fait l’intérêt de ce projet.' },
        ],
    },
    {
        id: 'numerotation',
        titre: 'La numérotation, et pourquoi pas une séquence',
        blocs: [
            { p: 'Une séquence PostgreSQL ne convient pas. `nextval()` est volontairement hors transaction : il ne revient jamais en arrière. Si la transaction qui a pris le numéro 42 échoue, 42 est perdu et la série passe de 41 à 43. C’est le comportement voulu d’une clé technique, et exactement ce que la loi interdit.' },
            { p: 'Le numéro est donc pris sur une ligne de la table `compteurs`, par une seule requête qui fait trois choses de façon atomique : elle crée la ligne si c’est le premier document de l’année, elle l’incrémente sinon, et elle la verrouille pour la durée de la transaction.' },
            { code: `INSERT INTO compteurs (entreprise_id, type, annee, dernier_numero)
     VALUES (:entreprise, :type, :annee, 1)
ON CONFLICT (entreprise_id, type, annee)
DO UPDATE SET dernier_numero = compteurs.dernier_numero + 1
  RETURNING dernier_numero` },
            { p: 'Condition indispensable : l’appel doit se trouver dans la MÊME transaction que l’écriture du document. Incrémenté dans sa propre transaction, le compteur serait validé tout seul, et l’échec de l’écriture suivante laisserait un numéro consommé pour rien. Le service refuse donc de travailler hors transaction plutôt que de produire un trou silencieux.' },
            { p: 'Prix payé, assumé : deux factures de la même entreprise ne peuvent pas être émises en parallèle. La loi exige justement un ordre total, et le verrou ne porte que sur une ligne — les autres entreprises continuent sans attendre.' },
        ],
    },
    {
        id: 'garanties',
        titre: 'Les cinq garanties, posées dans la base',
        blocs: [
            { p: 'L’application est un client de la base parmi d’autres. Il y aura un jour une commande d’import, une reprise de données, un correctif appliqué à la main un soir d’urgence. Une règle écrite dans un service PHP ne protège que les chemins qui passent par ce service.' },
            {
                liste: [
                    ['Les totaux ne mentent pas', 'Les montants d’une ligne sont des colonnes `GENERATED ALWAYS … STORED` : aucune requête, même écrite à la main, ne peut les renseigner. Les totaux du document sont recalculés par un trigger à partir des lignes, en somme complète et non en delta — une somme refaite à zéro ne peut pas dériver.'],
                    ['La série est contiguë', 'Un trigger vérifie, sous le verrou du compteur, que le numéro proposé est bien le suivant. Un index unique partiel interdit le doublon. L’index est partiel parce que tous les brouillons ont `numero` à NULL, et qu’un index complet les tiendrait pour distincts.'],
                    ['Un document émis est immuable', 'Modification et suppression refusées par trigger. Restent modifiables le statut, le montant encaissé, l’échéance et les notes internes — rien de ce qui est imprimé. Les lignes sont figées elles aussi, sans quoi la protection serait contournable en une requête.'],
                    ['On n’encaisse pas plus que le dû', 'Une contrainte `CHECK`, donc évaluée à l’écriture, sous le verrou de la ligne. Une vérification en PHP lirait puis écrirait : deux encaissements saisis en même temps la passeraient tous les deux.'],
                    ['Une relance ne part qu’une fois', 'Un index unique sur (document, niveau). Le travail en file d’attente commence par tenter d’y insérer sa ligne ; si elle existe, il s’arrête sans rien envoyer.'],
                ],
            },
            { p: 'Les refus portent des codes SQLSTATE maison (900xx), ce qui permet au code PHP de distinguer un conflit métier — à traduire en 409 ou 422 — d’une vraie panne, sans lire un message d’erreur au petit bonheur.' },
        ],
    },
    {
        id: 'concurrence',
        titre: 'Prouver la concurrence sans la simuler',
        blocs: [
            { p: 'Lancer des processus parallèles et espérer qu’ils se télescopent donne un test qui passe le plus souvent : il observe, il ne prouve rien. On fait l’inverse.' },
            { p: 'Deux vraies sessions PostgreSQL. La première prend le numéro 1 et garde son verrou, transaction ouverte. La seconde reçoit un `lock_timeout` de 400 ms et tente le même incrément — on VÉRIFIE qu’elle expire (SQLSTATE 55P03). La première valide, la seconde repart et obtient 2, pas 1. Le résultat est identique à chaque exécution, et il dit précisément ce qu’on voulait savoir.' },
            { p: 'Un second test montre le trou qu’une séquence aurait laissé : il n’exerce aucun code du projet, il documente le piège, et il échouera le jour où quelqu’un remplacera le compteur par un `nextval()` en trouvant ça plus simple.' },
            { p: 'Un troisième reproduit le double-clic sur « Émettre ». Une vue périmée du brouillon passe sans problème le contrôle de lecture — pour elle, le numéro est nul. C’est le `AND numero IS NULL` de la requête de mise à jour qui l’arrête. Et l’on vérifie que le numéro réservé par la tentative refusée est RENDU : sinon le refus lui-même trouerait la série, et le remède serait la maladie.' },
        ],
    },
    {
        id: 'cloisonnement',
        titre: 'Le cloisonnement multi-entreprise',
        blocs: [
            { p: 'Un filtre global posé par les modèles eux-mêmes, alimenté par un service unique par requête. Deux effets, et le second compte autant : toute lecture est filtrée, et toute création reçoit son entreprise automatiquement. Sans le second, on écrirait des lignes correctement invisibles mais rattachées à la mauvaise entreprise — le pire des deux mondes.' },
            { p: '`entreprise_id` n’est volontairement pas assignable en masse : une requête HTTP qui l’enverrait pourrait déplacer un client chez une autre entreprise. Le code qui doit délibérément traverser les cloisons — le balayage nocturne des impayés — le dit à voix haute, avec `sansCloisonnement()`.' },
            { p: 'Un défaut trouvé par les tests : la conversion d’un devis créait la facture dans l’entreprise COURANTE et non dans celle du devis. Invisible tant qu’on ne gère qu’une société. La création passe désormais par la relation, qui pose la clé étrangère depuis la source.' },
        ],
    },
    {
        id: 'pieges',
        titre: 'Les pièges rencontrés, et ce qu’ils ont coûté',
        blocs: [
            {
                liste: [
                    ['Les tests tournaient sur la base de développement', 'Le conteneur définissait `DB_DATABASE` en variable d’environnement, qui écrase le `.env`, le `.env.testing` et jusqu’aux valeurs déclarées dans `phpunit.xml`. `RefreshDatabase` a vidé la base locale. Les variables ont quitté le fichier Compose.'],
                    ['4 secondes pour amorcer Laravel', 'Plus de mille fichiers de `vendor/` lus à chaque requête à travers le pont de fichiers de Docker Desktop. Mesuré avant de conclure. `vendor/` est passé dans un volume Docker : amorçage 4,0 s → 0,23 s, requête 4–10 s → 0,13 s, suite de tests 36,5 s → 7,7 s.'],
                    ['opcache chargé et sans effet', '`php artisan serve` n’est pas un serveur web classique, c’est le serveur intégré de PHP lancé par le SAPI CLI, où opcache est désactivé par défaut. Sans `opcache.enable_cli = 1`, tout le fichier de réglages se lit dans `phpinfo()` et ne fait rien.'],
                    ['Une image qui se construit et ne fonctionne pas', 'Supprimer les paquets `-dev` après compilation emportait les bibliothèques d’EXÉCUTION. L’image se construisait sans erreur et `pdo_pgsql` refusait de se charger ; Laravel répondait « could not find driver », un message qui ne désigne pas la cause.'],
                    ['PostgreSQL abandonne toute la transaction', 'À la première erreur, tout ce qui suit répond 25P02. Dans une suite qui tourne sous `RefreshDatabase`, un simple try/catch autour d’une écriture refusée condamne toutes les assertions suivantes — et l’on croit à un second défaut là où il n’y en a qu’un. Les refus attendus sont encadrés d’un point de sauvegarde.'],
                ],
            },
        ],
    },
    {
        id: 'lancer',
        titre: 'Faire tourner le projet',
        blocs: [
            { code: `docker compose up -d                              # PostgreSQL + PHP + dépendances
docker compose exec php php artisan migrate --seed
npm install && npm run dev                       # le front, sur le poste

# Les tests, sur un vrai PostgreSQL
docker compose exec postgres psql -U factura -d postgres \\
    -c "CREATE DATABASE factura_test OWNER factura"
docker compose exec php php artisan test` },
            { p: 'PHP tourne dans un conteneur parce que Smart App Control, actif sur le poste de développement, refuse d’exécuter le binaire PHP officiel, qui n’est pas signé. Cette protection ne se réactive pas une fois coupée — il faut réinstaller Windows. Ce n’est pas seulement un contournement : cela rapproche le développement de la production, et permet à quiconque clone le dépôt de démarrer sans installer PHP.' },
        ],
    },
]
</script>

<template>
    <Head title="Documentation technique" />

    <div class="mx-auto max-w-5xl px-6 py-12">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <Marque :taille="40" />
                <h1 class="mt-5 text-3xl font-semibold tracking-tight">Documentation technique</h1>
                <p class="mt-2 max-w-2xl text-[15px] leading-relaxed text-texte-doux">
                    Ce que ce projet garantit, comment, et ce qu’il a fallu corriger en chemin.
                    Les décisions sont expliquées avec leur contrepartie : un choix sans
                    inconvénient est généralement un choix mal compris.
                </p>
            </div>

            <div class="flex gap-2">
                <Link href="/" class="bouton-discret rounded-lg px-3 py-1.5 text-[13px] font-medium">
                    L’application
                </Link>
                <button
                    type="button"
                    class="bouton-discret rounded-lg px-3 py-1.5 text-[13px] font-medium"
                    @click="router.post('/documentation/fermer')"
                >
                    Verrouiller
                </button>
            </div>
        </header>

        <nav class="verre mt-10 rounded-2xl p-5">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-texte-doux">Sommaire</p>
            <ol class="mt-3 grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
                <li v-for="(section, i) in SECTIONS" :key="section.id">
                    <a :href="`#${section.id}`" class="text-[14px] text-texte-doux transition-colors hover:text-accent">
                        <span class="nombre mr-2 text-accent">{{ String(i + 1).padStart(2, '0') }}</span>
                        {{ section.titre }}
                    </a>
                </li>
            </ol>
        </nav>

        <article
            v-for="(section, i) in SECTIONS"
            :id="section.id"
            :key="section.id"
            class="mt-12 scroll-mt-8"
        >
            <h2 class="flex items-baseline gap-3 text-xl font-semibold tracking-tight">
                <span class="nombre text-accent">{{ String(i + 1).padStart(2, '0') }}</span>
                {{ section.titre }}
            </h2>

            <div class="mt-4 space-y-4">
                <template v-for="(bloc, j) in section.blocs" :key="j">
                    <p v-if="bloc.p" class="max-w-3xl text-[15px] leading-relaxed text-texte-doux">
                        {{ bloc.p }}
                    </p>

                    <pre v-else-if="bloc.code" class="verre nombre overflow-x-auto rounded-xl p-4 text-[13px] leading-relaxed text-texte"><code>{{ bloc.code }}</code></pre>

                    <dl v-else-if="bloc.liste" class="space-y-4">
                        <div v-for="[titre, texte] in bloc.liste" :key="titre" class="border-l-2 border-accent pl-4">
                            <dt class="text-[15px] font-semibold">{{ titre }}</dt>
                            <dd class="mt-1 max-w-3xl text-[14px] leading-relaxed text-texte-doux">{{ texte }}</dd>
                        </div>
                    </dl>
                </template>
            </div>
        </article>

        <footer class="mt-16 border-t border-bordure pt-6">
            <p class="text-[13px] leading-relaxed text-texte-doux">
                86 tests, 190 assertions, sur un vrai PostgreSQL — tester ces garanties
                sur SQLite ne prouverait rien.
                <a href="https://github.com/diffonathan/factura" target="_blank" rel="noopener" class="font-semibold text-accent hover:underline">
                    Le code source ↗
                </a>
            </p>
        </footer>
    </div>
</template>
