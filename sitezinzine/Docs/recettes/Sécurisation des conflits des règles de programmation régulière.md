# Recette — Sécurisation des conflits des règles de programmation régulière

## Objectif

Vérifier le fonctionnement de la détection des conflits structurels lors de la création ou de la modification des créneaux d'une règle de programmation régulière.

La fonctionnalité doit respecter les règles suivantes :

- une configuration conflictuelle est toujours sauvegardée ;
- lorsqu'un conflit structurel apparaît, la règle concernée est automatiquement désactivée ;
- une règle désactivée n'est jamais réactivée automatiquement ;
- l'interface indique les créneaux en conflit et le créneau avec lequel le conflit existe ;
- lorsqu'un conflit est corrigé, la règle reste inactive mais est signalée comme pouvant être réactivée ;
- une règle ne peut être réactivée manuellement que lorsqu'elle ne possède plus aucun conflit structurel ;
- une règle active peut être désactivée manuellement à tout moment.

---

## Préconditions

- Être connecté avec un compte ayant le rôle `ROLE_ADMIN` ou supérieur.
- Disposer d'au moins deux catégories permettant de créer deux règles distinctes.
- Accéder à :

`Administration > Règles de programmation`

---

## 1. Création d'une situation sans conflit

### Étapes

1. Créer une première règle de programmation.
2. Lui ajouter un créneau :
   - récurrence : hebdomadaire ;
   - jour : mardi ;
   - heure : 20:00 ;
   - durée : 60 minutes ;
   - ordre de diffusion : 1re diffusion ;
   - décalage : même semaine radio ;
   - rythme hebdomadaire : toutes les semaines ;
   - actif : oui.
3. Enregistrer le créneau.

### Résultat attendu

- Le créneau est enregistré.
- La règle reste active.
- Aucun conflit n'est affiché.

---

## 2. Création d'un conflit avec une autre règle

### Étapes

1. Créer une deuxième règle de programmation.
2. Lui ajouter un créneau avec les mêmes caractéristiques :
   - récurrence : hebdomadaire ;
   - mardi ;
   - 20:00 ;
   - durée : 60 minutes ;
   - 1re diffusion ;
   - même semaine radio ;
   - toutes les semaines ;
   - actif.
3. Enregistrer.

### Résultat attendu

- Le créneau est bien sauvegardé.
- La deuxième règle est automatiquement désactivée.
- Un message indique que la règle a été désactivée à cause d'un conflit.
- Le créneau reste accessible et modifiable.

---

## 3. Affichage du détail du conflit

### Étapes

1. Ouvrir la liste des créneaux de la règle désactivée.
2. Repérer le créneau conflictuel.

### Résultat attendu

Le conflit est affiché sous le créneau concerné sans élargir ou déformer le tableau.

Les informations affichées doivent permettre d'identifier le créneau opposé, notamment :

- la règle concernée ;
- le type de diffusion ;
- le jour ;
- l'heure ;
- la durée ;
- la récurrence ;
- les informations de récurrence complémentaires lorsqu'elles sont pertinentes.

Sur mobile, le conflit doit également rester lisible dans la carte du créneau.

---

## 4. Correction du conflit

### Étapes

1. Modifier le créneau conflictuel de la deuxième règle.
2. Passer son horaire de `20:00` à `22:00`.
3. Enregistrer.

### Résultat attendu

- La modification est enregistrée.
- Le conflit disparaît.
- La règle reste inactive.
- Elle n'est pas automatiquement réactivée.
- L'interface indique qu'elle peut maintenant être réactivée.

---

## 5. Réactivation manuelle après résolution du conflit

### Étapes

1. Retourner dans la liste des règles de programmation.
2. Repérer la règle inactive dont le conflit vient d'être corrigé.
3. Cliquer sur `Réactiver`.

### Résultat attendu

- La règle devient active.
- Son état est correctement affiché dans la liste.
- Le bouton `Réactiver` disparaît.
- Le bouton `Désactiver` devient disponible.

---

## 6. Tentative de réactivation avec conflit existant

### Étapes

1. Recréer un conflit sur une règle.
2. Vérifier son état dans la liste des règles.

### Résultat attendu

