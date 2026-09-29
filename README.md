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
| **Base de données** | PostgreSQL 17, migrations Laravel |
| **Environnement** | Docker et Docker Compose |

---

## Pourquoi PHP tourne dans un conteneur

Smart App Control, actif sur le poste de développement, refuse d'exécuter le
binaire PHP officiel : il n'est pas signé. Cette protection ne se réactive pas
une fois coupée — il faut réinstaller Windows. La désactiver pour un confort de
développement serait un mauvais échange.

PHP tourne donc dans une image Docker. Ce n'est pas un contournement : c'est
aussi ce qui rapproche le développement de la production, et ce qui permet à
quiconque clone ce dépôt de démarrer sans installer PHP.

Le front, lui, tourne sur le poste : Node est signé, donc autorisé.

---

## Démarrage

```bash
docker compose up -d                                  # PostgreSQL + PHP
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate
npm install && npm run dev                            # le front, sur le poste
```

L'application répond sur **http://localhost:8000**.

> PostgreSQL écoute sur le port **5433** et non 5432 : une autre base de
> développement peut déjà occuper le port standard, et deux projets doivent
> pouvoir vivre ensemble sans qu'on en arrête un.

---

## État

Le socle est en place et vérifié : Laravel rend une page Vue par Inertia, les
données affichées viennent du serveur, les migrations tournent sur PostgreSQL.

Le métier reste à construire : entreprises, clients, devis, factures,
numérotation légale, TVA, relances.
