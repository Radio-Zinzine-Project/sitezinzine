# Radio Zinzine V3 — Tutoriel d'installation de l'infrastructure Domaine Public

**Version de travail — 20 novembre 2026.** Destiné à l'administrateur système. **Ne pas exécuter les étapes de modification de l'hôte sans inventaire préalable de l'ancien site.**

## 0. Périmètre et hypothèses

- `radiozinzine.org` sert **toujours l'ancien site** jusqu'à la bascule autorisée.
- `dev.radiozinzine.org` servira la préproduction V3.
- La future production V3 sera préparée sur un backend privé, sans vhost public concurrent.
- Deux projets Compose séparés, deux bases, deux ensembles d'uploads, deux racines audio, deux jeux de secrets.
- **Exemple opérationnel choisi** : Debian 13, Nginx sur l'hôte, Docker rootless sous un compte de service `zinzine-v3`, deux projets Compose `zinzine-preprod` et `zinzine-prod`. C'est un modèle d'installation, **pas un constat sur le serveur Domaine Public**. Un compte rootless commun facilite l'exploitation mais n'isole pas les droits entre les deux projets ; deux comptes rootless constituent une option plus cloisonnée, à décider avec le sysadmin.
- Ports locaux proposés : `127.0.0.1:18080` (préprod) et `127.0.0.1:18081` (future prod). **Vérifier leur disponibilité**.
- Aucun tag `latest` en exploitation. `APP_IMAGE` désigne un **digest GHCR réellement publié**.
- Les exemples de mots de passe sont des noms de variables, **jamais des valeurs à conserver**.

## 1. Inventaire non destructif, AVANT installation

À exécuter par le sysadmin sur Domaine Public, avec les droits adaptés :

```bash
cat /etc/os-release
uname -m
hostnamectl
sudo ss -lntup
sudo nginx -t
sudo nginx -T > /root/nginx-before-zinzine-v3.txt 2>&1
systemctl list-units --type=service --state=running
findmnt -T /srv
lsblk -f
df -hT
free -h
command -v docker && docker version
command -v docker && docker compose version
getent hosts radiozinzine.org dev.radiozinzine.org
```

**Ne pas installer une seconde pile Nginx ou Docker si une installation existante remplit déjà ce rôle.** Vérifier la capacité disque avec l'ancien site, les deux jeux de données V3 et les sauvegardes simultanément. Sauvegarder les configurations actuelles et documenter les vhosts, ports, volumes et services avant tout changement. Ne jamais faire `docker compose down -v` ni `docker system prune --volumes` sur cet hôte.

**Point d'arrêt** : si l'ancien site dépend d'un Docker rootful, de ports réservés, ou d'une configuration Nginx particulière, adapter la suite avant exécution.

## 2. Paquets Debian (uniquement si manquants)

Si Docker n'est pas déjà installé et que Debian 13 est confirmé, utiliser le dépôt officiel Docker :

