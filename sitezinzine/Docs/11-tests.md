# Tests

[Retour à l’architecture](01-architecture.md)

> `Docs/TEST.md` est utilisé comme journal personnel de l'évolution des tests et des corrections associées.  
> Le présent document constitue la documentation technique de référence pour l'utilisation et l'organisation des tests du projet.

Le projet utilise **PHPUnit** pour tester le code PHP de l'application.

Les tests couvrent notamment :

- les entités ;
- les repositories Doctrine ;
- les services métier ;
- les formulaires Symfony ;
- les contrôleurs publics et administrateur ;
- la sécurité ;
- les extensions et composants Twig ;
- les normalizers ;
- certains comportements d'intégration avec Symfony et Doctrine.

L'objectif n'est pas d'atteindre artificiellement 100 % de couverture, mais de sécuriser les comportements réellement utilisés par l'application et les parties sensibles du métier.

---

## État actuel

Dernière mesure effectuée le **12 septembre 2026** :

```text
Tests:      1327
Assertions: 6418

Classes: 75.00% (75/100)
Methods: 93.19% (821/881)
Lines:   97.25% (8689/8935)
```

La couverture des lignes est la métrique principale utilisée pour suivre la couverture globale.

Certaines classes ou branches restent volontairement partiellement couvertes lorsqu'un test supplémentaire n'apporterait pas de valeur réelle ou nécessiterait de tester artificiellement une branche inaccessible dans le fonctionnement normal de l'application.

L'API est notamment encore en cours de préparation et n'est pas utilisée par l'application à ce stade. Sa couverture n'est donc pas considérée comme prioritaire.

---

# Lancer les tests

Les tests sont exécutés dans le conteneur Docker `symfony_app`.

## Suite complète

```bash
docker exec -it symfony_app php -d memory_limit=1G bin/phpunit
```

Cette commande doit être exécutée après une modification importante afin de vérifier qu'aucune régression n'a été introduite.

Un résultat valide ressemble à :

```text
OK (1327 tests, 6418 assertions)
```

Le nombre exact de tests et d'assertions peut évoluer avec le projet.

---

## Lancer un fichier de tests

Pour travailler uniquement sur une classe :

```bash
docker exec -it symfony_app php bin/phpunit tests/chemin/MonTest.php
```

Exemple :

```bash
docker exec -it symfony_app php bin/phpunit tests/Repository/EmissionRepositoryTest.php
```

---

## Lancer un seul test

L'option `--filter` permet de cibler une méthode précise :

```bash
docker exec -it symfony_app php bin/phpunit tests/Repository/EmissionRepositoryTest.php --filter nomDuTest
```

C'est généralement la méthode la plus rapide pendant le développement d'une correction.

Une fois le test ciblé validé, la suite complète doit être relancée.

---

# Couverture du code

Xdebug est utilisé pour générer la couverture.

## Rapport dans le terminal

```bash
docker exec -e XDEBUG_MODE=coverage -it symfony_app php -d memory_limit=1G bin/phpunit --coverage-text --coverage-filter=src
```

Le rapport indique notamment :

```text
Classes
Methods
Lines
```

La couverture des **lignes** est la référence principale.

Une méthode peut apparaître comme non couverte alors que presque toutes ses lignes ont été exécutées. Il ne faut donc pas chercher à augmenter mécaniquement les pourcentages `Classes` ou `Methods` lorsque les branches restantes n'ont pas de comportement pertinent à tester.

---

## Rapport HTML

Pour obtenir un rapport détaillé :

```bash
docker exec -e XDEBUG_MODE=coverage -it symfony_app php -d memory_limit=1G bin/phpunit --coverage-html var/coverage --coverage-filter=src
```

Le rapport est généré dans :

```text
var/coverage/
```

Ouvrir ensuite :

```text
var/coverage/index.html
```

Le rapport HTML permet d'identifier précisément les lignes non exécutées dans chaque classe.

---

# Organisation des tests

Les tests se trouvent dans :

```text
tests/
```

Ils suivent autant que possible l'organisation de `src/`.

Par exemple :

```text
src/Repository/EmissionRepository.php
tests/Repository/EmissionRepositoryTest.php

src/Service/GridAssignmentService.php
tests/Service/GridAssignmentServiceTest.php
```

Cela permet de retrouver rapidement les tests associés à une classe.

---

# Types de tests utilisés

## Tests unitaires

Ils testent une classe ou un comportement isolé.

Ils sont particulièrement adaptés aux :

- services ;
- fonctions de transformation ;
- extensions Twig ;
- normalizers ;
- méthodes métier indépendantes de HTTP.

Des mocks ou stubs peuvent être utilisés lorsque la dépendance n'a pas besoin d'être réellement exécutée.

Attention : avec la version de PHPUnit utilisée par le projet, une classe `final` ne peut pas être directement doublée avec `createMock()` ou `createStub()`.

---

## Tests avec le Kernel Symfony

Les tests utilisant `KernelTestCase` démarrent le conteneur Symfony et permettent notamment de tester :

- Doctrine ;
- les repositories ;
- les services configurés dans le conteneur ;
- l'intégration entre plusieurs composants Symfony.

---

## Tests HTTP

Les tests utilisant `WebTestCase` permettent d'effectuer de véritables requêtes vers l'application :

```php
$client->request('GET', '/...');
```

Ils peuvent vérifier notamment :

- le statut HTTP ;
- les redirections ;
- les autorisations ;
- le rendu Twig ;
- la présence d'éléments HTML ;
- le comportement d'un formulaire.

