# Changelog

## [1.0.98] - 2026-06-02
### Correction
- Correction du carrousel qui faisait n'importe quoi à cause de ces ù&!$ de fuseaux horaires de $ù!&^... les dates et heures c'est une plaie à coder quelque soit le language. De toute façon je sais que personne lit mon change log alors je râle si je veux !.
- Correction de la table de migration afin de pouvoir créer le site sur le nouveau serveur.


## [1.0.97] - 2026-05-31
### Ajout
- Gestion des créneaux annulés, affichage et restauration des différentes diffusions.
- Quand on déplace un créneau, on peut aussi gérer les rediffs associées.
- Ajout gestion restauration liée des rediffusions et arbitrage complet.
- Contrôle des conflits entre créneaux réguliers et non réguliers.
- Quand on modifie la catégorie d'un créneau, c'est la nouvelle catégorie qui s'affiche, pas l'ancienne.
- Ajout du champ de recherche dans la liste des émissions non régulières.
- Possibilité de rajouter des rediffs aux émissions non régulières (gestion aussi des suppressions).
- Ajout du scroll de la sidebar pour facilité le remplissage de la grille.
### Correction
- Correction du partial ondes, cartes carousel en double, sécurité si rien dans diffusion, js qui met en actif le programme qui passe à ce moment à la radio.
- Amélioration graphique du carousel.
- Correction du bug de token CSRF à la connection.
- Quand on a déplacé une première diffusion, maintenant l'émission associée s'affiche correctement.
- Audit et refactor complet de l'architecture gridController.js devenu trop lourd.
- Modification du fonctionnement de l'affichage, si le créneau est vide, nom catégorie complet, si créneau rempli, slug catégorie + titre émission.
- Amélioration de l'affichage des infos de l'émission selectionnée (régulière ou non) dans la side bar.
- Possibilité de drag and drop les non régulières en mettant à jour le numéro de rediff.


## [1.0.96] - 2026-05-17
### Ajout
- Ajout du tag rediff (1, 2 ou 3) sur la grille.
- Ajout sur la grille, émissions régulières, du champs de recherche dans titre.
- Ajout du changement de catégorie sur un créneau.
- Ajout de "plus d'émissions" si besoin.
### Correction
- Correction du manque de fiabilité de la propagation et de la suppression des redifs d'émission quand elles sont attribuées à un créneau.
- Correction du placement multiple des émissions non régulière qui ne mettait pas à jour la date et l'heure.
- Correction du système de règle, maintenant les rediff sont calculées à partir de la première diffusion.
- Suppression du tag ponctuelle.
- Séparation du twig grille en deux pages car trop lourd pour une seule.


## [1.0.95] - 2026-05-01
### Ajout
- Ajout du n° de la semaine sur la grille.
### Correction
- Correction du calcul des semaines paires ou impaire en tenant compte de la semaine radio (du mardi au lundi).


## [1.0.94] - 2026-04-28
### Correction
- Correction du formulaire edit emission qui ne se validait plus à cause d'un bug de token csrf.


## [1.0.93] - 2026-04-23 bis
### Ajout
- Suppression dans la grille des émissions non régulière.


## [1.0.92] - 2026-04-23
### Ajout
- Ajout de la gestion des émissions non régulières.
### Correction
- Correction de la requête lastemission qui faisait planter la page d'accueil public.


## [1.0.91] - 2026-04-22
### Ajout
- Ajout de l'ajustement de la programmation en cas de créneau bancal à cause des mois coupés.
### Correction
- Changement de type pour editeur de int à entity et modif du code des pages associées.
- Modification du style de la page index émission pour uniformiser le visuel du site.


## [1.0.90] - 2026-04-21
### Ajout
- Gestion des conflits de créneaux dans la grille et leurs visualisation.
### Correction
- Correction de l'affichage de texte des créneaux et du tooltip associé pour une meilleure lisibilité sur les créneau d'un quart d'heure.


## [1.0.89] - 2026-04-20
### Ajout
- Ajout des jours de la semaine sur les colonnes de la grille.
### Correction
- Agrandissement de la grille pour une meilleure visibilité.
- Modification de la navigation des semaines de la grille.
- Modification des boutons régulière/non régulière pour une meilleure experience ux.
- Correction de la hauteur des créneaux qui dépassaient de leur taille prévue.
- Centrage vertical des heures à gauche de la grille.