```bash
sudo apt update
sudo apt install -y ca-certificates curl gnupg uidmap dbus-user-session slirp4netns fuse-overlayfs
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
printf 'Types: deb\nURIs: https://download.docker.com/linux/debian\nSuites: %s\nComponents: stable\nArchitectures: %s\nSigned-By: /etc/apt/keyrings/docker.asc\n' "$(. /etc/os-release && echo "$VERSION_CODENAME")" "$(dpkg --print-architecture)" | sudo tee /etc/apt/sources.list.d/docker.sources
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

Ne **pas** désinstaller ou désactiver le démon Docker existant sans inventaire de ses consommateurs. Si Docker rootful a été installé uniquement pour fournir les binaires et qu'aucun autre service n'en dépend, le sysadmin peut choisir de désactiver son service après validation. Nginx et Certbot ne doivent être installés que si leur rôle et leur absence ont été confirmés.

## 3. Compte et Docker rootless

Exemple à adapter aux conventions de comptes de Domaine Public :

```bash
sudo adduser --disabled-password --gecos '' zinzine-v3
sudo apt install -y docker-ce-rootless-extras
sudo grep '^zinzine-v3:' /etc/subuid /etc/subgid
```

Il faut une plage `subuid` et `subgid` suffisante et non chevauchante. Si absente, **la faire attribuer par le sysadmin** ; ne pas recopier les plages de Hetzner.

Dans une session **réellement ouverte comme `zinzine-v3`** (par SSH ou `machinectl shell`, pas en présumant que `su` initialise correctement systemd utilisateur) :

```bash
dockerd-rootless-setuptool.sh install
systemctl --user enable --now docker
systemctl --user status docker
docker info --format '{{json .SecurityOptions}}'
docker compose version
```

Puis, depuis un compte administrateur :

```bash
sudo loginctl enable-linger zinzine-v3
```

Le contexte Docker utilisé pour les commandes ultérieures doit être **le démon rootless de ce compte**, et non le démon système. Vérifier `docker context show`, `docker info` et le socket `unix:///run/user/<UID>/docker.sock`. Ne pas ajouter `zinzine-v3` au groupe `docker` rootful.

## 4. Répertoires et montages

Sous le compte `zinzine-v3` :

```bash
mkdir -p ~/radiozinzine/preprod/{uploads,audio/library,audio/work,audio/trash,backups}
mkdir -p ~/radiozinzine/prod/{uploads,audio/library,audio/work,audio/trash,backups}
chmod 700 ~/radiozinzine/preprod ~/radiozinzine/prod
chmod 700 ~/radiozinzine/preprod/backups ~/radiozinzine/prod/backups
```

Arborescence cible :

```text
/home/zinzine-v3/radiozinzine/
  preprod/
    compose.yaml
    .env.compose            # image/digest et variables Compose, 0600
    .env.app                # secrets applicatifs, 0600
    uploads/
    audio/{library,work,trash}/
    backups/
  prod/
    compose.yaml
    .env.compose
    .env.app
    uploads/
    audio/{library,work,trash}/
    backups/
```

**Important** : `library`, `work`, `trash` doivent appartenir au même système de fichiers. Les répertoires `work` et `trash` ne sont pas publics. La propriété des montages doit correspondre à l'UID/GID **mappés de `appuser` dans l'image effectivement déployée**. Ne pas appliquer aveuglément `chown 100:102` sur l'hôte : en rootless, ces identifiants sont traduits. Déterminer les UID/GID du conteneur, les mappings `/etc/subuid`/`/etc/subgid`, puis tester l'écriture et les hard links **avant** ouverture du service.

## 5. Fichier Compose de chaque environnement

Créer `compose.yaml` dans **chacun** des deux répertoires, avec ce modèle commun :

