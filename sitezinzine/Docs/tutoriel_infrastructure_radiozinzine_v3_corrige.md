# Radio Zinzine V3 — Procédure d'installation Domaine Public (révision 2)

**Date : 20 novembre 2026 — Destinataire : administrateur système Domaine Public.**  
**Statut : procédure de provisionnement détaillée, avec points d'arrêt obligatoires avant toute opération applicative ou bascule.**

> **Règle absolue :** `radiozinzine.org` continue de servir le site historique, qui **n'utilise pas Docker**. Le serveur cible est **Debian 13**, sans installation Docker préexistante (informations confirmées par la responsable du projet). Ne pas remplacer, redémarrer ni reconfigurer les services du site historique en dehors des ajouts Nginx explicitement validés.

## 0. Architecture et responsabilités

| | Préproduction V3 | Future production V3 |
|---|---|---|
| Domaine | `dev.radiozinzine.org` | `radiozinzine.org` **uniquement après bascule** |
| Compte système | `zinzine-preprod` | `zinzine-prod` |
| Docker | démon **rootless dédié** | démon **rootless dédié** |
| Projet Compose | `zinzine-preprod` | `zinzine-prod` |
| Répertoire | `/srv/radiozinzine/preprod` | `/srv/radiozinzine/prod` |
| Backend HTTP (proposition) | `127.0.0.1:18080` | `127.0.0.1:18081` |
| Images | `v1.0.xx`, déployées par **digest** | `v1.1.xx`, même digest que version validée |
| MySQL, uploads, audio, secrets | indépendants | indépendants |

Nginx reste sur l'hôte. Les ports 18080/18081 sont des **propositions**, pas des ports vérifiés. Le serveur actuel, son Nginx et son ancien site doivent être inventoriés avant tout changement. L'application Symfony des deux environnements fonctionne avec `APP_ENV=prod` et `APP_DEBUG=0` : *préproduction* n'est pas un environnement Symfony `dev`.

**Répartition :** le sysadmin prépare l'hôte, Docker, DNS/TLS, stockage, sauvegardes, proxy et supervision ; le développement fournit l'image approuvée, la configuration Symfony complète, les scripts de déploiement et les migrations. Les deux parties valident la recette.

## 1. Inventaire et sauvegarde de l'existant — OBLIGATOIRE

En session administrateur, vérifier sans rien arrêter :

```bash
cat /etc/os-release
uname -m
hostnamectl
sudo ss -lntup
sudo systemctl --type=service --state=running
command -v nginx && sudo nginx -t
command -v docker || true
findmnt -T /srv
lsblk -f
df -hT
free -h
getent hosts radiozinzine.org dev.radiozinzine.org
```

**Avant de poursuivre**, confirmer que les ports 18080 et 18081 sont libres, que la capacité couvre *ancien site + deux installations + médias + sauvegardes + croissance*, et que la configuration Nginx actuelle est sauvegardée dans un emplacement privé. Si Nginx existe, sauvegarder sa configuration sans exposer les secrets :

```bash
sudo install -d -m 0700 /root/zinzine-v3-inventaire
sudo sh -c 'nginx -T > /root/zinzine-v3-inventaire/nginx-before.txt 2>&1'
sudo chmod 0600 /root/zinzine-v3-inventaire/nginx-before.txt
```

**Point d'arrêt 1 :** consigner le serveur HTTP réel de l'ancien site, le vhost actif `radiozinzine.org`, le mode d'obtention de ses certificats, les ports libres, le système de fichiers sous `/srv`, l'espace libre et la disponibilité DNS de `dev.radiozinzine.org`. Ne pas installer un second Nginx si celui de l'ancien site fonctionne déjà. La procédure ci-dessous suppose Nginx existant et géré par le sysadmin.

## 2. Installation Docker officielle sur Debian 13

