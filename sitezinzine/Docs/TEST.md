# Tests automatisés – Radio Zinzine

Ce document décrit la stratégie de tests automatisés du projet Radio Zinzine,
les services actuellement couverts et les principaux scénarios sécurisés.

# Changelog — Tests du 13 septembre 2026

## Formulaire Emission — gestion des catégories

Poursuite de la couverture de `EmissionType` afin de sécuriser les catégories accessibles selon le rôle de l'utilisateur.

### Règles couvertes

- USER / EDITOR :
  - ne peuvent sélectionner que les catégories actives et non supprimées auxquelles ils sont actuellement associés ;
  - en édition, la catégorie déjà enregistrée sur l'émission reste disponible même si l'utilisateur n'y est plus associé ;
  - la catégorie courante reste également disponible si elle est devenue inactive ou soft-deleted.

- ADMIN / SUPER_ADMIN :
  - peuvent sélectionner toutes les catégories actives et non supprimées ;
  - en édition, la catégorie actuelle de l'émission reste disponible même si elle est devenue inactive ou soft-deleted.

- Vérification qu'une catégorie non autorisée ne peut pas être imposée simplement par un POST forgé.

Les tests correspondants ont été ajoutés à `tests/Form/EmissionTypeTest.php` et sont passés au vert.


## Formulaire Emission — gestion des utilisateurs

Mise en place et couverture de la nouvelle règle métier concernant les utilisateurs associés aux émissions.

### Règle métier retenue

L'association entre une catégorie et ses utilisateurs représente la responsabilité actuelle de la catégorie.

Elle ne doit pas effacer l'historique des émissions déjà réalisées.

En conséquence :

- un utilisateur qui n'est plus associé à une catégorie peut rester associé aux anciennes émissions qu'il a réalisées ;
- changer les utilisateurs d'une catégorie ne doit pas supprimer automatiquement les utilisateurs historiques des émissions existantes ;
- changer la catégorie d'une émission existante ne doit pas supprimer automatiquement ses utilisateurs historiques.


## Règles selon les rôles

### ROLE_USER / ROLE_EDITOR

Les utilisateurs sélectionnables dans une émission sont :

- les utilisateurs actuellement associés à la catégorie sélectionnée ;
- les utilisateurs déjà associés à l'émission lorsqu'elle est éditée.

À la création, si aucun utilisateur n'est explicitement sélectionné, l'utilisateur connecté est ajouté automatiquement uniquement s'il appartient actuellement à la catégorie.

### ROLE_ADMIN

L'ADMIN peut modifier toutes les émissions mais ne peut pas associer arbitrairement n'importe quel utilisateur.

Les utilisateurs disponibles sont :

- les utilisateurs actuellement associés à la catégorie ;
- les utilisateurs historiques déjà associés à l'émission.

À la création, si aucune sélection explicite n'est faite, tous les utilisateurs actuellement associés à la catégorie sont ajoutés par défaut.

Une sélection explicite d'un sous-ensemble reste prioritaire et doit être respectée.

### ROLE_SUPER_ADMIN

Le SUPER_ADMIN peut sélectionner n'importe quel utilisateur.

Tous les utilisateurs sont donc disponibles dans le formulaire.

À la création, seuls les utilisateurs actuellement associés à la catégorie sont néanmoins sélectionnés automatiquement par défaut.

Les utilisateurs extérieurs à la catégorie restent disponibles mais ne sont jamais ajoutés automatiquement.


## Statut des utilisateurs dans le formulaire

Introduction d'une distinction entre trois situations :

- `current` : utilisateur actuellement associé à la catégorie ;
- `historical` : utilisateur déjà associé à l'émission mais plus à la catégorie ;
- `outside` : utilisateur sans lien avec la catégorie ni avec l'émission.

Les libellés permettent notamment d'afficher :

- `— catégorie actuelle`
- `— ancienne association`
- `— hors catégorie`

Les utilisateurs hors catégorie ne sont proposés qu'au SUPER_ADMIN.


## Service EmissionUserChoicesProvider

Ajout/utilisation du service :

`src/Service/EmissionUserChoicesProvider.php`

Il centralise la détermination :

- des utilisateurs autorisés ;
- de leur statut ;
- de leur libellé ;
- de leur groupe d'affichage ;
- de l'appartenance actuelle à une catégorie.

Cela évite de disperser les règles d'autorisation entre le formulaire, le contrôleur et le futur comportement JavaScript.


## Tests EmissionType ajoutés

Ajout de tests spécifiques concernant le comportement ADMIN / SUPER_ADMIN :

- `testAdminAndSuperAdminDefaultToAllCategoryUsersOnCreation()`
- `testAdminExplicitUserSelectionOverridesCategoryDefault()`
- `testAdminCannotSelectUserOutsideCategory()`
- `testSuperAdminCanExplicitlySelectUserOutsideCategory()`

Un premier problème de fixture a été identifié : les tests utilisaient une association ne mettant pas à jour la collection `Categories::getUsers()` en mémoire alors que la nouvelle logique s'appuie directement dessus.

Les tests ont été corrigés en utilisant :

`$categorie->addUser(...)`

Un second problème concernait le test du fallback USER : l'émission utilisée était déjà persistée et possédait donc un ID, alors que le fallback ne doit fonctionner qu'à la création.

Le test a été corrigé pour utiliser une nouvelle instance non persistée de `Emission`.

### Résultat obtenu

`tests/Form/EmissionTypeTest.php`

Résultat :

`OK (19 tests, 112 assertions)`


## Suppression des dépréciations Doctrine

Les tests du formulaire étaient verts mais produisaient quatre dépréciations Doctrine.

Origine identifiée dans `EmissionUserChoicesProvider` :

ancienne utilisation de la direction de tri sous forme de chaîne.

Correction avec :

`SortDirection::Ascending`

Après correction :

- tests `EmissionTypeTest` verts ;
- plus de dépréciation sur ce fichier de tests.


## Endpoint dynamique utilisateurs / catégorie

Préparation de l'endpoint destiné au futur contrôleur Stimulus :

`admin.emission.users_for_category`

Route confirmée par Symfony :

`GET /admin/emission/users-for-category/{id}`

L'objectif de cet endpoint est de fournir au formulaire les utilisateurs autorisés lorsqu'une catégorie est changée dynamiquement.

Il doit appliquer les mêmes règles que `EmissionUserChoicesProvider` et ne pas confier les décisions d'autorisation au JavaScript.


## Nouveau fichier de tests fonctionnels

Ajout de :

`tests/Functional/EmissionUsersForCategoryTest.php`

11 scénarios ont été préparés pour couvrir notamment :

