# CalendrierSP

Plateforme multi-amicales de gestion des tournées de calendriers, avec une interface web connectée à Symfony et PostgreSQL.

## Contenu
- `backend/` : Symfony + API Platform + Doctrine, exécutable avec PHP/Apache
- `frontend-web/` : React + TypeScript + Vite, servi par Nginx
- `mobile/` : maquette mobile d’origine ; le parcours est intégré dans `../MobileSP/`
- `docker-compose.yml` : PostgreSQL/PostGIS, backend et frontend web
- `docs/` : architecture, règles métier et synchronisation offline

## Important
Le web utilise exclusivement les données de l’API : connexion, secteurs, adresses, comptes des sapeurs, tournées, carnets et reçus, affectations, saisie/correction des passages, clôture, statistiques par campagne et paramètres de l’amicale. Les données sont conservées dans PostgreSQL ; une base neuve affiche des listes vides. Les erreurs réseau sont affichées et les écritures sont confirmées uniquement après réponse du serveur.

Le module mobile est intégré dans l’onglet **Calendriers** de MobileSP et utilise l’API pour l’authentification, les tournées, les adresses, les passages et les reçus. Une connexion réseau est nécessaire ; la synchronisation offline reste à implémenter. Les travaux restant avant exposition en production sont suivis dans `docs/PRODUCTION_CHECKLIST.md`.

## Installation sur un VPS

Le [guide VPS OVH](docs/VPS_OVH.md) décrit l'installation sur un serveur Ubuntu : Docker, PostgreSQL, CalendrierSP, MobileSP web, HTTPS, reprise des données, sauvegardes et services cartographiques facultatifs. Si Nginx est déjà installé, suivre la [publication MobileSP avec Nginx](docs/MOBILESP_NGINX.md).

La [configuration Nginx pour mobilesp10.fr](docs/NGINX_MOBILESP10.md) sépare MobileSP, l'administration `amicale-calendriers.mobilesp10.fr` et l'API `api-calendriers.mobilesp10.fr`, avec proxy vers les conteneurs Docker.

## Démarrage avec Docker
Docker et Docker Compose v2 suffisent pour le backend et le web. Depuis ce dossier :

```bash
docker compose up -d --build --wait
docker compose exec --user www-data backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec --user www-data backend php bin/console app:create-admin
docker compose ps
```

| Service | Adresse locale |
|---|---|
| Interface web | http://localhost:8080 |
| API via le web | http://localhost:8080/api |
| API directe | http://localhost:8000/api |
| État du backend et connexion PostgreSQL | http://localhost:8000/health |
| PostgreSQL/PostGIS | `localhost:5432` |

La commande `app:create-admin` demande le nom de l’amicale, son **identifiant de connexion choisi**, son département, l’e-mail administrateur et un mot de passe de 12 caractères minimum, saisi de façon masquée. L’identifiant contient **4 à 20 lettres non accentuées ou chiffres**, sans espace ni signe. Il est unique sur la plateforme, enregistré en minuscules et accepté sans distinction de casse à la connexion. Il peut aussi être fourni en option :

```bash
docker compose exec --user www-data backend php bin/console app:create-admin --identifiant=troyes10
```

La commande affiche cet identifiant à utiliser avec l’e-mail et le mot de passe. Pour les amicales existantes, la migration génère un identifiant à partir du nom (avec un suffixe en cas de doublon), sans modifier leurs données ni leurs références internes. L’administrateur peut consulter et modifier l’identifiant dans **Paramètres** ; les prochaines connexions utilisent alors le nouvel identifiant, les sessions déjà ouvertes restent valides. L’ancien UUID ne sert plus à se connecter. Aucun compte ou jeu de données de démonstration n’est créé automatiquement. Les autres comptes se créent ensuite dans **Sapeurs**.

Pour commencer : créer un **Secteur**, ses **Adresses**, puis une **Tournée**. Ouvrir la tournée pour affecter les sapeurs et saisir les passages. Les résultats alimentent immédiatement le tableau de bord de l’année correspondante. Un sapeur consulte et renseigne uniquement ses tournées affectées ; un responsable gère les données de son amicale ; seul un administrateur crée des comptes et modifie les paramètres. Une tournée terminée peut être validée, ce qui verrouille ses écritures.