L'ancien site n'utilise pas Docker et aucun autre Docker n'est installé (confirmé). Contrôler néanmoins le résultat de l'inventaire. Ne pas modifier le Nginx existant.

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl uidmap dbus-user-session slirp4netns fuse-overlayfs
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
printf 'Types: deb\nURIs: https://download.docker.com/linux/debian\nSuites: %s\nComponents: stable\nArchitectures: %s\nSigned-By: /etc/apt/keyrings/docker.asc\n' "$(. /etc/os-release && echo "$VERSION_CODENAME")" "$(dpkg --print-architecture)" | sudo tee /etc/apt/sources.list.d/docker.sources
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin docker-ce-rootless-extras
```

L'installation du paquet `docker-ce` peut démarrer un démon **rootful** par défaut. Puisqu'il ne sera pas utilisé pour V3, vérifier qu'aucun autre service n'en dépend avant de le désactiver :

```bash
sudo systemctl disable --now docker.service docker.socket
sudo systemctl status docker.service docker.socket --no-pager
```

Ne **pas** supprimer les binaires Docker. Aucun compte V3 ne doit appartenir au groupe `docker` rootful. Les commandes Docker suivantes s'exécutent **dans la session du compte concerné**, jamais via `sudo docker`.

**Point d'arrêt 2 :** `docker --version`, `docker compose version`, `dockerd-rootless-setuptool.sh --help` et l'absence d'un service Docker rootful utilisé par V3.

## 3. Comptes système, plages subordonnées et Docker rootless

Créer les deux comptes avec répertoires personnels et accès administratifs distincts :

```bash
sudo adduser --disabled-password --gecos '' zinzine-preprod
sudo adduser --disabled-password --gecos '' zinzine-prod
id zinzine-preprod
id zinzine-prod
sudo grep -E '^(zinzine-preprod|zinzine-prod):' /etc/subuid /etc/subgid
```

Chaque compte doit disposer d'au moins **65 536 UID subordonnés et 65 536 GID subordonnés**, sans chevauchement entre comptes. Debian peut les attribuer à la création ; **ne pas ajouter des plages au hasard**. Si elles manquent, le sysadmin les alloue après inventaire complet de `/etc/subuid` et `/etc/subgid`, puis recontrôle les collisions. Ne pas reprendre les mappings de Hetzner.

Activer la persistance des services utilisateur :

```bash
sudo loginctl enable-linger zinzine-preprod
sudo loginctl enable-linger zinzine-prod
loginctl show-user zinzine-preprod -p Linger
loginctl show-user zinzine-prod -p Linger
```

**Pour chacun des comptes**, ouvrir une véritable session utilisateur avec `pam_systemd` et un bus systemd utilisateur opérationnel (SSH autorisé ou `machinectl shell` si disponible). **Ne pas présumer que `sudo su - ...` fournit le bus systemd.** Exécuter *dans la session `zinzine-preprod`*, puis répéter *dans la session `zinzine-prod`* :

```bash
dockerd-rootless-setuptool.sh install
systemctl --user enable --now docker
systemctl --user is-active docker
docker context show
docker info --format '{{json .SecurityOptions}}'
docker compose version
printf 'UID session : %s\nSocket : %s\n' "$(id -u)" "$XDG_RUNTIME_DIR/docker.sock"
```

Vérifier dans `docker info` la présence de `rootless` et le socket du **bon compte**. En cas de démon rootful encore actif, l'outil d'installation rootless peut exiger `--force` ; ne l'utiliser qu'après avoir vérifié les prérequis et la portée de l'option. **Interdire l'administration croisée** : chaque opérateur n'utilise que la session et le socket de l'environnement qu'il est autorisé à gérer. Protéger les accès SSH de ces comptes selon la politique du sysadmin.

**Point d'arrêt 3 :** deux démons rootless actifs, deux sockets différents, plages subordonnées non chevauchantes, services démarrant après redémarrage, aucun accès rootful nécessaire.

## 4. Répertoires persistants et isolation hôte

Créer les répertoires administratifs **en root**, sans donner à un compte accès au répertoire de l'autre :

```bash
sudo install -d -m 0755 -o root -g root /srv/radiozinzine
sudo install -d -m 0700 -o zinzine-preprod -g zinzine-preprod /srv/radiozinzine/preprod
sudo install -d -m 0700 -o zinzine-prod -g zinzine-prod /srv/radiozinzine/prod
```

Dans la session **de chaque compte**, utiliser son propre chemin (`preprod` ou `prod`) :

```bash
cd /srv/radiozinzine/preprod
mkdir -p uploads audio/library audio/work audio/trash private
chmod 0700 private
```

Pour `zinzine-prod`, remplacer `preprod` par `prod`. Le dossier de sauvegarde principal est **hors** des répertoires applicatifs et protégé par root, par exemple `/var/backups/radiozinzine/{preprod,prod}` (à adapter au stockage hors serveur). Ne pas placer de dumps SQL dans un répertoire servi par Apache.

Arborescence :

```text
/srv/radiozinzine/
  preprod/             # zinzine-preprod, 0700
    compose.yaml
    .env.compose       # secret, 0600
    .env.app           # secret, 0600
    uploads/
    audio/
      library/
      work/
      trash/
    private/
  prod/                # zinzine-prod, 0700
    compose.yaml
    .env.compose
    .env.app
    uploads/
    audio/{library,work,trash}/
    private/
