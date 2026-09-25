# Recette — Profil, changement d'e-mail et mots de passe

## Objectif

Vérifier les opérations qu'un utilisateur validé peut effectuer sur son propre compte : modification du pseudo, changement d'adresse e-mail, changement volontaire du mot de passe et récupération d'un mot de passe oublié.

## Préconditions

- Disposer d'un compte utilisateur confirmé, validé et actif.
- Disposer d'une deuxième adresse e-mail accessible pour tester le changement d'adresse.
- Connaître le mot de passe actuel du compte.

---

## Cas 1 — Modifier le pseudo

☐ Se connecter avec le compte de test.

☐ Ouvrir la page du profil.

☐ Modifier le pseudo.

☐ Enregistrer.

☐ Vérifier qu'un message confirme la modification.

☐ Revenir sur le profil.

☐ Vérifier que le nouveau pseudo est conservé.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 2 — Demander un changement d'adresse e-mail

☐ Depuis le profil, saisir une nouvelle adresse e-mail accessible.

☐ Enregistrer.

☐ Vérifier qu'un e-mail de confirmation est reçu sur la nouvelle adresse.

☐ Ne pas encore cliquer sur le lien.

☐ Vérifier que le compte reste utilisable normalement.

☐ Vérifier que l'adresse e-mail actuelle n'a pas été remplacée prématurément.

☐ Cliquer sur le lien reçu sur la nouvelle adresse.

☐ Vérifier que le changement est confirmé.

☐ Revenir sur le profil.

☐ Vérifier que la nouvelle adresse est maintenant celle du compte.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 3 — Mauvais mot de passe actuel

☐ Ouvrir la modification du mot de passe depuis le profil.

☐ Saisir volontairement un mauvais mot de passe actuel.

☐ Saisir un nouveau mot de passe valide.

☐ Confirmer le nouveau mot de passe.

☐ Valider.

☐ Vérifier que la modification est refusée.

☐ Vérifier que la session utilisateur reste utilisable.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 4 — Modifier correctement le mot de passe

☐ Recommencer avec le bon mot de passe actuel.

☐ Saisir deux fois le nouveau mot de passe.

☐ Valider.

☐ Vérifier que la modification réussit.

☐ Vérifier que l'utilisateur reste connecté.

☐ Se déconnecter.

☐ Essayer de se connecter avec l'ancien mot de passe.

☐ Vérifier que la connexion échoue.

☐ Se connecter avec le nouveau mot de passe.

☐ Vérifier que la connexion réussit.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 5 — Demander une réinitialisation de mot de passe

☐ Se déconnecter.

☐ Ouvrir la fonctionnalité « Mot de passe oublié ».

☐ Saisir l'adresse e-mail du compte.

☐ Valider la demande.

☐ Vérifier qu'aucune erreur technique n'apparaît.

☐ Vérifier qu'un e-mail de réinitialisation est reçu.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

## Cas 6 — Utiliser le lien de réinitialisation

☐ Cliquer sur le lien contenu dans l'e-mail.

☐ Vérifier que le formulaire permettant de choisir un nouveau mot de passe s'affiche.

☐ Saisir deux fois un nouveau mot de passe.

☐ Valider.

☐ Vérifier que le site redirige vers la connexion.

☐ Essayer de se connecter avec le mot de passe précédent.

☐ Vérifier que la connexion échoue.

☐ Se connecter avec le nouveau mot de passe.

☐ Vérifier que la connexion réussit.

**Résultat du cas :** ☐ OK ☐ KO

**Remarques :** ______________________________________________

---

# Validation globale de la recette

☐ Le pseudo peut être modifié et conservé.

☐ Un changement d'e-mail nécessite la confirmation de la nouvelle adresse.

☐ L'adresse actuelle reste utilisable tant que la nouvelle adresse n'est pas confirmée.

☐ Un mauvais mot de passe actuel empêche le changement de mot de passe depuis le profil.

☐ Un changement de mot de passe réussi ne déconnecte pas immédiatement l'utilisateur.

☐ L'ancien mot de passe cesse de fonctionner après modification.

☐ Le parcours « Mot de passe oublié » permet de définir un nouveau mot de passe.

☐ Le nouveau mot de passe permet ensuite de se connecter.

**Résultat global :** ☐ RECETTE VALIDÉE ☐ RECETTE NON VALIDÉE

**Testeur :** ______________________________________________

**Date :** ______________________________________________

**Version testée :** ______________________________________________

**Remarques générales :**

______________________________________________________________

______________________________________________________________

______________________________________________________________