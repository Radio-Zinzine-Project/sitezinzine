# Tests automatisés – Radio Zinzine

Ce document décrit la stratégie de tests automatisés du projet Radio Zinzine,
les services actuellement couverts et les principaux scénarios sécurisés.

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