- accès d'un USER aux utilisateurs de sa propre catégorie ;
- refus d'accès à une catégorie étrangère ;
- comportement EDITOR ;
- comportement ADMIN ;
- comportement SUPER_ADMIN ;
- présélection automatique des utilisateurs de catégorie en création ADMIN / SUPER_ADMIN ;
- conservation d'un utilisateur historique en édition ;
- absence de sélection automatique des nouveaux utilisateurs de catégorie en édition ;
- conservation de l'accès d'un ancien responsable à une émission historique ;
- impossibilité d'utiliser un `emissionId` appartenant à un autre utilisateur pour récupérer ses associations ;
- accès ADMIN aux émissions des autres utilisateurs ;
- catégories et émissions inexistantes.


## État actuel des tests fonctionnels de l'endpoint

Premier lancement :

`Tests: 11, Assertions: 13, Failures: 9`

La majorité des tests reçoit actuellement une page HTTP 404 au lieu de la réponse JSON attendue.

La route a été vérifiée avec `debug:router` et existe bien :

`admin.emission.users_for_category`
`GET /admin/emission/users-for-category/{id}`

Le problème n'est donc pas simplement une route absente.


## Isolation du premier échec

Le test suivant a été lancé seul :

`testUserGetsUsersFromOwnCategory`

Résultat :

`Tests: 1, Assertions: 1, Failures: 1`

La requête reçoit une vraie page 404 personnalisée Radio Zinzine.

Le fichier `var/log/test.log` consulté ensuite ne contient pas d'entrée correspondant à ce test récent et n'a donc pas permis d'identifier l'origine de cette 404.


## Diagnostic prévu pour la reprise

Avant de modifier le contrôleur ou les tests, vérifier directement dans le premier test que la catégorie créée par la fixture est retrouvée par Doctrine avant l'appel HTTP.

Contrôle prévu :

`$this->entityManager->getRepository(Categories::class)->find($categoryId)`

Cela permettra de distinguer :

- un problème de fixture / transaction / EntityManager ;
- d'un problème déclenché uniquement pendant le traitement HTTP de l'endpoint.

Aucune correction définitive n'a encore été appliquée sur ce point.

Le diagnostic des 9 échecs fonctionnels reprendra à partir de ce test isolé.


## État en fin de session

### Vert

- règles de catégories dans `EmissionType` ;
- règles de sélection des utilisateurs dans `EmissionType` ;
- fallback USER en création ;
- fallback ADMIN / SUPER_ADMIN vers tous les utilisateurs actuels de la catégorie ;
- sélection explicite ADMIN respectée ;
- interdiction ADMIN d'ajouter un utilisateur hors catégorie ;
- possibilité SUPER_ADMIN d'ajouter un utilisateur hors catégorie ;
- conservation conceptuelle des associations historiques ;
- `EmissionTypeTest` : 19 tests / 112 assertions ;
- dépréciations Doctrine du provider supprimées.

### À reprendre

- diagnostic de la 404 sur `EmissionUsersForCategoryTest` ;
- faire passer les tests fonctionnels de l'endpoint ;
- vérifier ensuite l'ensemble du fichier fonctionnel sans dépréciation ;
- seulement après, brancher le comportement dynamique dans `emission_form_controller.js`.

# Changelog Tests — 12 septembre 2026

## État de départ

La couverture technique du projet Radio Zinzine est désormais considérée comme terminée.

État connu avant le lancement de la nouvelle campagne fonctionnelle :

- 1327 tests
- 6418 assertions
- 97,25 % des lignes couvertes
- aucune dépréciation connue à ce stade
- API volontairement peu couverte car elle n'est pas encore utilisée

Décision prise :

- ne pas chercher artificiellement 100 % de couverture ;
- considérer la couverture technique actuelle comme suffisante ;
- passer aux tests fonctionnels orientés parcours utilisateur.

---

## Pages d'erreur personnalisées

Création et finalisation des pages d'erreur Symfony personnalisées dans :

templates/bundles/TwigBundle/Exception/

Fichiers concernés :

- error.html.twig
- error403.html.twig
- error404.html.twig
- error500.html.twig

Les pages conservent la structure graphique générale du site :

- navbar ;
- lecteur ;
- footer ;
- bandeau rouge ;
- déchirure graphique ;
- zone blanche jusqu'au footer.

La route utilisée pour revenir à l'accueil est :

home

URL correspondante :

/

La page 404 a notamment été adaptée avec :

- code 404 ;
- titre explicite indiquant que la page est introuvable ;
- message utilisateur sans information technique ;
- bouton permettant de revenir à l'accueil.

---

## Tests Twig des pages d'erreur

Création de :

tests/Twig/ErrorPagesTest.php

Type :

KernelTestCase

Couverture fonctionnelle :

- erreur générique ;
- erreur 403 ;
- erreur 404 ;
- erreur 500 ;
- présence de la structure navbar / main / footer ;
- absence d'exposition du message technique d'une exception 500.

Résultat :

- 6 tests
- 25 assertions
- VERT

Les pages d'erreur sont donc couvertes au niveau du rendu Twig.

---

## Test HTTP réel de la page 404

Création de :

tests/Twig/ErrorPagesHttpTest.php

Type :

WebTestCase

Objectif :

Vérifier que Symfony utilise réellement la page d'erreur personnalisée lors d'une requête HTTP produisant une 404.

Configuration particulière du KernelBrowser :

static::createClient([
    'environment' => 'test',
    'debug' => false,
]);

Le mode debug est volontairement désactivé afin de ne pas obtenir la page d'exception de développement Symfony à la place de la vraie page d'erreur destinée à l'utilisateur.

Résultat :

- 1 test
- 8 assertions
- VERT

Bilan pages d'erreur :

- 6 tests Twig
- 1 test HTTP
- 7 tests au total
- 33 assertions

Les pages d'erreur sont considérées comme techniquement terminées.

---

## Réflexion sur les URLs d'administration

Question étudiée :

Faut-il masquer des URLs explicites comme :

/admin/theme/

Décision :

NON.

Une URL lisible n'est pas considérée comme un problème de sécurité.

La sécurité doit être assurée par :

- security.yaml ;
- les rôles ;
- les contrôleurs ;
- les attributs IsGranted éventuels ;
- les voters éventuels ;
- les contrôles métier sur les ressources.

Configuration générale actuellement identifiée :

ROLE_SUPER_ADMIN
→ ROLE_ADMIN
→ ROLE_EDITOR
→ ROLE_USER

La zone :

/admin

est accessible à partir de ROLE_USER selon l'access_control général.

Les autorisations plus fines doivent ensuite être contrôlées par l'application.

---

## Définition des règles métier pour les émissions