## [1.0.88] - 2026-04-19 bis
### Correction
- Changement pour le mail, passage de smtp à api de mailjet.

## [1.0.87] - 2026-04-19
### Correction
- Correction de l'affichage du nombre de créneaux existant pour une règle.
- Correction du DSN mailjet afin que les mail systèmes du site fonctionnent (changement de mot de passe).


## [1.0.86] - 2026-04-18
### Ajout
- Ajout de la gestion des émissions auto-générées direct sur la grille.
- Ajout de l'affichage des émissions auto-générées direct sur le tableau de bord du users et des super-admin.


## [1.0.85] - 2026-04-17
### Ajout
- Automatisation de l'affichage de l'éditeur et de la durée lors du choix de la catégorie sur le form de creation d'émission.
- Ajout de la différenciation des règles de programmations.
- Ajout de la création d'une fiche émission autogénérée pour le direct, sa propagation et sa suppression.


## [1.0.84] - 2026-04-12
### Ajout
- Ajout de la propagation des émissions programmées (choisir une émission en première diffusion la place automatiquement sur les créneaux de rediffusions prévues par les règles).
- Ajout de la programmation par semaine paire ou impaire.
### Correction
- Tri des ancien·nes animateur·ices par ordre alphabétique sur le formulaire émission.


## [1.0.83] - 2026-04-10
### Ajout
- Ajout du thème éducation dans un groupe de thème.
### Correction
- Correction du form emission qui supprimait la catégorie si elle était inactive.
- Correction des pages recherche qui sortaient plus de page que de résultats.
- Correction de la duplication des champs date en cas de retour arrière sur le form recherche.
- Correction du reset du form recherche (encore imparfait).


## [1.0.82] - 2026-04-09
### Ajout
- Ajout du générateur de programmation.
- Ajout de la liste des émissions possible quand on clic sur un créneau.
- Ajout de la grille temporaire diffusionDraft.
- Ajout de la suppression d'une émission sur un créneau.
### Correction
- Mise à jour de la base de données le 7 avril 2026.


## [1.0.81] - 2026-04-06
### Ajout
- Ajout récurrence bimestrielle et bimensuelle pour la programmation prévisionnelle.


## [1.0.80] - 2026-04-05 quar
### Ajout
- Ajout de la récurrence mensuelle pour la programmation prévisionnelle + controller.js correspondant.


## [1.0.79] - 2026-04-05 ter
### Ajout
- Ajout de la programmation récurante prévisionnelle (entity, repository, controller et twig).


## [1.0.78] - 2026-04-05 bis
### Correction
- Ajustement du css des cartes émission de lastEmission.


## [1.0.77] - 2026-04-05
### Correction
- Correction du css des cartes émission de lastEmission.


## [1.0.76] - 2026-04-01
### Correction
- Correction des liens titre des émission sur la page de recherche admin.
- Correction du CSS des listes d'émissions côté admin et ajout des bouton modifier en supprimer.


## [1.0.75] - 2026-03-31
### Ajout
- Ajout de la fonction regexpFunction.php pour la gestion des regex sur la recherche d'émission.
### Correction
- Correction de la recherche sur le champs ref.

## [1.0.74] - 2026-03-30
### Ajout
- Ajout de la possibilité de recherche sur un user ou ancien animateurice (public et admin).
### Correction
- Correction du css de la pagination admin.
- Correction de la recherche de mot court.
- Optimisation de la recherche lors de plusieurs mots.


## [1.0.73] - 2026-03-26
### Ajout
- Rajout des imagesTags (entity, form, controller, repository, twig index/create/edit/form responsive).
- Ajout de la suppression du fichier mp3 de la fiche émission.
### Correction
- Modification de la navbar admin pour intégrer les imagesTags.
- Modification de la structure du tag titre pour correspondre à la charte RZ.
- Modification de la structure de l'url pour correspondre à la charte RZ.


## [1.0.72] - 2026-03-25
### Ajout
- Prise en charge de l'écriture des tags sur les fichiers Mp3 uploadés (sauf imageTags).


## [1.0.71] - 2026-01-24
### Correction
- Réparation du formulaire de création des émissions.


