# Preparation du deploiement MP3

Le script reste bloque par construction et n'a pas de bit executable dans le depot.
L'appel explicite avec Bash retourne 78 avant tout acces a Docker et toute ecriture.
Le blocage doit rester en place jusqu'a l'implementation et la revue des integrations serveur.

## Compose

En preproduction, utiliser docker-compose 1.29.2, le projet existant et le seul fichier installe
`/home/eloise/data/zinzine/docker-compose.yml`. Les recettes du depot dans docker/prod sont des
references, distinctes du fichier effectivement installe.

Apres autorisation, conserver une copie privee du Compose installe pour le retour arriere.
Dans ce fichier, sous `services.app.volumes`, ajouter exactement cette ligne a la liste existante :

```yaml
      - /home/eloise/data/zinzine/audio:/srv/zinzine/audio
```

Conserver tous les montages existants, dont
`/home/eloise/data/zinzine/uploads:/var/www/html/public/uploads`.
Verifier que la destination audio n'est presente qu'une fois et qu'aucun montage n'est imbrique.
La syntaxe courte peut creer une source manquante au demarrage. Provisionner et verifier la source
avant tout lancement.

Dans ce meme service, remplacer la valeur de `image:` par
`"${APP_IMAGE:?Explicit deployment image required}"`.
Donner a APP_IMAGE la reference GHCR v1.0.135 ou, de preference, son digest approuve.
Conserver le port 8080:80, le projet existant, db, phpmyadmin et le volume MySQL externe.
Verifier le modele avec `docker-compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE_PATH" config --quiet`
et controler les champs resolus en prive. Garder la sortie complete hors des journaux partages.

Le script attend COMPOSE_FILE_PATH, COMPOSE_PROJECT, DOCKER_CONTEXT_NAME, APP_IMAGE,
AUDIO_HOST_DIR et DEPLOY_STATE_DIR. Pour la preproduction, AUDIO_HOST_DIR vaut
`/home/eloise/data/zinzine/audio`. Une future production devra fournir et qualifier ses propres
chemins, identites rootless et controles de maintenance et de sauvegarde.

## Provisionnement sur le serveur apres autorisation

Reconfirmer le mapping rootless et l'identite de la nouvelle image. Les valeurs observees
sont 100:102 dans le conteneur et 100099:100101 sur l'hote. Les operations suivantes exigent
une autorite capable d'attribuer ces proprietaires sur l'hote. En cas de chemin existant,
examiner ses droits, son contenu et ses montages puis faire valider son reutilisation.

```sh
audio=/home/eloise/data/zinzine/audio
(
    set -eu
    test ! -e "$audio"
    test ! -L "$audio"
    test "$(realpath -e "$(dirname "$audio")")" = "$(dirname "$audio")"
    sudo install -d -o 1000 -g 1000 -m 0755 "$audio"
    sudo install -d -o 100099 -g 100101 -m 0755 "$audio/library"
    sudo install -d -o 100099 -g 100101 -m 0700 "$audio/work" "$audio/trash"
    findmnt -T "$audio"
    stat -c '%A %u:%g %F %n' "$audio" "$audio/library" "$audio/work" "$audio/trash"
)
```

Controler aussi findmnt pour chaque sous-repertoire et exclure les montages imbriques.
Pour les controles suivants, ouvrir un shell ponctuel du service app candidat avec
`docker-compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE_PATH" run --rm --no-deps --entrypoint sh app`.
Ce conteneur utilise la configuration et l'identite du candidat, sans lancer Apache.
Confirmer les droits et les trois directions de liens sous cette identite.

```sh
set -eu
id
php -r 'exit(function_exists("posix_geteuid") && posix_geteuid() === 100 && posix_getegid() === 102 ? 0 : 1);'
cd /srv/zinzine/audio
for dir in library work trash; do
    test -d "$dir" && test -w "$dir" && test -x "$dir"
    stat -c '%a %u:%g %F %n' "$dir"
done
(
    set -eu
    seed=$(mktemp work/.preprod-probe.XXXXXXXX)
    probe=${seed##*/}
    public="library/$probe"
    private="trash/$probe"
    restored="library/$probe-restored"
    public_created= private_created= restored_created=
    cleanup() {
        rm -f -- "$seed"
        if [ -n "$public_created" ]; then rm -f -- "$public"; fi
        if [ -n "$private_created" ]; then rm -f -- "$private"; fi
        if [ -n "$restored_created" ]; then rm -f -- "$restored"; fi
    }
    trap cleanup EXIT
    printf '%s' probe > "$seed"
    ln -T -- "$seed" "$public"; public_created=1
    ln -T -- "$public" "$private"; private_created=1
    ln -T -- "$private" "$restored"; restored_created=1
    if ln -T -- "$restored" "$public"; then exit 1; fi
    identity=$(stat -c '%d:%i' "$seed")
    for file in "$public" "$private" "$restored"; do
        test "$(stat -c '%d:%i' "$file")" = "$identity"
    done
    rm -- "$seed"
    test "$(cat "$restored")" = probe
)
```

Pour qualifier le verrou reel, demarrer deux commandes PHP independantes avec les memes
montages et la meme identite. Utiliser le service Symfony Mp3StorageLock avec le conteneur
PROD, dans un point d'entree de qualification a valider. Le detenteur doit signaler
l'acquisition avant le lancement du concurrent. Maintenir le verrou plus longtemps que le
delai configure, verifier le refus borne du concurrent, relacher puis verifier son acquisition.
Verifier l'identite avant et apres avec
`stat -c '%F %a %u:%g %h %d:%i %n' /srv/zinzine/audio/trash/.storage.lock`.
Attendre l'arret des processus de qualification. Conserver le fichier verrou sur le volume.

## Points a confirmer avant activation

- Compose effectif, projet, contexte rootless, fichiers d'environnement, empreinte GHCR et identite du candidat.
- Mecanisme de maintenance et verification de la fermeture des ecritures via le proxy, le port direct,
  phpMyAdmin, les taches et les autres clients SQL.
- Procedure d'obtention et de verification de la sauvegarde coherente sous maintenance,
  en incluant MySQL, uploads et audio. Confirmer la disponibilite de la restauration.
- Identite et version MySQL, plan Doctrine attendu, verification du schema et delais de chaque operation.
- Methode de verification du demarrage et criteres de recette avant reouverture.
- Reference de retour epinglee et ancien Compose reproductible. Le fichier d'etat conserve la reference
  et l'identifiant local de l'image precedente. Il ne remplace pas la conservation de sa configuration et
  d'une image effectivement reutilisable.

Le futur script doit maintenir la fermeture en cas d'echec, conserver MySQL et l'ancienne image,
et recueillir l'etat du schema avant de decider d'un retour. Une migration DDL interrompue
peut deja avoir modifie le schema. Un retour a l'ancienne image exige une compatibilite confirmee.
Apres des ecritures audio, tenir compte des chemins et des journaux avec une sauvegarde coherente.
Aucune suppression automatique de volume ou d'image ne fait partie du deploiement.
