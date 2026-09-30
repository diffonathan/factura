# Mettre Factura en ligne, gratuitement

Tout est prêt dans le dépôt. Il reste **trois actions** qui demandent un compte,
et un compte ne se crée pas à votre place.

Compter dix minutes.

---

## Pourquoi deux hébergeurs et pas un

| | Quoi | Pourquoi celui-là |
|---|---|---|
| **Render** | l'application | seul hébergeur gratuit qui exécute une image Docker avec PHP, sans carte bancaire |
| **Neon** | la base PostgreSQL | la base gratuite de Render **expire au bout de 30 jours**. Celle de Neon est permanente. Une démonstration qui meurt au bout d'un mois est pire qu'une absence de démonstration : le lien est dans votre CV |

---

## 1. La base de données — neon.tech

1. Créer un compte (connexion par GitHub possible).
2. **New Project** → région **Europe (Frankfurt)**, la plus proche du Maroc
   parmi les régions gratuites.
3. Copier la chaîne de connexion proposée. Elle ressemble à
   `postgresql://…@ep-…-pooler.eu-central-1.aws.neon.tech/neondb?sslmode=require`.

> Prendre la version **pooler** si les deux sont proposées : Render endort le
> service et le réveille, ce qui rouvre des connexions à chaque fois. Sans
> mutualisation, la limite de connexions de l'offre gratuite se remplit.

---

## 2. L'application — render.com

1. Créer un compte, connecter GitHub.
2. **New → Blueprint**, choisir le dépôt `diffonathan/factura`.
   Render lit `render.yaml` et prépare tout seul le service, la région et les
   variables.
3. Il demandera les deux valeurs laissées volontairement vides — elles ne sont
   pas dans le dépôt, qui est public :

   | Variable | Valeur |
   |---|---|
   | `DB_URL` | la chaîne Neon de l'étape 1, en remplaçant `postgresql://` par **`pgsql://`** |
   | `DOCUMENTATION_MOT_DE_PASSE` | celui de votre choix |

4. **Apply**. Le premier déploiement prend cinq à huit minutes : il construit
   le front, installe les dépendances, joue les migrations et le jeu de
   démonstration.

> `pgsql://` et non `postgresql://` : c'est le préfixe que Laravel attend. Avec
> l'autre, la configuration retombe **silencieusement** sur SQLite et les
> migrations échouent sur la première expression régulière PostgreSQL. Vérifié
> en lançant l'image.

---

## 3. Le lien

Render donne une adresse en `https://factura-XXXX.onrender.com`. À reporter :

- dans `portfolio/src/data/portfolio.ts` et `portfolio.en.ts`, champ `demoUrl`
  de la fiche Factura — le bouton « Voir la démo » apparaît alors ;
- dans le dépôt GitHub, champ **Website** ;
- dans la fiche LinkedIn du projet, en média.

---

## Ce que l'offre gratuite implique

**Le service s'endort après 15 minutes sans visite.** Le réveil prend 30 à 50
secondes. Pour une démonstration montrée à un recruteur c'est acceptable **à
condition de le dire** — une page blanche pendant quarante secondes passe pour
une panne. Une phrase dans le portfolio suffit : « première ouverture un peu
lente, le serveur se réveille ».

**512 Mo de mémoire.** Laravel y tient largement.

**Les files d'attente tournent en mode direct** (`QUEUE_CONNECTION=sync`) :
l'offre gratuite ne permet pas un second processus. Les relances partent donc
pendant la requête au lieu d'être mises en file. Le code du travail en file est
inchangé et reste la vraie implémentation ; seul le mode d'exécution diffère,
et c'est un réglage, pas une réécriture.

---

## Vérifier après coup

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://VOTRE-ADRESSE.onrender.com/up
```

`200` veut dire que l'application répond **et** que la base est jointe : le
contrôle de santé de Laravel vérifie les deux.
