# Recette — Pages d'erreur personnalisées

## Objectif

Vérifier que les pages d'erreur personnalisées 403, 404 et 500 sont compréhensibles, intégrées au site, utilisables sur ordinateur et mobile, et qu'elles n'exposent aucune information technique sensible.

## Préconditions

En environnement de développement Symfony, les pages peuvent être prévisualisées avec :

- `/_error/403`
- `/_error/404`
- `/_error/500`

---

## Cas 1 — Page 404

☐ Ouvrir `/_error/404`.

☐ Vérifier que le titre « Cette page est introuvable » apparaît correctement.

☐ Vérifier que le message « Impossible de trouver la page demandée » est affiché.

☐ Vérifier que les textes explicatifs sont lisibles et visuellement équilibrés.

☐ Vérifier que le bouton « Retour à l'accueil » est visible.

☐ Cliquer sur « Retour à l'accueil ».

☐ Vérifier que le retour vers l'accueil fonctionne.

☐ Vérifier qu'aucun message technique Symfony n'est affiché.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 2 — Page 403

☐ Ouvrir `/_error/403`.

☐ Vérifier que la page explique clairement que l'accès est interdit.

☐ Vérifier que le contenu reste compréhensible pour un utilisateur non technique.

☐ Vérifier qu'une action permettant de quitter la page ou de revenir au site fonctionne.

☐ Vérifier qu'aucune information technique n'est affichée.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 3 — Page 500

☐ Ouvrir `/_error/500`.

☐ Vérifier que le message destiné au visiteur apparaît normalement.

☐ Vérifier que la page ne contient aucune trace PHP.

☐ Vérifier qu'aucun nom d'exception technique n'est exposé.

☐ Vérifier qu'aucun chemin de fichier serveur n'est exposé.

☐ Vérifier qu'aucune requête SQL ou information de base de données n'est exposée.

☐ Vérifier que le retour vers le site fonctionne.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 4 — Structure générale du site

☐ Vérifier que le header est correctement affiché.

☐ Vérifier que la navigation reste utilisable.

☐ Vérifier que le contenu principal conserve la présentation générale du site.

☐ Vérifier que le footer est correctement affiché.

☐ Vérifier que les éléments graphiques de Radio Zinzine sont correctement chargés.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 5 — Affichage desktop

☐ Contrôler les pages 403, 404 et 500 sur un écran d'ordinateur.

☐ Vérifier que les titres ne débordent pas.

☐ Vérifier que les textes sont correctement positionnés et lisibles.

☐ Vérifier que les boutons restent correctement positionnés.

☐ Vérifier qu'aucun scroll horizontal anormal n'apparaît.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 6 — Affichage mobile

☐ Afficher au minimum la page 404 sur mobile ou avec l'affichage responsive du navigateur.

☐ Vérifier que le titre reste lisible.

☐ Vérifier que les paragraphes restent correctement répartis.

☐ Vérifier que le bouton de retour ne déborde pas.

☐ Vérifier que la navigation du site reste utilisable.

☐ Vérifier qu'aucun élément ne provoque de débordement horizontal.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

# Validation globale de la recette

☐ La page 403 est compréhensible et fonctionnelle.

☐ La page 404 est compréhensible et fonctionnelle.

☐ La page 500 est compréhensible et fonctionnelle.

☐ Aucune information technique sensible n'est exposée.

☐ Les pages conservent la structure générale de Radio Zinzine.

☐ Les actions de retour vers le site fonctionnent.

☐ L'affichage reste utilisable sur ordinateur.

☐ L'affichage reste utilisable sur mobile.

**Résultat global :** ☐ RECETTE VALIDÉE ☐ RECETTE NON VALIDÉE

**Testeur :** ______________________________________________

**Date :** ______________________________________________

**Version testée :** ______________________________________________

**Remarques générales :**

______________________________________________________________

______________________________________________________________

______________________________________________________________