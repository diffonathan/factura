# L'API de Factura

Pour brancher un outil : logiciel de caisse, site marchand, export comptable.

Tout est en `/api/v1/`. La version est dans le chemin et non dans un en-tête,
parce qu'un intégrateur colle une adresse dans un navigateur pour essayer, et
qu'il doit voir tout de suite à quoi il s'adresse.

---

## S'authentifier

L'API agit au nom d'une **entreprise**, pas d'un utilisateur. C'est une
décision, pas un raccourci : une intégration ne doit pas tomber le jour où la
personne qui l'a mise en place quitte le cabinet.

Créez un jeton en ligne de commande :

```bash
php artisan factura:jeton creer 1 "caisse boutique"
```

La valeur n'est affichée **qu'une fois**. Seule son empreinte SHA-256 est
stockée : une copie de la base, un journal ou une sauvegarde qui fuite ne
donnent rien d'utilisable. La perdre oblige à en créer une autre — c'est le
comportement correct, pas un défaut.

Chaque appel la porte en en-tête :

```
Authorization: Bearer fct_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Lister et révoquer :

```bash
php artisan factura:jeton lister 1
php artisan factura:jeton revoquer 7
```

Un jeton révoqué reçoit `401` au coup suivant.

### Ce que le jeton ne donne pas

Il ouvre **une seule entreprise**. Un identifiant de document appartenant à une
autre société, glissé dans une URL, rend `404` — pas `403`, parce que du point
de vue de l'appelant ce document n'existe pas.

Ce n'est pas un contrôle écrit dans les contrôleurs de l'API : c'est la portée
globale des modèles, la même qui protège les visites au navigateur. Il n'y a
donc pas une isolation « web » et une isolation « API » à garder en accord.

---

## Les ressources

### `GET /api/v1/documents`

| Paramètre | Valeurs | Défaut |
|---|---|---|
| `type` | `DEVIS`, `FACTURE`, `AVOIR` | tous |
| `statut` | `BROUILLON`, `EMIS`, `PAYE`, `ANNULE` | tous |
| `client_id` | entier | tous |
| `par_page` | 1 à 100 | 25 |
| `page` | entier | 1 |

```json
{
  "donnees": [
    {
      "id": 9,
      "reference": "FA-2026-0009",
      "numero": 9,
      "type": "FACTURE",
      "statut": "EMIS",
      "client": { "id": 1, "nom": "Groupe Chérifien de Distribution" },
      "date_emission": "2026-09-15",
      "date_echeance": "2026-11-14",
      "montant_ht": "13500.00",
      "montant_tva": "2700.00",
      "montant_ttc": "16200.00",
      "montant_paye": "0.00",
      "reste_a_payer": "16200.00",
      "devise": "MAD"
    }
  ],
  "pagination": { "page": 1, "par_page": 25, "total": 13, "pages": 1 }
}
```

> **Les montants sont des chaînes, jamais des nombres.** `"16200.00"` tient
> exactement ; `16200.00` en virgule flottante ne tient pas toujours, et un
> client qui additionne des totaux finirait par afficher un centime de travers.
> Les décimales d'argent voyagent en texte, comme elles sont stockées.

Un **brouillon** a `numero: null` et `reference: null`. Ce n'est pas une
donnée manquante : le numéro n'est attribué qu'à l'émission, et c'est
précisément ce qui garantit une série sans trou.

### `GET /api/v1/documents/{id}`

Le résumé ci-dessus, plus `objet`, `conditions`, `emis_le`,
`jours_de_retard`, `ventilation_tva`, `lignes` et `paiements`.

```json
"ventilation_tva": [
  { "taux": 20, "base": "13500.00", "tva": "2700.00" }
]
```

### `POST /api/v1/documents`

Crée un brouillon. Avec `"emettre": true`, il est émis dans la foulée — le cas
courant d'une caisse, qui ne relit pas son brouillon.

```json
{
  "type": "FACTURE",
  "client_id": 1,
  "date_emission": "2026-10-03",
  "date_echeance": "2026-12-02",
  "objet": "Prestation de conseil",
  "emettre": false,
  "lignes": [
    {
      "designation": "Audit",
      "unite": "jour",
      "quantite": 2,
      "prix_unitaire_ht": 3000,
      "remise_pct": 0,
      "taux_tva": 20
    }
  ]
}
```

Réponse `201` avec le document complet.

**Les taux de TVA acceptés sont 0, 7, 10, 14 et 20.** Tout autre valeur rend
`422`. La base pose la même contrainte ; la validation ne sert qu'à rendre un
message lisible plutôt qu'une erreur SQL.

`client_id` doit appartenir à **votre** entreprise. Un identifiant valide mais
étranger rend `422`, pas `404` : c'est une donnée refusée, pas une ressource
absente.

### `POST /api/v1/documents/{id}/emettre`

Attribue le numéro de la série légale et fige le document.

**Irréversible**, et c'est le point : une numérotation sans trou n'a de sens
que si l'on ne peut pas revenir en arrière. Une erreur se corrige par un avoir,
jamais par une réécriture. Émettre deux fois rend `409`.

### `GET /api/v1/documents/{id}/pdf`

Le document en PDF — mentions légales, TVA ventilée, règlements reçus.
`Content-Type: application/pdf`, nommé d'après la référence légale, ou
`brouillon-12.pdf` si le document n'est pas émis. Dans ce cas le PDF porte en
toutes lettres « sans valeur légale ».

### `GET /api/v1/clients`

`recherche` filtre sur le nom. L'écriture n'est pas exposée : un client se
saisit une fois, à la main, avec son ICE — c'est une donnée légale qu'on ne
veut pas voir créée en masse par une boucle.

---

## Les codes de retour

| Code | Quand | Ce qu'il faut faire |
|---|---|---|
| `200` | lecture réussie | — |
| `201` | document créé | — |
| `401` | jeton absent, inconnu ou révoqué | vérifier l'en-tête, créer un jeton |
| `404` | document inexistant **ou appartenant à une autre entreprise** | — |
| `409` | l'état a changé : déjà émis, devis déjà facturé | relire le document |
| `422` | données refusées | lire `errors`, corriger |

`409` mérite une explication : rien n'a échoué, l'état du monde a changé entre
votre lecture et votre écriture. Ce n'est pas une panne, et le traiter comme
telle déclencherait des alertes pour une situation normale.

---

## Un exemple complet

```bash
JETON="fct_..."
BASE="https://votre-instance/api/v1"

# 1. Trouver le client
curl -s -H "Authorization: Bearer $JETON" \
     "$BASE/clients?recherche=Ch%C3%A9rifien"

# 2. Créer la facture et l'émettre
curl -s -X POST -H "Authorization: Bearer $JETON" \
     -H "Content-Type: application/json" \
     -d '{
       "type": "FACTURE",
       "client_id": 1,
       "date_emission": "2026-10-03",
       "emettre": true,
       "lignes": [
         {"designation": "Vente comptoir", "quantite": 1,
          "prix_unitaire_ht": 250, "taux_tva": 20}
       ]
     }' "$BASE/documents"

# 3. Récupérer le PDF
curl -s -H "Authorization: Bearer $JETON" \
     -o facture.pdf "$BASE/documents/14/pdf"
```