Dans le détail d’une tournée, **Ajouter des sapeurs à l’équipe** permet de cocher plusieurs comptes actifs de l’amicale, puis de cliquer sur **Ajouter à l’équipe** pour les affecter ensemble. Les membres déjà affectés sont conservés et ne sont plus proposés à l’ajout. Chaque sapeur de l’équipe peut consulter la tournée et saisir ses passages, sur le web et le mobile. **Retirer de l’équipe** retire un membre individuellement, en conservant ses saisies. Une tournée validée verrouille les affectations.

Les résultats de passage proposés sont **Vente**, **Don**, **Refus**, **Absent**, **À revoir** et **Non visitée**. Le résultat **Don** demande la sélection du carnet et du reçu, puis propose un commentaire facultatif : aucune quantité, aucun montant ni moyen de paiement n’est demandé. Il compte comme une adresse traitée et un calendrier donné, sans augmenter les encaissements.

Le tableau de bord, le récapitulatif de campagne et le détail d’une tournée affichent deux cartes : **Calendriers vendus ou donnés / stock de calendriers**, avec le détail des quantités vendues et des dons, et **Adresses à visiter / total des adresses**, avec le nombre déjà renseigné. Une vente compte sa quantité de calendriers ; un don compte un calendrier. Les tableaux des tournées affichent également les deux rapports. Les adresses à visiter sont celles sans passage ou avec le résultat **Non visitée** ; les autres résultats sont déjà renseignés. Les totaux d’une campagne additionnent les tournées de l’année : une même adresse présente dans deux tournées compte une fois pour chaque tournée. Un stock nul affiche **Stock à renseigner** ; les compteurs restent visibles sans division par zéro.

### Sapeurs et équipes

Dans **Sapeurs**, la création demande un **Nom** en plus de l’identifiant e-mail. **Modifier le nom**, réservé à l’administrateur, permet aussi de compléter les comptes existants. L’équipe des tournées et les choix d’affectation affichent ce nom sur le front web et dans MobileSP. Tant qu’un compte ancien n’a pas de nom, son e-mail reste affiché. La migration `Version20261113120000` ajoute le champ sans modifier les identifiants, mots de passe, affectations ou passages existants.

### Saisie et recherche dans une tournée

Sur le front web et dans MobileSP, **Afficher les filtres et le tri** développe les contrôles, masqués par défaut. Les replier conserve la sélection et leur bouton indique le nombre de filtres actifs. Sur téléphone, le tableau du front s’affiche en fiches sans défilement horizontal. Les actions **Saisir / Modifier** et **＋ Appartement** utilisent des boutons courts ; dans MobileSP, ils sont regroupés sur une ligne qui revient à la ligne si nécessaire, sans répéter l’adresse dans le texte des boutons.

Une vente accepte les paiements **Espèces**, **Chèque**, **CB** ou **Autre**. Le tableau affiche les résultats en vert pour une vente ou un don, en rouge pour un refus, et en orange pour une absence ou une adresse à revoir ; le libellé reste visible. Les filtres par **rue**, **résultat** et **carnet** se combinent avec une recherche par **numéro exact de reçu**. Le tri par adresse regroupe d’abord les **rues**, puis compare la **partie numérique du numéro** dans chaque rue : `2` précède `171`, et `3`, `3t` et `3 ter` ont la même valeur. Les numéros équivalents conservent leur ordre d’origine. Les tris par montant et résultat restent disponibles dans les deux sens, via les en-têtes ou les sélecteurs. Un compteur indique le nombre d’adresses affichées ; les filtres sont conservés après une saisie et peuvent être réinitialisés.

Le formulaire de passage permet de compléter l’adresse avec **bâtiment, étage, appartement et porte**, affichés ensemble dans la cellule **Logement**. Ces précisions sont conservées sur l’adresse du secteur pour les prochaines tournées. Une adresse ayant un passage dans une tournée validée ne peut plus changer de logement, afin de préserver son historique.