```

**Attention :** `mkdir` sous le compte hôte ne donne **pas** automatiquement à `appuser` (UID/GID remappés) les droits d'écriture. Ne pas passer les répertoires en `777`. Les droits finaux seront appliqués **après** qualification du mapping rootless (§7). Ne pas créer des montages séparés pour `library`, `work` et `trash` : les liens physiques exigent un même système de fichiers.

## 5. Images candidates, secrets et configuration Symfony — POINT D'ARRÊT APPLICATION

L'image prévue est `ghcr.io/eloisemcglasson/sitezinzine-app`. Le développement doit remettre **le digest réel approuvé** (`ghcr.io/...@sha256:<64 caractères hexadécimaux>`) ; ne pas utiliser `latest`. Les images `v1.0.xx` et `v1.1.xx` ne sont que des références de publication ; **Compose déploie le digest**.

Le développement doit également fournir, pour chaque environnement, les valeurs ou instructions vérifiées des paramètres Symfony :

- `APP_ENV=prod`, `APP_DEBUG=0`, `APP_SECRET` propre à chaque environnement ;
- `DATABASE_URL` pointant vers `db:3306`, **la base de cet environnement**, et le compte SQL correspondant ;
- `MAILER_DSN` et politique d'envoi de préproduction (pas d'e-mails réels involontaires) ;
- variables Messenger, LibreTime et autres intégrations réellement utilisées ;
- **origine MP3 absolue** : `app.mp3_public_base_url` est actuellement un paramètre de `config/services.yaml`, **pas une variable d'environnement automatiquement reconnue** ; un raccordement explicite dans le code/config Symfony est nécessaire avant de prétendre le configurer depuis `.env.app` ;
- proxies de confiance Symfony, origine HTTPS et en-têtes transmis par Nginx ;
- limites PHP d'upload cohérentes : l'audit a trouvé `post_max_size=200M` et `upload_max_filesize=300M` ; la valeur métier finale reste à fixer.

**Ne pas lancer Apache** tant que la configuration effective n'est pas contrôlée sur le digest cible. Les secrets doivent être produits par un gestionnaire sûr, jamais copiés depuis Hetzner, jamais commités, jamais affichés dans un rapport ou une sortie `docker compose config` intégrale. Le Dockerfile copie un contexte de construction : les fichiers privés serveur ne doivent pas être présents dans ce contexte.

## 6. Fichier Compose commun aux deux comptes

Créer `/srv/radiozinzine/preprod/compose.yaml`, puis le même fichier dans `prod`. Ce modèle doit être **validé contre l'image finale** avant lancement ; il n'utilise ni `container_name`, ni port MySQL public, ni volume partagé. Les deux comptes ont chacun leur propre démon rootless.

```yaml
services:
  db:
    image: ${MYSQL_IMAGE:?digest_mysql_obligatoire}
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: ${MYSQL_DATABASE:?}
      MYSQL_USER: ${MYSQL_USER:?}
      MYSQL_PASSWORD: ${MYSQL_PASSWORD:?}
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:?}
    volumes:
      - mysql_data:/var/lib/mysql
    networks:
      - internal
    healthcheck:
      test: ["CMD-SHELL", "MYSQL_PWD=\"$$MYSQL_PASSWORD\" mysql -h 127.0.0.1 -u \"$$MYSQL_USER\" \"$$MYSQL_DATABASE\" -Nse 'SELECT 1' >/dev/null"]
      interval: 10s
      timeout: 5s
      retries: 30
      start_period: 30s

  app:
    image: ${APP_IMAGE:?digest_app_obligatoire}
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    env_file:
      - .env.app
    ports:
      - "127.0.0.1:${APP_PORT:?}:80"
    volumes:
      - ./uploads:/var/www/html/public/uploads
      - ./audio:/srv/zinzine/audio
    networks:
      - internal

volumes:
  mysql_data:

networks:
  internal:
```

**Pourquoi `MYSQL_IMAGE` ?** Éviter un tag flottant `mysql:8.0` : le sysadmin et le développement doivent qualifier un digest compatible MySQL 8.0 (au minimum 8.0.16 pour les migrations auditées) et sa disponibilité dans le registre. Le digest n'est **pas connu** dans cet échange.

Dans `.env.compose` de **préproduction** (modèle à compléter hors Git) :

```dotenv
APP_PORT=18080
APP_IMAGE=ghcr.io/eloisemcglasson/sitezinzine-app@sha256:REMPLACER_PAR_64_HEX
MYSQL_IMAGE=mysql@sha256:REMPLACER_PAR_64_HEX
MYSQL_DATABASE=zinzine_preprod
MYSQL_USER=zinzine_preprod
MYSQL_PASSWORD=REMPLACER_SECRET_UNIQUE
MYSQL_ROOT_PASSWORD=REMPLACER_SECRET_ROOT_UNIQUE
```

Dans `.env.compose` de **production**, mêmes clés mais `APP_PORT=18081`, `MYSQL_DATABASE=zinzine_prod`, `MYSQL_USER=zinzine_prod`, **autres secrets** et digest applicatif de la version approuvée. `MYSQL_IMAGE` doit désigner un manifeste réellement accessible depuis le serveur ; l'architecture CPU doit être compatible.

Dans `.env.app` de **préproduction**, squelette **incomplet tant que le développement n'a pas livré la configuration finale** :

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=REMPLACER_SECRET_UNIQUE
DATABASE_URL=mysql://zinzine_preprod:REMPLACER_SECRET_URL_ENCODE@db:3306/zinzine_preprod?serverVersion=8.0
MAILER_DSN=null://null
```

Dans `.env.app` de **production**, utiliser le compte SQL et la base `zinzine_prod`, un `APP_SECRET` différent et le `MAILER_DSN` validé. **Attention :** encoder correctement les caractères spéciaux du mot de passe dans `DATABASE_URL` ; la version `serverVersion` doit correspondre à la version réelle. `MAILER_DSN=null://null` est une proposition pour empêcher l'envoi d'e-mails en préproduction, à valider selon les workflows à tester. Les variables supplémentaires requises par l'application restent à renseigner : ne pas prendre ce squelette pour une configuration complète.

Pour chaque compte :

```bash
chmod 0600 .env.compose .env.app
chmod 0600 compose.yaml
```

**Contrôles avant démarrage** (dans la session du compte et le bon répertoire) :

```bash
docker compose --project-directory "$PWD" --env-file "$PWD/.env.compose" -f "$PWD/compose.yaml" -p zinzine-preprod config --quiet
```

Remplacer `zinzine-preprod` par `zinzine-prod` dans la session production. Vérifier le digest avec le registre et la disponibilité de l'image ; `config --quiet` vérifie la syntaxe et l'interpolation, **pas** les droits, la connectivité ou l'existence des images. Ne pas imprimer la configuration résolue, qui contient des secrets.

**Point d'arrêt 4 :** digests réels, secrets distincts, paramètres Symfony vérifiés, politique SMTP et limite MP3 approuvées.

## 7. Permissions rootless et tests MP3 sans Apache

### 7.1 Identifier l'utilisateur de l'image et le mapping réel

Dans chaque session rootless, depuis le bon répertoire, **après `docker pull` de l'image approuvée** :

```bash
docker image inspect --format '{{.Config.User}}' "$(sed -n 's/^APP_IMAGE=//p' .env.compose)"
cat /proc/self/uid_map
cat /proc/self/gid_map
```

**Attention :** `/proc/self/uid_map` sur l'hôte décrit le shell, pas forcément le démon rootless. Pour connaître le mapping **réel d'un conteneur**, exécuter un conteneur ponctuel de l'image candidate, sans réseau, sans Apache, en remplaçant l'entrypoint :

```bash
docker run --rm --network none --entrypoint /bin/sh "$(sed -n 's/^APP_IMAGE=//p' .env.compose)" -c 'id; cat /proc/self/uid_map; cat /proc/self/gid_map'
```

L'image peut utiliser `USER appuser` avec un UID/GID numérique ; la sortie `id` est la référence, et non une supposition. **Le sysadmin doit calculer le propriétaire hôte attendu** à partir des plages et du mapping réellement observé. Pour un mapping classique `0 -> UID_hôte` sur un identifiant, puis `1..65536 -> plage_subuid`, un UID conteneur `u >= 1` se traduit par `subuid_debut + u - 1` ; **ne pas appliquer cette formule si le mapping affiché diffère**. Faire le même calcul pour le GID.

### 7.2 Appliquer les droits

Depuis une session root, **après avoir vérifié les valeurs calculées**, remplacer `UID_HOTE_PHP`, `GID_HOTE_PHP` et le chemin cible :

```bash
sudo chown -R UID_HOTE_PHP:GID_HOTE_PHP /srv/radiozinzine/preprod/uploads /srv/radiozinzine/preprod/audio
sudo chmod 0755 /srv/radiozinzine/preprod/uploads /srv/radiozinzine/preprod/audio /srv/radiozinzine/preprod/audio/library
sudo chmod 0700 /srv/radiozinzine/preprod/audio/work /srv/radiozinzine/preprod/audio/trash
```

