# Installer Radio Zinzine sur Debian 13 avec Docker rootless

Ce tutoriel installe un **nouveau serveur Debian 13 Trixie**, puis y transfère le site existant : code, base MySQL et totalité de `public/uploads`. Nginx reçoit les connexions HTTPS sur le serveur et les transmet à Apache dans Docker. MySQL reste accessible uniquement dans le réseau Docker.

Les commandes sont à exécuter en **Bash sur Linux**, sauf indication contraire. Ne les exécuter ni dans PowerShell ni sur l'ancien serveur par inadvertance. Arrêter à la première erreur et la résoudre avant de poursuivre.

Le fichier `12-deploiement.md` reste indépendant. Les fichiers de déploiement ci-dessous seront créés **sur le serveur, hors du dépôt**, dans `/home/zinzine/deploy`. Ils forment une recette dédiée : ne pas les combiner avec les autres fichiers Compose du projet.

## 1. Préparer les informations et la reprise

Les noms, domaines et accès des exemples sont génériques. Aucun domaine n'est présupposé pour votre installation. Remplacer les valeurs suivantes partout où elles apparaissent, avant d'exécuter les étapes concernées :

| Valeur | À renseigner |
| --- | --- |
| `IP_SERVEUR` | Adresse IP du nouveau serveur |
| `radio.example.org` | Exemple de domaine à remplacer par votre futur domaine ; ne pas le saisir tel quel dans Certbot |
| `admin@example.org` | Votre adresse de contact pour le certificat |
| `URL_DEPOT` | URL Git du projet, sans jeton dans l'URL |
| `REF_A_DEPLOYER` | Tag ou identifiant de commit validé |
| `UTILISATEUR_SQL`, `BASE_SOURCE` | Accès et nom de la base sur l'ancien serveur |
| `SERVEUR_SMTP`, `UTILISATEUR`, `MOT_DE_PASSE` | Serveur et identifiants fournis par votre prestataire email |
| `SERVEUR_LIBRETIME` | Hôte de votre installation LibreTime, si utilisée |
| `/CHEMIN/ANCIEN_SITE` | Chemin absolu de l'application sur le serveur source |

Le compte Linux `zinzine`, le dossier `~/radiozinzine`, la base et l'utilisateur SQL `zinzine`, ainsi que les noms d'images et de volumes `radiozinzine`, sont des choix d'exemple utilisables tels quels. Pour les personnaliser, reporter les changements dans **tous** les chemins, commandes et configurations correspondants, notamment les chemins absolus `/home/zinzine/...` et le lanceur Compose. Le sous-dossier `sitezinzine` correspond, lui, à l'arborescence réelle du dépôt. Adapter également le fuseau horaire `Europe/Paris` si nécessaire, de manière cohérente sur l'hôte et dans PHP.

**Si vous n'avez pas encore de domaine :** préparer le serveur, construire l'image et importer les données jusqu'à la section 8. Garder les URL d'exemple comme valeurs provisoires, sans ouvrir le site au public. Pour consulter l'application depuis votre poste, ouvrir un tunnel SSH :

```bash
ssh -N -L 18080:127.0.0.1:8080 zinzine@IP_SERVEUR
```

Laisser cette connexion ouverte et visiter `http://localhost:18080`. Ce contrôle provisoire ne valide pas les liens absolus des médias, les liens envoyés par email ni HTTPS. Une fois le domaine choisi, remplacer `radio.example.org` dans `production.yaml` et dans les exemples Nginx/Certbot, refaire le cache de la section 8, puis poursuivre les sections 9 et 10. Les étapes DNS et Certbot de ce tutoriel sont à différer jusque-là.

Prévoir les accès DNS, SSH, au dépôt et au fournisseur SMTP, l'URL LibreTime actuellement utilisée, et un compte administrateur du site existant. Les comptes applicatifs seront conservés par l'import SQL.

Utiliser un serveur 64 bits avec un disque local supportant les espaces de noms utilisateurs Docker. Prévoir de la place pour les MP3, la base, les images Docker et au moins une copie de sauvegarde complète ; mesurer les volumes existants avant de choisir le disque. Une base MySQL 8.0 est utilisée ici pour rester dans la même version majeure que les recettes du projet. Si la source est MariaDB ou MySQL 8.4+, tester sa conversion séparément avant la bascule ; ne pas importer son répertoire de données brut dans MySQL 8.0.

