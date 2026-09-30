# Mettre Factura en ligne sur Back4App

Back4App Containers a été retenu pour une raison simple : **aucune carte
bancaire n'est demandée**. En 2026, c'est devenu rare — Render, Koyeb et
Fly.io exigent tous une vérification par carte, Hugging Face Docker Spaces
n'est plus gratuit, et Zeabur a cessé d'accepter de nouveaux projets sur son
cluster partagé.

L'offre libre donne 256 Mo. Mesuré sur cette image : **70 Mo** au repos. Large.

---

## 1. Le service

**back4app.com** → inscription → **Containers** → **Deploy a Web App** →
connecter GitHub → dépôt **`diffonathan/factura`**.

Le `Dockerfile` est à la racine et Back4App le trouve seul — rien à
renseigner.

> **Pourquoi il y est.** Un premier essai le plaçait dans `docker/php/`, ce
> qui se lit mieux. Back4App n'a rien trouvé à la racine, a vu le
> `package.json` du front, conclu « application Node », et construit une image
> sans PHP. Le déploiement est mort sur « node: command not found ». Un
> hébergeur qui ne trouve pas de Dockerfile ne renonce pas : il devine.

Le premier déploiement prend cinq à huit minutes : il construit le front,
installe les dépendances PHP, puis joue les migrations au démarrage.

## 2. Les variables

Tout ce qui n'est pas secret est déjà dans l'image. Il ne reste que ceci, à
saisir dans **Variables** :

| Variable | Valeur |
|---|---|
| `APP_KEY` | voir ci-dessous |
| `DOCUMENTATION_MOT_DE_PASSE` | celui de votre choix |
| `APP_URL` | l'adresse donnée par Back4App, à remplir **après** le premier déploiement |

Puis la base, **au choix** entre deux formes.

### Forme courte, une seule variable

| Variable | Valeur |
|---|---|
| `DB_URL` | la chaîne Neon complète, avec `/factura` avant le `?` |

C'est le plus rapide, et c'est ce que Neon donne à copier. Mais attention :
**une adresse est analysée.** Si le mot de passe contient `@ : / ? # % &`,
le découpage part de travers.

Mesuré, pas supposé : avec le mot de passe `a@b/c:d`, Laravel retient `a`
comme mot de passe, prend **`b` pour le nom du serveur**, et avale le reste
comme nom de base. L'erreur affichée est alors « hôte introuvable » en
désignant une machine qui ne figure nulle part dans ce que vous avez collé.

### Forme longue, cinq variables

| Variable | Valeur |
|---|---|
| `DB_HOST` | l'hôte seul, sans `postgresql://` ni `/` |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | `factura` |
| `DB_USERNAME` | l'utilisateur Neon |
| `DB_PASSWORD` | le mot de passe, **tel quel**, sans encodage |
| `DB_SSLMODE` | `require` (posé tout seul si vous l'omettez) |

Aucune analyse n'a lieu : le mot de passe passe intact, quels que soient ses
caractères. **À préférer dès que le mot de passe n'est pas purement
alphanumérique.**

Si `DB_URL` est renseignée, elle gagne : pour passer à la forme longue, il
faut **supprimer** `DB_URL`, pas seulement ajouter les cinq autres.

### Produire l'APP_KEY

Laravel chiffre les cookies de session avec cette clé. Elle doit être stable :
la changer déconnecte tout le monde.

```bash
docker compose exec php php artisan key:generate --show
```

Copiez la ligne complète, `base64:` compris. Ne la réutilisez nulle part
ailleurs — c'est la clé de chiffrement de l'application.

## 3. Le domaine

Back4App attribue une adresse en `*.b4a.run` dès le déploiement. Reportez-la
dans `APP_URL`, puis redéployez : sans elle, les URL absolues générées par
Laravel pointeraient vers `localhost`.

---

## Ce que l'offre gratuite implique

**Le service s'endort après une période d'inactivité** et se réveille à la
première visite suivante. Pour une démonstration montrée à un recruteur c'est
acceptable **à condition de le dire** — le portfolio l'annonce déjà en toutes
lettres.

**256 Mo de mémoire.** Mesuré : 70 Mo au repos. Laravel y tient largement.

**Les files d'attente tournent en mode direct** (`QUEUE_CONNECTION=sync`) :
l'offre ne permet pas un second processus. Les relances partent donc pendant
la requête au lieu d'être mises en file. Le code du travail en file est
inchangé et reste la vraie implémentation ; seul le mode d'exécution diffère,
et c'est un réglage, pas une réécriture.

---

## Vérifier

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://VOTRE-ADRESSE.b4a.run/up
```

`200` signifie que l'application répond **et** que la base est jointe : le
contrôle de santé de Laravel vérifie les deux.
