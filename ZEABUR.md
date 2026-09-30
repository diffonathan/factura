# Mettre Factura en ligne sur Zeabur

Zeabur a été retenu pour une raison simple : **aucune carte bancaire n'est
demandée**. Render, Koyeb et Fly.io exigent tous une vérification par carte
depuis 2026, et Hugging Face Docker Spaces n'est plus gratuit.

`zbpack.json` désigne le Dockerfile à construire : il vit dans
`docker/php/Dockerfile.production` et non à la racine, parce que celui de la
racine sert au développement et ne doit pas partir en production.

---

## 1. Le service

**zeabur.com** → inscription par GitHub → **New Project** → région
**Frankfurt** (la même que la base Neon : une base à Francfort et une
application à Singapour, c'est 200 ms perdues à chaque requête).

**Add Service → Git → `diffonathan/factura`**. Zeabur lit `zbpack.json`,
trouve le Dockerfile et construit. Le premier déploiement prend cinq à huit
minutes : il construit le front, installe les dépendances PHP, puis joue les
migrations au démarrage.

## 2. Les quatre variables

Tout ce qui n'est pas secret est déjà dans l'image. Il ne reste que ceci, à
saisir dans **Variables** :

| Variable | Valeur |
|---|---|
| `APP_KEY` | voir ci-dessous |
| `DB_URL` | la chaîne Neon, avec `/factura` avant le `?` |
| `DOCUMENTATION_MOT_DE_PASSE` | celui de votre choix |
| `APP_URL` | l'adresse donnée par Zeabur, à remplir **après** le premier déploiement |

### Produire l'APP_KEY

Laravel chiffre les cookies de session avec cette clé. Elle doit être stable :
la changer déconnecte tout le monde.

```bash
docker compose exec php php artisan key:generate --show
```

Copiez la ligne complète, `base64:` compris. Ne la réutilisez nulle part
ailleurs — c'est la clé de chiffrement de l'application.

## 3. Le domaine

**Networking → Generate Domain**. Zeabur propose une adresse en
`xxx.zeabur.app`. Reportez-la ensuite dans `APP_URL`, puis redéployez : sans
elle, les URL absolues générées par Laravel pointeraient vers `localhost`.

---

## Ce que l'offre gratuite implique

**Le service s'endort après une période d'inactivité** et se réveille à la
première visite suivante. Pour une démonstration montrée à un recruteur c'est
acceptable **à condition de le dire** — le portfolio l'annonce déjà en toutes
lettres.

**512 Mo de mémoire.** Laravel y tient largement.

**Les files d'attente tournent en mode direct** (`QUEUE_CONNECTION=sync`) :
l'offre ne permet pas un second processus. Les relances partent donc pendant
la requête au lieu d'être mises en file. Le code du travail en file est
inchangé et reste la vraie implémentation ; seul le mode d'exécution diffère,
et c'est un réglage, pas une réécriture.

---

## Vérifier

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://VOTRE-ADRESSE.zeabur.app/up
```

`200` signifie que l'application répond **et** que la base est jointe : le
contrôle de santé de Laravel vérifie les deux.