## [1.0.70] - 2026-01-19
### Ajout
- Ajout de la visibilité des mots de passes sur les formulaire de création de compte et de reset de mot de passe.
- Ajout de la page profil qui permet aux utilisateurs d'utiliser un nom au lieu de son identifiant de compte et de changer son email si besoin.
### Correction
- Correction des flèches du carrousel qui avaient disparues en mode desktop.
- Modification du mailer_DNS avec mailjet (vrai envoi de mail) mais ça ne fonctionne pas en local, à voir en prod ce que ça donne puisqu'il y a un rebuild du container.


## [1.0.69] - 2026-01-04
### Correction
- Correction de la gestion des erreurs de formulaire pour catégorie


## [1.0.68] - 2026-01-04
### Ajout
- Gestion de multiple propriétaires des émissions.
- Gestion des erreurs de formulaire pour catégorie et émission.
- Ajout d'une ancre pour la recherche côté public (échec, mais renfort du formulaire car les contraintes de catégorie s'appliquaient sur la recherche par erreur).
### Correction
- Modification de admin/categorie/show sur le modèle du public.
- Modification de admin/emission/show sur le modèle du public.
- Modification des formulaires edit et create des émissions et ajout du responsive.
- Modification de la navbar admin pour intégrer le responsive.


## [1.0.67] - 2026-01-02
### Ajout
- Gestion de multiple propriétaires des catégories.
### Correction
- Modification des formulaires edit et create des catégories et ajout du responsive.
- Modification des formulaires edit et create des invités et ancien·nes animateurices et ajout du responsive.
- Seul le prénom est obligatoire pour les invités et ancien·nes animateurices dans les formulaires.


## [1.0.66] - 2025-12-30
### Ajout
- Gestion des mots de passe oubliés.
- Possibilité d'afficher son mot de passe quand on le tape.
- responsive sur ces pages.


## [1.0.65] - 2025-12-30
### Correction
- Correction responsive et uniformisation des listes côté public, correction responsive d'évènements et du lecteur.


## [1.0.64] - 2025-12-27
### Correction
- Correction du CSS coté public (tout les partials, les éditables, navbar, annonces, emissions recherche, login, register), restructuration de l'architecture du CSS (factorisation dans base + editable.css, personnalisation possible sur les pages elles même).


## [1.0.63] - 2025-12-18
### Correction
- correction du yml vitch pour suppression correcte des fichiers entêtes des pages éditables.(ter)


## [1.0.62] - 2025-12-18
### Correction
- correction du yml vitch pour suppression correcte des fichiers entêtes des pages éditables.(bis)


## [1.0.61] - 2025-12-18
### Correction
- correction du yml vitch pour suppression correcte des fichiers entêtes des pages éditables.


## [1.0.60] - 2025-12-18
### Ajout
- Ajout du responsive pour le menu, ondes, vagues, footer, lecteur et lastemission côté public.
- Ajout de la partie grille côté public (en cours).
### Correction
- correction des formulaires d'édition des pages pour réparer la suppression de l'image de tête.


## [1.0.59] - 2025-12-15
### Correction
- Correction de la page edit qui empêchait la validation de la page.