**Ajouter un appartement**, sur chaque ligne d’une tournée non validée, reprend l’adresse et ses coordonnées. Les champs bâtiment, étage, appartement et porte sont initialement vides. Renseigner un logement différent, choisir le résultat et enregistrer : une adresse distincte est créée avec son propre passage et, pour une vente ou un don, un nouveau reçu. Chaque nouveau logement reçoit une saisie indépendante pour le résultat, la quantité, le montant, le commentaire et le reçu. L’adresse et le passage sont enregistrés dans la même transaction : un reçu indisponible ou un logement déjà existant bloque toute la création. L’action est accessible aux responsables et aux sapeurs affectés à la tournée. Les adresses importées conservent leur déduplication BAN/OSM ; les appartements n’utilisent pas l’identifiant de source de l’adresse principale.

**Nouvelle adresse**, au-dessus du tableau des passages, permet d’enregistrer une vente exceptionnelle à un numéro ou dans une rue absents du référentiel, même si la tournée ne contient encore aucune adresse. Saisir numéro et rue, code postal, ville, les compléments éventuels et le passage avec son carnet/reçu. L’adresse est ajoutée au secteur de la tournée et le passage est enregistré dans la même transaction. L’action est accessible aux responsables et aux sapeurs affectés, sauf sur une tournée validée. Les filtres sont réinitialisés après l’ajout pour afficher la nouvelle adresse.

Le filtre de rue retire aussi les suffixes de numéro séparés par un espace (`12 T`, `16 B`), accolés (`18B`) et écrits en lettres (`14bis`, `14 ter`), sans modifier les adresses enregistrées. Les variantes de casse et d’espacement du nom de rue sont regroupées.

### Suppression et dépendances

Les listes **Sapeurs**, **Secteurs**, **Adresses**, **Tournées** et **Campagne** proposent un bouton **Supprimer**. Le détail d’une tournée permet aussi de retirer une affectation, supprimer un carnet inutilisé ou supprimer un passage sans reçu. Une fenêtre contrôle d’abord les liens et affiche leurs nombres. Si la suppression est autorisée, une confirmation définitive est demandée ; le serveur revérifie les liens dans la transaction avant de supprimer.

| Élément | Suppression refusée si… |
|---|---|
| Sapeur / compte | Le compte est connecté, affecté à une tournée ou auteur de passages. Suppression réservée à l’administrateur. |
| Secteur | Il contient des adresses ou possède des tournées. |
| Adresse | Un passage la référence ou une tournée utilise son secteur, même sans passage. |
| Tournée | Elle est validée, ou possède des affectations, carnets ou passages. |
| Campagne | Elle possède des tournées ou est définie par défaut dans les paramètres de l’amicale. |
| Carnet | Un reçu a déjà été utilisé (même annulé), un passage le référence, ou sa tournée est validée. |
| Passage | Il référence un reçu, a consommé un reçu dans le passé, ou appartient à une tournée validée. |
| Affectation | La tournée est validée. Retirer un sapeur de l’équipe conserve son compte et ses passages. |

Un carnet entièrement inutilisé est supprimé avec ses reçus ; les reçus ne se suppriment pas individuellement, afin de conserver les plages numérotées. Les autres liens ne sont pas supprimés automatiquement. Pour supprimer une tournée inutilisée, retirer d’abord ses affectations et ses carnets inutilisés. Les passages ayant consommé un reçu conservent leur historique, y compris après correction en refus ou absence. Un responsable peut supprimer les données métier de son amicale ; seul un administrateur peut supprimer un compte. Les suppressions sont protégées par session, CSRF, contrôle d’amicale, verrous et contraintes en base.

Les campagnes sont désormais enregistrées par amicale et année. **Campagne → Ajouter une campagne** crée une année vide, supprimable tant qu’elle n’est pas utilisée. Une tournée crée automatiquement la campagne de sa date de début si nécessaire ; définir une nouvelle année par défaut dans les paramètres la crée aussi. La migration reprend les années des tournées existantes et la campagne par défaut, sans supprimer les données historiques. Supprimer une campagne vide retire son entrée de la liste des campagnes.

### Secteurs sur carte et extraction des adresses

