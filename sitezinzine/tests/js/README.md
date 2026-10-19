# Première base de tests JavaScript

État du 7 octobre 2026 : **18 suites, 122 tests réussis** avec Node 20.11.1, Yarn 1.22.22, Vitest 2.1.9 et jsdom 24.1.3, sous Windows. Aucun rapport de couverture généré et aucun provider installé. Cette première passe s’arrête à cette base verte.

Depuis la racine Symfony (`sitezinzine/` dans le dépôt) :

```powershell
yarn.cmd test:js
yarn.cmd test:js:watch
```

`yarn.cmd` utilise les scripts Yarn existants ; sous ce PowerShell, `yarn` seul sélectionne yarn.ps1, bloqué par la politique d’exécution. Aucun changement de cette politique n’a été effectué. Dans un shell où Yarn est directement accessible, `yarn test:js` reste la commande habituelle.

## Inventaire des sources et priorités

Les 29 fichiers JavaScript applicatifs de `assets/` ont été lus. Les copies/minifications tierces sous `assets/js/tinymce.min.js`, `assets/js/themes/`, `assets/js/icons/` et `assets/js/skins/` ne constituent pas de logique métier à tester.

Chemins ci-dessous relatifs à `assets/`. Les suites reflètent les chemins des sources sous `tests/js/`.

