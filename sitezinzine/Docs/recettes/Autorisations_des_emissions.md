# Recette — Autorisations sur les émissions

## Objectif

Vérifier qu'un utilisateur ne peut modifier ou supprimer que les émissions auxquelles il est associé, tandis que les administrateurs disposent des droits globaux prévus.

## Préconditions

- Disposer d'un compte `ROLE_USER`.
- Disposer d'un compte `ROLE_ADMIN`.
- Disposer d'un compte `ROLE_SUPER_ADMIN`.
- Disposer d'une émission associée au `ROLE_USER` de test.
- Disposer d'une autre émission non associée à ce compte.

---

## Cas 1 — USER sur une émission associée

☐ Se connecter avec le `ROLE_USER` associé à l'émission.

☐ Ouvrir ou rechercher son émission.

☐ Vérifier que l'action de modification est disponible.

☐ Ouvrir la modification.

☐ Vérifier que le formulaire est accessible.

☐ Revenir à la liste ou à la recherche.

☐ Vérifier que l'action de suppression est disponible pour cette émission.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 2 — USER sur une émission non associée

☐ Toujours connecté avec le même `ROLE_USER`, effectuer une recherche globale permettant de retrouver l'autre émission.

☐ Vérifier que l'émission apparaît dans les résultats lorsque la recherche globale doit l'afficher.

☐ Vérifier que l'action `Modifier` n'est pas proposée.

☐ Vérifier que l'action `Supprimer` n'est pas proposée.

☐ Tenter d'accéder directement à l'URL de modification de cette émission.

☐ Vérifier que l'accès est refusé.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 3 — ADMIN sur une émission non associée

☐ Se connecter avec un `ROLE_ADMIN`.

☐ Rechercher une émission qui ne lui est pas associée.

☐ Vérifier que l'action `Modifier` est disponible.

☐ Ouvrir la modification.

☐ Vérifier que le formulaire est accessible.

☐ Vérifier que l'action `Supprimer` est disponible.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 4 — SUPER_ADMIN sur une émission non associée

☐ Se connecter avec un `ROLE_SUPER_ADMIN`.

☐ Rechercher une émission qui ne lui est pas associée.

☐ Vérifier que l'action `Modifier` est disponible.

☐ Vérifier que la modification est accessible.

☐ Vérifier que l'action `Supprimer` est disponible.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 5 — Suppression effective d'une émission associée

> Utiliser uniquement une émission créée pour la recette.

☐ Se connecter avec le `ROLE_USER` associé à l'émission de test.

☐ Utiliser l'action `Supprimer`.

☐ Confirmer la suppression lorsque l'interface le demande.

☐ Vérifier que l'opération est acceptée.

☐ Vérifier que l'émission supprimée n'est plus présentée comme une émission active normale.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 6 — Suppression effective par un ADMIN

> Utiliser uniquement une émission créée pour la recette.

☐ Se connecter avec un `ROLE_ADMIN`.

☐ Choisir une émission de test qui ne lui est pas associée.

☐ Utiliser l'action `Supprimer`.

☐ Confirmer la suppression.

☐ Vérifier que l'opération est acceptée.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

# Validation globale de la recette

☐ Un utilisateur associé peut modifier son émission.

☐ Un utilisateur associé peut supprimer son émission.

☐ Un utilisateur non associé ne peut ni modifier ni supprimer l'émission d'un autre utilisateur.

☐ Les actions interdites ne sont pas proposées dans l'interface.

☐ L'accès direct à une modification interdite est également protégé.

☐ Un `ROLE_ADMIN` peut modifier et supprimer toutes les émissions.

☐ Un `ROLE_SUPER_ADMIN` peut modifier et supprimer toutes les émissions.

**Résultat global :** ☐ RECETTE VALIDÉE ☐ RECETTE NON VALIDÉE

**Testeur :** ______________________________________________

**Date :** ______________________________________________

**Version testée :** ______________________________________________

**Remarques générales :**

______________________________________________________________

______________________________________________________________

______________________________________________________________