Ces lignes sont **des gabarits** : les marqueurs ne sont pas des nombres valides. Refaire les calculs pour `prod`, sans jamais réutiliser les valeurs de préproduction. Le parent `/srv/radiozinzine/preprod` reste `0700` sous son compte : la traversée par les UID subordonnés peut être bloquée. Le sysadmin doit qualifier **tout le chemin** (par exemple avec des ACL de traversée minimales pour l'UID PHP remappé, sans accès au répertoire de l'autre environnement), ou retenir une stratégie de montage/permissions validée par test. **Ne pas rendre les parents publics avec `chmod 777`.**

### 7.3 Qualification en conteneur ponctuel

Tester dans un conteneur de l'image **candidate**, sans réseau, avec `--entrypoint /bin/sh` et les mêmes bind mounts que Compose :

```bash
docker run --rm --network none --entrypoint /bin/sh -v "$PWD/uploads:/var/www/html/public/uploads" -v "$PWD/audio:/srv/zinzine/audio" "$(sed -n 's/^APP_IMAGE=//p' .env.compose)" -c 'id; touch /srv/zinzine/audio/work/.test-write && rm /srv/zinzine/audio/work/.test-write; touch /var/www/html/public/uploads/.test-write && rm /var/www/html/public/uploads/.test-write'
```

Puis vérifier **sur des fichiers de test uniquement** : création de liens `work -> library`, `library -> trash`, `trash -> library`, égalité des numéros d'inode, non-écrasement d'un fichier existant et fonctionnement du verrou applicatif. Ne pas se contenter de `flock` : `Mp3StorageLock` vérifie notamment `trash` en `0700`, la propriété attendue et un fichier de verrou régulier `0600` à un seul lien. Pour la contention et les règles exactes du verrou, utiliser le test ou la commande de qualification fournis par le développement ; **aucune commande métier de contention n'a été confirmée dans l'audit**.

**Point d'arrêt 5 :** tests écriture, liens, inodes et verrou réussis **dans les deux environnements**, sans lancer Apache, sans toucher à des MP3 réels. Si échec : corriger les propriétaires/mappings/ACL, jamais `777`.

## 8. Premier démarrage, import et migrations — séquence par environnement

Cette section est **un parcours opératoire avec étapes conditionnelles**, car ni les dumps SQL de Domaine Public, ni le digest candidat, ni l'état des migrations ne sont fournis. Ne jamais exécuter les migrations sur l'ancien site.

1. Dans la session du **compte concerné**, vérifier projet, socket rootless, répertoire, digest et absence d'erreur Compose :

```bash
pwd
id
docker context show
docker info --format '{{json .SecurityOptions}}'
docker compose --env-file .env.compose -f compose.yaml -p zinzine-preprod config --quiet
```

2. Démarrer **uniquement MySQL**, après validation des secrets et de l'image MySQL :

```bash
docker compose --env-file .env.compose -f compose.yaml -p zinzine-preprod up -d db
docker compose --env-file .env.compose -f compose.yaml -p zinzine-preprod ps db
```

3. Tester une **vraie requête authentifiée** sur la base du projet (la sonde du Compose le fait ; `mysqladmin ping` seul n'est pas une preuve d'accès SQL).

4. **Décider de la source des données** : initialisation vide ou import depuis une sauvegarde identifiée. **Ne pas importer un dump non identifié.** Pour un import, contrôler l'encodage, la version MySQL, le schéma, les volumes et la cohérence des médias. Le transfert initial et la synchronisation finale de l'ancien site constituent un chantier de migration distinct. Les commandes d'import exactes dépendent du format réel du dump ; ne pas les inventer.

5. Avec l'image candidate, exécuter les contrôles Doctrine dans un **conteneur ponctuel** du projet, sans publier le port Apache :

```bash
docker compose --env-file .env.compose -f compose.yaml -p zinzine-preprod run --rm --no-deps --entrypoint php app bin/console doctrine:migrations:status --env=prod
```

6. Avant de migrer : contrôler l'historique Doctrine et les préconditions des migrations. Les migrations `Version20261112120000`, `Version20261115120000`, `Version20261119120000` et `Version20261119123000` nécessitent une attention particulière : unicité de `emission.url`, incidents MP3 et journaux ; plusieurs sont non transactionnelles. Vérifier les doublons avant l'unicité URL. **Ne pas migrer sans sauvegarde restaurable et validation du plan de reprise.**

7. Sous maintenance et **uniquement après approbation**, exécuter sur le projet cible :