Une distinction importante a été formalisée entre :

- visibilité d'une émission ;
- droit de modification ;
- droit de suppression.

### ROLE_USER

Dans « Mes émissions » :

- voit uniquement ses propres émissions.

Dans la recherche globale admin :

- voit toutes les émissions.

Permissions :

- peut modifier ses propres émissions ;
- peut supprimer ses propres émissions ;
- ne doit pas voir les actions Modifier/Supprimer sur les émissions d'autrui ;
- ne doit pas pouvoir contourner cette restriction en appelant directement les routes d'édition ou de suppression.

### ROLE_ADMIN

Dans « Mes émissions » :

- voit uniquement ses propres émissions.

Dans la recherche globale admin :

- voit toutes les émissions.

Permissions :

- peut modifier ses propres émissions ;
- peut supprimer ses propres émissions ;
- ne doit pas automatiquement disposer de droits globaux sur les émissions ;
- ne doit pas modifier ou supprimer les émissions d'autres utilisateurs.

ROLE_ADMIN ne doit donc pas être assimilé à ROLE_SUPER_ADMIN pour la gestion des émissions.

### ROLE_SUPER_ADMIN

Dans « Mes émissions » :

- voit toujours uniquement ses propres émissions.

Dans la recherche globale admin :

- voit toutes les émissions.

Permissions :

- voit Modifier/Supprimer sur toutes les émissions ;
- peut modifier les émissions d'autres utilisateurs ;
- peut supprimer les émissions d'autres utilisateurs.

Décision importante :

La disparition d'un bouton dans Twig ne constitue pas une sécurité.

Les autorisations doivent également être vérifiées par des appels HTTP directs aux routes protégées.

---

## Préparation de la première campagne de tests fonctionnels

Décision de créer une première campagne limitée à :

tests/Functional/AuthenticationTest.php
tests/Functional/AuthorizationTest.php
tests/Functional/PublicEmissionWorkflowTest.php

La deuxième campagne est volontairement reportée.

Elle concernera ultérieurement :

- EmissionWorkflowTest.php
- UploadWorkflowTest.php
- GridWorkflowTest.php
- GridPublicationWorkflowTest.php

Aucun test responsive visuel ne sera réalisé avec PHPUnit.

---

## Mission Codex

Une mission spécifique a été préparée pour Codex.

Contraintes imposées :

- WebTestCase / KernelBrowser ;
- vraie base Doctrine de test ;
- vraies routes du projet ;
- données de test indépendantes ;
- ne jamais supposer la base vide ;
- valeurs uniques ;
- réutilisation des helpers existants ;
- transaction/rollback selon les conventions du projet ;
- aucun mock inutile ;
- aucun Panther ;
- aucun Playwright ;
- aucun Selenium ;
- aucune route artificielle de test.

Codex devait impérativement inspecter avant de coder :

- security.yaml ;
- contrôleurs ;
- routes ;
- IsGranted ;
- voters ;
- tests existants ;
- tests/Support ;
- relations User / Emission ;
- fonctionnement réel des recherches et filtres.

Interdiction de modifier :

- src/
- contrôleurs
- services
- repositories
- entités
- security.yaml
- templates
- routes
- documentation
- Docs/TEST.md

En cas d'incohérence fonctionnelle :

- ne pas modifier l'application ;
- conserver ou signaler le test concerné ;
- documenter précisément l'écart constaté.

---

## Première campagne fonctionnelle générée par Codex

Codex a créé uniquement :

tests/Functional/AuthenticationTest.php
tests/Functional/AuthorizationTest.php
tests/Functional/PublicEmissionWorkflowTest.php
tests/Support/FunctionalTestCase.php

FunctionalTestCase.php sert de helper commun pour :

- fixtures ;
- préparation des données fonctionnelles ;
- rollback/nettoyage selon les conventions retenues.

Aucun fichier métier, template, configuration ou documentation n'a été modifié.

---

## AuthenticationTest

Nombre de tests :

6

Nombre d'assertions :

24

Les assertions fonctionnelles réussissent.

Scénarios couverts notamment :

- affichage de la page de connexion ;
- connexion valide ;
- mauvais mot de passe ;
- accès admin sans authentification ;
- logout ;
- accès admin après authentification.

Une dépréciation liée à :

User::eraseCredentials()

entraîne cependant un code de sortie PHPUnit 1 lors de l'exécution de ce fichier.

Cette dépréciation devra être étudiée séparément avant la mise en production.

Elle ne correspond pas à un échec des assertions fonctionnelles d'AuthenticationTest.

---

## AuthorizationTest

Nombre de tests :

36

Nombre d'assertions :

126

Résultat :

- 30 tests réussis
- 6 tests en échec

Ces échecs ont volontairement été conservés par Codex car ils correspondent à des différences entre les règles métier demandées et le comportement actuel de l'application.

### Anomalie 1 — ROLE_ADMIN et modification des émissions d'autrui

Comportement attendu :

ROLE_ADMIN ne doit pouvoir modifier que ses propres émissions.

Comportement constaté :

- ROLE_ADMIN voit actuellement le bouton Modifier sur une émission appartenant à un autre utilisateur ;
- ROLE_ADMIN peut accéder directement au formulaire d'édition de cette émission.

Cette règle devra être vérifiée dans :

- le Twig concerné ;
- la sécurité serveur de la route d'édition ;
- les éventuels IsGranted/voters/contrôles de propriété.

ROLE_ADMIN ne doit pas être assimilé à ROLE_SUPER_ADMIN.

### Anomalie 2 — Bouton Supprimer absent pour le propriétaire

Comportement attendu :

ROLE_USER et ROLE_ADMIN doivent pouvoir supprimer leurs propres émissions.

Comportement constaté :

- ROLE_USER ne voit pas le bouton Supprimer sur sa propre émission ;
- ROLE_ADMIN ne voit pas le bouton Supprimer sur sa propre émission.

Il faudra vérifier si cette absence est :

- volontaire dans le template actuel ;
- due à une condition Twig incorrecte ;
- ou liée à une autre règle actuellement implémentée.

Aucune correction n'a encore été décidée.

### Anomalie 3 — Suppression directe d'une émission appartenant à autrui

Comportement attendu :

ROLE_USER et ROLE_ADMIN ne doivent pas pouvoir supprimer l'émission d'un autre utilisateur.

Comportement constaté :

ROLE_USER et ROLE_ADMIN peuvent appeler directement la route de suppression d'une émission appartenant à un autre utilisateur lorsque la requête contient un token CSRF valide.

Ce comportement constitue une incohérence importante avec les règles métier définies.

Le CSRF ne remplace pas le contrôle d'autorisation sur la ressource.