Faire d'abord une répétition avec une copie des données. Pour la bascule finale, arrêter les écritures sur l'ancien site, refaire un export SQL et une archive des uploads cohérents, puis les importer sur la nouvelle base encore vierge. Ne pas laisser les deux sites recevoir des modifications pendant la propagation DNS.

## 2. Installer Debian et créer le compte de déploiement

Installer Debian 13 minimal avec OpenSSH depuis l'image de l'hébergeur ou l'installateur Debian. Les étapes suivantes supposent un serveur neuf, sans autre application Docker.

**Comme root sur le nouveau serveur :**

```bash
cat /etc/os-release
apt update
apt full-upgrade -y
apt install -y sudo ca-certificates curl git rsync openssl nano \
  uidmap dbus-user-session slirp4netns fuse-overlayfs \
  nginx certbot python3-certbot-nginx ufw
adduser zinzine
usermod -aG sudo zinzine
timedatectl set-timezone Europe/Paris
```

Depuis votre poste, installer votre clé publique et tester une nouvelle connexion :

```bash
ssh-copy-id zinzine@IP_SERVEUR
ssh zinzine@IP_SERVEUR
sudo -v
```

Sous Windows sans `ssh-copy-id`, ajouter votre clé **publique** à `/home/zinzine/.ssh/authorized_keys` via votre accès administrateur ; propriétaire `zinzine`, permissions `700` sur `.ssh` et `600` sur `authorized_keys`.

**Comme zinzine sur le nouveau serveur**, ouvrir les ports nécessaires avant d'activer le pare-feu :

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status
```

Si SSH utilise un autre port, autoriser ce port avant `ufw enable`. Ouvrir également SSH, 80 et 443 dans le pare-feu de l'hébergeur. Garder 3306, 8080 et 8081 fermés depuis Internet. Tester une seconde connexion SSH avant de fermer la première. Redémarrer si les mises à jour du système le demandent.

## 3. Installer Docker et activer le mode rootless

### 3.1. Installer les paquets officiels

**Comme zinzine avec sudo :**

```bash
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg \
  -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
sudo tee /etc/apt/sources.list.d/docker.sources >/dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/debian
Suites: trixie
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io \
  docker-buildx-plugin docker-compose-plugin docker-ce-rootless-extras