| Source | Logique, DOM et dépendances | Suite / comportements vérifiés |
| --- | --- | --- |
| `controllers/grid/utils.js` | Échappement, identité d’occurrence, affectation DOM | [utils.test.js](controllers/grid/utils.test.js) : textes inertes, origine avant destination, identité incomplète, carte/sidebar/candidats après affectation. |
| `controllers/grid/api.js` | fetch GET, POST URLSearchParams et JSON | [api.test.js](controllers/grid/api.test.js) : payloads/headers, erreurs HTTP et métier malgré HTTP 200, JSON de POST illisible, panne réseau. |
| `controllers/grid/dragDrop.js` | Coordonnées, calendrier local, verrouillage, routage des dépôts, position DOM | [dragDrop.test.js](controllers/grid/dragDrop.test.js) : semaine radio franchissant une année, non bloquant/ghost/verrouillé, état dragstart/dragend, délégation Pending/ponctuel/draft, bornes de journée. Géométrie simulée. |
| `controllers/grid/conflicts.js` | Lecture du JSON de conflits, déduplication, rangs et actions | [conflicts.test.js](controllers/grid/conflicts.test.js) : JSON invalide, identité origine, regroupement, rangs, échappement, retour à l’origine et index de conflit. Ne calcule pas les conflits PHP. |
| `controllers/grid/postitRenderer.js` | Badges, styles, titres et résumé visuel | [postitRenderer.test.js](controllers/grid/postitRenderer.test.js) : rangs/direct/déplacement, priorité des styles, petits passages, contenu échappé et nettoyage des anciennes classes/conflits. |
| `controllers/grid/sidebar.js` | Résumés réguliers/manuels, ghost recouvert, groupe par fetch | [sidebar.test.js](controllers/grid/sidebar.test.js) : readonly, ghost/verrouillage, conflit, exception séparée de l’émission effective, ancien chargement ignoré après changement de sélection, clé/texte du groupe. |
| `controllers/grid/pendingRebroadcasts.js` | GET Parc, recherche locale, DOM/historique, duplication/DELETE/placement | [pendingRebroadcasts.test.js](controllers/grid/pendingRebroadcasts.test.js) : chargement/erreurs/retry, historique et échappement, accents/casse, états vides, compteur total, panneau mémorisé, readonly, boutons, confirmation, payload du dépôt, reload seulement après succès. |
| `controllers/grid/drafts.js` | POST déplacement/création/suppression, modales DOM | [drafts.test.js](controllers/grid/drafts.test.js) : éligibilité corbeille, modes single/rebroadcasts/group, backdrop, déplacements refusés, créations live/special, dates complètes de rediffusion ; caractérisation explicite du bug de suppression décrit ci-dessous. |
| `controllers/grid/arbitration.js` | Annulation, semaine/date personnalisée, restauration, POST et navigation | [arbitration.test.js](controllers/grid/arbitration.test.js) : identité d’origine, stratégies et cibles liées, confirmation, champs obligatoires, semaine cible manquante, retour/restauration avec rediffs. |
| `controllers/grid/rebroadcastModal.js` | Promesses de choix, fetch des occurrences liées, DOM et keydown | [rebroadcastModal.test.js](controllers/grid/rebroadcastModal.test.js) : rang >1 sans dialogue, keep/cancel/move/custom, Escape et retrait du listener, cibles personnalisées, erreur récupérable, restauration explicitement choisie. |
| `controllers/grid/emissions.js` | Candidats, recherches, affectation/retrait, création live et rendu | [emissions.test.js](controllers/grid/emissions.test.js) : recherche dès trois caractères, origine, mode étendu, règles de proposition du direct, affectation/catégorie, reload pour origine projetée, retrait et refus sans affectation optimiste. |
| `controllers/grid_controller.js` | Orchestration Stimulus, readonly, recherche différée, sessionStorage, resize | [grid_controller.test.js](controllers/grid_controller.test.js) : vrai connect et actions Stimulus, readonly/drop, gardes de mutations publiques, panneaux/accessibilité/session, debounce 300 ms, disconnect/resize. |
| `controllers/programmation_rule_slot_controller.js` | Champs mensuels/hebdomadaires, valeurs par défaut, weekOffset | [programmation_rule_slot_controller.test.js](controllers/programmation_rule_slot_controller.test.js) : vrai connect/change, mensuel actif puis données mensuelles effacées en hebdomadaire, décalage conservé. |
| `controllers/emission_form_controller.js` | Valeurs de catégorie, GET utilisateurs, AbortController, options/groupes | [emission_form_controller.test.js](controllers/emission_form_controller.test.js) : defaults/change natif, URL/ID émission, options inertes/sélection/statut, erreur HTTP sans perte des choix, annulation de requête au changement et à disconnect. |
| `controllers/turbo_controller.js` | Audio et localStorage sur turbo:visit/load | [turbo_controller.test.js](controllers/turbo_controller.test.js) : restauration, bouton play/pause, remplacement audio à la navigation et listeners retirés ; API média simulée, aucun audio réel. |
| `controllers/return_controller.js` | URL de retour, Turbo.visit, confirmation submit | [return_controller.test.js](controllers/return_controller.test.js) : chemin/recherche encodés, confirmation/refus ; Turbo entièrement mocké. |
| `controllers/carousel_controller.js` | Choix du direct ou repli, montage/démontage Glide | [carousel_controller.test.js](controllers/carousel_controller.test.js) : choix du passage, mount/destroy, élément non Glide ignoré ; bibliothèque mockée. |
| `controllers/flatpickr_controller.js` | Configuration des dates et cycle du widget | [flatpickr_controller.test.js](controllers/flatpickr_controller.test.js) : options Stimulus/locale, destruction avant reconnexion et à disconnect ; Flatpickr et locale mockés. |
| `controllers/tinymce_controller.js` | Éditeur, plugins, timeout initial, tinymce:reload, turbo:before-cache, submit et upload XHR | Non testé dans cette base : imports à effets de bord de nombreux plugins, initialisation différée et upload constituent un parcours distinct. À isoler avec mocks du package **et de tous ses plugins** ; ne pas charger le véritable éditeur/CDN. |
| `controllers/annonce_form_controller.js` | Type « autre », limites de caractères, messages/input et nettoyage | Non testé : formulaire éditorial indépendant ; la priorité de cette passe est la grille et les interactions asynchrones critiques. |
| `controllers/image_modal_controller.js` | Ouverture, backdrop/Escape, verrou de scroll et disconnect | Non testé : parcours de consultation indépendant, hors priorité grille. |
| `controllers/description_toggle_controller.js` | Troncature mesurée par requestAnimationFrame et expansion | Non testé : mesure de layout absente de jsdom ; une future suite devra simuler explicitement scrollHeight/clientHeight, sans prétendre valider le CSS réel. |
| `controllers/descriptif_controller.js` | Même type de mesure/expansion | Même limite et priorité que description_toggle ; aucune refactorisation des deux implémentations. |
| `controllers/theme_filter_controller.js` | Filtres multiples de thèmes et visibilité DOM | Non testé : fonctionnalité publique indépendante, après les workflows prioritaires. |
| `js/themeFilter.js` | Variante globale du filtre, listener turbo:load | Non testé : script sans exports, effets de bord globaux ; à examiner avec le branchement réel avant d’ajouter des tests. |
| `controllers/reset_form_controller.js` | Navigation vers URL de remise à zéro | Non testé : navigation simple, moins prioritaire ; Location devra être simulée. |
| `controllers/hello_controller.js` | Démonstration Stimulus | Non testé : aucun comportement métier. |
| `app.js` | Imports CSS/bootstrap et tinymce:reload sur turbo:load | Non testé directement : entrée de bundle Encore, pas une fonction métier isolée. Les événements Turbo du lecteur sont testés séparément. |
| `bootstrap.js` | Application Stimulus, Turbo, UX LiveComponent, require.context | Non testé directement : `require.context` appartient à Webpack, pas à Vitest/Vite. Les tests montent les contrôleurs explicitement avec Stimulus réel. L’auto-enregistrement et UX LiveComponent nécessitent un test de bundle/navigateur séparé. |

## Infrastructure et isolation

