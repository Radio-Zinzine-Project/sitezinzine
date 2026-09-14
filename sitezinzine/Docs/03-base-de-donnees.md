# Base de données

> Note — Détails transférés depuis la vue d’ensemble, à partir de la lecture du dépôt du 11 septembre 2026. Les chemins sont relatifs à la racine Symfony sitezinzine/. Cette page ne constitue pas une nouvelle analyse exhaustive du sujet.

[Retour à l’architecture](01-architecture.md)

## Modèle persistant et accès aux données

### Responsabilités des entités

Les principales familles sont :

- **Catalogue audio** : émissions, catégories, thèmes, éditeurs et participants. Une émission porte les métadonnées éditoriales et audio ; son horaire de passage n’est pas sa seule date de publication éditoriale.
- **Programmation** : règles, créneaux récurrents, arbitrages d’occurrences, brouillons et diffusions concrètes.
- **Contenus du site** : annonces, événements et pages, avec images et indicateurs de visibilité selon les entités.
- **Identité** : utilisateurs, rôles, vérification d’adresse et jetons de réinitialisation.

`User` et `InviteOldAnimateur` représentent deux catégories distinctes de participants : un invité ou ancien animateur n’est pas nécessairement un compte capable de se connecter.

### Repositories et Doctrine

Les repositories encapsulent recherches, filtres de visibilité, tris et pagination Knp. Certains renvoient des entités, d’autres des QueryBuilders, paginations ou tableaux prêts à être consommés par les vues. Des regroupements métier, notamment de thèmes, sont aussi codés dans les requêtes. Il existe des accès DBAL directs, par exemple pour retrouver les utilisateurs d’une catégorie lors de la création d’un direct.

Le mapping ORM repose sur les attributs PHP de `src/Entity`. La connexion vient de `DATABASE_URL`. Les recettes Docker utilisent MySQL 8 ; une fonction DQL personnalisée `REGEXP` est enregistrée. Les migrations sont dans `migrations/` mais leur présence ne garantit ni leur application ni l’alignement d’une base existante avec les entités.

Les entités déclarent relations, index et contraintes d’unicité, dont le couple créneau/horaire pour un draft régulier. La suppression n’est pas uniforme : booléens `softDelete`, dates `deletedAt`, annulation et suppression physique coexistent. Aucun filtrage global ne doit être supposé : il faut consulter la méthode de repository appelée.

Les écritures utilisent `persist`, `remove` et `flush`. Publication et dépublication encadrent leur travail dans `wrapInTransaction`. Cela ne constitue pas une garantie générale de verrouillage contre toutes les modifications concurrentes. En environnement de test, Doctrine ajoute le suffixe `_test` au nom de base.