La route de suppression devra donc être examinée afin de vérifier le contrôle de propriété et l'exception accordée à ROLE_SUPER_ADMIN.

Aucune correction du code métier n'a été réalisée pendant la campagne Codex.

---

## PublicEmissionWorkflowTest

Nombre de tests :

9

Nombre d'assertions :

66

Résultat :

VERT

Les parcours publics testés concernent notamment :

- accueil ;
- fiche publique d'une émission ;
- émission inexistante ;
- vraie réponse HTTP 404 ;
- intégration de la page 404 personnalisée ;
- recherche publique ;
- filtres ;
- pagination lorsque applicable.

Aucune anomalie fonctionnelle n'a été remontée sur cette partie.

---

## Résultat global de la première campagne fonctionnelle

Total :

- 51 tests
- 216 assertions

Résultat :

- 45 tests réussis
- 6 tests en échec

Répartition :

Authentication :
- 6 tests
- 24 assertions
- assertions réussies
- dépréciation User::eraseCredentials() à examiner

Authorization :
- 36 tests
- 126 assertions
- 6 échecs fonctionnels conservés

PublicEmissionWorkflow :
- 9 tests
- 66 assertions
- VERT

Les trois fichiers ont été exécutés séparément dans Docker.

Puis :

php bin/phpunit tests/Functional

a été exécuté.

La suite PHPUnit complète du projet n'a volontairement pas encore été relancée.

Il ne faut donc pas considérer à ce stade que les nouveaux tests sont intégrés à une suite globale entièrement verte.

---

## Points à traiter lors de la prochaine session

Avant de commencer la deuxième campagne fonctionnelle :

1. vérifier les six tests rouges de AuthorizationTest ;
2. confirmer que chaque échec représente bien la règle métier souhaitée ;
3. corriger en priorité la sécurité serveur de la suppression des émissions d'autrui ;
4. corriger la sécurité serveur de l'édition des émissions d'autrui pour ROLE_ADMIN ;
5. aligner ensuite les boutons Modifier/Supprimer dans Twig sur les mêmes règles ;
6. vérifier le comportement ROLE_SUPER_ADMIN après les corrections ;
7. examiner la dépréciation User::eraseCredentials() ;
8. relancer AuthenticationTest ;
9. relancer AuthorizationTest ;
10. relancer PublicEmissionWorkflowTest ;
11. relancer tests/Functional ;
12. lorsque la première campagne sera entièrement validée, relancer la suite PHPUnit complète avant mise en production.

---

## .gitignore

Codex a signalé que les nouveaux fichiers sont actuellement concernés par une règle existante :

tests/

dans .gitignore.

Ce point doit être vérifié avant le prochain commit.

Ne pas modifier la règle à l'aveugle.

Il faut d'abord comprendre comment les tests existants du projet sont actuellement suivis par Git et pourquoi cette règle est présente.

Les nouveaux fichiers fonctionnels ne doivent pas rester uniquement sur la machine locale.

---

## État en fin de session

Couverture technique historique :

- 1327 tests
- 6418 assertions
- 97,25 % des lignes couvertes

Nouveaux tests fonctionnels ajoutés :

- 51 tests
- 216 assertions

Les résultats de cette première campagne ont permis d'identifier plusieurs incohérences d'autorisation qui n'avaient pas été mises en évidence par la couverture technique précédente.

La deuxième campagne fonctionnelle n'est pas commencée.

Prochaine priorité :

stabiliser complètement AuthenticationTest, AuthorizationTest et PublicEmissionWorkflowTest avant de poursuivre la préparation de la mise en production.

## 11 septembre 2026 — Tests et couverture technique

### LiveEmissionCreator

Ajout et finalisation de la couverture de `LiveEmissionCreator`.

Tests ajoutés pour couvrir notamment :

- création automatique d'une émission depuis un `ProgrammationRuleSlot` ;
- durée par défaut de 15 minutes lorsqu'aucune durée n'est définie sur le créneau ;
- récupération des utilisateurs associés à une catégorie ;
- suppression des doublons d'utilisateurs ;
- absence de règle ou de catégorie ;
- création manuelle d'une émission pour une catégorie ;
- durée manuelle par défaut de 60 minutes ;
- rejet des catégories inactives ;
- rejet des catégories supprimées logiquement ;
- absence du thème par défaut ;
- catégorie sans éditeur ;
- catégorie possédant un éditeur.

Couverture obtenue :

```text
App\Service\LiveEmissionCreator
Methods: 90.00% (9/10)
Lines:   99.00% (99/100)
```

### Correction détectée grâce aux tests

Les tests de `LiveEmissionCreator` ont mis en évidence une incohérence dans la gestion de l'éditeur d'une catégorie.

`Categories::getEditeur()` retourne directement une entité `Editeur`, alors que le service traitait cette valeur comme un identifiant et tentait de rechercher à nouveau l'éditeur avec `EditeurRepository`.

Correction effectuée :

- utilisation directe de la relation `Categories::getEditeur()` ;
- suppression de `EditeurRepository` dans `LiveEmissionCreator` ;
- simplification de `resolveEditorForCategory()`.

### Nettoyage des tests

Nettoyage de `LiveEmissionCreatorTest` :

- suppression de l'ancien mock `EditeurRepository` devenu inutile ;
- typage des mocks PHPUnit avec `MockObject` ;
- adaptation des fixtures `Categories` aux types réellement attendus par l'entité ;
- conservation des tests sur les valeurs de durée par défaut.

### Suppression de LibreTime

Suppression du code `LibreTime`, qui n'était pas utilisé par l'application et n'avait pas vocation à être développé actuellement.

Nettoyage de la déclaration de service Symfony restante après suppression de la classe `LibreTimeWeekInfoClient`.

La suite complète reste verte après cette suppression.

### État final de la suite de tests

```text
OK (1302 tests, 6147 assertions)
```

Aucune régression détectée après :

- l'ajout des tests `LiveEmissionCreator` ;
- la correction de la gestion de l'éditeur ;
- la suppression du code LibreTime.

### À suivre

La couverture globale du projet sera recalculée après ces modifications afin d'établir le bilan final de la couverture technique avant de passer à la couche de tests fonctionnels/métier.

# Tests PHPUnit — avancement du 09/09/2026

## Tests et couverture du code

### Services