```bash
docker compose --env-file .env.compose -f compose.yaml -p zinzine-preprod run --rm --no-deps --entrypoint php app bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

8. Vérifier le statut et le schéma. En cas d'échec d'une migration non transactionnelle : **arrêt immédiat**, maintenance maintenue, examen du schéma et de l'historique ; décider reprise ou restauration, **pas de rollback aveugle par changement de digest**.

9. Démarrer **seulement alors** Apache :

```bash
docker compose --env-file .env.compose -f compose.yaml -p zinzine-preprod up -d --no-deps app
docker compose --env-file .env.compose -f compose.yaml -p zinzine-preprod ps
curl --fail --silent --show-error --output /dev/null --write-out '%{http_code}\n' http://127.0.0.1:18080/
```

Un code HTTP 3xx peut être normal pour une redirection : le critère de recette doit être défini pour les pages et les en-têtes attendus. Répéter **toute** la séquence dans la session `zinzine-prod` en remplaçant `preprod` par `prod`, le projet par `zinzine-prod` et le port par `18081`. La production reste privée pendant cette phase.

**Important :** `docker/prod/deploy.sh` reste volontairement bloqué avec `exit 78`. **Ne pas l'utiliser** comme procédure automatisée tant que les sauvegardes, la maintenance, les migrations et les contrôles n'ont pas été implémentés et qualifiés.

## 9. Nginx, HTTPS et coexistence avec le site historique

**Avant tout :** sauvegarder la configuration active ; confirmer la gestion DNS/TLS par Domaine Public ; obtenir un certificat valide pour `dev.radiozinzine.org` sans remplacer celui de `radiozinzine.org`.

Exemple de vhost **distinct** `dev.radiozinzine.org`, à intégrer à la politique TLS existante :

```nginx
server {
    listen 443 ssl;
    server_name dev.radiozinzine.org;

    ssl_certificate     /CHEMIN_VALIDÉ/fullchain.pem;
    ssl_certificate_key /CHEMIN_VALIDÉ/privkey.pem;

    # Valeur à aligner avec la limite MP3 applicative et PHP.
    client_max_body_size 256m;

    location / {
        proxy_pass http://127.0.0.1:18080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_read_timeout 120s;
    }
}
```

Le bloc HTTP/ACME et la redirection HTTPS doivent être intégrés selon la configuration réelle du serveur. La chaîne de proxies de confiance dans Symfony doit être configurée et testée ; ne pas faire confiance à des en-têtes transmis directement par un client non autorisé. `client_max_body_size 256m` est **illustratif** : le projet doit corriger `post_max_size` inférieur à `upload_max_filesize` avant validation MP3.

Avant rechargement :

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Vérifier immédiatement **les deux domaines** et confirmer que `https://radiozinzine.org` sert toujours l'ancien site. Si le contrôle échoue, restaurer le vhost précédent selon la sauvegarde et la procédure Nginx du sysadmin.

### Recette privée de la future production V3

**Ne pas ajouter** de vhost public V3 pour `radiozinzine.org` tant que l'ancien site est actif. Le backend production reste sur `127.0.0.1:18081`. Pour une **sonde HTTP uniquement**, utiliser depuis un poste autorisé :

```bash
ssh -N -L 18081:127.0.0.1:18081 UTILISATEUR_AUTORISÉ@HÔTE_DOMAINE_PUBLIC
```

Puis dans un autre terminal local :

```bash
curl -i -H 'Host: radiozinzine.org' http://127.0.0.1:18081/
```

**Cette sonde n'est pas une recette navigateur HTTPS.** Pour tester authentification, cookies sécurisés, redirections et URL absolues, le sysadmin doit fournir un **accès HTTPS privé** (nom de recette protégé, VPN ou autre solution TLS contrôlée), sans toucher au vhost public `radiozinzine.org`. Le choix de ce canal est un **point d'arrêt** avant validation fonctionnelle de la production.

## 10. Sauvegarde et restauration — méthode rootless compatible

**Règle :** les sauvegardes doivent être faites **séparément** pour chaque environnement et copiées hors serveur. Les répertoires `work`/`trash` peuvent appartenir à un UID subordonné et être en `0700` : un `tar` lancé sous le compte rootless peut échouer. `--numeric-owner` ne confère aucun droit d'accès. Utiliser une identité de sauvegarde privilégiée et auditée (typiquement root via tâche systemd dédiée), **sans assouplir les permissions des médias**.

### 10.1 Préconditions d'une sauvegarde cohérente

- Identifier le projet, le socket Docker et le digest exact ; arrêter les **écritures** HTTP, workers, tâches planifiées, imports et autres producteurs de l'environnement concerné. Un simple arrêt d'Apache ne garantit pas l'absence d'écritures indirectes.
- Pour un premier démarrage ou une mise à jour avec migration : sauvegarder **avant** toute modification du schéma.
- Conserver la base, `uploads`, **toute** la racine `audio` et les références de configuration dans un même ensemble daté.
- Ne pas mettre de dump dans un répertoire web. Vérifier espace libre et destination hors serveur.