- [setup.js](setup.js) : fetch et XMLHttpRequest réels interdits ; toute requête non simulée fait échouer le test, même si le code applicatif intercepte l’exception. alert/confirm simulés. Fermeture des modales Escape, déconnexion Stimulus, DOM et stockage nettoyés, timers simulés vidés, timers réels et globals restaurés après chaque test.
- [support/dom.js](support/dom.js) : éléments DOM, réponses JSON, promesses contrôlées, montage/déconnexion Stimulus. `flushDom()` ne lance aucun timer : il laisse les microtasks/MutationObserver finir. Les tests ne doivent pas l’utiliser comme délai réseau ; ils attendent les promesses explicites des fonctions dès que possible.
- `mockNavigation()` présente une Location simulée via un proxy de window : jsdom 24 empêche de remplacer directement Location.reload. Pas de modification du code de production pour contourner cette limite.
- Les modules de grille dont les exports utilisent `this` sont appelés avec un petit contexte de test explicite. Ce contexte représente seulement leurs collaborateurs publics ; les vrais cycles/targets/actions sont testés en complément dans les suites de contrôleurs.
- Les requêtes asynchrones sensibles utilisent des promesses contrôlées, pas des délais arbitraires. Le debounce utilise l’horloge simulée de Vitest. Les dates calendaires testées sont fixes et locales, sans comparaison à « maintenant » ni conversion UTC dépendant de la machine.
- Glide, Flatpickr, Turbo.visit et le lecteur HTMLMediaElement sont simulés. Aucun détail interne de ces bibliothèques n’est testé. TinyMCE n’est pas importé par cette suite.

## Limites de cette première passe

Les 18 sources testées ne sont pas intégralement couvertes. Sont volontairement reportés : toutes les combinaisons des arbitrages d’un conflit opposé, tout le HTML des renderEmissions/renderSpecialEmissions, les candidats ponctuels et la création régulière de direct, les résumés manuels exhaustifs, les raccourcis/clamps défensifs de tous les formulaires et la restauration animée de scroll.

Le glisser-déposer natif du navigateur, layout/CSS, accessibilité complète, cache/restauration réelle de Turbo, navigation/reload effectifs et intégration avec les réponses du serveur Symfony ne sont pas validés par jsdom. Les tests de payload ne certifient pas les mutations PHP ni les règles de publication/historique calculées au serveur. Aucun PHP ni test PHPUnit n’a été modifié ou exécuté.

Il n’y a ni cible chiffrée de couverture ni branche ajoutée pour « faire monter le taux ». La suite est maintenant verte ; l’audit de couverture sera une tâche séparée.

## Anomalies et comportements à examiner, sans correction

1. **Bug reproduit** — `assets/controllers/grid/drafts.js`, suppression d’une rediffusion depuis `openRebroadcastModal()` : le POST peut réussir puis `this.saveScrollTarget(startsAt)` lève `ReferenceError`, car startsAt n’est pas défini dans ce callback. La page ne recharge pas et la modale affiche l’erreur. Le test « constat de bug » caractérise explicitement cet état actuel ; il ne définit pas ce résultat comme comportement souhaité. Aucun test ignoré ou échec attendu n’est utilisé pour rendre la suite verte.
2. **Risque lu, non reproduit par cette base** — GridController::disconnect() retire resize mais n’annule pas regularSearchTimeout/specialSearchTimeout ; les listeners anonymes drag/drop ajoutés dans connect ne sont pas retirés. Une déconnexion/reconnexion sur le même DOM peut laisser une recherche différée ou multiplier les handlers. Le nettoyage des tests empêche des timers de fuir entre cas ; il ne corrige pas la production.
3. **Risque asynchrone lu, non reproduit** — `emission_form_controller.js::refreshUsers()` remet toujours `this.abortController=null` dans finally. La fin d’une requête antérieure annulée peut effacer la référence de la nouvelle requête encore active. La suite vérifie les annulations dans un ordre contrôlé ; elle ne garantit pas l’absence de cette course.
4. **Risque de sélection lu, non reproduit** — loadLinkedDiffusions() ignore une ancienne réponse si la sélection a changé, ce que le test vérifie ; selectSlot() poursuit après await sans refaire la même garde. Ne pas généraliser cette protection à toute la sidebar ni au chargement des candidats.
5. **Contrat actuel à examiner** — api.getJson() retourne null si un HTTP réussi a un JSON illisible, alors que les POST le refusent. Plusieurs appelants attendent ensuite data.items. La suite vérifie le refus JSON des POST, sans présenter le GET comme une validation stricte du JSON.

## Modifications de configuration

- [vitest.config.js](../../vitest.config.js) : ajout de `setupFiles: ['tests/js/setup.js']` et `unstubGlobals: true`. environment jsdom, include, clearMocks et restoreMocks conservés. Aucun alias, plugin, reporter ou provider de couverture ajouté.
- [.gitignore](../../.gitignore) : `tests/` remplacé par `/tests/*` puis `!/tests/js/` pour versionner uniquement cette base JavaScript ; les autres sous-répertoires de tests restent ignorés comme auparavant. Aucun fichier PHPUnit modifié.
- package.json et yarn.lock n’ont pas été modifiés par cette tâche ; Vitest/jsdom et les scripts test:js/test:js:watch étaient déjà installés.
- `tests/js/smoke.test.js` supprimé : les vraies suites vérifient maintenant Vitest/jsdom et Stimulus.