```yaml
services:
  db:
    image: mysql:8.0
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
      test: ["CMD-SHELL", "MYSQL_PWD=\"$$MYSQL_ROOT_PASSWORD\" mysqladmin ping -h 127.0.0.1 -u root --silent"]
      interval: 10s
      timeout: 5s
      retries: 20
    # Aucun port MySQL publié sur l'hôte.

  app:
    image: ${APP_IMAGE:?APP_IMAGE_digest_obligatoire}
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

**Attention : ce modèle est à confronter au Dockerfile et à la recette Compose finale du dépôt.** Le chemin `/var/www/html/public/uploads`, les variables applicatives et la commande d'entrypoint doivent être vérifiés sur l'image GHCR candidate. Les fichiers Compose locaux audités ne sont pas directement adaptés à deux installations (noms fixes, ports et volumes). Ne pas démarrer une application avant cette vérification.

Le volume `mysql_data` est volontairement **non externe** : Compose le préfixe avec le nom de projet. Cela empêche une collision involontaire entre les deux installations, à condition de **toujours** préciser le nom du projet.

### `.env.compose` — préproduction

```dotenv
APP_PORT=18080
APP_IMAGE=ghcr.io/eloisemcglasson/sitezinzine-app@sha256:REMPLACER_PAR_DIGEST_VALIDE
MYSQL_DATABASE=zinzine_preprod
MYSQL_USER=zinzine_preprod
MYSQL_PASSWORD=REMPLACER_SECRET_UNIQUE
MYSQL_ROOT_PASSWORD=REMPLACER_SECRET_ROOT_UNIQUE
```

### `.env.compose` — production V3

```dotenv
APP_PORT=18081
APP_IMAGE=ghcr.io/eloisemcglasson/sitezinzine-app@sha256:REMPLACER_PAR_DIGEST_VALIDE
MYSQL_DATABASE=zinzine_prod
MYSQL_USER=zinzine_prod
MYSQL_PASSWORD=REMPLACER_AUTRE_SECRET_UNIQUE
MYSQL_ROOT_PASSWORD=REMPLACER_AUTRE_SECRET_ROOT_UNIQUE
```

Créer les secrets avec un gestionnaire sécurisé ; ne jamais les committer ni les transmettre en clair par messagerie. `chmod 600 .env.compose .env.app`. L'exemple ci-dessus conserve les mots de passe MySQL dans un fichier Compose protégé : pour un déploiement durci, privilégier des secrets montés en fichiers et vérifier le support `_FILE` des images utilisées. Éviter `docker compose config` dans les journaux car il peut afficher des secrets ; utiliser `docker compose config --quiet` pour la validation.

### `.env.app` — à fournir par le développement

La liste définitive dépend de la configuration Symfony réelle (`DATABASE_URL`, `APP_SECRET`, `MAILER_DSN`, intégrations, etc.). Pour chaque environnement : secrets indépendants, URL canonique appropriée, accès SMTP maîtrisé, aucune connexion de préproduction aux services de production sans autorisation. **Ne pas inventer de valeurs ni recopier la configuration Hetzner.**

## 6. Validation des projets et démarrage contrôlé

Sous `zinzine-v3`, après avoir créé les fichiers et choisi un digest valide :

```bash
cd ~/radiozinzine/preprod
docker compose --env-file .env.compose -p zinzine-preprod config --quiet
cd ~/radiozinzine/prod
docker compose --env-file .env.compose -p zinzine-prod config --quiet
```

Vérifier les ports avant démarrage :

```bash
ss -lnt | grep -E ':(18080|18081) ' || true
```

Pour lancer **uniquement les bases** (si les secrets et chemins ont été validés) :

```bash
cd ~/radiozinzine/preprod && docker compose --env-file .env.compose -p zinzine-preprod up -d db
cd ~/radiozinzine/prod && docker compose --env-file .env.compose -p zinzine-prod up -d db
```

Le lancement de `app` attend : image candidate approuvée, variables applicatives vérifiées, permissions des montages validées et procédure de migrations/maintenance disponible. Ne pas utiliser `up -d` global avant ces préconditions.

## 7. Nginx — préproduction, sans toucher au site historique

**Précondition** : le sysadmin a confirmé que Nginx sert le site actuel et que le DNS `dev.radiozinzine.org` pointe vers l'hôte. Installer un certificat TLS par la méthode de l'hébergeur (ACME/Certbot ou autre), sans réécrire les vhosts existants.

Créer un vhost **distinct** pour `dev.radiozinzine.org` ; exemple de bloc HTTPS à intégrer aux chemins de certificats validés :

```nginx
server {
    listen 443 ssl;
    server_name dev.radiozinzine.org;

    ssl_certificate     /CHEMIN/CERTIFICAT_VALIDÉ/fullchain.pem;
    ssl_certificate_key /CHEMIN/CLE_VALIDÉ/privkey.pem;

    client_max_body_size 256m; # VALEUR D'EXEMPLE : aligner sur les limites MP3 réelles

    location / {
        proxy_pass http://127.0.0.1:18080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 120s;
    }
}
```

Créer séparément le vhost HTTP 80 pour le challenge ACME et la redirection HTTPS, en tenant compte de l'installation de certificats existante. **Ne pas créer un second `server_name radiozinzine.org` pour V3 à ce stade.** Le backend V3 production `127.0.0.1:18081` reste privé ; tester via tunnel SSH (par exemple `ssh -L 18081:127.0.0.1:18081 ...`) et, si nécessaire, une résolution locale contrôlée du nom de domaine, plutôt que de publier un vhost concurrent.

Avant rechargement :

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Vérifier que `https://radiozinzine.org` sert **toujours l'ancien site**, puis tester `https://dev.radiozinzine.org` après démarrage de l'application. Configurer les proxies de confiance dans Symfony conformément à l'architecture Nginx réellement déployée.