### 10.2 Exemple d'archive des fichiers (sysadmin root, écritures suspendues)

```bash
sudo install -d -m 0700 -o root -g root /var/backups/radiozinzine/preprod
sudo tar --acls --xattrs --numeric-owner -C /srv/radiozinzine/preprod -cpf /var/backups/radiozinzine/preprod/files-AAAAmmJJ-HHMM.tar uploads audio
sudo tar -tf /var/backups/radiozinzine/preprod/files-AAAAmmJJ-HHMM.tar >/dev/null
```

`AAAAmmJJ-HHMM` est un **nom à remplacer**, pas une date calculée. Une archive unique `uploads audio` préserve les liens physiques *dans l'archive*, sous réserve d'une restauration compatible. Ne pas archiver à travers des liens ou montages non qualifiés. Répéter sous `/prod` avec destination `/var/backups/radiozinzine/prod`.

### 10.3 Dump SQL authentifié (exemple à qualifier)

Dans la session du **compte rootless concerné**, avec `.env.compose` protégé, base en état cohérent et sans afficher les secrets :

```bash
umask 077
docker compose --env-file .env.compose -f compose.yaml -p zinzine-preprod exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump --single-transaction --routines --triggers --events --set-gtid-purged=OFF -u root "$MYSQL_DATABASE"' > /CHEMIN_PRIVÉ/dump.sql
```

`/CHEMIN_PRIVÉ/dump.sql` doit être un emplacement accessible au compte de sauvegarde, protégé et **hors** des répertoires publics. Le jeu d'options de `mysqldump` doit être vérifié sur **l'image MySQL réellement retenue** ; notamment, `--single-transaction` ne couvre pas à lui seul les fichiers MP3 ni les modifications DDL concurrentes. La méthode privilégiée est un arrêt contrôlé des écritures de l'environnement pendant la capture SQL + fichiers. Vérifier le code de retour de la commande, la taille et la lisibilité du dump ; un fichier vide ne constitue pas une sauvegarde. Éviter d'afficher le contenu des variables et les logs détaillés de Compose.

### 10.4 Restauration de qualification (OBLIGATOIRE)

Créer **un troisième projet isolé** avec compte/ports/volumes de test, sans accès au site public ni aux services externes. Tester :

1. Récupération des archives et du digest approuvé depuis un stockage indépendant.
2. Restauration SQL dans une base **vide dédiée** (jamais sur la base active).
3. Restauration de `uploads` et de **toute** la racine `audio` avec propriétaires et ACL adaptés au mapping rootless **du serveur de restauration** (les UID subordonnés peuvent différer).
4. Contrôle des liens physiques (`stat` : inode et nombre de liens), des permissions `0700` et du verrou `0600`, de la lecture HTTP limitée à `library` et du cycle de remplacement/corbeille.
5. Vérification du schéma Doctrine et des journaux, puis recette applicative.
6. Mesure du temps de restauration et documentation des erreurs rencontrées.

**Point d'arrêt 6 :** pas de mise en production ni de migration sans sauvegarde **et restauration isolée testée**. Les commandes exactes de restauration SQL dépendent du format du dump et de la stratégie de comptes MySQL retenue ; le sysadmin doit les tester et les consigner dans la fiche d'exploitation avant mise en service.

## 11. Publication GHCR, promotion et déploiements séparés

**État constaté dans le dépôt :** le workflow actuel construit à chaque tag Git `v*.*.*` et publie aussi `latest`. **Il ne respecte pas** la promotion sans reconstruction.

**Processus cible obligatoire :**

1. Créer un tag Git `v1.0.xx` sur le commit candidat. Construire **une seule fois** l'image et enregistrer son digest de manifeste/index GHCR.
2. Déployer **explicitement** ce digest en préproduction ; effectuer la recette.
3. Après approbation, créer le tag Git `v1.1.xx` sur **le même commit** ; associer ce tag GHCR au **même manifeste ou index**, sans reconstruire. Vérifier l'égalité des digests **au même niveau** (image ou index).
4. Déployer **séparément** ce digest en production, après sauvegarde et migrations ciblées. La préproduction reste inchangée.

Le workflow GitHub Actions doit être corrigé et testé **avant de pousser un tag `v1.1.xx`**. Le sysadmin ne doit pas inventer la promotion sur le serveur. La modification du tag GHCR ne déclenche **aucun** déploiement automatique dans le processus cible. L'image utilisée en Compose reste épinglée au digest, même si les tags évoluent.

## 12. Bascule finale — opération distincte, non autorisée par ce tutoriel

