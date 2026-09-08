# Tests automatisés – Radio Zinzine

Ce document décrit la stratégie de tests automatisés du projet Radio Zinzine,
les services actuellement couverts et les principaux scénarios sécurisés.

# Tests — 09/09/2026

## Repositories

- Finalisation des tests d’intégration des repositories.
- `DiffusionRepository` : **88,89 % méthodes / 98,81 % lignes**.
  - Les dernières branches non couvertes sont défensives et ne justifient pas de tests artificiels.
- `DiffusionDraftRepository` : **100 %**
- `AnnonceRepository` : **100 %**
- `CategoriesRepository` : **100 %**
- `CategorieTagImageRepository` : **100 %**
- `EditeurRepository` : **100 %**
- `EvenementRepository` : **100 %**
- `GridSlotArbitrationRepository` : **100 %**
- `InviteOldAnimateurRepository` : **100 %**
- `PageRepository` : **100 %**
- `ProgrammationRuleRepository` : **100 %**
- `ProgrammationRuleSlotRepository` : **100 %**
- `ThemeRepository` : **100 %**
- `UserRepository` : **100 %**

✅ Partie **Repositories terminée**.

## Services

- Finalisation des tests des services.
- `GridAssignmentService` : **100 %**
- `GridPublicationService` : **92,31 % des lignes**
- `GridRebroadcastCoverageService` : **82,70 % des lignes**
- `GridUnpublicationService` : **93,96 % des lignes**
- `GridViewBuilder` : **90,61 % des lignes**
- `GridOccurrenceProjectionService` : **96,05 % des lignes**
- `Mp3Processor` : **95,80 % des lignes**
- `InfosSoirRssService` : **100 %**
- `ProgrammationGridBuilder` : **94,36 % des lignes**
- `PublicScheduleBuilder` : **100 %**
- `SafeFilenameNamer` : **100 %**
- `WeeklyAnnouncementPrintBuilder` : **100 %**
- Les branches restantes correspondent principalement à des gardes défensives ou à des états incohérents impossibles ou peu pertinents à reproduire.
- Décision de ne pas ajouter de tests artificiels uniquement pour atteindre 100 %.

### Correction détectée grâce aux tests

- `SafeFilenameNamer` :
  - détection d’un problème avec `iconv()` qui supprimait notamment le `É` initial ;
  - remplacement de cette logique par le `AsciiSlugger` de Symfony ;
  - couverture finale : **100 %**.

✅ Partie **Services terminée**.

## Controllers

- Début de la reprise des tests des controllers.
- Lancement des tests existants de `HomeControllerTest`.
- Identification du gros bloc HTML affiché par PHPUnit : il correspond à la page d’exception Symfony générée lorsqu’une requête du test échoue.
- Vérification de l’environnement et de la base utilisés par les tests.
- Correction de l’accès à `symfony_test`.
- Confirmation que `symfony_test` ne contient pas les pages statiques nécessaires aux anciens tests.

### HomeControllerTest

- Début de la refonte de `HomeControllerTest` afin qu’il ne dépende plus du contenu préexistant de `symfony_test`.
- Ajout de la création des pages statiques nécessaires aux tests :
  - `radio`
  - `zone`
  - `aide`
  - `amis`
  - `mentions`
  - `contacts`
  - `don`
  - `newsletter`
- Ajout d’une transaction Doctrine dans `setUp()`.
- Ajout d’un rollback dans `tearDown()`.
- Les données créées par les tests ne restent donc plus dans `symfony_test`.
- `testShowEvenement()` crée son propre `Evenement` dans la transaction de test.

### Mise à jour des assertions HTML

- Mise à jour de `testShowEvenement()` pour correspondre au template actuel.
- Ancien sélecteur :
  - `div.bodyevenement`
- Nouveau sélecteur :
  - `div.evenement`
- Ajout d’une vérification de `h1.evenement-titre` afin de confirmer que le bon événement est affiché.
- Constat que les anciennes classes spécifiques des pages statiques (`bodyradio`, `bodyzoneecoute`, `bodyaide`, etc.) n’existent plus.
- Les pages statiques utilisent désormais une structure commune :
  - `div.page-card`
  - `h1.page-title`
  - `div.page-content`
