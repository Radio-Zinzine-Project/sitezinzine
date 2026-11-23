#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

fail() {
    printf '%s\n' "$*" >&2
    exit 1
}

# These integrations require the actual server configuration and a separate review.
server_integrations_confirmed() {
    printf '%s\n' 'Deployment disabled: effective Compose, maintenance and backup checks require validation.' >&2
    return 78
}

server_integrations_confirmed

: "${COMPOSE_FILE_PATH:?Select the installed Compose file}"
: "${COMPOSE_PROJECT:?Select the existing Compose project}"
: "${DOCKER_CONTEXT_NAME:?Select the existing Docker context}"
: "${APP_IMAGE:?Select the versioned or digest-pinned application image}"
: "${AUDIO_HOST_DIR:?Select the provisioned host AUDIO directory}"
: "${DEPLOY_STATE_DIR:?Select an existing private directory for deployment records}"

audio=$AUDIO_HOST_DIR
export APP_IMAGE DOCKER_CONTEXT="$DOCKER_CONTEXT_NAME"

[[ $APP_IMAGE =~ ^ghcr\.io/eloisemcglasson/sitezinzine-app:(v[0-9]+\.[0-9]+\.[0-9]+)$ ||
   $APP_IMAGE =~ ^ghcr\.io/eloisemcglasson/sitezinzine-app@sha256:[0-9a-f]{64}$ ]] ||
    fail 'Use an explicit GHCR release tag or digest.'
[[ $COMPOSE_FILE_PATH = /* && -f $COMPOSE_FILE_PATH ]] || fail 'An absolute path to the installed Compose file is required.'
[[ -d $DEPLOY_STATE_DIR && -O $DEPLOY_STATE_DIR && ! -L $DEPLOY_STATE_DIR &&
   $(stat -c %a -- "$DEPLOY_STATE_DIR") == 700 ]] || fail 'Deployment records require an owned private directory (0700).'
for directory in "$audio" "$audio/library" "$audio/work" "$audio/trash"; do
    [[ -d $directory && ! -L $directory ]] || fail "Missing or symbolic audio directory: $directory"
done
[[ $(realpath -- "$audio") == "$audio" ]] || fail 'The audio root has symbolic ancestors.'

exec 9>"$DEPLOY_STATE_DIR/deploy.lock"
flock -n 9 || fail 'A deployment is already in progress.'

compose=(docker-compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE_PATH")
"${compose[@]}" config --quiet

app_id=$("${compose[@]}" ps -q app)
db_id=$("${compose[@]}" ps -q db)
[[ $app_id =~ ^[a-f0-9]+$ && $db_id =~ ^[a-f0-9]+$ ]] || fail 'Expected one existing app and one existing db container.'
[[ $(docker inspect --format '{{.State.Running}}' "$app_id") == true &&
   $(docker inspect --format '{{.State.Running}}' "$db_id") == true ]] || fail 'Both app and db must be running initially.'
previous_image=$(docker inspect --format '{{.Image}}' "$app_id")
previous_ref=$(docker inspect --format '{{.Config.Image}}' "$app_id")
docker image inspect "$previous_image" >/dev/null

# Implement only after review: verify project identity, the merged service model,
# the exact mounts, the target release digest and the tested rollback inputs.
verify_effective_server_configuration

record=$(mktemp "$DEPLOY_STATE_DIR/deploy.XXXXXXXX")
printf 'project=%s\ncontext=%s\ncompose_file=%s\nprevious_ref=%s\nprevious_image=%s\ncandidate=%s\n' \
    "$COMPOSE_PROJECT" "$DOCKER_CONTEXT_NAME" "$COMPOSE_FILE_PATH" "$previous_ref" "$previous_image" "$APP_IMAGE" > "$record"

"${compose[@]}" pull app
# Verify the pulled digest and identities, and qualify storage as the candidate PHP user.
verify_candidate_image_and_storage
trap 'printf "%s\n" "Deployment stopped. Keep maintenance active and inspect the schema and containers before recovery." >&2' EXIT
activate_maintenance_and_verify_writers_fenced

"${compose[@]}" stop app
verify_coherent_backup_after_stop
verify_migration_plan_and_database

"${compose[@]}" run --rm -T --no-deps --entrypoint php app \
    bin/console doctrine:migrations:migrate --env=prod --no-interaction
verify_committed_schema
[[ $("${compose[@]}" ps -q db) == "$db_id" &&
   $(docker inspect --format '{{.State.Running}}' "$db_id") == true ]] || fail 'MySQL changed during deployment.'

"${compose[@]}" up -d --no-deps --no-build --force-recreate app
verify_application_and_acceptance
release_maintenance
trap - EXIT
printf 'Deployment completed. Rollback record: %s\n' "$record"