- Finalisation de la couverture des services.
- `GridConflictDetector` : couverture des comportements utiles, hors branche d’overlap structurellement inaccessible.
- `GridOccurrenceProjectionService` : 96,05 %.
- `Mp3Processor` : 95,80 %.
- `GridAssignmentService` : 100 %.
- `GridPublicationService` : 92,31 %.
- `GridRebroadcastCoverageService` : 82,70 %.
- `GridUnpublicationService` : 93,96 %.
- `GridViewBuilder` : 90,61 %.
- `InfosSoirRssService` : 100 %.
- `ProgrammationGridBuilder` : 94,36 %.
- `PublicScheduleBuilder` : 100 %.
- `SafeFilenameNamer` : 100 %.
- `WeeklyAnnouncementPrintBuilder` : 100 %.
- Correction de `SafeFilenameNamer` avec `AsciiSlugger` de Symfony à la place de `iconv`.

### Contrôleurs publics

- `EmissionController` : 99,47 % des lignes.
- `HomeController` : 100 %.
- `RegistrationController` : 100 %.
- Ajout de tests sur les affichages, recherches, filtres, validations et cas d’erreur.
- Normalisation des filtres catégorie et thème dans `EmissionController`.
- Correction de `Emission::$ref` pour accepter une valeur `null`.

### Contrôleurs d’administration

- `CategorieController` : 98,15 % des lignes.
- `DiffusionController` : 100 %.
- `CategorieTagImageController` : 100 %.
- `EditeurController` : 100 %.
- `EvenementController` : 100 %.
- `GridDraftController` : 100 %.
- `InviteOldAnimateurController` : 100 %.
- `PageController` : 100 %.
- `ProfileController` : 100 %.
- `ProgrammationRuleController` : 100 %.
- `ProgrammationRuleSlotController` : 92,62 %.
- `TestLibreTimeController` : 100 %.
- `ThemeController` : 100 %.
- `TinyMCEController` : 100 %.
- `UserController` : 100 %.
- Les 9 nouvelles suites d’administration totalisent 108 tests et 754 assertions.
- Les 9 nouvelles suites couvrent 390 lignes sur 399, soit 97,74 %.
- Les lignes restantes de `ProgrammationRuleSlotController` correspondent à des valeurs invalides déjà bloquées par les setters de l’entité.
- Suppression de branches défensives mortes dans `GridDraftController`.
- Suppression du traitement de formulaire devenu inutile dans l’index de `EvenementController`.
- Correction de `Evenement::setDepartement()` pour accepter une valeur `null`.

### Grille de programmation

- Extension importante de `GrilleControllerTest`.
- 98 tests et 286 assertions passent sur `GrilleController`.
- `GrilleController` atteint 93,22 % de couverture des lignes.
- 894 lignes sur 959 sont couvertes dans `GrilleController`.
- 18 méthodes sur 25 sont entièrement couvertes, soit 72 %.
- `candidates()` : 100 %.
- `specialCandidates()` : 100 %.
- `assign()` : 100 %.
- `createLive()` : 100 %.
- `remove()` : 100 %.
- `rescheduleWeek()` : 98 %.
- `rescheduleCustom()` : 86,11 %.
- `cancelOccurrence()` : 98,73 %.
- `clearReschedule()` : 100 %.
- `restoreOccurrence()` : 100 %.
- `linkedRebroadcasts()` : 100 %.
- `gotoWeek()` : 100 %.
- Ajout de tests sur les rediffusions liées.
- Ajout de tests sur les stratégies de déplacement, conservation et annulation.
- Ajout de tests sur les déplacements personnalisés des rediffusions.
- Ajout de tests sur la restauration des groupes d’arbitrage.
- Ajout de tests sur les catégories de remplacement et les recherches de candidats.
- Ajout de tests sur les émissions automatiques et leur déduplication.
- Les principaux chemins restant à couvrir concernent `publishWeek()`, `unpublishWeek()` et certaines branches de `renderGrid()` et `rescheduleCustom()`.

### Formulaires

- Début de la couverture des 18 formulaires de l’application.
- Ajout de tests sur les soumissions réelles et les validations.
- Ajout de tests sur les transformations de données.
- Ajout de tests sur les champs conditionnels.
- Ajout de tests sur les formulaires d’inscription, de mot de passe, de profil, de rôles et d’invités.
- Ajout de tests sur les formulaires liés aux pages.
- Ajout de tests sur les formulaires de programmation.
- Ajout de tests sur les choix invalides.
- Ajout de tests sur les champs devant rester verrouillés en édition.
- Ajout de tests sur les catégories, les émissions et la recherche.
- Ajout de tests sur les filtres de choix et les propriétaires d’émissions.
- Ajout de tests sur la séparation entre invités et anciens animateurs.
- Ajout de tests sur les doublons lors de l’inscription.
- Identification d’une incohérence existante dans `CategorieType` : le champ description est déclaré facultatif mais une valeur vide provoque une erreur lors du mapping vers l’entité.
- L’incohérence de `CategorieType` est actuellement documentée par un test sans modification du formulaire.

### Qualité des tests

- Utilisation de Xdebug pour mesurer la couverture réelle des lignes exécutées.
- Conservation des protections métier existantes au lieu de les contourner artificiellement pour atteindre 100 %.
- Les branches structurellement inaccessibles ou purement défensives ne sont pas forcées uniquement pour améliorer le pourcentage de couverture.
- Les tests privilégient les comportements utiles, les validations, les erreurs et les chemins métier réels.

# Tests PHPUnit — avancement du 09/09/2026

## Contexte

Objectif actuel : couvrir techniquement le code avant de passer à la couche fonctionnelle / métier.

Ordre de travail retenu :

1. Repositories ✅
2. Services ✅
3. Controllers 🔄
4. Forms
5. Autres classes si nécessaire
6. Tests fonctionnels / workflows métier uniquement après la couverture technique

Pour les contrôleurs, on avance fichier par fichier avec PHPUnit, en visant la couverture utile maximale sans forcer artificiellement des branches impossibles.

---

# Contrôleurs déjà terminés

## `EmissionController`

Couverture technique terminée.

- Methods : 85,71 %
- Lines : 99,47 %

Corrections faites :

- normalisation des filtres catégorie / thème
- `Emission::$ref` rendu nullable côté Doctrine
- `setRef(?string)`

Pas besoin de forcer la dernière ligne défensive.

---

## `HomeController`

100 %.

---

## `RegistrationController`

100 %.

Le service `EmailVerifier` a pu être remplacé dans le container de test.

---

## `Admin/CategorieController`

Couverture technique considérée terminée.

- Lines : 98,15 %
- Methods : 80 %

La branche Vich restante n’a pas été forcée artificiellement.

---

## `Admin/DiffusionController`

100 %.

`DiffusionType` : 100 %.

---

## `Admin/CategorieTagImageController`

100 %.

`CategorieTagImageType` reste à revoir plus tard avec les Forms.

---

## `Admin/EditeurController`

100 %.

`EditeurType` : 100 %.