- Préparation d’une méthode commune `assertStaticPage()` afin de centraliser les assertions et d’éviter la duplication.
- Chaque page statique vérifiera :
  - la réponse HTTP ;
  - la présence de `div.page-card` ;
  - le titre correspondant dans `h1.page-title`.

## État en fin de session

- ✅ Repositories : **terminé**
- ✅ Services : **terminé**
- 🚧 Controllers : **en cours**
- 🚧 `HomeControllerTest` : **refonte en cours**

### Prochaine étape

1. Terminer la mise à jour de `HomeControllerTest`.
2. Relancer l’ensemble de ses tests.
3. Vérifier `testProgramme()` et `testInfos()`.
4. Continuer avec les autres controllers.
5. Traiter les Forms après les Controllers.

## [Tests] – 08/09/2026

### 🧪 Poursuite de la couverture de `EmissionRepository`

Poursuite des tests du repository des émissions, avec un focus sur les méthodes utilisées par la grille de programmation et sur la fiabilisation de la recherche textuelle.

- ajout de tests sur `findGridCandidatesByCategory()` ;
- vérification de la sélection des émissions appartenant uniquement à la catégorie demandée ;
- vérification de l’exclusion des émissions auto-générées de la liste générique des candidats ;
- vérification de la recherche sur le titre avec plusieurs mots ;
- identification de l’impossibilité de tester une émission sans `datepub`, la colonne étant `NOT NULL` en base ;
- harmonisation de la recherche textuelle entre la grille, la recherche publique et la recherche admin ;
- extraction de la construction de la regex dans une méthode privée commune `buildWordSearchRegex()` ;
- ajout d’une tolérance sur certaines variations simples de mots, notamment singulier/pluriel ;
- conservation des limites de mots afin d’éviter les correspondances indésirables ;
- ajout d’un test de non-régression vérifiant que `chronique` trouve `chroniques`, tandis que `rap` ne correspond pas à `rapporteur` ;
- ajout d’un premier test sur `findAutoGeneratedForSlotAndStartsAt()` ;
- vérification qu’une émission auto-générée est retrouvée à partir de son `ProgrammationRuleSlot` et de son horaire exact ;
- prise en compte des différents mappings Doctrine `DATETIME_MUTABLE`, `DateTimeImmutable` et `time_immutable` dans les données de test ;
- validation du passage d’un `DateTimeImmutable` au repository et de sa conversion pour la comparaison avec le champ mutable en base ;
- `EmissionRepositoryTest` atteint désormais au moins 11 tests, avec les nouveaux comportements de recherche et de grille couverts.

**Résultat : OK**

## [Tests] – 06/09/2026

### 🧪 Reprise complète des tests des Entity

Audit et remise à niveau de l’ensemble des tests des entités Doctrine.

Vérification des principaux comportements :

- getters / setters ;
- valeurs par défaut ;
- relations Doctrine ;
- méthodes métier ;
- comportements spécifiques des entités.

Les 16 entités principales disposent désormais chacune de leur classe de test dédiée :

- `Emission`
- `Categorie`
- `Annonce`
- `Editeur`
- `Evenement`
- `InviteOldAnimateur`
- `Theme`
- `User`
- `CategorieTagImage`
- `Diffusion`
- `DiffusionDraft`
- `GridSlotArbitration`
- `Page`
- `PasswordResetToken`
- `ProgrammationRule`
- `ProgrammationRuleSlot`

Validation de l’ensemble de la suite `tests/Entity`.

**Résultat : 330 tests / 1119 assertions – OK**

### 🔧 Fiabilisation de l'environnement PHPUnit

Lors de la reprise des tests, un problème d’isolation entre les environnements
`dev` et `test` a été identifié.

Le conteneur de développement chargeait à la fois :

```yaml
env_file:
  - .env
  - .env.test
```

Le fichier `.env.test` prenait alors le dessus et faisait démarrer
l’application de développement en environnement `test`.

Cela expliquait notamment l’impossibilité temporaire de se connecter
normalement à l’administration.