## 8. Vérification du stockage audio

Depuis le conteneur `app` de **chaque environnement**, vérifier sous l'utilisateur applicatif :

1. `id` et permissions effectives de `/srv/zinzine/audio`.
2. Création/suppression d'un fichier temporaire dans `work`.
3. Création d'un lien physique `work → library`, puis `library → trash` et `trash → library` sur des fichiers **de test**.
4. Utilisation de `flock` sur le chemin de verrou applicatif.
5. Absence d'accès HTTP aux répertoires `work` et `trash`.
6. Accès HTTP autorisé à un MP3 de test dans `library`, selon les règles Apache.

**Ne pas tester sur des MP3 réels.** Si un lien physique échoue (`EXDEV`), vérifier les montages : déplacer les trois répertoires sur un même système de fichiers. Si l'écriture échoue, corriger les mappings UID/GID, pas les permissions en `777`.

## 9. Sauvegarde et restauration — prérequis à la mise en service

Prévoir pour **chaque** environnement une sauvegarde cohérente de :

- La base MySQL, y compris journaux applicatifs.
- `uploads/`.
- `audio/library`, `audio/work`, `audio/trash`.
- Les fichiers privés nécessaires à la restauration, conservés dans un coffre sécurisé.
- Le digest GHCR et la version Git déployés.

Les écritures applicatives, tâches asynchrones et autres producteurs doivent être suspendus pendant la capture coordonnée SQL + fichiers, ou un mécanisme de snapshot cohérent doit être démontré. Ne pas considérer un simple `tar` et un `mysqldump` pris à des instants différents comme automatiquement cohérents.

**Exemple de capture des fichiers une fois les écritures réellement arrêtées**, sous le compte `zinzine-v3` :

```bash
cd ~/radiozinzine/preprod
mkdir -p backups
umask 077
tar --acls --xattrs --numeric-owner -cpf "backups/files-$(date +%Y%m%d-%H%M%S).tar" uploads audio
```

Le dump SQL doit être réalisé avec un compte de sauvegarde autorisé, en utilisant une méthode compatible avec MySQL 8 et une stratégie de cohérence adaptée. Ne pas placer de mot de passe sur la ligne de commande. **La sauvegarde n'est validée qu'après restauration testée dans une troisième installation isolée**, avec vérification des droits, du schéma et des liens physiques. Une copie hors serveur et une rétention définie sont obligatoires avant production.

Le script de déploiement B2 actuel est volontairement bloqué (`exit 78`) : il ne doit pas être activé tant que la maintenance, les sauvegardes, les migrations, les smoke tests et la reprise ne sont pas implémentés et validés.

## 10. Images GHCR et promotion sans reconstruction

