# Sécurité multi-amicales

1. L'amicale courante provient exclusivement de l'utilisateur authentifié.
2. Les opérations de collection sont filtrées par amicale.
3. Les opérations item vérifient aussi l'appartenance au tenant : masquer un identifiant UUID ne suffit pas.
4. À la création, le serveur affecte lui-même l'amicale et vérifie la cohérence de toutes les relations.
5. Les utilisateurs ne peuvent consulter que les tournées auxquelles ils sont affectés, sauf rôle de gestion.
6. Une tournée validée est en lecture seule. Une réouverture doit être une action explicite, auditée et réservée au responsable.
7. Les montants sont en `DECIMAL`, jamais en float. Le serveur valide la cohérence montant / quantité / tarif configuré.
8. Ne pas exposer les commentaires libres ou les adresses au-delà du besoin opérationnel.

## Rôles proposés
- `ROLE_SUPER_ADMIN` : administration technique de la plateforme, sans accès métier implicite.
- `ROLE_ADMIN_AMICALE` : administration de son amicale.
- `ROLE_RESPONSABLE` : secteurs, tournées, affectations, clôture.
- `ROLE_SAPEUR` : tournées affectées et saisie terrain.

## État actuel du web et de MobileSP

Les contrôleurs Symfony utilisent les requêtes filtrées de `Api/Workspace.php`. Les contrôles de rôles sont effectués sur chaque opération et les références sont recherchées dans l’amicale du compte authentifié. Les sapeurs ont accès uniquement à leurs tournées affectées. Des tests HTTP négatifs vérifient les collections, références inter-amicales, affectations et écritures de passages. Aucune entité n’est exposée directement comme ressource API Platform.

L’authentification web utilise une session avec cookie HttpOnly et SameSite=Lax, Secure sous HTTPS. Toutes les écritures, y compris connexion et déconnexion, exigent le jeton CSRF de la session. Les mots de passe sont hachés, les comptes désactivés sont refusés et les données API ne sont pas mises en cache.

L’onglet Calendriers de MobileSP utilise les mêmes contrôles de session, CSRF, amicale et affectation. Le client transmet les cookies avec `credentials: 'include'` ; ces cookies restent gérés par le système. La mémorisation des identifiants est désactivée par défaut. Après activation et connexion réussie, l’amicale, l’e-mail et le mot de passe sont conservés avec l’adresse du serveur : dans Expo SecureStore sur Android/iOS, et dans le stockage local du navigateur sur le web. Le stockage web n’est pas chiffré, ce que l’écran précise avant activation ; cette option est destinée à un appareil personnel. Désactiver l’option ou utiliser le bouton d’oubli efface la sauvegarde, sans fermer la session active. Un échec de stockage ou d’effacement est affiché. Pour Expo web, seules les origines explicites de `MOBILE_WEB_ORIGINS` sont autorisées avec les cookies ; SameSite reste applicable. La reprise des cookies et HTTPS sur appareils natifs réels restent à vérifier.

## À compléter avant production

- Limitation de débit sur l’authentification, récupération/changement des mots de passe et journalisation des actions.
- Validation de la session sur appareils natifs réels, synchronisation mobile idempotente et gestion des conflits.
- Réouverture auditée des tournées, contrôle du tarif si l’amicale impose un tarif, tests de charge et de concurrence.
- HTTPS, gestion des secrets, sauvegardes et mesures opérationnelles de `PRODUCTION_CHECKLIST.md`.
