# Recette — Administration des comptes et des rôles

## Objectif

Vérifier l'affichage des différents états des comptes, leur validation et leur désactivation, ainsi que les droits de gestion des rôles pour `ROLE_ADMIN` et `ROLE_SUPER_ADMIN`.

## Préconditions

- Disposer d'un compte `ROLE_ADMIN`.
- Disposer d'un compte `ROLE_SUPER_ADMIN`.
- Disposer de plusieurs comptes utilisateurs de test dans différents états.

---

## Cas 1 — Compte dont l'e-mail n'est pas confirmé

☐ Créer ou utiliser un compte dont l'adresse e-mail n'a pas été confirmée.

☐ Se connecter avec un `ROLE_ADMIN`.

☐ Ouvrir la gestion des utilisateurs.

☐ Vérifier que le compte est signalé comme « E-mail à confirmer ».

☐ Vérifier qu'il n'apparaît pas comme un compte validé.

☐ Vérifier que l'action de validation du compte n'est pas proposée tant que l'e-mail n'est pas confirmé.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 2 — Compte confirmé en attente de validation

☐ Confirmer l'adresse e-mail du compte précédent.

☐ Actualiser la gestion des utilisateurs.

☐ Vérifier que son état devient « En attente de validation ».

☐ Vérifier que l'action permettant de valider le compte est disponible.

☐ Valider le compte.

☐ Vérifier que son état devient « Compte validé ».

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 3 — Désactiver un compte

### À effectuer avec un `ROLE_SUPER_ADMIN`

☐ Choisir un compte utilisateur de test validé.

☐ Désactiver le compte.

☐ Vérifier que son état devient « Compte désactivé ».

☐ Vérifier que le compte reste visible dans l'administration.

☐ Se déconnecter du `ROLE_SUPER_ADMIN`.

☐ Essayer de se connecter avec le compte désactivé.

☐ Vérifier que la connexion est refusée.

> La réactivation d'un compte est volontairement hors périmètre de cette recette : cette fonctionnalité a été reportée après la mise en production.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 4 — Gestion des rôles par un ADMIN

☐ Se connecter avec un `ROLE_ADMIN`.

☐ Ouvrir la modification d'un utilisateur ordinaire.

☐ Vérifier que les rôles autorisés jusqu'à `ROLE_ADMIN` peuvent être gérés.

☐ Vérifier que `ROLE_SUPER_ADMIN` ne peut pas être attribué par un `ROLE_ADMIN`.

☐ Ouvrir, si disponible, un compte possédant déjà `ROLE_SUPER_ADMIN`.

☐ Vérifier qu'un `ROLE_ADMIN` ne peut pas retirer ou modifier le rôle `ROLE_SUPER_ADMIN`.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 5 — Gestion des rôles par un SUPER_ADMIN

☐ Se connecter avec un `ROLE_SUPER_ADMIN`.

☐ Ouvrir la modification d'un autre utilisateur.

☐ Vérifier que le rôle `ROLE_SUPER_ADMIN` est disponible lorsque nécessaire.

☐ Vérifier qu'un `ROLE_SUPER_ADMIN` peut gérer les rôles d'un autre `ROLE_SUPER_ADMIN`.

☐ Ouvrir son propre compte `ROLE_SUPER_ADMIN`.

☐ Vérifier qu'il n'est pas possible de se retirer accidentellement son propre rôle `ROLE_SUPER_ADMIN`.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

# Validation globale de la recette

☐ Les différents états des comptes sont correctement identifiables.

☐ Un compte non confirmé ne peut pas être validé prématurément.

☐ Un compte confirmé peut être validé administrativement.

☐ Un compte désactivé reste présent dans l'administration mais ne peut plus se connecter.

☐ Un `ROLE_ADMIN` peut gérer les rôles jusqu'à `ROLE_ADMIN`.

☐ Un `ROLE_ADMIN` ne peut pas gérer `ROLE_SUPER_ADMIN`.

☐ Un `ROLE_SUPER_ADMIN` peut gérer les rôles d'un autre `ROLE_SUPER_ADMIN`.

☐ Un `ROLE_SUPER_ADMIN` ne peut pas retirer son propre rôle `ROLE_SUPER_ADMIN`.

**Résultat global :** ☐ RECETTE VALIDÉE ☐ RECETTE NON VALIDÉE

**Testeur :** ______________________________________________

**Date :** ______________________________________________

**Version testée :** ______________________________________________

**Remarques générales :**

______________________________________________________________

______________________________________________________________

______________________________________________________________