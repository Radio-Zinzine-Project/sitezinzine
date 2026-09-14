# Exécution et déploiement

## Ce qui reste à vérifier sur l’environnement

> Limite — Le dépôt ne permet pas d’identifier le Compose réellement lancé, les variables effectives, le proxy HTTPS ou la configuration d’hébergement.

Les points d’exploitation suivants demandent une vérification sur le serveur :

- migrations appliquées, données de référence et données historiques ;
- sauvegarde, restauration, permissions et durabilité des uploads ;
- disponibilité du SMTP, du RSS, de LibreTime et des URL audio ;
- exécution des tâches cron ou des workers.

Les procédures éditoriales et la transmission effective au système de diffusion radio ne sont pas déterminables depuis ces configurations.

> Note — Détails transférés depuis la vue d’ensemble, à partir de la lecture du dépôt du 11 septembre 2026. Les chemins sont relatifs à la racine Symfony sitezinzine/. Cette page ne constitue pas une nouvelle analyse exhaustive du sujet.

[Retour à l’architecture](01-architecture.md)

## Docker et livraison

La recette `docker-compose.dev.yml` décrit PHP/Apache, MySQL 8 et phpMyAdmin sur un réseau commun. Elle monte le code Symfony dans `/var/www/html`, conserve la base dans un volume et monte les uploads. Le Dockerfile de développement installe notamment les extensions SQL, Intl, Zip et Xdebug.

La recette `docker/prod/dockerfile.prod` possède trois étapes : dépendances Composer sans développement, compilation frontend avec Node 18/Yarn/Encore, puis image PHP 8.3/Apache contenant code, vendor et `public/build`. Apache est configuré par `docker/prod/apache/apache.conf`.

Plusieurs fichiers Compose de production coexistent, ainsi que `compose_old.yaml` et `compose.effective.yml`. Ils ne sont pas interchangeables : emplacements des Dockerfiles, montages d’uploads et volumes de base diffèrent. Le Compose de production placé à la racine Symfony référence `dockerfile.prod`, alors que le Dockerfile de production trouvé est sous `docker/prod/`. Les chemins relatifs des montages doivent être vérifiés pour le fichier Compose effectivement utilisé.

`docker/entrypoint.sh` attend une réponse SQL, reconstruit le cache puis lance Apache. Les lignes de migration y sont commentées ; la variable `RUN_MIGRATIONS` présente dans une recette n’est pas exploitée par ce script. Il ne faut donc pas supposer une migration automatique au démarrage.

Les limites PHP déclarent `upload_max_filesize=300M` mais `post_max_size=200M` : la taille totale autorisée pour la requête constitue une limite distincte et plus basse.

Le workflow GitHub Actions [`docker-publish.yml`](../.github/workflows/docker-publish.yml) construit l’image de production sur les tags `v*.*.*` et la publie dans GHCR. Il ne décrit pas un déploiement automatique sur le serveur.

## Intégrations et tâches

`InfosSoirRssService` récupère un flux externe via HttpClient, le met en cache et journalise les erreurs. L’intégration LibreTime lit un endpoint `week-info` configuré par `LIBRETIME_WEEK_INFO_URL` puis transforme les données pour un affichage de test en administration. Aucun export automatique de la grille vers LibreTime n’est visible dans ces services.

Mailer est configuré par DSN. Messenger déclare des transports `async`, `failed` et `sync`, mais le routage des messages email et notification est **synchrone**. La présence d’un transport asynchrone ne prouve pas un traitement applicatif en arrière-plan.

Une commande `app:cleanup-annonces` supprime physiquement les anciennes annonces sélectionnées par le repository avec une borne d’un an. Un fichier de note cron existe à la racine Git ; il ne prouve pas l’installation d’une tâche planifiée sur le serveur.