Correction de `docker-compose.dev.yml` afin que le conteneur de développement
ne charge plus que :

```yaml
env_file:
  - .env
```

Le conteneur a ensuite été recréé et le fonctionnement de l’authentification
vérifié.

### 🔧 Isolation de PHPUnit

Correction de `phpunit.xml.dist` afin que PHPUnit force lui-même son
environnement de test :

```xml
<env name="APP_ENV" value="test" force="true" />
<env name="DATABASE_URL" value="mysql://root:root@db:3306/symfony?serverVersion=8.0" force="true" />
```

La séparation est désormais garantie entre :

- l’application locale en environnement `dev` ;
- PHPUnit en environnement `test` ;
- la base Doctrine de test `symfony_test`.

Les tests continuent ainsi à fonctionner sans que `.env.test` soit chargé
globalement par Docker.

### ✅ Audit des tests de `EmissionController`

Poursuite de l’audit des tests fonctionnels du contrôleur des émissions.

Les scénarios couvrent désormais notamment :

- affichage de la liste personnelle des émissions ;
- création d’une émission ;
- modification d’une émission ;
- suppression par soft delete ;
- affichage d’une émission ;
- recherche administrative ;
- recherche par titre ;
- recherche par initiale ;
- finalisation d’une émission ;
- suppression d’un MP3 ;
- suppression d’une miniature ;
- contrôles d’accès ;
- contrôles CSRF.

### 🐛 Correction de l'affichage d'une émission sans catégorie

L’ajout d’un test sur `show()` avec une émission ne possédant aucune catégorie
a révélé une erreur dans le template Twig.

Le template tentait d’accéder à :

`emission.categorie.titre`

même lorsque `categorie` était `null`.

Le bloc d’affichage de la catégorie a été protégé afin qu’il ne soit rendu
que lorsqu’une catégorie existe.

Deux scénarios sont maintenant couverts :

- émission avec catégorie ;
- émission sans catégorie.

**Résultat : `show()` couvert à 100 %**

### 🔎 Fiabilisation de la recherche administrative

Ajout et vérification des tests autour de la recherche des émissions :

- accès à la page de recherche ;
- recherche par titre ;
- recherche par initiale.

Le test de recherche par initiale a mis en évidence un problème lié à
l’accumulation des données dans la base de test et à la pagination limitée
à 12 résultats.

Une émission commençant par `A` pouvait être correctement trouvée par la
requête mais apparaître sur une autre page du paginator.

Le scénario a été fiabilisé en combinant :

- une initiale connue ;
- un terme de recherche unique généré pour le test.

Le test ne dépend ainsi plus de l’ordre ou du volume des données déjà
présentes dans la base de test.

### 🧹 Suppression d'une ancienne route MP3 inutilisée

L’audit de la couverture a montré que l’ancienne méthode :

`EmissionController::deleteMp3()`

était très peu couverte.

Une recherche dans le projet a confirmé que cette route n’était plus appelée
par l’interface.

La suppression du MP3 est aujourd’hui gérée directement depuis le formulaire
d’édition via :

- `EmissionType::deleteMp3` ;
- `EmissionController::edit()` ;
- `Mp3Processor::delete()`.

L’ancienne route dédiée constituait donc du code mort.

Suppression de :

- la méthode `deleteMp3()` ;
- sa route ;
- ses anciens tests devenus inutiles ;
- l’import CSRF associé devenu inutilisé.

### ✅ Suppression du MP3 depuis l'édition

Ajout d’un test fonctionnel du mécanisme réellement utilisé par l’interface.

Le scénario crée une émission possédant :

- un `thumbnailMp3` ;
- une URL associée.

Le formulaire d’édition est ensuite soumis avec la demande de suppression
du MP3.

Vérifications :

- passage dans `Mp3Processor::delete()` ;
- remise à `null` de `thumbnailMp3` ;
- remise à `null` de l’URL ;
- sauvegarde correcte de l’émission.

Le test ne nécessite pas de vrai fichier MP3 : le processeur ne tente la
suppression physique que lorsque le fichier existe.