## [1.0.58] - 2025-12-15
### Ajout
- Ajout du css de la page d'index d'admin.
- Ajour de la barre de menu dans l'éditeur tinymce pour l'édition des pages.
- Ajout de la mise en page de la page index côté admin (page d'acceuil).
### Correction
- Correction de la page zone d'écoute qui ne prenait pas en compte les valeurs du formulaire d'édition.


## [1.0.57] - 2025-12-14
### Correction
- Correction du bug qui empêchait la validation des formulaires d'édition de pages.
- Correction de l'affichage du bouton supprimer l'image sur le formulaire d'édition qui apparaissait même s'il n'y avait pas d'image.


## [1.0.56] - 2025-12-14
### Correction
- Correction du controller js de tinymce afin que la preview fonctionne correctement en prod.
- Correction du bouton supprimer l'image des pages éditable qui apparait quand il ne faut pas.


## [1.0.55] - 2025-12-14
### Ajout
- Ajout du visuel de preview pour les pages éditables identique aux pages côté public.
- Ajout du CSS des pages éditables côtés admin.
- Ajout de toutes les pages éditables.
### Correction
- Blocage dur slug en édition afin de ne pas casser les liens.
- Possibilité de créer de nouvelles pages bloquée pour l'instant.


## [1.0.54] - 2025-12-12
### Correction
- Correction du controller js tinymce pour corriger les erreurs d'upload d'image.


## [1.0.53] - 2025-12-12
### Correction
- Correction du docker-compose.prod pour persister les nouveaux dossiers images des pages éditables.


## [1.0.52] - 2025-12-12
### Ajout
- Création de l'édition des pages fixes du site côté admin. Création du menu, de la page d'index, de la page de création et de la page d'édition.
- Modification de la base de données en conséquence, ajout de la table page.
- Modification du module tinymce pour qu'il gère l'upload de photos et de vidéos.
### Correction
- Correction d'attribut d'entity concernant la base de donnée. (datetime mutable -> datetime) dans un soucis de solidité des données.


## [1.0.51] - 2025-11-22
### Ajout
- MAJ de la base de donnée au 21 novembre 2025
### Correction
- MAJ de la page don


## [1.0.50] - 2025-08-16
### Correction
- Correction de admin.html.twig auquel il manque un block stylesheets.


## [1.0.49] - 2025-08-16
### Correction
- Correction des reste d'importmap pour réparer le côté admin.

## [1.0.48] - 2025-08-16
### Correction
- Correction des images qui ne marchent pas en prod, suppression de assets/images pour cause de conflit (5ème essai).


## [1.0.47] - 2025-08-16
### Correction
- Correction des images qui ne marchent pas en prod, suppression de assets/images pour cause de conflit (4ème essai).


## [1.0.46] - 2025-08-16
### Correction
- Correction des images qui ne marchent pas en prod (3ème essai).


## [1.0.45] - 2025-08-16
### Correction
- Correction des images qui ne marchent pas en prod (second essai).


## [1.0.44] - 2025-08-16
### Correction
- Correction des images qui ne marchent pas en prod.


## [1.0.43] - 2025-08-16
### Correction
- Correction live component qui ne marche pas en prod.


## [1.0.42] - 2025-08-15
### Correction
- Correction du css de la liste d'émissions de la page showCat.
- Correction de la page show (filtrage avec livecomponent et pagination en dynamique) et css de la pagination.


## [1.0.41] - 2025-08-08
### Correction
- Correction du lien titre dans les résultats de recherche public qui envoyait côté admin et recherche admin qui envoyait dans public.
- Suppression de l'attribution user_id si pas de user en cas d'édition.
- Modification de la navbar, fusion d'émissions et recherche.
- Correction de la navbar public, Annonces n'était pas active (en rouge) quand on était sur ces pages.
- Correction de admin/categories pour que le user soit affiché.


## [1.0.40] - 2025-08-07
### Ajout
- Ajout du softDelete sur categories, annule la redirection sur ce bouton (conflit controllerjs).
- Ajout du user_id dans catégories, modification en conséquence des méthodes, controller et navbar.
### Correction
- Correction de l'erreur 500 de la page Annonce côté admin.
### BUG
- la liste émissions est plantée, en cours de réparation.


## [1.0.39] - 2025-08-03
### Ajout
- Ajout des annonces et du mot recherche dans la navbar publique.
- Ajour de le page newsletter, changement dans vagues de la case "aide à l'écoute" (en doublon avec le footer).
### Correction
- Factorisation de la liste des émissions de recherche dans le but de l'intégrer sur d'autre pages (modèle liste émissions de recherche).
- Correction de la page de présentation des catégories.


## [1.0.38] - 2025-08-01
### Ajout
- Ajout de la page admin/evenement/show qui avait été oublié.
### Correction
- Correction css de showevenement.
- Correction du controller tinymce qui n'était pas parfaitement opérationnel ( bug du champ descriptif sur les formulaire ).
- Gestion de la redirection après suppression d'émissions, annonces, évènements ou de catégories.


## [1.0.37] - 2025-07-24
### Correction
- Correction du formulaire d'édition d'émission, si pas d'animateur·ice référent·e indiqué, prend le nom de la personne qui édite l'émission, si le descriptif est vide, met "descriptif à remplir".
- Correction de la redirection des pages après validation de formulaire edit.


## [1.0.36] - 2025-07-24
### Ajout
- Ajout de la recherche côté admin, selectionne aussi les émissions sans fichier audio.
### Correction
- Correction de la logique d'affichage des émissions côté admin, maintenant une émission qui n'a pas de fichier son, mais qui a un user s'affiche(j'espère !).
- Correction de la couleur de la bordure de la catégorie sur la page home/show (du rouge vers le noir).


