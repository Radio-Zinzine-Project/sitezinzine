# Recette — Comptes, inscription et confirmation des e-mails

## Objectif

Vérifier le parcours d'un nouvel utilisateur depuis son inscription jusqu'à l'activation complète de son compte, ainsi que le renvoi de l'e-mail de confirmation.

## Préconditions

- Disposer d'une adresse e-mail accessible au testeur et non encore utilisée sur le site.
- Disposer d'un compte `ROLE_ADMIN` ou supérieur pour la validation du nouveau compte.

---

## Cas 1 — Créer un nouveau compte

☐ Ouvrir la page d'inscription.

☐ Remplir correctement le formulaire.

☐ Valider l'inscription.

☐ Vérifier qu'un message confirme la prise en compte de l'inscription.

☐ Vérifier qu'un e-mail de confirmation est reçu.

☐ Ne pas encore cliquer sur le lien de confirmation.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 2 — Connexion avant confirmation de l'e-mail

☐ Essayer de se connecter avec le nouveau compte.

☐ Vérifier que la connexion est refusée.

☐ Vérifier que le message indique que l'adresse e-mail doit être confirmée.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 3 — Confirmer l'adresse e-mail

☐ Ouvrir l'e-mail reçu lors de l'inscription.

☐ Cliquer sur le lien de confirmation.

☐ Vérifier que le site confirme la validation de l'adresse e-mail.

☐ Essayer de se connecter.

☐ Vérifier que la connexion est encore refusée.

☐ Vérifier que le message indique que le compte est en attente de validation par Radio Zinzine.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 4 — Validation du compte par un administrateur

☐ Se connecter avec un `ROLE_ADMIN` ou supérieur.

☐ Ouvrir la gestion des utilisateurs.

☐ Retrouver le nouveau compte.

☐ Vérifier qu'il est indiqué comme étant en attente de validation.

☐ Valider le compte.

☐ Vérifier que son état devient « Compte validé ».

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 5 — Première connexion après validation

☐ Se connecter avec le nouveau compte.

☐ Vérifier que la connexion réussit.

☐ Vérifier que l'espace d'administration accessible à l'utilisateur s'affiche normalement.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 6 — Renvoyer un e-mail de confirmation

### Précondition spécifique

Utiliser un compte inscrit dont l'adresse e-mail n'a pas encore été confirmée.

☐ Depuis la page de connexion, utiliser le lien permettant de renvoyer l'e-mail de confirmation.

☐ Saisir l'adresse e-mail du compte non confirmé.

☐ Valider.

☐ Vérifier qu'une réponse est affichée sans révéler inutilement l'existence du compte.

☐ Vérifier qu'un nouvel e-mail est reçu.

☐ Cliquer sur le nouveau lien.

☐ Vérifier que l'adresse e-mail est confirmée.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 7 — Demander un renvoi pour une adresse inconnue

☐ Recommencer la demande de renvoi avec une adresse qui ne correspond à aucun compte.

☐ Vérifier que la page ne révèle pas explicitement que cette adresse n'existe pas dans Radio Zinzine.

☐ Vérifier qu'aucune erreur technique n'apparaît.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

# Validation globale de la recette

☐ Un nouveau compte est créé non confirmé et non validé.

☐ La connexion est impossible avant confirmation de l'e-mail.

☐ La confirmation de l'e-mail ne valide pas automatiquement le compte.

☐ La connexion reste impossible avant validation administrative.

☐ Après validation administrative, la connexion fonctionne.

☐ Le renvoi d'un e-mail de confirmation fonctionne.

☐ La demande de renvoi ne permet pas de déterminer si une adresse inconnue possède un compte.

**Résultat global :** ☐ RECETTE VALIDÉE ☐ RECETTE NON VALIDÉE

**Testeur :** ______________________________________________

**Date :** ______________________________________________

**Version testée :** ______________________________________________

**Remarques générales :**

______________________________________________________________

______________________________________________________________

______________________________________________________________