Dans **Secteurs**, créer un secteur ou ouvrir **Carte et adresses**. La carte Leaflet utilise le fond OpenStreetMap. Rechercher une ville ou une rue, choisir le résultat, puis cliquer sur **Dessiner un polygone**. Chaque clic ajoute un sommet ; les points peuvent être déplacés. Terminer le polygone, puis enregistrer le secteur. Le tracé peut ensuite être modifié ou effacé.

Dans **Extraire les adresses**, la source proposée par défaut est la **Base Adresse Nationale (France)**. Lancer la recherche dans le polygone enregistré : aucun nom de commune ni code postal n’est nécessaire. Le serveur détecte les départements concernés, lit leurs fichiers d’adresses complets et conserve les points dans le polygone. Cette extraction ne dépend pas d’une recherche de lieu limitée à quelques résultats. Les fichiers sont téléchargés et vérifiés intégralement, puis conservés en cache pendant 24 heures. La carte reste un fond OpenStreetMap.

**OpenStreetMap via Overpass** reste sélectionnable, notamment hors de France. Cette source fournit les objets portant un numéro et une voie (`addr:street` ou `addr:place`) et peut être temporairement saturée. Dans ce mode, le code postal et la ville facultatifs complètent les valeurs absentes. Les bâtiments utilisent le centre de leur emprise. Les objets sans voie ou coordonnées exploitables sont ignorés et signalés.

L’aperçu indique la source, les départements BAN, les points sur la carte, les nouvelles adresses et les doublons. **Importer** enregistre les nouvelles adresses dans PostgreSQL ; elles sont alors disponibles dans les tournées du secteur. Les adresses existantes sont conservées, y compris si le tracé change. La déduplication vérifie l’identifiant de source (`ban:…` ou `osm:…`) et l’adresse normalisée au sein du secteur. Deux sources décrivant différemment une même adresse peuvent nécessiter une vérification manuelle ; les secteurs qui se chevauchent peuvent chacun contenir la même adresse.

Les polygones simples sont limités à **500 sommets et 100 km²**, sans trou ni croisement. Une extraction accepte au maximum 10 000 points candidats et, pour la BAN, quatre départements. Les limites départementales officielles simplifiées sont élargies de 500 mètres pour charger aussi les départements voisins, puis les adresses sont filtrées précisément avec PostGIS, bord du polygone compris. L’aperçu reste valable 15 minutes ; modifier le polygone oblige à le refaire. Une erreur, un fichier tronqué ou une réponse partielle ne déclenche aucun import.

La couverture dépend du référentiel : la BAN et OpenStreetMap peuvent avoir des adresses absentes ou mal positionnées. L’extraction ne garantit pas tous les logements réels d’un immeuble et ne reconstitue pas les numéros manquants par interpolation. Les adresses sans code postal ou ville restent importables et sont signalées dans l’aperçu.