## [1.0.35] - 2025-07-23
### Ajout
- Ajout du responsive pour le lecteur.
### Correction
- Correction des noms de groupe de thèmes.
- Correction côté admin de l'affichage des émissions n'ayant pas d'url mais ayant un user.
- Déplacement du menu invité ancien·nes animateur·ices dans le groupe admin droit user.


## [1.0.34] - 2025-07-23
### Ajout
- Ajout d'un début de responsive sur la navbar publique (en attente de retour des graphistes).
### Correction
- Correction de la logique d'affichage des émissions côté admin, maintenant une émission qui n'a pas de fichier son, mais qui a un user s'affiche.
- Correction de la navbar publique (espacement avec le bord sur les icone de zinzine et la loupe de recherche).
- Uniformisation des tailles sur les balise h1 et h3 de la page d'accueil, décalage des images à gauche du titre.
- Réduction à 3 lignes des descriptifs dans les listes d'émissions (home/emission, admin/emission, home/show).
- Fin de refonte du home/show selon la maquette de Fernande.


## [1.0.33] - 2025-07-22
### Correction
- Hotfix affichage des émissions côté admin.

## [1.0.32] - 2025-07-22
### Correction
- bug de migration de base de donnée changement des annotations entity diffusion.


## [1.0.30] - 2025-07-22
### Correction
- Correction de la recherche (bug de date obligatoire) et classement par date de diffusion decroissante avec affichage de la date de diffusion.
- Optimisation de certaine requêtes car la table diffusion est très lourde et ça ralentissait beaucoup le site (à vérifer).
- Correction de la requête qui empêchait d'aller dans le menu émission du site publique.
- Début de refonte de la page home/show.html.twig selon le modèle de Fernande (à suivre).
- Modification de la base de donnée entity emission et diffusion pour optimisation.


## [1.0.29] - 2025-07-21
### Ajout
- Ajout de la gestion de l'affichage de categorie.show si elle n'a pas d'émission.
- Ajout de la diffusion : Entity, Controller, Form, Repository, et les templates.
- Ajout de la table et des données de diffusion (migration ).
### Correction
- Correction des templates affectés par l'affichage de la diffusion.
- Correction des formulaires create et edit (url null, si ref vide user_id).
- Correction du formulaire de recherche qui forçait à mettre des dates.


## [1.0.28] - 2025-07-20
### Ajout
- Ajout des alt sur les images de la navbar côté auditeurices.
- Ajout des controllers tinymce et flatpickr.
- Ajout d'une favicon.ico.
### Correction
- Correction du partial vague qui soulignait les liens.
- Correction de l'implémentation de tinymce, conflit stimulus et webpack helper.
- Corrections des fichiers docker en conséquence.


## [1.0.26] - 2025-07-20
### Ajout
- Ajout d'un bouton supprimer pour les émissions de la catégorie archive (visible uniquement pour superadmin).
- Ajout d'une nouvelle règle de nommage des fichiers uploadés afin d'éviter les bugs de charactères spéciaux (vitch).
### Correction
- Correction de la redirection de page quand on validait un changement sur une émission ou une catégorie, maintenant on se retrouve sur la page précédent le formulaire et pas sur la première page de la liste.
- Correction de l'affichage des images en background cartes de lastemission contenant un charactère spécial.
- Factorisation du code de la page categorie.show pour éviter des doublons.
- Correction du css de la page admin.emission.show, le blanc de la partie catégorie ne descendait pas jusqu'en bas.

## [1.0.25] - 2025-07-19
### Base de données
- Récupération de la base de données réelle pour tests de script d'adaptation.
### Correction
- Correction du glide.js avec stimulus pour que le chargement du carrousel soit plus fluide.
- correction du coupage automatique des titre d'émission sur l'affichage de lastemission et carrousel.


