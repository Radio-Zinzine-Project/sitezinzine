# Fichiers, MP3 et médias

> Note — Détails transférés depuis la vue d’ensemble, à partir de la lecture du dépôt du 11 septembre 2026. Les chemins sont relatifs à la racine Symfony sitezinzine/. Cette page ne constitue pas une nouvelle analyse exhaustive du sujet.

[Retour à l’architecture](01-architecture.md)

## Fichiers, images et MP3

### Stockage technique

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