La recherche de villes, rues et adresses utilise le [service officiel de géocodage](https://adresse.data.gouv.fr/outils/api-doc/adresse) alimenté par la Base Adresse Nationale, via `https://data.geopf.fr/geocodage/search` (Géoplateforme). Elle fonctionne sur demande explicite, sans autocomplétion, avec cache de 24 heures et limitation à une requête par seconde par instance backend. Pour une rue, préciser la ville : « rue Blanqui, Troyes » propose notamment **Boulevard Blanqui 10000 Troyes**, puis un clic centre la carte. Le fond de carte reste OpenStreetMap. `OVERPASS_URL`, `GEOCODER_URL` (API officielle renvoyant du GeoJSON), `BAN_URL` (répertoire des exports `adresses-XX.csv.gz`) et `BAN_DEPARTMENTS_URL` (contours GeoJSON officiels Etalab) sont configurables dans `.env`. Pour un usage soutenu, dimensionner les services et le stockage du cache. Les données BAN et les contours administratifs sont sous [Licence Ouverte](https://adresse.data.gouv.fr/donnees-nationales) ; le fond de carte et la source OSM sont sous [licence ODbL](https://www.openstreetmap.org/copyright).

### Carnets et reçus

Dans le détail d’une tournée, un responsable peut **Ajouter un carnet** en renseignant son numéro, le numéro du premier reçu et le nombre de reçus (**25 par défaut**). Les reçus sont créés automatiquement avec des numéros consécutifs : un carnet commençant au n° 100 et contenant 25 reçus couvre les n° 100 à 124. Le détail du carnet affiche chaque reçu et son état.

Les numéros de carnets et de reçus sont uniques au sein de l’amicale, toutes tournées et campagnes confondues. Les plages de reçus qui se chevauchent sont refusées. Chaque carnet appartient à une seule tournée. Les numéros de reçus sont des entiers positifs ; la taille d’un carnet est configurable entre 1 et 1 000 reçus.

Chaque **Vente** ou **Don** exige la sélection d’un carnet de la tournée et d’un reçu disponible de ce carnet. Un reçu déjà utilisé n’est plus proposé pour un autre passage. La modification d’un passage conserve son reçu ; remplacer ce reçu ou transformer le passage en refus/absence annule l’ancien reçu et ne le rend jamais réutilisable. Les écritures sont verrouillées en transaction pour empêcher deux utilisations simultanées d’un reçu. La validation de la tournée verrouille aussi l’ajout de carnets.

Le tableau de bord présente, par carnet de la campagne sélectionnée, le nombre total de reçus, les reçus utilisés et disponibles, les ventes, les dons, les reçus annulés et le montant encaissé. Les reçus annulés restent comptés dans les utilisés ; seul le montant actuel des passages actifs est inclus. Les ventes historiques sont conservées par la migration, sans numéro de reçu inventé ; leurs reçus doivent être renseignés lors d’une correction.

Le web est compilé avec Vite puis servi par Nginx, qui transmet `/api` au backend sur le réseau Docker. Le navigateur utilise la même origine pour le web et l'API. Les routes React sont servies par `index.html`, y compris lors d'un accès direct à une page.

Compose attend que PostgreSQL puis le backend soient disponibles avant de démarrer le web. Les dépendances PHP et JavaScript sont installées dans les images ; aucune installation locale de Composer ou npm n'est nécessaire.

Pour personnaliser les ports, les identifiants PostgreSQL ou `APP_SECRET`, copier `.env.example` vers `.env` à la racine avant le démarrage. Par défaut, les ports sont accessibles uniquement depuis la machine locale. Les identifiants fournis sont destinés au développement. Les fichiers `.env` locaux sont exclus des images ; Compose transmet la configuration du backend à l'exécution. Un changement d'identifiants PostgreSQL ne modifie pas un volume de base de données déjà initialisé.

Commandes utiles :

```bash
docker compose logs -f backend frontend-web
docker compose exec --user www-data backend php bin/console about
docker compose exec --user www-data backend php bin/console doctrine:schema:validate --skip-sync
docker compose down
```

Le volume `postgres_data` conserve les données après l'arrêt. Les sources sont copiées dans les images : après une modification, relancer `docker compose up -d --build --wait`. Le backend fonctionne avec `APP_ENV=prod` et sans les dépendances Composer de développement.

Le schéma métier est versionné dans `backend/migrations/`. Lors d’une mise à jour, construire les images, arrêter brièvement le web et le backend pour éviter une écriture avec l’ancien modèle, migrer, puis redémarrer :

```bash
docker compose build backend frontend-web
docker compose stop frontend-web backend
docker compose run --rm --no-deps --user www-data backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose up -d --wait
```

## Développement hors conteneurs
Prévoir PHP 8.3+ avec les extensions `intl`, `pdo_pgsql`, `zip`, Composer et Node.js 22+ avec npm. La base peut rester dans Docker :

```bash
docker compose up -d database
```

### Backend
```bash
cd backend
composer install
# Adapter DATABASE_URL dans .env.local si les paramètres PostgreSQL ont changé.
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:create-admin
php -S 127.0.0.1:8000 -t public public/index.php
```

Les dépendances exactes sont conservées dans `backend/composer.lock`. Pour une évolution du modèle, générer une nouvelle migration avec `php bin/console doctrine:migrations:diff` sur les sources locales. Le filtre de schéma Doctrine limite ces différences aux tables métier afin de préserver les objets des extensions PostGIS.

### Web
```bash
cd frontend-web
npm ci
cp .env.example .env
npm run dev
```

Le serveur Vite transmet `/api` à `http://127.0.0.1:8000`. Arrêter les services Docker correspondants si leurs ports sont utilisés par les serveurs locaux.

### Vérifications

La construction Docker du web exécute TypeScript et Vite. Les tests HTTP utilisent deux amicales et une base PostgreSQL éphémère séparée, sur le port 18080. Ils couvrent authentification, CSRF, persistance, validation, totaux monétaires, permissions, isolation des amicales, verrouillage des tournées, génération des carnets, unicité et consommation concurrente des reçus, agrégats par carnet, validation des polygones, inclusion géographique, doublons et imports périmés. Ils vérifient aussi les paiements CB, les compléments de logement, la création atomique d’appartements, les suppressions, leurs dépendances et la conservation des reçus utilisés, y compris face à une écriture concurrente. Le test de migration reprend des tournées historiques dans une base jetable et vérifie leur conservation et leur rattachement aux campagnes. L’environnement de test utilise une source cartographique déterministe, sans solliciter les services publics. Le test Chrome couvre le dessin réel, l’aperçu, l’import, la persistance du polygone et les confirmations et blocages de suppression, les couleurs des résultats, les filtres cumulés, la recherche de reçu, les tris et la création d’appartements.

```bash
docker compose -f compose.test.yml up -d --build --wait
python3 backend/tests/api_test.py
python3 backend/tests/migration_test.py
docker compose -f compose.test.yml exec --user www-data backend php tests/amicale_command_test.php
docker compose -f compose.test.yml exec --user www-data backend php bin/console doctrine:schema:validate
# Facultatif : parcours réel dans Google Chrome, avec Node.js 22+ installé.
node frontend-web/tests/browser.mjs
docker compose -f compose.test.yml down
```

Relancer les tests sur une base neuve en arrêtant cet environnement avec `down` avant un nouveau `up`. La base de développement et son volume ne sont pas utilisés par ces tests.

Les contrôles d’identifiant couvrent la création interactive et par option, les limites de 4 et 20 caractères, la casse, les collisions, la migration des noms existants, le changement dans les paramètres et la conservation des sessions et des références. Le navigateur vérifie la connexion en majuscules et la modification persistante de l’identifiant.

Les tests HTTP vérifient également le nom des sapeurs, sa modification par l’administrateur, sa présence dans les profils et les équipes web/mobile et l’isolation des amicales. Après `npm ci` dans `frontend-web`, `npm test` vérifie le tri par rue puis numéro, les suffixes et l’ordre stable des numéros identiques.

### Authentification web

Le web et l’API utilisent la même origine. `GET /api/session` fournit le profil courant et un jeton CSRF ; `POST /api/login` ouvre une session avec `amicaleId`, `email` et `password`. Le cookie est HttpOnly et SameSite=Lax ; toute écriture exige l’en-tête `X-CSRF-Token`. `POST /api/logout` invalide la session. Les mots de passe sont hachés côté serveur. Les réponses API sont privées et ne sont pas mises en cache. HTTPS reste nécessaire en production.

### Mobile
Le parcours mobile est intégré à l’onglet **Calendriers** de MobileSP (`../MobileSP/app/(tabs)/calendrier.tsx`), sur Expo 54. Prévoir Expo Go ou un émulateur Android/iOS. L’application mobile reste exécutée hors Docker.

```bash
cd ../MobileSP
npm install
npx expo start
```

La page `app/(tabs)/calendrier.tsx` charge `components/Calendriers/CalendriersScreen.tsx` dans MobileSP. Le parcours propose la connexion par identifiant d’amicale, e-mail et mot de passe, les campagnes et tournées autorisées, les adresses, la saisie et la correction des passages, les appartements, les nouvelles adresses et les carnets/reçus du serveur. Un sapeur ne voit que ses tournées affectées ; une tournée validée reste consultable et ses écritures sont bloquées.

Une option désactivée par défaut permet de mémoriser l’amicale, l’e-mail et le mot de passe après une connexion réussie, avec l’adresse de l’API. Les champs sont préremplis au prochain lancement ; le bouton **Oublier mes informations de connexion** ou la désactivation de l’option efface la sauvegarde. Android/iOS utilisent Expo SecureStore ; le web conserve ces valeurs sans chiffrement dans le navigateur, comme indiqué avant activation. Chaque tournée affiche aussi les calendriers **vendus ou donnés / stock total**, avec le détail des ventes et dons, actualisé après chaque saisie ou correction.

Configurer l’API dans `../MobileSP/.env.local`, puis redémarrer Expo :

```dotenv
EXPO_PUBLIC_CALENDRIERS_API_URL=http://192.168.1.20:8080/api
```

Remplacer l’IP par celle du poste Docker et le port par `WEB_PORT` de ce projet (`8080` par défaut, `8082` dans la configuration locale actuelle). L’adresse peut aussi être saisie sur l’écran de connexion. Sur un téléphone, `localhost` désigne le téléphone. Pour rendre le frontal accessible sur le réseau local, démarrer avec `WEB_BIND_ADDRESS=0.0.0.0 docker compose up -d frontend-web` ; le téléphone doit pouvoir joindre ce port.

Le client utilise le cookie de session et le jeton CSRF de l’API. Pour Expo web, `MOBILE_WEB_ORIGINS` autorise les origines exactes du navigateur, par défaut `http://localhost:8081,http://127.0.0.1:8081`. Garder le même nom d’hôte pour Expo et l’API pour les cookies SameSite. Ajouter une autre origine dans le `.env` de CalendrierSP et recréer le backend si nécessaire. Pour un déploiement web sur un autre domaine, prévoir un proxy de même origine ou une configuration de cookies adaptée.

Si Expo ouvre MobileSP sur `http://localhost:8083`, ajouter ce port dans le `.env` de **CalendrierSP** :

```dotenv
MOBILE_WEB_ORIGINS=http://localhost:8081,http://127.0.0.1:8081,http://localhost:8083,http://127.0.0.1:8083
```

Reconstruire puis recréer le backend pour charger le gestionnaire CORS et la configuration : `docker compose up -d --build --no-deps backend`. Un simple `restart` ne recharge ni les sources intégrées à l’image ni les variables Compose. Après mise à jour des sources, appliquer aussi les migrations selon la procédure de mise à jour ci-dessus avant le redémarrage. Vérifier `/api/session` plutôt que `/api` : la réponse doit contenir `Access-Control-Allow-Origin: http://localhost:8083` et `Access-Control-Allow-Credentials: true`. Utiliser l’adresse d’API `http://localhost:8082/api` avec la configuration locale actuelle.

L’interface confirme les écritures uniquement après réponse positive du serveur. Une erreur conserve la saisie ; aucune écriture n’est rejouée automatiquement. La synchronisation offline reste à implémenter. Les anciennes sauvegardes de démonstration ne sont pas envoyées au serveur. Le dossier `mobile/` conserve la maquette Expo 53 d’origine et n’est pas importé par MobileSP.

Vérifications du module depuis MobileSP :

```bash
node --test scripts/test-calendriers.cjs scripts/test-calendriers-api.cjs scripts/test-calendriers-connexion.cjs
npx tsc --noEmit -p tsconfig.calendriers.json
```

Le parcours navigateur interprojets utilise un export web de MobileSP et la base éphémère de `compose.test.yml`. Depuis CalendrierSP :

```bash
docker compose -f compose.test.yml up -d --build --wait backend
(cd ../MobileSP && EXPO_PUBLIC_CALENDRIERS_API_URL=http://127.0.0.1:18080/api npx expo export --platform web --output-dir /tmp/calendriersp-mobilesp-web)
node frontend-web/tests/mobile-browser.mjs
docker compose -f compose.test.yml down
```

## Multi-tenancy
Les requêtes métier sont limitées à l’amicale de l’utilisateur authentifié. Le serveur affecte lui-même l’amicale à la création et contrôle les associations `Tournee -> Secteur -> Amicale`, `Passage -> Tournee` et `Passage -> Adresse -> Secteur`. Le sapeur est limité aux tournées auxquelles il est affecté. Voir `docs/SECURITY.md`.
# calendriersp
