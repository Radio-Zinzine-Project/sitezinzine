# Purge RadioExport

La commande traite toutes les semaines dont le mardi suivant est atteint en Europe/Paris.
Elle supprime les tentatives connues, puis les confirmations de la semaine. Une semaine
occupée est reportée. En cas d’échec, le code de sortie est non nul.
Le répertoire de semaine vide et son verrou sont conservés pour maintenir une identité
de verrou stable. Les entrées ambiguës sont préservées.
Le stockage doit être privé, réservé aux processus applicatifs et sans montage imbriqué.

## Validation avant activation

Depuis le projet Compose effectif, sous le compte rootless de l’environnement,
vérifier le service PHP, son UID, le montage privé, la base applicative active et
la disponibilité de la table des confirmations. Puis lancer la simulation :

    docker compose exec -T app php bin/console --env=prod app:radio-export:purge-expired --dry-run --no-interaction

Exemple : 2026-11-17 - 3 fichier(s) recensés, confirmations à purger si présentes.
La simulation ne prend pas le verrou et son inventaire peut évoluer avant la purge.

## Activation ultérieure sur Debian 13

Les fichiers docker/prod/systemd/radio-export-purge@.service et radio-export-purge@.timer
sont des gabarits. Adapter le binaire Docker, la variante Compose et le nom du service PHP
à l’installation effective. Installer les deux fichiers dans ~/.config/systemd/user/
du compte rootless concerné. Préparer un fichier sans secret par instance
~/.config/zinzine/radio-export-purge-<instance>.env :

    COMPOSE_FILE=/chemin/absolu/du/compose-effectif.yml
    COMPOSE_PROJECT_NAME=nom-du-projet-effectif
    DOCKER_HOST=unix:///run/user/UID_DU_COMPTE/docker.sock

Utiliser des valeurs distinctes en préproduction et production. Si Compose utilise
plusieurs fichiers ou un fichier d’environnement spécifique, reprendre exactement les
arguments de l’installation. Confirmer que le compte par défaut du service PHP correspond
au propriétaire du stockage. Les conteneurs restent en place.

Après validation sur le serveur ciblé, vérifier que le bus systemd utilisateur et le linger
sont opérationnels pour le compte rootless. Remplacer <instance> par le nom de l’instance :

    systemctl --user daemon-reload
    systemctl --user enable --now radio-export-purge@<instance>.timer
    systemctl --user list-timers 'radio-export-purge*'
    journalctl --user -u radio-export-purge@<instance>.service

Le timer passe chaque heure, y compris le mardi à minuit, en Europe/Paris.
Un passage rattrape les semaines oubliées et réessaie les semaines occupées.
Le timer persistant permet de rattraper une échéance manquée.
Un instantané de téléchargement déjà ouvert reste indépendant de la purge.
Une ouverture concurrente peut échouer si la publication disparaît.