**Résultat : OK**

### ✅ Suppression de l'image depuis l'édition

Ajout d’un test fonctionnel du mécanisme `delete_thumbnail` utilisé par
le formulaire Twig d’édition.

Vérifications :

- présence initiale d’une miniature ;
- soumission de la checkbox `delete_thumbnail` ;
- passage dans le mécanisme de suppression VichUploader ;
- remise à `null` de `thumbnail`.

**Résultat : OK**

### ✅ Finalisation d'une émission

Ajout d’un test fonctionnel de `markCompleted()` depuis la liste des émissions
à finaliser du dashboard administrateur.

Le scénario vérifie :

- émission initialement marquée `isPendingCompletion = true` ;
- utilisateur lié à l’émission ;
- récupération du véritable formulaire Twig ;
- utilisation du véritable token CSRF ;
- soumission du formulaire ;
- passage de `isPendingCompletion` à `false`.

**Résultat : OK**

### 🔐 Tests de sécurité de `markCompleted()`

Ajout des scénarios de sécurité manquants.

#### Utilisateur non autorisé

Création d’une émission non liée à l’utilisateur connecté.

Vérification que l’accès est refusé.

Le test a également confirmé le comportement du `AccessDeniedHandler`
du projet : l’exception d’accès refusé entraîne une redirection vers :

`/access-denied`

**Résultat : OK**

#### Token CSRF invalide

Création d’une émission correctement liée à l’utilisateur afin de franchir
le contrôle d’autorisation.

Envoi volontaire d’un token CSRF invalide.

Vérification que la requête est refusée et redirigée vers :

`/access-denied`

**Résultat : OK**

Grâce à ces scénarios :

**`markCompleted()` : 100 % – 11 / 11 lignes couvertes**

### ✅ Sécurisation de la suppression d'une émission

Ajout d’un scénario de suppression avec token CSRF invalide.

Vérifications :

- redirection vers la liste des émissions ;
- conservation de l’émission en base ;
- absence de soft delete ;
- `isDeleted()` reste à `false`.

Le test vérifie ainsi le comportement métier et pas uniquement la
redirection HTTP.

**Résultat : OK**

### 📊 Rapport de couverture de `EmissionController`

Utilisation du rapport HTML PHPUnit afin d’identifier précisément les
branches du contrôleur encore non couvertes.

Un problème a été rencontré lors de la régénération du rapport.

Xdebug était bien chargé dans le conteneur mais le mode `coverage`
n’était pas activé.

PHPUnit indiquait :

```text
XDEBUG_MODE=coverage or xdebug.mode=coverage has to be set
```

La génération du rapport fonctionne désormais avec :

```bash
docker exec -it -e XDEBUG_MODE=coverage symfony_app php bin/phpunit tests/Controller/Admin/EmissionControllerTest.php --coverage-html var/coverage
```

Cette commande permet d’activer la couverture uniquement lorsque le rapport
est nécessaire, sans l’imposer aux exécutions PHPUnit normales.

### 📈 Progression de la couverture de `EmissionController`

Au début de l’audit :

**86,78 % – 151 / 174 lignes couvertes**

Après ajout des nouveaux scénarios :

**91,95 % – 160 / 174 lignes couvertes**

État du dernier rapport généré :

- `show()` : **100 %**
- `markCompleted()` : **100 %**
- `index()` : **97,06 %**
- `search()` : **96,30 %**
- `create()` : **92,59 %**
- `edit()` : **84,09 %**
- `delete()` : **72,73 %**

Un nouveau test du CSRF de `delete()` a été ajouté après la génération
de ce rapport.

Le nouveau pourcentage de `delete()` reste donc à recalculer.

### ⚠️ Branche MP3 volontairement laissée hors couverture

La branche correspondant à l’upload d’un nouveau MP3 dans `edit()` n’a pas
été forcée uniquement pour augmenter artificiellement le pourcentage.

`Mp3Processor::process()` réalise un traitement complet :

- validation du slug de catégorie ;
- vérification du fichier source ;
- création du répertoire de destination ;
- génération du nom définitif ;
- écriture des tags MP3 ;
- déplacement physique du fichier ;
- génération de l’URL publique.