- La règle est inactive.
- L'interface indique qu'elle possède encore un conflit.
- La réactivation n'est pas proposée tant que le conflit existe.

---

## 7. Désactivation manuelle d'une règle

### Étapes

1. Choisir une règle active sans conflit.
2. Cliquer sur `Désactiver`.

### Résultat attendu

- La règle devient inactive.
- Ses créneaux ne sont pas supprimés.
- La configuration de la règle reste disponible.
- Si aucun conflit structurel n'existe, l'interface propose ensuite `Réactiver`.

---

## 8. Conflit interne à une même règle

### Étapes

1. Créer une règle possédant déjà un créneau hebdomadaire le mardi à 20:00 pour 60 minutes.
2. Ajouter dans cette même règle un autre créneau structurellement incompatible avec le premier.
3. Enregistrer.

### Résultat attendu

- Le nouveau créneau est sauvegardé.
- La règle est désactivée.
- Les deux créneaux concernés sont signalés comme étant en conflit.
- Le détail permet de comprendre qu'il s'agit d'un conflit à l'intérieur de la même règle.

---

## 9. Conflit impliquant une rediffusion

### Étapes

1. Créer une règle avec une première diffusion.
2. Ajouter une rediffusion à un horaire entrant structurellement en conflit avec un autre créneau régulier.
3. Enregistrer.

### Résultat attendu

- Le conflit impliquant la rediffusion est détecté.
- La configuration est sauvegardée.
- La règle concernée est désactivée.
- Le créneau conflictuel est clairement identifié dans l'interface.

Les rediffusions doivent être soumises aux mêmes règles de sécurité structurelle que les premières diffusions.

---

## 10. Vérification des cas autorisés

Vérifier également que les configurations suivantes ne sont pas bloquées lorsqu'elles ne constituent pas un conflit structurel systématique.

### Semaines paires / impaires

Créer deux créneaux au même jour et à la même heure :

- l'un en semaines paires ;
- l'autre en semaines impaires.

**Attendu :** aucun conflit structurel.

### Occurrences mensuelles différentes

Créer deux créneaux au même jour et à la même heure :

- 1er lundi du mois ;
- 2e lundi du mois.

**Attendu :** aucun conflit structurel.

### 4e occurrence / dernière occurrence

Créer deux créneaux au même horaire :

- 4e lundi du mois ;
- dernier lundi du mois.

**Attendu :** aucun conflit structurel systématique, même si les deux occurrences peuvent ponctuellement tomber le même jour.

### Créneaux adjacents

Créer :

- un créneau de 20:00 à 21:00 ;
- un créneau commençant à 21:00.

**Attendu :** aucun conflit.

Les créneaux se touchent mais ne se chevauchent pas.

---

## 11. Vérification responsive

Effectuer les vérifications principales en affichage desktop puis mobile.

### Desktop

Vérifier que :

- les boutons d'action restent correctement alignés ;
- l'ajout du bouton `Réactiver` ou `Désactiver` ne déforme pas le tableau ;
- le détail d'un conflit s'affiche sous le créneau concerné ;
- aucun scroll horizontal anormal n'est provoqué par l'affichage du conflit.

### Mobile

Vérifier que :

- les règles restent lisibles sous forme de cartes ;
- les actions restent accessibles ;
- le détail des conflits reste lisible ;
- les boutons ne débordent pas de leur conteneur.

---

# Validation de la recette

La fonctionnalité est considérée comme validée si :

- les conflits structurels sont détectés lors de la création et de la modification des créneaux ;
- une configuration conflictuelle n'est jamais perdue ;
- une règle conflictuelle est automatiquement désactivée ;
- les conflits sont identifiables depuis l'interface ;
- la résolution d'un conflit ne réactive jamais automatiquement la règle ;
- une règle sans conflit peut être réactivée manuellement ;
- une règle encore conflictuelle ne peut pas être réactivée ;
- une règle active peut être désactivée manuellement ;
- les conflits internes à une règle sont détectés ;
- les rediffusions sont prises en compte ;
- les collisions uniquement occasionnelles ne sont pas assimilées à des conflits structurels ;
- l'affichage reste utilisable sur desktop et mobile.