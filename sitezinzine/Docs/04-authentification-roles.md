# Authentification et rôles

> Note — Détails transférés depuis la vue d’ensemble, à partir de la lecture du dépôt du 11 septembre 2026. Les chemins sont relatifs à la racine Symfony sitezinzine/. Cette page ne constitue pas une nouvelle analyse exhaustive du sujet.

[Retour à l’architecture](01-architecture.md)

## Authentification et autorisations

Le firewall principal utilise une connexion par formulaire et un fournisseur Doctrine basé sur `User.username`. Le hachage est configuré en `auto`. Le login active le CSRF, redirige vers l’administration et le logout invalide la session. L’impersonation est activée.

La hiérarchie déclarée est `ROLE_USER` → `ROLE_EDITOR` → `ROLE_ADMIN` → `ROLE_SUPER_ADMIN`, chaque niveau supérieur héritant du précédent ; le super administrateur reçoit aussi `ROLE_ALLOWED_TO_SWITCH`. L’accès global à `/admin` exige `ROLE_USER`, puis des attributs et contrôles des actions renforcent les permissions.

`AccessDeniedHandler` mémorise le premier attribut requis en session et redirige vers la page de refus. `EmailVerifier` délègue signatures et validation à SymfonyCasts VerifyEmail, puis marque l’utilisateur comme vérifié. Le profil possède une adresse en attente, explicitement transmise au service dans le parcours actuel. Le statut de vérification ne doit pas être interprété comme une interdiction globale de connexion : une telle exigence n’apparaît pas dans le firewall lu.

La réinitialisation utilise une entité `PasswordResetToken`, une expiration d’une heure et un email contenant le lien. Les anciens jetons de l’utilisateur sont supprimés lors d’une nouvelle demande ; le jeton utilisé est retiré après changement du mot de passe. Les routes de vérification, les contrôleurs et la configuration doivent être examinés ensemble pour comprendre le parcours complet.