---

## `Admin/EvenementController`

100 %.

Correction production :

- `Evenement::setDepartement(?string)`

Le code mort de gestion de formulaire dans `index()` a été retiré.

---

# Contrôleur actuel : `Admin/GridDraftController`

Fichier :

    src/Controller/Admin/GridDraftController.php

Test :

    tests/Controller/Admin/GridDraftControllerTest.php

Commande de test :

    docker exec -it symfony_app php bin/phpunit tests/Controller/Admin/GridDraftControllerTest.php --stop-on-error --stop-on-failure

Commande couverture texte :

    docker exec -e XDEBUG_MODE=coverage -it symfony_app php bin/phpunit tests/Controller/Admin/GridDraftControllerTest.php --coverage-text --coverage-filter=src/Controller/Admin/GridDraftController.php

Commande couverture HTML :

    docker exec -e XDEBUG_MODE=coverage -it symfony_app php bin/phpunit tests/Controller/Admin/GridDraftControllerTest.php --coverage-html var/coverage --coverage-filter=src/Controller/Admin/GridDraftController.php

---

# État actuel de `GridDraftController`

Suite PHPUnit verte.

Dernière couverture HTML :

- Lines : 97,31 %
- 578 / 594 lignes
- Methods : 53,85 %
- 7 / 13 méthodes entièrement couvertes

Important : le faible pourcentage `Methods` vient surtout du fait que PHPUnit ne considère une méthode comme entièrement couverte que si toutes ses lignes le sont.

Il ne reste plus que 16 lignes non couvertes.

---

# Couverture par méthode

## `createManual`

100,00 %

106 / 106 lignes

✅ Terminée.

---

## `createManualLive`

98,91 %

91 / 92 lignes

Il reste uniquement le fallback de durée :

    $duration = (int) ($emission->getDuree() ?? 0);

    if ($duration < 1) {
        $duration = 60;
    }

La seule ligne encore rouge est :

    $duration = 60;

À vérifier demain si cet état est réellement atteignable avec les contraintes actuelles de l’entité `Emission`.

---

## `filterBlockingDraftOverlaps`

100,00 %

21 / 21 lignes

100 % méthode.

✅ Terminée.

C’est une grosse partie du travail fait aujourd’hui.

---

# Travail effectué sur `filterBlockingDraftOverlaps`

Le helper suivant a été ajouté dans `GridDraftControllerTest.php` afin de pouvoir tester directement la méthode privée sans dépendre du comportement du repository Doctrine dans une requête HTTP :

    private function invokeFilterBlockingDraftOverlaps(
        array $drafts,
        GridSlotArbitrationRepository $arbitrationRepository
    ): array {
        $controller = static::getContainer()->get(
            \App\Controller\Admin\GridDraftController::class
        );

        $reflection = new \ReflectionMethod(
            \App\Controller\Admin\GridDraftController::class,
            'filterBlockingDraftOverlaps'
        );

        $reflection->setAccessible(true);

        return $reflection->invoke(
            $controller,
            $drafts,
            $arbitrationRepository
        );
    }

Tests actuels utiles pour cette partie :

    testCreateManualTreatsRegularDraftWithoutSlotAsBlocking()

    testFilterBlockingDraftOverlapsKeepsRegularDraftWithoutArbitration()

    testFilterBlockingDraftOverlapsIgnoresCancelledRegularDraft()

    testFilterBlockingDraftOverlapsIgnoresRescheduledRegularDraft()

    testFilterBlockingDraftOverlapsKeepsRegularDraftWhenArbitrationDoesNotCancelOrReschedule()

Ces tests couvrent désormais toutes les branches de :

    private function filterBlockingDraftOverlaps(
        array $drafts,
        GridSlotArbitrationRepository $arbitrationRepository
    ): array {
        return array_values(array_filter(
            $drafts,
            static function (DiffusionDraft $draft) use ($arbitrationRepository): bool {
                if (DiffusionDraft::TYPE_REGULAR !== $draft->getDraftType()) {
                    return true;
                }

                $slot = $draft->getSlot();
                $startsAt = $draft->getHoraireDiffusion();

                if (!$slot || !$slot->getId() || !$startsAt instanceof \DateTimeImmutable) {
                    return true;
                }

                $arbitration = $arbitrationRepository->findOneBy([
                    'slot' => $slot,
                    'originalStartsAt' => $startsAt,
                ]);

                if (!$arbitration instanceof GridSlotArbitration) {
                    return true;
                }

                return !(
                    $arbitration->isCancelAction()
                    || $arbitration->isRescheduleAction()
                );
            }
        ));
    }

Cette méthode est maintenant entièrement verte dans le rapport HTML.

---

# Tests remplacés aujourd’hui

Les premiers tests HTTP destinés à couvrir les arbitrages ne fonctionnaient pas correctement, car le mock de `DiffusionDraftRepository` injecté dans le container n’était pas celui réellement utilisé dans ce chemin HTTP.

Symptôme observé :

- le test attendait HTTP 409
- le contrôleur répondait HTTP 200
- un nouveau `manual_special` était réellement créé

Les tests suivants ont donc été supprimés :

    testCreateManualIgnoresCancelledRegularDraftOverlap()

    testCreateManualIgnoresRescheduledRegularDraftOverlap()

    testCreateManualKeepsRegularDraftBlockingWhenArbitrationDoesNotCancelOrReschedule()

Ils ont été remplacés par :

    testFilterBlockingDraftOverlapsIgnoresCancelledRegularDraft()

    testFilterBlockingDraftOverlapsIgnoresRescheduledRegularDraft()

    testFilterBlockingDraftOverlapsKeepsRegularDraftWhenArbitrationDoesNotCancelOrReschedule()

Le test :

    testCreateManualTreatsRegularDraftWithoutSlotAsBlocking()

a été conservé, mais corrigé pour utiliser un vrai `DiffusionDraft` enregistré en base au lieu du mock du repository.

Le test :

    testCreateManualTreatsRegularDraftWithoutArbitrationAsBlocking()

a été remplacé par :

    testFilterBlockingDraftOverlapsKeepsRegularDraftWithoutArbitration()

---

# Correction de code mort dans `createManualLive`

Il existait dans `GridDraftController::createManualLive()` :

    if (!$emission instanceof Emission) {
        return $this->json([
            'success' => false,
            'error' => 'Impossible de créer le direct.',
        ], 500);
    }

Un test avait été créé :

    testCreateManualLiveReturns500WhenCreatorDoesNotReturnEmission()

Le mock essayait de faire :

    ->willReturn(null)