1. Valider la future production V3 sur son accès HTTPS privé.
2. Tester la restauration et figer les sauvegardes de l'ancien site et de V3.
3. Fixer la fenêtre de bascule et inventorier **toutes** les sources d'écriture de l'ancien site.
4. Mettre l'ancien site en maintenance, suspendre les écritures et effectuer la dernière synchronisation SQL/médias selon la procédure de migration métier validée.
5. Vérifier les URL absolues historiques, l'origine MP3, le schéma et les permissions.
6. **Seulement après accord explicite**, modifier le routage Nginx `radiozinzine.org` vers `127.0.0.1:18081` si l'ancien et le nouveau site cohabitent effectivement sur ce serveur ; ne pas modifier le DNS sans nécessité confirmée.
7. Tester HTTPS, sessions, formulaires, médias, administration et envoi d'e-mails ; rouvrir les écritures après validation.
8. Conserver l'ancien site et ses sauvegardes pendant la période de sécurité convenue.

Un retour à l'ancien vhost ou à l'ancien digest **ne restaure pas** les données écrites après bascule ni les migrations non transactionnelles. Documenter le traitement des écritures post-bascule avant toute intervention.

## 13. Contrôles d'acceptation et points d'arrêt

- [ ] Inventaire Debian 13, ancien site, Nginx, ports, DNS/TLS et capacité validé.
- [ ] Ancien site toujours accessible sur `https://radiozinzine.org`.
- [ ] Deux comptes, plages UID/GID et démons Docker rootless indépendants ; persistance après reboot.
- [ ] Deux projets Compose, bases, volumes MySQL, uploads, audio et secrets isolés.
- [ ] Aucun port MySQL public ; backends HTTP sur loopback uniquement.
- [ ] Image GHCR et MySQL fixées par digest réel et architecture compatible.
- [ ] Configuration Symfony complète, `APP_ENV=prod`, `APP_DEBUG=0`, origine MP3 et proxies vérifiés.
- [ ] Permissions UID/GID, ACL de traversée, liens physiques et verrou MP3 vérifiés **sans Apache**.
- [ ] Premier import, statut Doctrine, migrations et démarrage testés séparément.
- [ ] `dev.radiozinzine.org` en HTTPS ; futur site de production testé via HTTPS privé.
- [ ] `work` et `trash` inaccessibles en HTTP ; seuls les médias autorisés de `library` sont publics.
- [ ] Limites Nginx/PHP harmonisées pour les MP3.
- [ ] Sauvegarde hors serveur et **restauration isolée** testées pour chaque environnement.
- [ ] Workflow GHCR corrigé : promotion même digest, sans reconstruction ni déploiement implicite.
- [ ] `docker/prod/deploy.sh` toujours bloqué jusqu'à qualification complète de B3.
- [ ] Plan de bascule et de retour arrière signé séparément.

## 14. Livrables encore nécessaires avant une exécution de bout en bout

**Le présent document est complet sur la structure et l'ordre d'installation, mais ne prétend pas remplacer les données absentes.** Pour passer des modèles aux commandes définitives, le sysadmin et le développement doivent compléter une fiche d'installation comprenant :

- Inventaire réel des ports/vhosts, emplacement de sauvegarde hors serveur, capacité disque et méthode TLS privée ;
- digests GHCR **réels** de l'image applicative et de MySQL ;
- variables Symfony et intégrations externes effectivement requises, y compris origine MP3 configurable ;
- UID/GID effectifs de l'image et mapping rootless de **chaque** compte, ACL/permissions validées ;
- source et format des données de chaque environnement, procédure d'import et de synchronisation ;
- méthode de sauvegarde automatisée, commandes de restauration testées et politique de rétention ;
- workflow de promotion GHCR et script de déploiement B3 validés.

**Ne pas franchir les points d'arrêt correspondants par hypothèse.**

## Références techniques

- Audit Codex « Audit d'infrastructure et de déploiement — Radio Zinzine V3 », 20 novembre 2026, rapport du dépôt local.
- Audit Codex « Audit technique du tutoriel Domaine Public — Radio Zinzine V3 », 20 novembre 2026, rapport du dépôt local.
- Docker, *Install Docker Engine on Debian* : https://docs.docker.com/engine/install/debian/
- Docker, *Rootless mode* : https://docs.docker.com/engine/security/rootless/
- Docker, *Compose project name* : https://docs.docker.com/compose/how-tos/project-name/
- MySQL, *mysqldump* : https://dev.mysql.com/doc/refman/8.0/en/mysqldump.html
- Nginx, *Reverse proxy* : https://docs.nginx.com/nginx/admin-guide/web-server/reverse-proxy/