## [1.0.24] - 2025-07-18
### Ajout
- Ajout du bouton pause sur le lecteur et le js nécessaire pour que ça fonctionne.
- Ajout d'un lien vers le site publique sur le logo radio zinzine de la navbar côté admin.
- Ajout de Node.js.
### Correction
- Correction des groupes de thèmes (rajout de /).
- Correction de l'édition des évènements qui ne préremplissait pas le champ titre du formulaire.
- Correction des formulaires utilisant tinymce avec un controller stimulus afin d'éviter le rechargement de la page à cause de turbo.
- Correction des filtres dynamiques des thèmes sur la page home/show.


## [1.0.23] - 2025-07-16
### Serveur
- Modification des droits du dossier uploads pour permettre le chargement des images.
### Correction
- Correction des groupes de thèmes selon la nouvelle proposition.


## [1.0.22] - 2025-07-15
### Correction
- Correction du js de la page home/show.html.twig qui avait besoin d'un refresh pour fonctionner (pb de turbo:load)


## [1.0.21] - 2025-07-15
### Ajout
- Ajout de la logique de groupe de thème pour le partial lastemissionbytheme qui devient lastEmissionsByGroupTheme.
- Ajout du js pour le filtrage dynamique par thème dans la page home/show.html.twig.
### Correction
- Correction du formulaire de création de thème (problème d'updateAt et de dateimmutable).
- Correction de la gestion de l'affichage des descriptions d'émission sur la page emission home/show.html.twig qui pouvaient dépasser du cadre et déformer la page.
- Modification de la base de donnée pour que chaque émission n'ai qu'un seul thème.


## [1.0.20] - 2025-07-07
- Ouverture du site en démo aux zinzinien·nes.
### Ajout
- Ajout du contenu provisoire pour la page infos.
- Ajout du widget grille hebdomadaire de libretime dans la partie programme (mais c'est moche ...). 


## [1.0.19] - 2025-07-06
### Ajout
- Ajout du bouton télécharger sur la liste des émission en bas de page home/show.html.twig.
- Ajout du tableau infos diverses, horaire de passage, liens, dans la partie émission de la page home/show.html.twig.
- Ajout d'un contenu provisoire pour les pages aide à l'écoute et zone d'écoute (en attente de Klaus) et contacts (en attente de Joëlle).
### Correction
- Correction de la couleur des H1 et des liens a et a:hover dans la page home/amis.html.twig et modification de la hierarchisation des titres H.
- Correction du css de la page don.
- Correction de la gestion de l'affichage des images sur la page emission home/show.html.twig.
- Correction des bloc title des pages aides à l'écoute, zone d'écoute et contact.


## [1.0.18] - 2025-07-06
### Ajout
- Ajout de titre de pages côté admin.
### Correction
- Correction du css de la page Mentions légales.
- Correction de la page home/show.html.twig et du controller EmissionShowController.php pour coller à la vision de Nick. Transfert de la catégorie dans le haut de la page pour laisser la place à la liste des émissions ayant un lien, sur le même thème, du plus récent au plus ancien avec le css associé et le responsive. En prévision du changement de gestion des thèmes et leurs affichages.
- Correction du menu de navigation côté auditeurs qui ne mettait plus en avant le menu dans lequel on était (page active, menu en rouge).
- Correction alignement de la colonne 2 des annonces (à revoir ne prend pas toute la place disponible).
- Correction des block title côté admin.


## [1.0.17] - 2025-07-05
### Ajout
- Ajout du changelog en dynamique sur le tableau de bord du côté admin.
### Correction
- Factorisation de tinymce dans admin.html.twig.
- Correction centrage menu déroulant navbar admin + problème de conflit css avec pagination.
- Correction du rappel des données de catégorie et thème dans le formulaire émission create et edit.


## [1.0.16] - 2025-07-04
### Correction
- Correction du conflit entre turbo (fixation du lecteur en header) et la page recherche problème de chargement du js(suite et fin !).


## [1.0.15] - 2025-07-04
### Correction
- Correction du conflit entre turbo (fixation du lecteur en header) et la page recherche.


## [1.0.14] - 2025-07-04
### Correction
- Correction du conflit entre turbo (fixation du lecteur en header) et les pages login et registration.