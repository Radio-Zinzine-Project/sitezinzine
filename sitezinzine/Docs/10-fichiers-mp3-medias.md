# Fichiers, MP3 et médias

> Note — Détails transférés depuis la vue d’ensemble, à partir de la lecture du dépôt du 11 septembre 2026. Les chemins sont relatifs à la racine Symfony sitezinzine/. Cette page ne constitue pas une nouvelle analyse exhaustive du sujet.

[Retour à l’architecture](01-architecture.md)

## Fichiers, images et MP3

### Stockage technique

#### Stockage audio Docker DEV

Le verrou global MP3 est prévu dans `trash/.storage.lock`, avec le mode `0600` et le même propriétaire que `trash/` (`0700`). Les futurs appelants doivent utiliser le même compte POSIX et le même fichier sur le volume audio. Le service conserve le fichier entre les requêtes et ferme son descripteur en fin de section critique. Il doit être pris avant les verrous SQL et rester détenu jusqu'à la fin des opérations disque, même après le commit. Le délai d'attente par défaut est de 1 000 ms. PHPUnit utilise le répertoire privé de son exécution, partagé explicitement avec les processus de contention. La racine PROD reste à configurer lors du déploiement.

Ce verrou est coopératif. Les répertoires et l'entrée du verrou doivent rester stables. PHP vérifie l'identité du descripteur et de l'entrée, mais `fopen()` ne fournit pas d'option portable `O_NOFOLLOW`. Ces contrôles ne protègent pas contre un écrivain externe capable de remplacer l'entrée entre les vérifications.

Le remplacement et le retraitement MP3 utilisent le verrou en DEV et TEST via l'alias `app.mp3_replacement_storage_lock`. Toute transaction externe doit être ouverte par un appelant qui détient déjà le verrou. Le remplacement laisse alors le nettoyage définitif à la coordination externe, comme avant. La suppression directe depuis l'édition reste hors de ce verrou jusqu'à son raccordement. Les environnements sans alias conservent le comportement historique. Avant d'activer l'alias dans un autre environnement, qualifier son stockage partagé et le compte qui exécute PHP.

Les primitives disque de la corbeille utilisent des liens durs sans écrasement sur le même montage. Elles vérifient le fichier restant avant de retirer l'autre nom. La coordination applicative doit sérialiser les opérations sur les deux chemins et protéger leurs répertoires contre les modifications concurrentes. Les vérifications PHP et `unlink()` restent des opérations distinctes.

Un lien visible après `link()` peut être retrouvé après l'arrêt du seul processus. Après une panne de l'hôte ou une perte d'alimentation, la persistance dépend aussi de la synchronisation du fichier et des entrées de répertoire. Les primitives actuelles ne synchronisent pas ces entrées. Le niveau de durabilité requis et sa qualification sur le stockage cible restent à définir avant leur raccordement.

En environnement DEV, Symfony et Vich lisent la bibliothèque sous `/srv/zinzine/audio/library`. Le processeur prépare les fichiers sous `/srv/zinzine/audio/work`. Le répertoire `trash` est réservé à un lot ultérieur. Le vhost [Apache DEV](../docker/dev/apache.conf) sert uniquement la bibliothèque sous `/uploads/emissionsMp3/`. Les autres uploads continuent d'utiliser `public/uploads/`.

`docker-compose.dev.yml` est local et ignoré par Git. Pour reproduire le raccordement, ajouter ces deux entrées à `services.app.volumes` en conservant les montages existants.

```yaml
- dev_audio:/srv/zinzine/audio
- ./docker/dev/apache.conf:/etc/apache2/sites-available/000-default.conf:ro
```

Ajouter les deux variables suivantes à `services.app.environment`. Elles ne sont injectées qu'à la création du conteneur, ce qui coordonne le changement de chemins avec l'ajout des montages. Les lancements DEV sans ces variables conservent les chemins historiques.

```yaml
DEV_MP3_BASE_DIR: /srv/zinzine/audio/library
DEV_MP3_WORK_DIR: /srv/zinzine/audio/work
```

Ajouter également cette entrée aux volumes de premier niveau. Le volume externe doit être provisionné avant le démarrage. S'il existe déjà, vérifier son contenu sans le réinitialiser. Les trois répertoires appartiennent à `www-data` (UID/GID 33), avec le mode `0755` pour `library` et `0700` pour `work` et `trash`.

```yaml
dev_audio:
  name: radiozinzine_dev_audio
  external: true
```

Après accord sur la fenêtre d'interruption DEV, appliquer la configuration avec `docker compose -f docker-compose.dev.yml up -d --no-deps --no-build app`. Vérifier ensuite le montage, les chemins Symfony et Vich, puis la lecture statique sous l'URL historique. Le chemin physique sous `public/uploads/emissionsMp3/` décrit plus bas correspond à la configuration commune.

Les uploads reposent sur **le système de fichiers local**, sous `public/uploads/`. Les entités stockent des noms ou chemins ; les fichiers binaires ne sont pas stockés en colonnes Doctrine. VichUploader relie les propriétés de fichiers aux champs persistants.

`config/packages/vich_uploader.yaml` configure les images d’émissions, catégories, thèmes, annonces et événements, les images de pages, les couvertures annuelles de catégories et les MP3. Les principales destinations sont `uploads/images/…`, `uploads/pages`, `uploads/tag-images` et `uploads/emissionsMp3`.

Les noms passent par `SafeFilenameNamer` ou `SmartUniqueNamer` selon le mapping. Les uploads TinyMCE ont des actions dédiées, avec contrôle de MIME et déplacement dans `public/uploads/tinymce`. Leurs conditions ne doivent pas être assimilées automatiquement à celles des champs Vich.

### Chaîne MP3

Dans l’édition d’une émission, le premier `flush()` permet à Vich d’enregistrer le nouveau fichier. Si un MP3 a été téléversé, le contrôleur appelle ensuite `Mp3Processor`, puis effectue un second `flush()` pour conserver le chemin et l’URL finaux.

Le traitement vérifie un code catégorie de trois lettres majuscules et l’existence du fichier. Il écrit des tags **ID3v2.3** via getID3, puis range le fichier sous :

```text
public/uploads/emissionsMp3/CODE/ANNEE/CODEYYYYMMDDTitre.mp3
```

Le titre est translittéré et normalisé ; un suffixe évite les collisions de noms. Les tags reprennent titre, participants, catégorie, année, descriptif simplifié et éditeur. La couverture vient de l’image de catégorie pour l’année, avec repli sur `public/images/default-mp3-cover.jpg` si disponible.

`thumbnailMp3` reçoit le chemin relatif et `url` est construite à partir de `app.mp3_public_base_url`, actuellement une URL absolue définie dans `config/services.yaml`. L’opération de suppression MP3 enlève le fichier local et efface ces deux champs.

Cette chaîne ne montre pas de transcodage audio, de transfert vers LibreTime ni de worker MP3. Les modifications SQL et les opérations de fichiers ne forment pas une transaction atomique commune : une erreur après le premier flush peut laisser une étape déjà effectuée.