Ce comportement mérite plutôt une suite de tests dédiée à `Mp3Processor`
qu’un test lourd ajouté artificiellement à `EmissionControllerTest`.

### 🔜 Prochaine étape

Terminer la couverture utile de :

`EmissionController::delete()`

Il reste à tester la redirection personnalisée lorsque `returnTo` est fourni.

Une fois cette branche couverte :

- régénérer le rapport de couverture ;
- vérifier le nouveau résultat de `delete()` ;
- considérer `EmissionController` comme suffisamment couvert ;
- poursuivre progressivement l’audit des contrôleurs suivants.

## [Tests] – 05/09/2026
### 🧪 Mise en place et fiabilisation de l'environnement de test

- Configuration de la base Doctrine dédiée aux tests (`symfony_test`).
- Validation du schéma Doctrine dans l'environnement `test`.
- Mise en place d'un `DoctrineTestTrait` pour les tests d'intégration.
- Ajout d'une transaction automatique autour de chaque test Doctrine :
  - ouverture de la transaction au démarrage du test ;
  - rollback automatique à la fin ;
  - isolation des données entre les tests ;
  - suppression du besoin de nettoyer manuellement la base après chaque scénario.
- Ajout / organisation des commandes Composer :
  - `test`
  - `test:unit`
  - `test:integration`
  - `test:grid`
  - `test:schema`
  - `test:new`
- Configuration de `services_test.yaml` pour rendre
  `GridRebroadcastCoverageService` accessible au conteneur de tests avec
  autowiring/autoconfiguration.
- Nettoyage du cache Symfony `test` après modification de la configuration.

### ✅ GridConflictDetector

- Mise en place d'une suite de tests unitaires du détecteur de conflits.
- Couverture des principaux cas de chevauchement de créneaux.
- Validation du comportement aux limites des créneaux.

**Résultat : 11 tests / 50 assertions – OK**

### ✅ GridOccurrenceProjectionService

- Ajout des tests de projection des occurrences.
- Vérification des arbitrages :
  - annulation ;
  - déplacement ;
  - occurrence d'origine ;
  - occurrence projetée ;
  - gestion des projections hors semaine.

**Résultat : 6 tests / 87 assertions – OK**

### ✅ Tests Doctrine

- Ajout d'un premier test d'intégration Doctrine avec `Theme`.
- Validation de la persistance et de la relecture réelle depuis la base de test.

**Résultat : 1 test / 6 assertions – OK**

### ✅ GridPublicationService

Ajout d'une suite complète de tests d'intégration de la validation de grille.

Scénarios couverts :

- création d'une nouvelle `Diffusion` depuis un `DiffusionDraft` ;
- republication d'un Draft déjà lié en mettant à jour la même `Diffusion` ;
- réutilisation d'une ancienne `Diffusion` non réservée au même horaire ;
- blocage lorsqu'une `Diffusion` est déjà réservée par un autre Draft ;
- création d'une nouvelle `Diffusion` lorsqu'il existe plusieurs anciennes
  Diffusions réutilisables et qu'aucune ne peut être choisie arbitrairement.

Ces tests ont permis d'identifier un vrai problème lors du déplacement d'une
Diffusion déjà existante : la modification séparée de l'heure de début et de
l'heure de fin pouvait temporairement créer un état invalide.

Ajout de `Diffusion::setSchedule()` afin de modifier atomiquement :

- `horaireDiffusion` ;
- `durationMinutes` ;
- `endsAt`.

Adaptation de `GridPublicationService` pour utiliser cette méthode lors de la
publication/republication.

**Résultat : 5 tests / 132 assertions – OK**

### ✅ GridUnpublicationService

Ajout d'un test d'intégration de la dévalidation d'une semaine.

Vérifications :

- passage de la `Diffusion` à l'état `unpublished` ;
- retour du `DiffusionDraft` à l'état `draft` ;
- remise à `null` de `publishedAt` ;
- conservation volontaire du lien `publishedDiffusion` ;
- conservation de l'horaire et du groupe de diffusion.