Mais PHPUnit a répondu :

    PHPUnit\Framework\MockObject\IncompatibleReturnValueException:
    Method createManualForCategory may not return value of type NULL,
    its declared return type is "App\Entity\Emission"

La raison est que :

    LiveEmissionCreator::createManualForCategory()

est explicitement typé pour retourner :

    App\Entity\Emission

Le `if (!$emission instanceof Emission)` était donc structurellement inatteignable avec le contrat actuel du service.

Décision prise :

- suppression du test `testCreateManualLiveReturns500WhenCreatorDoesNotReturnEmission`
- suppression du bloc mort dans `GridDraftController::createManualLive()`

Le contrôleur enchaîne maintenant directement après le `try/catch` avec le calcul de la durée.

---

# `delete`

Couverture actuelle :

93,33 %

70 / 75 lignes

Il reste 5 lignes.

Premier bloc restant :

    if ([] === $draftsToDelete) {
        return $this->json([
            'success' => false,
            'error' => 'Aucun draft à supprimer.',
        ], 400);
    }

Ce bloc représente 4 lignes rouges dans le rapport.

À analyser demain pour déterminer si `draftsToDelete === []` peut réellement se produire avec les modes de suppression actuellement autorisés :

- `single`
- `rebroadcasts`
- `group`

Si cette situation est atteignable, ajouter un test.

Si la logique précédente garantit qu’au moins un draft existe toujours dans `$draftsToDelete`, il s’agit potentiellement d’une garde morte.