- Un tag Git `v1.0.xx` déclenche la **construction** d'une image de préproduction et enregistre son digest.
- La préproduction déploie explicitement ce digest après validation de la procédure.
- Après recette, un tag Git `v1.1.xx` peut référencer le **même commit**, et le tag GHCR correspondant doit référencer le **même manifeste/image**, sans rebuild.
- Le digest utilisé pour la production doit être vérifié identique au digest approuvé ; attention aux index multi-architectures : comparer au même niveau.
- Le déploiement de production est **une opération manuelle distincte**, qui ne modifie pas la préproduction.
- Le workflow actuel `v*.*.*` reconstruit pour tous les tags et publie `latest` : **il doit être corrigé avant toute promotion `v1.1.xx`**.

Le sysadmin n'a pas à inventer le mécanisme de promotion. Le développement doit livrer le workflow GHCR corrigé, les digests et les scripts de déploiement vérifiés.

## 11. Procédure de bascule finale — à planifier, pas à exécuter maintenant

1. Confirmer l'approbation fonctionnelle et technique de V3 production.
2. Vérifier les sauvegardes restaurables de l'ancien site et de V3.
3. Définir une fenêtre d'interruption et identifier toutes les sources d'écritures (HTTP, tâches planifiées, imports, API, messagerie).
4. Mettre l'ancien site en maintenance et suspendre les écritures.
5. Effectuer le dernier transfert SQL et médias selon le plan de migration métier validé.
6. Contrôler les données, les URL absolues, les MP3 et les permissions.
7. Modifier **seulement alors** le routage Nginx du domaine `radiozinzine.org` vers `127.0.0.1:18081` (si ancien et nouveau sites sont sur le même hôte).
8. Tester les pages publiques, les formulaires, l'authentification, les médias et les fonctions d'administration.
9. Autoriser la réouverture des écritures après validation.
10. Conserver l'ancien site et ses sauvegardes pendant la période de sécurité convenue.

**Retour arrière** : définir à l'avance comment traiter les écritures nouvelles après bascule ; revenir simplement à l'ancien vhost ou à un ancien digest ne restaure pas les données ni les migrations.

## 12. Recette finale à faire signer

- [ ] Ancien site toujours opérationnel sur `radiozinzine.org`.
- [ ] Préproduction accessible en HTTPS sur `dev.radiozinzine.org`.
- [ ] Future production accessible uniquement par un moyen de test privé.
- [ ] Projets Compose `zinzine-preprod` et `zinzine-prod` indépendants.
- [ ] Deux bases et volumes MySQL distincts, aucun port MySQL public.
- [ ] Uploads et racines audio indépendants.
- [ ] UID/GID rootless, hard links et verrous MP3 vérifiés.
- [ ] `work` et `trash` non accessibles par HTTP.
- [ ] Secrets protégés et isolés.
- [ ] Sauvegarde hors serveur et restauration testée.
- [ ] Images fixées par digest ; aucune dépendance à `latest`.
- [ ] Workflow de promotion GHCR corrigé et testé.
- [ ] Déploiements et migrations séparés, script B2 débloqué uniquement après validation.
- [ ] Plan de bascule et de retour arrière approuvé.

## 13. Informations qui restent indispensables

Ce tutoriel est **exécutable pour la préparation** sous les hypothèses annoncées, mais pas encore une recette aveugle de mise en production. Avant d'appliquer les étapes dépendantes de l'hôte, confirmer : OS, services existants, espace disque, DNS/TLS, ports, comptes rootless, chemins et quotas. Avant de démarrer `app`, confirmer : digest GHCR réel, variables Symfony exactes, emplacement des uploads dans l'image, limites PHP/Nginx, politique d'envoi de mails en préproduction, permissions et procédures de sauvegarde/migration.

## Références

- Audit Codex du dépôt Radio Zinzine V3 transmis le 20/11/2026 (source projet, état local uniquement).
- Docker, installation Debian : https://docs.docker.com/engine/install/debian/
- Docker, rootless : https://docs.docker.com/engine/security/rootless/
- Docker Compose, noms de projets : https://docs.docker.com/compose/how-tos/project-name/
- Nginx, reverse proxy : https://docs.nginx.com/nginx/admin-guide/web-server/reverse-proxy