Exemple :

```php
$this->assertResponseIsSuccessful();
$this->assertSelectorTextContains('h1', 'Titre attendu');
```

Un test HTTP apporte davantage de garanties qu'un appel direct à une méthode de contrôleur, car il passe par une plus grande partie de Symfony.

---

## Tests isolés de contrôleurs

Certains tests appellent directement une action de contrôleur avec des doubles de :

- repositories ;
- formulaires ;
- services ;
- moteur de rendu.

Ces tests permettent de couvrir certaines branches difficiles à atteindre par HTTP.

Ils ne valident cependant pas nécessairement :

- le routing ;
- les règles de sécurité ;
- les requêtes SQL réelles ;
- le rendu Twig final.

Ils complètent donc les tests HTTP mais ne les remplacent pas.

---

# Base de données de test

Les tests ne doivent jamais utiliser la base de production.

En environnement `test`, Doctrine utilise la configuration de test et ajoute le suffixe :

```text
_test
```

au nom de la base lorsque cette configuration est active.

Les tests utilisant Doctrine peuvent créer leurs propres données afin de reproduire précisément le scénario testé.

---

## Isolation des tests Doctrine

Lorsque cela est approprié, les tests démarrent une transaction dans `setUp()` puis effectuent un rollback dans `tearDown()`.

Cela évite que les données créées par un test polluent les suivants.

Lorsqu'un même test effectue plusieurs requêtes HTTP avec le même client Symfony, il peut être nécessaire d'utiliser :

```php
$client->disableReboot();
```

afin de conserver le même Kernel et la transaction ouverte pendant tout le scénario.

---

## Ne pas supposer que la base est vide

Les tests doivent éviter de dépendre du contenu global de la base.

Il faut privilégier :

- des données créées spécifiquement pour le test ;
- des valeurs uniques ;
- `uniqid()` lorsque cela est pertinent ;
- des assertions portant sur les entités créées par le test.

À éviter :

```php
$this->assertCount(3, $repository->findAll());
```

si le test ne garantit pas lui-même que la base est entièrement isolée.

Il vaut mieux vérifier directement la présence, l'absence ou l'état des données créées pour le scénario.

---

# Tests et Twig

Les classes PHP utilisées par Twig sont couvertes normalement par PHPUnit.

C'est notamment le cas de :

```text
App\Twig\AppExtension
App\Twig\Components\RelatedEmissions
```

Ces deux classes sont actuellement couvertes à **100 %**.

Les fichiers `.html.twig`, en revanche, ne sont pas comptabilisés directement dans le rapport PHPUnit limité à :

```text
--coverage-filter=src
```

Ils sont principalement exercés indirectement par les tests HTTP qui rendent réellement les pages.

Cela permet notamment de détecter :

- les erreurs de syntaxe Twig ;
- les variables manquantes ;
- certains problèmes de rendu ;
- les incompatibilités avec une nouvelle version de Symfony ou Twig.

---

# Dépréciations Symfony

La suite de tests sert également à détecter les API dépréciées avant une future montée de version.

Après la migration vers **Symfony 7.4**, les dépréciations directes et indirectes détectées par PHPUnit ont été corrigées.

La suite complète doit normalement s'exécuter sans avertissement de dépréciation provenant du code du projet.

Lorsqu'une dépréciation apparaît, il faut rechercher son origine avant de la masquer.

Le Symfony PHPUnit Bridge peut aider à isoler une dépréciation précise.

Exemple :

```bash
docker exec -e SYMFONY_DEPRECATIONS_HELPER="/texte de la dépréciation/" -it symfony_app php bin/phpunit tests/chemin/MonTest.php --filter nomDuTest
```

Cela permet notamment d'obtenir la pile d'appels responsable de la dépréciation.

---

# Méthode recommandée lors d'une modification

Pendant le développement :

1. identifier les tests liés au code modifié ;
2. lancer le test ou le fichier concerné ;
3. corriger le comportement ;
4. relancer les tests concernés ;
5. lancer la suite PHPUnit complète ;
6. vérifier périodiquement la couverture globale.

Exemple :

```bash
docker exec -it symfony_app php bin/phpunit tests/Service/GridAssignmentServiceTest.php
```

puis :

```bash
docker exec -it symfony_app php -d memory_limit=1G bin/phpunit
```

La couverture complète n'a pas besoin d'être recalculée après chaque petite modification.

---

# Principes retenus

Les tests du projet suivent les principes suivants :

- tester les comportements utiles plutôt que les détails d'implémentation ;
- privilégier les scénarios métier importants ;
- éviter les tests artificiels uniquement destinés à atteindre 100 % ;
- reproduire les bugs découverts par un test lorsque cela est pertinent ;
- isoler autant que possible les données créées par chaque test ;
- conserver les tests lisibles et compréhensibles ;
- lancer la suite complète avant une mise en production ou une modification importante ;
- considérer un test comme une protection contre les régressions, pas seulement comme un outil de couverture.

---

# Éléments volontairement non prioritaires

Certaines parties du code ne nécessitent pas actuellement une couverture maximale.

C'est notamment le cas de l'API, qui est présente dans l'architecture mais n'est pas encore utilisée par l'application.

Certaines branches défensives ou techniquement inaccessibles par les API publiques des entités peuvent également rester non couvertes lorsqu'un test nécessiterait de contourner artificiellement les règles du code de production.

Ces exceptions doivent rester limitées et justifiées.