Deuxième ligne restante :

    foreach ($remainingDrafts as $remainingDraft) {
        if (!$remainingDraft instanceof DiffusionDraft) {
            continue;
        }

Le `continue` est rouge.

À vérifier demain si :

    $draftRepository->findBy(...)

peut réellement retourner autre chose que des objets `DiffusionDraft`.

Si Doctrine garantit le type des entités retournées par ce repository, cette garde pourrait être inutile.

---

# `move`

Couverture actuelle :

97,14 %

68 / 70 lignes

Il reste 2 lignes.

Code concerné :

    $duration = $draft->getDurationMinutes()
        ?? $draft->getEmission()?->getDuree()
        ?? 15;

La ligne rouge est le fallback :

    ?? 15;

Deuxième branche :

    if ($duration < 1) {
        $duration = 15;
    }

La ligne rouge est :

    $duration = 15;

À vérifier demain avec les contraintes actuelles de `DiffusionDraft` et `Emission`.

On sait déjà que :

    DiffusionDraft::setDurationMinutes()

refuse une durée non nulle inférieure à 1.

Et :

    DiffusionDraft::setSchedule()

refuse également une durée inférieure à 1.

Il faut donc déterminer si un draft existant peut réellement arriver dans `move()` avec :

- `durationMinutes === null`
- et `Emission::duree === null`

ou avec une durée `< 1`.

Si ces états sont impossibles avec le modèle actuel, il ne faudra pas inventer un test artificiel uniquement pour obtenir 100 %.

---

# Helpers de conflit avec programmation régulière

Les méthodes suivantes sont maintenant entièrement couvertes :

## `hasRegularBlockingOverlap`

100 %

7 / 7 lignes.

✅ Terminée.

## `findRegularBlockingOverlaps`

100 %

9 / 9 lignes.

✅ Terminée.

## `getRadioWeekStart`

100 %

5 / 5 lignes.

✅ Terminée.

---

# Signatures vérifiées pour la gestion des conflits réguliers

## `ProgrammationGridBuilder`

Signature :

    public function buildForWeek(
        \DateTimeImmutable $startOfWeek,
        \DateTimeImmutable $endOfWeek
    ): array

La méthode construit les segments de programmation régulière sur la semaine radio.

---

## `GridOccurrenceProjectionService`

Signature :

    public function applyForWeek(
        array $daySegments,
        \DateTimeImmutable $startOfWeek,
        \DateTimeImmutable $endOfWeek
    ): array

Elle applique les arbitrages de type annulation / déplacement aux segments de programmation.

---

## `GridConflictDetector`

Signature :

    public function findBlockingOverlapsForRange(
        array $daySegments,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt
    ): array

Elle retourne les segments bloquants qui chevauchent la plage demandée.

Ces trois services sont déjà couverts indépendamment dans leurs propres tests.

Dans `GridDraftControllerTest`, leur rôle est uniquement de vérifier que le contrôleur réagit correctement lorsqu’un conflit régulier lui est signalé.

---

# `createRebroadcasts`

Couverture actuelle :

94,96 %

113 / 119 lignes

Il reste 6 lignes.

Premier bloc restant :

    $emission = $parentDraft->getEmission();

    if (!$emission instanceof Emission) {
        return $this->json([
            'success' => false,
            'error' => 'Émission introuvable.',
        ], 404);
    }

Le corps du `if` est rouge.

À analyser demain.

`DiffusionDraft` possède normalement une relation obligatoire vers `Emission`.

Si `getEmission()` ne peut jamais retourner autre chose qu’une `Emission` dans un draft valide, cette garde est probablement du même type que celle supprimée aujourd’hui dans `createManualLive()`.

Il ne faudra pas essayer de fabriquer artificiellement un état impossible juste pour couvrir ces lignes.

Deuxième partie restante :

    $duration = $parentDraft->getDurationMinutes()
        ?? $emission->getDuree()
        ?? 15;

La ligne rouge est :

    ?? 15;

Puis :

    if ($duration < 1) {
        $duration = 15;
    }

La ligne rouge est :

    $duration = 15;

Même analyse que pour `move()` :

- vérifier les contraintes de `DiffusionDraft`
- vérifier les contraintes de `Emission::duree`
- déterminer si ces fallbacks sont réellement atteignables

---

# Conflit régulier dans `createRebroadcasts`

La branche suivante faisait partie des lignes rouges précédemment observées :

    if (\count($regularOverlaps) > 0) {
        return $this->json([
            'success' => false,
            'conflict' => true,
            'error' => 'Une rediffusion chevauche déjà une programmation régulière.',
            'debug' => $regularOverlaps,
        ], 409);
    }

Après les nouveaux tests, cette partie est maintenant couverte.

Elle n’apparaît plus parmi les 16 lignes restantes.

---

# `listRebroadcasts`

100 %

31 / 31 lignes.

100 % méthode.

✅ Terminée.

---

# `group`

Couverture actuelle :

97,62 %

41 / 42 lignes

Il reste une seule ligne.

Code concerné :

    foreach ($groupDrafts as $item) {
        if (!$item instanceof DiffusionDraft) {
            continue;
        }

La ligne rouge est :

    continue;

Le reste de la construction du tableau `$items` est couvert :

    $items[] = [
        'id' => $item->getId(),
        'label' => $this->buildDraftGroupLabel($item),
        'draftType' => $item->getDraftType(),
        'nombreDiffusion' => $item->getNombreDiffusion(),
        'startsAt' => $item->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
        'endsAt' => $item->getEndsAt()?->format('Y-m-d H:i:s'),
    ];

À vérifier demain si le repository Doctrine peut réellement retourner autre chose qu’un `DiffusionDraft`.

Très probablement, cette garde est inutile.

---

# `buildDraftGroupLabel`

100 %

7 / 7 lignes.

100 % méthode.

✅ Terminée.

Les deux branches sont couvertes :

    $nombreDiffusion = (int) ($draft->getNombreDiffusion() ?? 1);

    if ($nombreDiffusion <= 1) {
        return '1re diffusion';
    }

et :

    return sprintf(
        'Rediffusion %d',
        $nombreDiffusion - 1
    );

---

# `renumberDraftGroupChronologically`

Couverture actuelle :

90 %

9 / 10 lignes

Il reste une seule ligne.

Code concerné :

    $groupDrafts = $draftRepository->findBy(
        ['assignmentGroupKey' => $assignmentGroupKey],
        ['horaireDiffusion' => 'ASC']
    );

    $rank = 1;

    foreach ($groupDrafts as $groupDraft) {
        if (!$groupDraft instanceof DiffusionDraft) {
            continue;
        }

La ligne rouge est :

    continue;

Le reste de la renumérotation est couvert.

À vérifier demain si le résultat de :

    DiffusionDraftRepository::findBy()

peut contenir autre chose que des `DiffusionDraft`.

Si ce n’est pas possible, le `instanceof` est une garde inutile et pourra probablement être retiré.

---

# Les 16 lignes restantes au 09/09/2026

Répartition exacte :

## `createManualLive`

1 ligne :

    $duration = 60;

---

## `delete`

5 lignes :

    return $this->json([
        'success' => false,
        'error' => 'Aucun draft à supprimer.',
    ], 400);

et :

    continue;

dans la vérification :

    if (!$remainingDraft instanceof DiffusionDraft)

---

## `move`

2 lignes :

    ?? 15;

et :

    $duration = 15;

---

## `createRebroadcasts`

6 lignes :

Bloc :

    return $this->json([
        'success' => false,
        'error' => 'Émission introuvable.',
    ], 404);

plus :

    ?? 15;

et :

    $duration = 15;

---

## `group`

1 ligne :

    continue;

dans :

    if (!$item instanceof DiffusionDraft)

---

## `renumberDraftGroupChronologically`

1 ligne :

    continue;

dans :

    if (!$groupDraft instanceof DiffusionDraft)

---

Total :

    1 + 5 + 2 + 6 + 1 + 1 = 16 lignes

Ce qui correspond bien au rapport :

    578 / 594 lignes couvertes
    97,31 %

---

# État global à la fin de la journée

`GridDraftControllerTest` :

✅ VERT

Nombre de tests lors des derniers runs : environ 65 tests après les ajouts de la journée.

Le nombre exact pourra évoluer légèrement selon les tests supprimés/remplacés, mais la suite actuelle est verte.

Couverture actuelle de `GridDraftController` :

    Lines:   97,31 % — 578 / 594
    Methods: 53,85 % — 7 / 13

Méthodes totalement terminées :

    createManual
    filterBlockingDraftOverlaps
    hasRegularBlockingOverlap
    findRegularBlockingOverlaps
    getRadioWeekStart
    listRebroadcasts
    buildDraftGroupLabel

Méthodes presque terminées :

    createManualLive       98,91 %
    delete                 93,33 %
    move                   97,14 %
    createRebroadcasts     94,96 %
    group                  97,62 %
    renumberDraftGroupChronologically 90 %

---

# À faire demain

Reprendre directement les 16 lignes restantes.

Pour chacune, décider entre trois cas :

1. La branche est réellement atteignable dans l’application.
   → écrire un test utile.

2. La branche est impossible à cause du typage PHP, des relations Doctrine ou des contraintes des entités.
   → supprimer le code mort si cela est confirmé.

3. La branche est une garde défensive volontaire mais difficile/impossible à provoquer normalement.
   → décider si elle mérite d’être conservée sans forcer artificiellement la couverture.

Ordre conseillé demain :

    1. createManualLive : fallback durée 60
    2. move : fallbacks durée
    3. createRebroadcasts : Emission + fallbacks durée
    4. delete : draftsToDelete vide
    5. delete/group/renumber : instanceof sur résultats Doctrine

Pour les fallbacks de durée, regarder précisément :

    Emission::$duree
    Emission::getDuree()
    Emission::setDuree()
    DiffusionDraft::$durationMinutes
    DiffusionDraft::getDurationMinutes()
    DiffusionDraft::setDurationMinutes()
    DiffusionDraft::setSchedule()

Pour les gardes `instanceof`, vérifier les contrats de :

    DiffusionDraftRepository::findBy()

et éventuellement les annotations/types PHPStan/PHPDoc du repository.

---

# Important pour la suite

Ne pas revenir sur les parties déjà à 100 % sauf régression.

Ne pas chercher le 100 % uniquement pour obtenir un chiffre.

Une branche structurellement impossible doit être identifiée comme telle et, si elle n’a aucune utilité défensive, supprimée plutôt que testée avec des hacks.

La priorité reste la couverture du code utile et réellement exécutable.

---

# Règle de travail pour les prochaines corrections

Quand un test doit être remplacé, toujours indiquer explicitement :

    TEST À SUPPRIMER :
    nomExactDuTest()

puis :

    TEST À METTRE À LA PLACE :
    nouveauNomExactDuTest()

Et fournir ensuite la méthode de test complète.

Quand un nouveau test doit simplement être ajouté, indiquer :

    TEST À AJOUTER :
    nomExactDuTest()

et fournir la méthode complète.

Quand du code du contrôleur doit être supprimé, indiquer :

    FICHIER :
    src/Controller/Admin/GridDraftController.php

    MÉTHODE :
    nomDeLaMethode()

    BLOC À SUPPRIMER :
    ...

Ne pas donner plusieurs petits bouts de code dispersés sans préciser exactement où ils vont.

Préférence générale : méthodes complètes corrigées ou fichier complet lorsque nécessaire.

---

# Prochaine étape après `GridDraftController`

Une fois `GridDraftController` considéré techniquement terminé, continuer les autres Controllers.

Ne pas commencer encore les tests fonctionnels métier.

Ordre général toujours en vigueur :

    Repositories ✅
    Services ✅
    Controllers 🔄
    Forms
    autres classes utiles
    couche fonctionnelle / workflows métier en dernier

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