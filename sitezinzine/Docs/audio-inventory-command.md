# Inventaire MP3 non certifié

```text
php bin/console app:audio-inventory:run --inventory-profile=<profil_autorisé> --scope=web_full
php bin/console app:audio-inventory:run --inventory-profile=<profil_autorisé> --scope=test_subset
```

`--profile` reste l'option globale de profilage Symfony ; le choix du profil
d'inventaire utilise uniquement `--inventory-profile`.

Aucun profil n'est actif par défaut. Le déploiement doit renseigner le paramètre
`app.audio_inventory.profiles` dans sa configuration Symfony. Aucun chemin ne
peut être fourni par option CLI. Les racines doivent exister avant l'exécution.
L'assemblage n'utilise ni le workspace des uploads ni la connexion des incidents.

Exemple de **structure à renseigner**, sans valeurs de chemins implicites :

```yaml
parameters:
    app.audio_inventory.profiles:
        nom_du_profil:
            mp3_root: '%env(INVENTORY_MP3_ROOT)%'
            workspace_root: '%env(INVENTORY_WORKSPACE_ROOT)%'
            report_root: '%env(INVENTORY_REPORT_ROOT)%'
            scopes: ['test_subset'] # Autoriser web_full explicitement si pertinent.
            local_url_prefixes:
                - '%env(INVENTORY_LOCAL_URL_PREFIX)%'
            budgets:
                max_open_streams: 7
                max_runs: 10000
                max_input_runs: 4
                max_records_per_run: 1000
                max_path_bytes_per_run: 1048576
                max_association_bytes_per_run: 2097152
                max_path_bytes: 16384
                max_record_bytes: 32768
                max_identifier_bytes: 128
                max_diagnostics: 1000
                max_passes: 16
                sql_page_size: 500
                max_associations_per_path: 1000
                report_page_size: 100
                max_report_page_bytes: 2097152
                max_manifest_bytes: 4194304
                max_report_pages: 10000
```

Ces budgets illustrent le format ; ils ne constituent pas un dimensionnement
validé de la bibliothèque historique. Chaque valeur doit être un entier positif.
`max_open_streams >= max_input_runs + 3`, `max_input_runs >= 2`,
`max_identifier_bytes >= 48`, `sql_page_size <= 500`.
Les préfixes sont des URL HTTP(S) explicites, sans identifiants, port, paramètres
ou fragment ; configurer tous les préfixes historiques nécessaires sans les inventer.

Les trois racines doivent être absolues, locales, sans composant symbolique,
disjointes et accessibles. Workspace et rapports sont privés, hors de `public/`,
sans chevauchement avec les uploads privés. Sous POSIX, leurs racines ne doivent
pas être inscriptibles par le groupe ou les autres utilisateurs. Sous Windows,
les ACL doivent être contrôlées opérationnellement. Les parents doivent rester
sous contrôle de confiance ; les vérifications PHP n'éliminent pas les courses
de remplacement de répertoires ou les alias de volumes/bind mounts.

Une connexion DBAL dédiée est créée paresseusement depuis les paramètres résolus
de Doctrine (suffixe TEST compris). Elle n'est pas partagée avec l'EntityManager.
La configuration ne démontre pas l'indépendance physique des volumes : le
déploiement doit garantir que chaque profil ne monte que son stockage autorisé.
Les préconditions MySQL/InnoDB et la transaction REPEATABLE READ restent celles
du collecteur existant. Aucun SQL métier d'écriture n'est ajouté.

Le moteur réel publie ses pages et son manifeste privés uniquement à la fin.
La commande affiche l'identifiant, les compteurs et **terminée avec restrictions** :
`observation_only`, `storage_certified=false`, `absence_conclusions_allowed=false`.
L'identifiant aléatoire d'observation n'est ni une identité de volume ni une
génération. Aucun fichier MP3 n'est écrit, renommé ou supprimé.

Codes : 0 = rapport publié avec restrictions ; 2 = options/configuration invalides ;
1 = interruption, erreur d'exécution ou de nettoyage. Les messages SQL bruts ne
sont pas affichés. Une erreur de nettoyage après publication peut laisser un
rapport valide sur disque sans annonce de succès par la commande.

Avec PCNTL et l'application Console Symfony, SIGINT/SIGTERM demandent un arrêt
coopératif aux frontières existantes du moteur. Un appel SQL/une fusion en cours
doit se terminer avant ce contrôle ; aucun délai d'arrêt immédiat n'est garanti.
Sans PCNTL ou après arrêt brutal, des résidus provisoires peuvent rester : aucune
reprise automatique ni suppression par âge/PID n'est implémentée. Les rapports
incomplets ne sont pas publiés. Le workspace de cette exécution est nettoyé dans
`finally`, indépendamment de la fermeture SQL ; les rapports persistants restent.

La mémoire du registre du workspace dépend encore des blocs possédés ; `max_runs`
limite les créations cumulées. Il n'existe pas de quota disque global applicatif.
Prévoir espace libre, limites opérationnelles et validation PHP 8.3/Linux sur un
petit jeu synthétique avant tout inventaire autorisé. Aucun inventaire historique
n'est déclenché par l'installation ou le démarrage de Symfony.
