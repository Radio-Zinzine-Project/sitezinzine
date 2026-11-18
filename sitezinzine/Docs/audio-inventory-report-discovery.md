# Consultation des manifestes (lot 7.46.1)

`AudioInventoryReportDiscovery` est assemblable sans kernel, moteur, racine MP3
ni connexion SQL. Aucun profil n'est ajouté à la configuration existante : les
profils autorisés restent vides par défaut. Le constructeur reçoit les profils
serveur, la véritable racine publique et un budget positif `maxEntries`.

Chaque profil contient seulement `report_root` et les budgets existants :
`report_page_size`, `max_report_page_bytes`, `max_manifest_bytes`,
`max_report_pages`, dans `budgets`. Un profil complet d'inventaire peut fournir
ces mêmes champs ; les autres ne sont pas utilisés. Aucun chemin HTTP n'est reçu.

API :

- `getProfiles(): array` : noms autorisés uniquement, sans accès au disque.
- `discover(string $profile): array` : résumés des manifestes publiés, anomalies,
  compteur des provisoires et compteurs/état de découverte.
- `readManifest(string $profile, string $inventoryId): array` : observation
  structurée avec état, motif fixe, date inconnue et manifeste validé si publié.
- `readPage(string $profile, string $inventoryId, int $page): array` : délégation
  à la pagination native du store ; aucun contrôle d'intégrité n'est réimplémenté.

États d'un rapport : `published`, `unpublished`, `corrupt_or_unreadable`,
`unavailable`. Les anomalies ne contiennent que l'identifiant et des codes fixes,
jamais les messages d'exception, chemins privés ou paramètres de connexion.
Un manifeste absent dans un répertoire accessible signifie non publié à l'instant
observé ; cela ne prouve pas qu'il n'a jamais été publié. Un répertoire disparu
est indisponible ; une publication en cours peut changer l'observation suivante.
Un manifeste publié est toujours validé par le véritable `readManifest()` du store.

La découverte ne parcourt que le premier niveau de la racine autorisée avec
`readdir()`. Toutes les entrées hors `.` et `..` consomment le budget, même celles
dont le nom est ignoré. À la limite, `status=partial`, `partial=true`,
`reason=entry_budget_reached`, y compris si la taille du répertoire correspond
exactement à ce budget. Aucune lecture supplémentaire ne tente d'affirmer la fin.
`completed` indique une énumération terminée, pas l'absence d'anomalies.
`unavailable` ne doit jamais être affiché comme une liste exhaustive vide.

Un seul handle de répertoire et un manifeste borné à la fois sont nécessaires.
Les listes de résumés/anomalies sont bornées par `maxEntries`. Aucun fichier de
page n'est lu, aucune récursion n'est faite. L'ordre de `readdir` n'est pas stable :
pas de tri chronologique, curseur HTTP, cache, instantané atomique ou index durable
dans ce lot. Le budget borne les entrées et octets par manifeste, pas la latence
d'une E/S défaillante. PHP ne distingue pas toujours une fin de répertoire d'une
erreur système non signalée ; les erreurs levées sont rapportées comme partielles.

Les racines sont locales, absolues, sans liens symboliques, distinctes de l'arbre
public. Les entrées symboliques et fichiers spéciaux ne sont pas suivis. Les
parents doivent rester sous contrôle de confiance : PHP ne fournit pas ici de
protection `openat` contre un acteur remplaçant les chemins concurremment, ni de
preuve d'isolation des bind mounts ou d'absence d'alias Apache.

Le store existant exige encore une racine inscriptible et POSIX non inscriptible
par le groupe/autres. Le service ne crée rien, ne modifie aucun mode et n'élargit
aucune permission. Les répertoires/fichiers publiés sont `0700/0600` : prévoir
une identité utilisateur opérationnelle compatible pour la future consultation.
Un montage réellement en lecture seule nécessiterait une évolution distincte du
constructeur du store ; aucune hypothèse d'accès CLI/web n'est fabriquée ici.

## Datation proposée, non implémentée

Tous les résumés/observations exposent `inventory_date=null`. Le manifeste n'a
aucune date d'exécution ; ni le nom aléatoire ni le mtime ne permettent de l'inférer.

Une évolution ultérieure pourrait ajouter `report.execution.started_at` et
`report.execution.completed_at`, UTC explicite avec microsecondes, observés autour
de la véritable exécution. Cela constitue une évolution du contrat persistant,
même si le dictionnaire `report` extensible permet de conserver l'enveloppe v1 et
les douze clés du manifeste. Elle doit définir horloge, validation, interruption,
sens de la date affichée et tests de compatibilité. Les anciens rapports garderaient
des dates inconnues, sans réécriture. Aucun format ni producteur n'est modifié ici.

## Interface ultérieure

Les contrôleurs sont sous `src/Controller/Admin/AudioLibrary/`, les templates
sous `templates/admin/audio_library/`. Toute l'interface est réservée à
`ROLE_SUPER_ADMIN`, avec vérification serveur avant lecture ; le service seul ne
réalise pas l'autorisation. Le lot 7.46.2 ajoute uniquement des consultations GET :
`/admin/audio-library/inventory?profile=<nom>` et
`/admin/audio-library/inventory/<profil>/<identifiant>?page=1`.
Un profil et une page sont lus par requête. Le menu Bibliothèque audio est réservé
au même rôle. Aucun lancement HTTP d'inventaire n'est possible.
La pagination de résultats reste celle de `AudioInventoryReportStore`.
Les restrictions de certification sont conservées sans recalcul ni permission
de suppression. Un résumé valide ne certifie pas l'intégrité actuelle des pages.

L'assemblage Symfony utilise les profils existants (toujours vides par défaut) et
le budget configurable `app.audio_inventory.discovery_max_entries` (100 par défaut).
Il n'injecte aucune connexion SQL dans la consultation. Les réponses, y compris
les refus de sécurité sur ces URL, sont `private, no-store`. Les chemins binaires
ou de contrôle sont représentés en hexadécimal et les chemins absolus masqués ;
les autres valeurs sont échappées par Twig. Seuls les diagnostics sélectionnés
sont affichés, jamais les messages bruts d'exception ou des URL privées.

Les associations restent dans leur ordre et avec leurs doublons ; les liens
utilisent les identifiants d'émission sans charger leurs titres. Les fiches peuvent
avoir changé ou disparu depuis l'inventaire. Le menu/les réponses ne constituent
ni un cache de résultats ni un journal de certification. Les règles de datation
et les limites de permissions ci-dessus restent inchangées.