```

Cette installation utilise le [dépôt officiel Docker pour Debian](https://docs.docker.com/engine/install/debian/), qui prend en charge Trixie.

Sur ce **nouveau serveur uniquement**, désactiver le démon système installé par les paquets :

```bash
sudo systemctl disable --now docker.service docker.socket
sudo loginctl enable-linger zinzine
grep '^zinzine:' /etc/subuid /etc/subgid
```

Les deux fichiers doivent attribuer à `zinzine` au moins 65 536 identifiants subordonnés. `adduser` les attribue normalement. S'ils manquent, faire attribuer une plage libre dans chacun des deux fichiers avant de continuer ; ne pas copier une plage déjà utilisée par un autre compte.

### 3.2. Démarrer le démon utilisateur

Ouvrir une **vraie session SSH en tant que zinzine**, sans passer par `su` :

```bash
echo "$XDG_RUNTIME_DIR"
dockerd-rootless-setuptool.sh install
docker context use rootless
systemctl --user enable --now docker
docker info
docker run --rm hello-world
docker compose version
```

`XDG_RUNTIME_DIR` doit ressembler à `/run/user/1000` et `docker info` doit afficher `rootless` dans les options de sécurité. Désormais, exécuter **toutes les commandes Docker sans sudo**, avec ce compte. Ne pas l'ajouter au groupe `docker`.

Le maintien du service après déconnexion et au démarrage repose sur `enable-linger` et le service utilisateur. Voir la [configuration officielle rootless](https://docs.docker.com/engine/security/rootless/) et les [instructions systemd utilisateur](https://docs.docker.com/engine/security/rootless/tips/).

## 4. Récupérer le code et préparer les répertoires

**Comme zinzine :**

```bash
umask 077
mkdir -p ~/deploy ~/imports ~/backups
git clone URL_DEPOT ~/radiozinzine
git -C ~/radiozinzine checkout REF_A_DEPLOYER
cd ~/radiozinzine/sitezinzine
test -f composer.lock
test -f yarn.lock
test -f docker/prod/dockerfile.prod
git rev-parse HEAD
```

La racine Git du projet contient un sous-dossier `sitezinzine` avec `bin/console`, `composer.json` et `public`. Si votre livraison contient directement ces fichiers à sa racine, adapter le chemin `context` de Compose plus bas.

Ne pas copier de dump SQL ou de secrets dans ce répertoire de construction. Les imports restent dans `~/imports`, les paramètres privés dans `~/deploy`.

## 5. Créer la recette de construction du serveur

Créer les fichiers suivants avec `nano`. Les chemins sont absolus pour éviter de confondre les différents Compose.

### 5.1. `/home/zinzine/deploy/Dockerfile`

Cette recette reprend PHP 8.3/Apache, les extensions et Encore du projet. Elle utilise Node 22 pour les dépendances frontend actuelles, effectue les scripts Symfony après configuration, et laisse le processus maître Apache démarrer avec les permissions attendues **à l'intérieur du conteneur rootless**. Les requêtes PHP sont traitées par `www-data`. Node 22 est une [branche LTS](https://nodejs.org/en/about/previous-releases).

```dockerfile
FROM php:8.3-apache AS php_base
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev unzip git \
    && docker-php-ext-install intl pdo_mysql zip opcache \
    && rm -rf /var/lib/apt/lists/*
RUN a2enmod rewrite
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html

FROM php_base AS dependencies
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY . .
RUN composer install --no-dev --no-scripts --prefer-dist \
    --no-interaction --optimize-autoloader

FROM node:22-bookworm AS frontend
WORKDIR /app
COPY package.json yarn.lock webpack.config.js ./
COPY assets ./assets
RUN yarn install --frozen-lockfile
RUN yarn build

FROM php_base AS app
ENV APP_ENV=prod APP_DEBUG=0
COPY . .
COPY --from=dependencies /var/www/html/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY docker/prod/apache/apache.conf /etc/apache2/sites-available/000-default.conf
RUN mkdir -p var public/uploads public/images \
    && if [ -d assets/images ]; then cp -a assets/images/. public/images/; fi \
    && chmod -R a+rX /var/www/html \
    && chown -R www-data:www-data var public/uploads
CMD ["apache2-foreground"]
```

Le script `docker/entrypoint.sh` du dépôt n'est pas utilisé par cette recette : l'attente de MySQL passe par un healthcheck, les migrations et le cache sont exécutés explicitement. Ne pas ajouter `RUN_MIGRATIONS` en pensant qu'il déclenchera une migration.

### 5.2. `/home/zinzine/deploy/Dockerfile.dockerignore`

Ce fichier d'exclusion, associé au Dockerfile ci-dessus, évite d'intégrer les médias, secrets et fichiers locaux à l'image :

```text
.git
.env*
!.env
vendor
node_modules
var
tests
public/uploads
public/build
public/assets
*.sql
*.sql.gz
*.7z
*.tar
*.tar.gz
*.bak
.idea
.vscode
```

Le `.env` versionné est conservé pour le démarrage de Symfony ; vérifier qu'il contient uniquement des valeurs de développement sans secret réel. Les paramètres de production seront injectés à l'exécution.

### 5.3. `/home/zinzine/deploy/production.yaml`

Remplacer `radio.example.org` aux deux endroits concernés :

```yaml
parameters:
    app.mp3_public_base_url: 'https://radio.example.org/uploads/emissionsMp3'

framework:
    router:
        default_uri: 'https://radio.example.org'
    trusted_proxies: 'REMOTE_ADDR'
    trusted_headers: ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-port']
    session:
        cookie_secure: auto
```

Cette configuration sera montée dans `config/services_prod.yaml`, chargé après `config/services.yaml`. L'URL des nouveaux MP3 remplace ainsi le domaine actuellement fixé dans ce dernier. Si une future version du projet fournit déjà un `services_prod.yaml`, intégrer son contenu à ce fichier de serveur avant de le monter.

La confiance dans `REMOTE_ADDR` est adaptée ici à un backend publié **uniquement sur 127.0.0.1**, derrière Nginx qui réécrit les en-têtes. Ne pas exposer directement Apache sur Internet avec cette configuration. Voir la [configuration Symfony derrière un proxy](https://symfony.com/doc/7.4/deployment/proxies.html).

### 5.4. `/home/zinzine/deploy/uploads.ini`

```ini
upload_max_filesize=300M
post_max_size=320M
memory_limit=512M
max_execution_time=300
max_input_time=300
date.timezone=Europe/Paris
```

La limite totale POST doit dépasser celle du fichier ; le fichier actuel du dépôt limite POST à 200 Mo. Nginx sera réglé à 320 Mo également. Ces limites ne remplacent pas les validations propres aux formulaires.

### 5.5. Créer les secrets

```bash
cd ~/deploy
umask 077
cat > .env <<EOF
APP_SECRET=$(openssl rand -hex 32)
MYSQL_ROOT_PASSWORD=$(openssl rand -hex 32)
MYSQL_PASSWORD=$(openssl rand -hex 32)
APP_IMAGE=radiozinzine:initial
EOF
chmod 600 .env
nano app.env
```

Contenu de `app.env`, à compléter avec les paramètres réels de l'ancien site :

```dotenv
MAILER_DSN='smtp://UTILISATEUR:MOT_DE_PASSE@SERVEUR_SMTP:587'
MESSENGER_TRANSPORT_DSN='doctrine://default?auto_setup=0'
LIBRETIME_WEEK_INFO_URL='https://SERVEUR_LIBRETIME/api/week-info'
```

```bash
chmod 600 ~/deploy/app.env
chmod 644 ~/deploy/production.yaml ~/deploy/uploads.ini
```

Encoder les caractères réservés dans les identifiants du DSN SMTP. Recopier l'URL LibreTime réellement utilisée, y compris son éventuelle authentification ; l'exemple n'est pas un endpoint confirmé pour votre installation. Vérifier que le fournisseur SMTP autorise les expéditeurs définis dans le code, notamment dans `src/Controller/RegistrationController.php`. Si ces adresses ne correspondent pas à votre installation, prévoir leur adaptation avant de tester les envois réels : le DSN SMTP seul ne change pas l'expéditeur. Pour une répétition sans envoi d'emails, utiliser temporairement `MAILER_DSN='null://null'`, puis rétablir le vrai DSN avant ouverture.

### 5.6. `/home/zinzine/deploy/compose.yaml`

```yaml
name: radiozinzine

services:
  db:
    image: mysql:8.0
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: zinzine
      MYSQL_USER: zinzine
      MYSQL_PASSWORD: ${MYSQL_PASSWORD:?Mot de passe MySQL requis}
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:?Mot de passe root requis}
    command:
      - --character-set-server=utf8mb4
      - --collation-server=utf8mb4_unicode_ci
    volumes:
      - db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD-SHELL", "MYSQL_PWD=$$MYSQL_PASSWORD mysql -h 127.0.0.1 -u zinzine -D zinzine -e 'SELECT 1' >/dev/null 2>&1"]
      interval: 10s
      timeout: 5s
      retries: 30
      start_period: 60s
    logging:
      driver: local
      options:
        max-size: "10m"
        max-file: "5"

  app:
    image: ${APP_IMAGE:?Nom de l'image requis}
    build:
      context: /home/zinzine/radiozinzine/sitezinzine
      dockerfile: /home/zinzine/deploy/Dockerfile
    restart: unless-stopped
    env_file:
      - /home/zinzine/deploy/app.env
    environment:
      APP_ENV: prod
      APP_DEBUG: "0"
      APP_SECRET: ${APP_SECRET:?Secret Symfony requis}
      DATABASE_URL: "mysql://zinzine:${MYSQL_PASSWORD}@db:3306/zinzine?charset=utf8mb4"
    depends_on:
      db:
        condition: service_healthy
    ports:
      - "127.0.0.1:8080:80"
    volumes:
      - uploads:/var/www/html/public/uploads
      - /home/zinzine/deploy/production.yaml:/var/www/html/config/services_prod.yaml:ro
      - /home/zinzine/deploy/uploads.ini:/usr/local/etc/php/conf.d/zz-uploads.ini:ro
    logging:
      driver: local
      options:
        max-size: "10m"
        max-file: "5"

volumes:
  db_data:
    name: radiozinzine_db_data
  uploads:
    name: radiozinzine_uploads
```

Ne pas ajouter `user: www-data` : Apache doit initialiser son processus maître avant de réduire les privilèges de ses workers. Le démon Docker reste rootless. Les volumes nommés permettent de régler les droits depuis le conteneur, sans deviner les UID remappés sur l'hôte.

Les variables MySQL initialisent **un volume vide uniquement**. Modifier `.env` après initialisation ne change pas les mots de passe de la base existante. C'est le comportement de l'[image officielle MySQL](https://hub.docker.com/_/mysql).

### 5.7. Fixer la commande Compose utilisée

```bash
mkdir -p ~/bin
cat > ~/bin/zinzine-compose <<'EOF'
#!/bin/sh
exec docker --context rootless compose \
  --env-file /home/zinzine/deploy/.env \
  -f /home/zinzine/deploy/compose.yaml "$@"
EOF
chmod 700 ~/bin/zinzine-compose
~/bin/zinzine-compose config --quiet
~/bin/zinzine-compose build --pull app
~/bin/zinzine-compose up -d --wait --wait-timeout 360 db
```

La validation `config --quiet` ne montre pas les secrets à l'écran. Toutes les commandes suivantes utilisent ce lanceur, quel que soit le dossier courant. Ne pas poursuivre si la construction échoue ou si MySQL n'est pas sain.

## 6. Exporter et transférer les données existantes

### 6.1. Sur l'ancien serveur

Mettre l'ancien site en maintenance et arrêter ses éventuels traitements qui écrivent en base ou dans les uploads. Conserver cette maintenance jusqu'à la bascule finale. Exporter **toutes les tables**, y compris `doctrine_migration_versions` si elle existe, et toute l'arborescence des uploads.

Exemple avec le client MySQL installé sur l'ancien serveur :

```bash
umask 077
mysqldump -u UTILISATEUR_SQL -p \
  --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF \
  --default-character-set=utf8mb4 BASE_SOURCE > zinzine.sql
test -s zinzine.sql
gzip zinzine.sql
tar -C /CHEMIN/ANCIEN_SITE/public/uploads -czf uploads.tar.gz .
sha256sum zinzine.sql.gz uploads.tar.gz > SHA256SUMS
scp zinzine.sql.gz uploads.tar.gz SHA256SUMS zinzine@IP_SERVEUR:imports/
```

Vérifier le succès de `mysqldump` avant de compresser. Si MySQL tourne dans Docker sur l'ancien serveur, exécuter son client via `docker exec` avec les accès de cet environnement, ou exporter depuis votre outil d'administration. Le dump doit contenir structure et données de la base applicative, sans les bases système MySQL, sans `CREATE DATABASE` ni `USE` imposant un autre nom. Les routines, triggers ou événements ajoutés hors projet doivent aussi être recensés et exportés avec les droits appropriés.

L'archive doit contenir directement `images/`, `emissionsMp3/`, `pages/`, `tag-images/` et les autres dossiers présents, **pas un dossier parent `uploads/`**. Ne pas se limiter aux images et aux MP3 : conserver aussi les fichiers TinyMCE et tout média historique.

Pour des fichiers déjà présents sur votre poste Windows, les envoyer avec `scp` après avoir vérifié leur contenu et recalculé leurs empreintes. Ne pas exporter les médias depuis Git : utiliser ceux du serveur en activité.

### 6.2. Sur le nouveau serveur, comme zinzine

```bash
cd ~/imports
sha256sum -c SHA256SUMS
gzip -t zinzine.sql.gz
tar -tzf uploads.tar.gz | head -30
gzip -dc zinzine.sql.gz > zinzine.sql
chmod 600 zinzine.sql
grep -nE '^(CREATE DATABASE|USE )' zinzine.sql
```

La dernière commande doit ne rien trouver. Si elle trouve un changement de base, réexporter sans celui-ci ou faire préparer une copie adaptée du dump. L'import suivant cible une **base vierge du nouveau serveur** ; ne pas le rejouer sur des données déjà utilisées.

```bash
~/bin/zinzine-compose exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -u zinzine --default-character-set=utf8mb4 zinzine' \
  < ~/imports/zinzine.sql

~/bin/zinzine-compose exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -u zinzine zinzine -e "SHOW TABLES; SELECT COUNT(*) AS emissions FROM emission;"'
```

Ne pas ajouter `--force` à l'import. Comparer le nombre d'émissions avec la source.

Restaurer les médias et régler les permissions depuis le même espace de noms que l'application :

```bash
~/bin/zinzine-compose run --rm --no-deps -T --user 0:0 --entrypoint sh app -c \
  'tar --no-same-owner -xzf - -C public/uploads && chown -R www-data:www-data public/uploads && find public/uploads -type d -exec chmod 755 {} + && find public/uploads -type f -exec chmod 644 {} +' \
  < ~/imports/uploads.tar.gz

~/bin/zinzine-compose run --rm --no-deps --user www-data --entrypoint sh app -c \
  'test -w public/uploads && du -sh public/uploads && find public/uploads -type f | wc -l'
```

Comparer taille et nombre de fichiers avec la source. Ne pas utiliser `chmod 777` ni un `chown 33:33` directement dans le stockage Docker de l'hôte.

## 7. Vérifier et migrer la base importée

```bash
~/bin/zinzine-compose run --rm --no-deps --user www-data app \
  php bin/console doctrine:migrations:status
~/bin/zinzine-compose run --rm --no-deps --user www-data app \
  php bin/console doctrine:migrations:list
~/bin/zinzine-compose run --rm --no-deps --user www-data app \
  php bin/console doctrine:migrations:migrate --dry-run --no-interaction
```

Lire les migrations annoncées **avant** de les appliquer. La migration `Version20260602204623` crée déjà les tables applicatives. Si elle est annoncée à exécuter alors que ces tables existent, l'historique du dump est manquant ou différent : **arrêter cette étape**. Récupérer l'historique depuis la source et vérifier la correspondance du schéma. Ne pas marquer toutes les migrations comme exécutées et ne pas lancer `doctrine:schema:update --force` pour contourner ce problème.

Si le plan correspond bien aux migrations manquantes de la base source :

```bash
~/bin/zinzine-compose run --rm --no-deps --user www-data app \
  php bin/console doctrine:migrations:migrate --no-interaction
~/bin/zinzine-compose run --rm --no-deps --user www-data app \
  php bin/console doctrine:schema:validate
```

Résoudre tout écart avant ouverture. Les opérations DDL MySQL ne sont pas toutes annulables par transaction ; garder le dump d'origine intact pour recommencer sur une base vierge si nécessaire.

## 8. Préparer les assets, le cache et lancer Apache

Créer le conteneur sans le démarrer, puis effectuer la préparation dans **ce même conteneur**, car le cache et les assets installés ne sont pas des volumes partagés :

```bash
~/bin/zinzine-compose create app
~/bin/zinzine-compose start app
~/bin/zinzine-compose exec -T --user 0:0 app \
  php bin/console assets:install public --env=prod
~/bin/zinzine-compose exec -T --user 0:0 app chown -R www-data:www-data var
~/bin/zinzine-compose exec -T --user www-data app \
  php bin/console cache:clear --env=prod --no-debug
~/bin/zinzine-compose exec -T --user www-data app \
  php bin/console cache:warmup --env=prod --no-debug
~/bin/zinzine-compose exec -T app php -r \
  'exit(is_file("public/build/entrypoints.json") ? 0 : 1);'
~/bin/zinzine-compose ps
~/bin/zinzine-compose logs --tail=100 app
curl -I -H 'Host: radio.example.org' http://127.0.0.1:8080/
```

Le frontend du projet est chargé par Encore et compilé pendant le build. Les auto-scripts Composer ont volontairement été différés ; `assets:install` est donc exécuté ici. Les templates actuels n'appellent pas `importmap()`, il n'est pas nécessaire d'ajouter une compilation Importmap à ce parcours.

Une réponse HTTP 200 ou une redirection applicative attendue confirme que le serveur répond ; une erreur 500 doit être résolue dans les logs avant de continuer. Vérifier aussi un fichier réel sous `/build/` et une image sous `/uploads/`.

## 9. Installer le proxy Nginx et HTTPS

### 9.1. Créer le site Nginx

**Comme zinzine avec sudo**, créer `/etc/nginx/sites-available/radiozinzine` :

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name radio.example.org;

    client_max_body_size 320m;
    client_body_timeout 300s;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_set_header X-Forwarded-Host "";
        proxy_set_header Forwarded "";
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
    }
}
```

Activer le site :

```bash
sudo ln -s /etc/nginx/sites-available/radiozinzine /etc/nginx/sites-enabled/radiozinzine
sudo nginx -t
sudo systemctl enable --now nginx
sudo systemctl reload nginx
curl -I -H 'Host: radio.example.org' http://127.0.0.1/
```

Ne créer le lien qu'une fois. Garder l'ancien site en maintenance pendant l'ouverture du nouveau. Dans la zone DNS, faire pointer le domaine vers la nouvelle IP. Ne conserver un enregistrement AAAA que si l'IPv6 du nouveau serveur est configurée et accessible.

### 9.2. Obtenir le certificat

Une fois le domaine résolu publiquement vers le nouveau serveur et le port 80 accessible :

```bash
sudo certbot --nginx -d radio.example.org \
  --redirect --agree-tos -m admin@example.org
sudo nginx -t
sudo systemctl enable --now certbot.timer
sudo certbot renew --dry-run
curl -I https://radio.example.org/
```

Le paquet [Certbot pour Nginx fourni par Debian Trixie](https://packages.debian.org/trixie/python3-certbot-nginx) gère le certificat et sa configuration. Si vous devez valider HTTPS **avant** de changer les DNS, utiliser une validation DNS-01 avec le module propre à votre fournisseur DNS ; la commande HTTP ci-dessus attend que le domaine pointe déjà vers ce serveur.

## 10. Contrôler le site avant de rouvrir les écritures

Dans un navigateur, vérifier :

- l'accueil et les listes d'émissions, avec les images et les styles ;
- la connexion avec un compte existant et ses droits d'administration ;
- la recherche et l'affichage d'une émission historique ;
- la lecture d'un ancien MP3 et le déplacement dans sa piste audio ;
- l'ajout d'une image et d'un MP3 avec un compte autorisé, puis leur lecture ;
- les formulaires et composants interactifs, sans erreur JavaScript ;
- un email de réinitialisation vers une boîte que vous contrôlez ;
- l'affichage LibreTime si cette intégration est utilisée.

Les URL absolues déjà enregistrées dans la colonne `emission.url` restent celles du dump. Si le domaine change, conserver leur ancien domaine accessible ou préparer une migration ciblée après inventaire et sauvegarde. Le paramètre `app.mp3_public_base_url` règle les **futurs** fichiers, pas les URL historiques. Vérifier aussi les liens présents dans les contenus riches.

Tester le redémarrage pendant la fenêtre de maintenance :

```bash
sudo reboot
```

Après reconnexion :

```bash
systemctl --user is-active docker
~/bin/zinzine-compose ps
curl -I https://radio.example.org/
```

Rouvrir les écritures sur le nouveau site seulement après ces contrôles. Conserver l'ancien serveur et les exports intacts pendant la période de validation.

## 11. Sauvegarder le site

Une sauvegarde complète comprend la base, les uploads, les fichiers privés de `~/deploy` et l'identifiant du code/image déployé. Pour un instantané cohérent, arrêter temporairement l'application pendant l'export ; MySQL reste démarré.

**Comme zinzine :**

```bash
umask 077
BACKUP_DIR="$HOME/backups/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"
~/bin/zinzine-compose stop app
~/bin/zinzine-compose exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump -u zinzine --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF zinzine' \
  > "$BACKUP_DIR/zinzine.sql"
~/bin/zinzine-compose run --rm --no-deps -T --entrypoint tar app \
  -C /var/www/html/public/uploads -czf - . > "$BACKUP_DIR/uploads.tar.gz"
tar -C "$HOME" -czf "$BACKUP_DIR/deploy.tar.gz" deploy
git -C ~/radiozinzine rev-parse HEAD > "$BACKUP_DIR/commit.txt"
~/bin/zinzine-compose images > "$BACKUP_DIR/images.txt"
~/bin/zinzine-compose start app
```

Vérifier chaque code de retour. En cas d'erreur, remettre l'application en service avec `start app` et traiter la sauvegarde comme invalide. Compresser le SQL uniquement si l'export a réussi, vérifier les archives, calculer leurs empreintes, puis transférer la sauvegarde chiffrée vers un stockage indépendant du serveur. `deploy.tar.gz` contient les secrets.

Tester périodiquement une restauration sur un environnement isolé : même recette avec un autre nom de projet **et d'autres noms de volumes**, import SQL dans une base vide, restauration des uploads, puis contrôles des sections 7 à 10. Ne jamais tester la restauration par-dessus la production.

## 12. Déployer une mise à jour

Faire une sauvegarde complète selon la section précédente et conserver l'image actuelle. Construire la nouvelle version avant l'interruption de service :

```bash
git -C ~/radiozinzine fetch --tags
git -C ~/radiozinzine checkout NOUVELLE_REF
nano ~/deploy/.env
```

Dans `.env`, changer seulement `APP_IMAGE`, par exemple `radiozinzine:2026-09-20`. Ne pas régénérer les secrets.

```bash
~/bin/zinzine-compose build --pull app
~/bin/zinzine-compose stop app
~/bin/zinzine-compose run --rm --no-deps --user www-data app \
  php bin/console doctrine:migrations:migrate --dry-run --no-interaction
```

Après examen du plan :

```bash
~/bin/zinzine-compose run --rm --no-deps --user www-data app \
  php bin/console doctrine:migrations:migrate --no-interaction
~/bin/zinzine-compose up -d --no-deps --force-recreate app
~/bin/zinzine-compose exec -T --user 0:0 app php bin/console assets:install public --env=prod
~/bin/zinzine-compose exec -T --user 0:0 app chown -R www-data:www-data var
~/bin/zinzine-compose exec -T --user www-data app php bin/console cache:clear --env=prod --no-debug
~/bin/zinzine-compose exec -T --user www-data app php bin/console cache:warmup --env=prod --no-debug
~/bin/zinzine-compose logs --tail=100 app
```

Refaire les contrôles fonctionnels. Cette procédure comporte une courte interruption ; prévoir une fenêtre de maintenance. Si aucune migration incompatible n'a été appliquée, remettre l'ancien `APP_IMAGE` et recréer `app` permet de revenir au code précédent ; refaire ensuite l'installation des assets et le cache. Si le schéma ou les données ont changé de façon incompatible, restaurer la sauvegarde cohérente dans un environnement de remplacement avant de réouvrir le service. Ne pas lancer automatiquement les migrations `down()`.

Ne pas utiliser `docker compose down -v` ni `docker volume prune` : les volumes contiennent la base et les médias. Ne pas changer la version majeure MySQL pendant une simple mise à jour applicative. Programmer séparément son évolution après validation sur une copie restaurée.

## 13. Commandes de dépannage

```bash
# Démon Docker rootless
systemctl --user status docker
journalctl --user -u docker -n 100 --no-pager

# Application et base
~/bin/zinzine-compose ps
~/bin/zinzine-compose logs --tail=100 app
~/bin/zinzine-compose logs --tail=100 db
~/bin/zinzine-compose exec -T --user www-data app php bin/console about

# Proxy et espace disque
sudo nginx -t
sudo journalctl -u nginx -n 50 --no-pager
df -h
docker system df
```

| Symptôme | Vérification à effectuer |
| --- | --- |
| `Cannot connect to the Docker daemon` | Connexion SSH avec `zinzine`, contexte `rootless`, état du service utilisateur |
| `Failed to connect to bus` | Rouvrir une session SSH directe ; vérifier `dbus-user-session` et `XDG_RUNTIME_DIR` |
| Nginx répond 502 | Tester `http://127.0.0.1:8080`, puis consulter les logs Apache |
| Erreur MySQL `Access denied` | Comparer les identifiants avec ceux ayant initialisé le volume ; une modification de `.env` ne les réinitialise pas |
| Erreur 500 Symfony | Lire les logs `app`, vérifier schéma, variables, droits sur `var` et cache |
| Upload refusé en 413 | Vérifier `client_max_body_size` dans le site Nginx actif et recharger Nginx |
| Fichier trop volumineux côté PHP | Vérifier les valeurs effectives avec `php -i` dans `app`, puis recréer le conteneur si nécessaire |
| MP3 introuvable | Vérifier le chemin dans l'archive, la casse, les permissions et le domaine de `emission.url` |
| Erreur lors des migrations sur une table existante | Comparer l'historique importé avec les migrations ; ne pas forcer leur exécution |

Les emails sont actuellement routés de façon synchrone dans `config/packages/messenger.yaml` : ce tutoriel n'ajoute pas de worker. La commande `app:cleanup-annonces` supprime définitivement des annonces anciennes ; ne reprendre une planification existante qu'après avoir confirmé sa fréquence et son utilité auprès de l'équipe.

Ce document fournit une procédure à exécuter et à valider sur le serveur cible. La construction de l'image, l'import de votre dump et la bascule DNS ne sont pas considérés comme effectués par la rédaction de ce fichier.