Le Draft reste ainsi lié à sa Diffusion historique afin qu'une future
revalidation puisse mettre à jour cette même Diffusion au lieu d'en créer
une nouvelle.

**Résultat : OK**

### ✅ Cycle complet validation → dévalidation → modification → revalidation

Ajout d'un test d'intégration couvrant le cycle réel d'utilisation de la grille :

1. création d'un Draft ;
2. validation de la semaine ;
3. création de la Diffusion ;
4. dévalidation ;
5. conservation du lien Draft ↔ Diffusion ;
6. modification du Draft pendant que la semaine est dévalidée ;
7. changement d'horaire, de durée, de rang et de groupe ;
8. revalidation ;
9. mise à jour de la Diffusion initiale au lieu d'en créer une nouvelle.

Ce test valide notamment le principe suivant :

> Après dévalidation, `DiffusionDraft` redevient la source de vérité et une
> nouvelle validation synchronise la `Diffusion` existante avec le Draft.

**Résultat : 1 test / 49 assertions – OK**

### ✅ GridRebroadcastCoverageService

Début de la couverture d'intégration du système de contrôle et de réparation
des rediffusions régulières.

#### Détection d'une rediffusion manquante

Création d'un scénario réel comprenant :

- un `Editeur` ;
- une `Categories` ;
- une `ProgrammationRule` ;
- un slot de première diffusion ;
- un slot de rediffusion de rang 2 ;
- une `Emission` ;
- une vraie `Diffusion` correspondant à la première diffusion ;
- aucun `DiffusionDraft` sur la rediffusion attendue.

Vérification que `GridRebroadcastCoverageService::previewWeek()` :

- retrouve la Diffusion source ;
- retrouve la rediffusion théorique depuis la règle ;
- associe correctement `firstBroadcastStartsAt` ;
- détecte l'absence de Draft ;
- classe le créneau en `STATUS_MISSING` ;
- indique le créneau comme remplissable.

**Résultat : 1 test / 41 assertions – OK**

#### Réparation automatique d'une rediffusion manquante

Ajout d'un second scénario autour de
`fillMissingRebroadcastsForWeek()`.

Vérification que le service :

- part d'une rediffusion réellement classée `missing` ;
- crée un `DiffusionDraft` ;
- utilise l'émission de la Diffusion source ;
- rattache le Draft au bon `ProgrammationRuleSlot` ;
- crée un Draft de type `regular` ;
- utilise le rang 2 comme `nombreDiffusion` ;
- reprend le bon horaire et la bonne durée ;
- calcule correctement `endsAt` ;
- reprend l'`assignmentGroupKey` de la Diffusion source ;
- laisse le Draft à l'état `draft` ;
- ne crée aucun lien `publishedDiffusion` prématuré.

Après réparation, une nouvelle preview confirme que le créneau passe de :

`missing` → `normal`

**Résultat : OK**

### 🔧 Refactor des tests de rediffusion

- Factorisation de la création des données de test dans
  `createRebroadcastContext()`.
- Les scénarios utilisent de vraies entités Doctrine et le véritable
  `ProgrammationGridBuilder`.
- Aucun mock du système de construction de grille pour ces tests.
- Isolation de chaque scénario grâce au rollback transactionnel.

### ⚠️ À traiter ultérieurement

Doctrine signale actuellement une dépréciation concernant la déclaration
`uniqueConstraints` de `ProgrammationRule` :

`ORM\Table(uniqueConstraints: ...)`

Cette syntaxe devra être remplacée par l'attribut Doctrine
`ORM\UniqueConstraint` avant Doctrine ORM 4.

Cette dépréciation n'empêche actuellement aucun test de passer.

### 🔜 Prochaine étape

Ajouter un test de `GridRebroadcastCoverageService` lorsque la Diffusion source
ne possède pas encore d'`assignmentGroupKey`.

Le test devra vérifier que le service :

- génère une clé stable de type
  `rule_{ruleId}_origin_{YYYYMMDD_HHmm}` ;
- l'enregistre sur la Diffusion source ;
- utilise exactement la même clé sur le nouveau `DiffusionDraft`.