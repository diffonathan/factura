# Factura

**Facturation conforme au Maroc, pour les TPE et les indépendants.**

Une très petite entreprise marocaine facture encore sous Word ou Excel. Trois
conséquences, toujours les mêmes : une numérotation qui saute ou se répète, des
mentions légales oubliées — ICE, IF, RC — et des impayés que personne ne relance
parce que personne ne sait lesquels traînent.

Factura remplace ces fichiers par un outil : la numérotation ne peut pas avoir
de trou, les mentions obligatoires sont portées par le document, et les relances
partent toutes seules.

> **Projet de démonstration.** Les entreprises et les clients sont inventés.

---

## Les technologies

| Domaine | Mise en œuvre |
|---|---|
| **Serveur** | PHP 8.4, Laravel 13 |
| **Interface** | Vue 3, Inertia 3, Vite 8, Tailwind CSS 4 |
| **Base de données** | PostgreSQL 17 — contraintes, colonnes calculées, triggers |
| **Asynchrone** | Files d'attente et planificateur Laravel |
| **Tests** | PHPUnit 12, sur un vrai PostgreSQL |
| **Environnement** | Docker et Docker Compose |

---

## Ce que la base garantit, et pourquoi

Le cœur du projet n'est pas dans le code PHP : il est dans le schéma. Une règle
écrite dans un service ne protège que les chemins qui passent par ce service.
Il y aura un jour une commande d'import, une reprise de données, un correctif
appliqué à la main un soir d'urgence. Une règle écrite dans la base les couvre
tous.

### 1. La numérotation ne peut pas avoir de trou

Le Code Général des Impôts impose une série **continue et sans rupture**. Un
trou est présumé être une facture soustraite au fisc.

Une séquence PostgreSQL ne peut pas produire cela : `nextval()` ne revient
jamais en arrière, donc une transaction annulée laisse un numéro perdu. C'est
le comportement voulu d'une clé technique, et exactement l'inverse de ce que
la loi demande.

Le numéro est donc pris sur une **ligne de table**, avec un
`INSERT … ON CONFLICT DO UPDATE` qui verrouille cette ligne. Deux émissions
simultanées de la même entreprise s'attendent ; un échec rend le numéro.

Un test le démontre avec **deux vraies sessions PostgreSQL**, en vérifiant que
la seconde expire sur le verrou — un résultat identique à chaque exécution,
là où lancer des processus en parallèle et espérer une collision ne prouverait
rien. Un autre test montre le trou qu'une séquence aurait laissé.

### 2. Les totaux ne peuvent pas mentir

Les montants d'une ligne sont des colonnes `GENERATED ALWAYS … STORED` : elles
ne peuvent être renseignées de l'extérieur par aucune requête. Les totaux du
document sont recalculés par un trigger à partir des lignes.

### 3. Un document émis est immuable

On ne le réécrit pas, on ne le supprime pas : on le corrige par un avoir.
C'est la loi, et c'est la seule façon de garder une piste vérifiable. Les
lignes d'un document émis sont figées elles aussi — sans quoi la protection
serait contournable en une requête.

### 4. On n'encaisse pas plus que le dû

Une contrainte `CHECK`, donc évaluée à l'écriture, sous le verrou de la ligne.
Une vérification en PHP lirait puis écrirait : deux encaissements saisis en
même temps la passeraient tous les deux.

### 5. Une relance ne part qu'une fois

Une file d'attente promet « au moins une fois », pas « au plus une fois » : un
travail dont l'accusé de réception se perd est rejoué. Le travail commence donc
par **réserver** son niveau (`ON CONFLICT DO NOTHING` sur un index unique), et
n'envoie que s'il l'a obtenu.

---

## Démarrage

```bash
docker compose up -d          # PostgreSQL, PHP, et l'installation des dépendances
docker compose exec php php artisan migrate --seed
npm install && npm run dev    # le front, sur le poste
```

L'application répond sur **http://localhost:8000**.

Comptes de démonstration : `demo@factura.ma` (propriétaire) et
`comptable@factura.ma` (comptable), mot de passe `demonstration`.

Le jeu de démonstration calcule toutes ses dates depuis aujourd'hui : il y a
toujours des factures en retard de 8, 25 et 62 jours, donc toujours les trois
niveaux de relance à montrer.

> PostgreSQL écoute sur le port **5433** et non 5432 : une autre base de
> développement peut déjà occuper le port standard.

### Les tests

```bash
docker compose exec postgres psql -U factura -d postgres -c "CREATE DATABASE factura_test OWNER factura"
docker compose exec php php artisan test
```

Les tests tournent sur un **vrai PostgreSQL**, jamais sur SQLite en mémoire.
Tout ce que ce projet garantit est écrit en PostgreSQL ; une suite verte sur
une autre base ne prouverait rien, et rassurerait à tort.

---

## Deux choix d'environnement, et leurs raisons

### PHP tourne dans un conteneur

Smart App Control, actif sur le poste de développement, refuse d'exécuter le
binaire PHP officiel : il n'est pas signé. Cette protection ne se réactive pas
une fois coupée — il faut réinstaller Windows. La désactiver pour un confort de
développement serait un mauvais échange.

Ce n'est pas qu'un contournement : cela rapproche le développement de la
production, et permet à quiconque clone ce dépôt de démarrer sans installer PHP.
Le front, lui, tourne sur le poste : Node est signé, donc autorisé.

### `vendor/` vit dans un volume Docker, pas dans le montage

Chaque requête Laravel ouvre plus d'un millier de fichiers de `vendor/`. À
travers le pont de fichiers de Docker Desktop sur Windows, chacun coûte
quelques millisecondes.

Mesuré sur ce projet :

| | Montage Windows | Volume Docker |
|---|---|---|
| Amorçage de Laravel | 4,0 s | **0,23 s** |
| Requête HTTP | 4 à 10 s | **0,13 s** |
| Suite de tests | 36,5 s | **7,7 s** |

Contrepartie assumée : `vendor/` n'est plus lisible depuis l'éditeur pour
l'autocomplétion, et `composer install` doit tourner dans le conteneur — ce qui
était déjà le cas.

À noter : `opcache` est nécessaire mais ne suffit pas, et il faut
`opcache.enable_cli = 1`. `php artisan serve` n'est pas un serveur web
classique, c'est le serveur intégré de PHP lancé par le SAPI **CLI**, où
opcache est désactivé par défaut. Sans cette ligne, les réglages se lisent
correctement dans `phpinfo()` et n'ont aucun effet.

---

## État

Le métier est complet et testé : entreprises, clients, devis, factures, avoirs,
encaissements partiels, relances à trois niveaux, cloisonnement multi-entreprise,
tableau de bord.

Le document se télécharge en PDF, avec les mentions légales de l'émetteur, la
TVA ventilée par taux et l'historique des règlements. Un brouillon s'y annonce
comme tel : il n'a pas de numéro, et le PDF le dit en toutes lettres plutôt que
de laisser un document de travail passer pour une facture.

Une API REST permet de brancher un outil — caisse, site marchand, export
comptable. Elle agit au nom d'une entreprise par jeton porteur, et réemploie le
cloisonnement des modèles plutôt que d'en écrire un second : un document d'une
autre société n'est jamais résolu. Voir [docs/API.md](docs/API.md).

**103 tests, 246 assertions.**
