# Architecture cible

```text
React Web ───────┐
                 ├── HTTPS / JSON REST ───── Symfony + Doctrine ───── PostgreSQL/PostGIS
Expo React Native┘                                      │
                                                        └── workers / exports (évolutions)
```

## Applications
- Web : administration d'une amicale, gestion des secteurs, adresses, campagnes, tournées, affectations et clôture.
- Mobile : consultation des tournées affectées, saisie des passages et ventes, fonctionnement déconnecté puis synchronisation.
- Backend : source de vérité, autorisations, validation des règles métier, calcul des agrégats.

### Intégration dans MobileSP

Le parcours mobile est accessible depuis l’onglet `MobileSP/app/(tabs)/calendrier.tsx`, qui charge le composant `components/Calendriers/CalendriersScreen.tsx`. Il utilise Expo 54 et le routeur de l’application existante. La maquette du dossier `calendriersp/mobile/` reste indépendante et n’est pas importée.

Le module actif utilise `utils/calendriersApi.ts` pour l’authentification par session et CSRF, la lecture de `/api/mobile/tournees` et `/api/mobile/tournees/{id}/adresses`, puis les routes métier de saisie des passages, d’ajout d’adresses/appartements et de création des carnets. Le backend contrôle les affectations et l’amicale ; les carnets, reçus et agrégats proviennent du serveur. Les formulaires React Native partagent les mêmes règles métier que le web, avec vente, don, CB, précisions de logement, filtres et tris.

Une connexion réseau est nécessaire. Le module attend la confirmation serveur avant d’afficher un succès et conserve le formulaire en cas d’erreur. Les anciennes sauvegardes de démonstration sont conservées séparément et ne sont pas importées. `/api/mobile/sync`, le journal idempotent et la gestion des conflits offline restent à réaliser.

`EXPO_PUBLIC_CALENDRIERS_API_URL` dans MobileSP configure l’API, également modifiable dans le formulaire de connexion. Le `.env` racine de CalendrierSP configure Docker et n’est pas chargé par MobileSP. `WEB_BIND_ADDRESS` permet d’exposer le frontal au téléphone sans modifier l’adresse d’écoute de PostgreSQL. Pour Expo web, `MobileCorsSubscriber` autorise les origines explicites de `MOBILE_WEB_ORIGINS`, les cookies et l’en-tête CSRF ; les règles SameSite des cookies continuent de s’appliquer.

## Exécution avec Docker Compose

```text
Navigateur ── localhost:8080 ── frontend-web (Nginx, build React/Vite)
                                      │
                                      └── /api ── backend (PHP 8.3 / Apache)
                                                        │
                                                        └── database:5432 (PostgreSQL 16 / PostGIS)

Client API / mobile ── localhost:8000 ── backend
```

Les trois services sont définis dans `docker-compose.yml`. Le backend est construit à partir de `backend/Dockerfile` avec les extensions `intl`, `pdo_pgsql`, `zip` et OPcache, puis les dépendances Composer verrouillées. Symfony démarre en environnement `prod`, avec le répertoire public comme racine HTTP. Le frontend est compilé dans une étape Node.js 22, puis les fichiers statiques sont copiés dans une image Nginx.

Le navigateur appelle l'API avec le chemin relatif `/api`. Nginx conserve ce chemin et le transmet au service `backend`, ce qui évite une configuration CORS pour le web. Les pages React utilisent un repli vers `index.html`. Le serveur Vite local propose le même relais `/api` vers le port 8000.

Le backend accède à PostgreSQL via le nom DNS Docker `database`, sans passer par le port publié sur l'hôte. Le volume nommé `postgres_data` conserve les données. Le contrôle de santé PostgreSQL précède le démarrage du backend ; l'endpoint `/health` du backend vérifie une requête SQL avant de permettre le démarrage du web. Un contrôle HTTP vérifie ensuite Nginx.

Les ports hôte par défaut sont 8080 (web), 8000 (backend) et 5432 (base). Ils sont liés à `127.0.0.1` et configurables dans le fichier `.env` racine, à partir de `.env.example`. Pour un appareil mobile physique, `localhost` désigne l'appareil : il faut une adresse de la machine accessible depuis celui-ci et une configuration réseau adaptée.

Lancement : `docker compose up -d --build --wait`. Les sources sont intégrées aux images et nécessitent une reconstruction après modification. Les secrets ne sont pas copiés depuis les fichiers `.env` dans les images. Les migrations sont appliquées explicitement avec la console Symfony ; aucun changement de schéma automatique n'est exécuté au démarrage.

Le web charge ses données dans PostgreSQL via les endpoints JSON Symfony. La session authentifiée et son jeton CSRF protègent les appels de même origine ; les requêtes métier sont filtrées par amicale et les sapeurs par affectation de tournée. Le détail des routes, formats et autorisations est décrit dans `API_CONTRACT.md`. La migration initiale est versionnée ; `app:create-admin` initialise une amicale vide et son administrateur.

L’amicale possède un `identifiant` public de connexion, choisi dans `app:create-admin` (saisie interactive ou `--identifiant`), composé de 4 à 20 lettres ASCII ou chiffres et normalisé en minuscules. Un index unique global et une contrainte PostgreSQL imposent ce format. Les UUID internes et toutes les clés étrangères restent stables. La migration génère les identifiants existants à partir du nom, sans accents, avec un suffixe numérique si nécessaire. L’authentification cherche le compte par identifiant d’amicale et e-mail ; l’UUID du compte reste utilisé dans la session. Le changement d’identifiant par l’administrateur dans les paramètres ne coupe donc pas les sessions ouvertes et conserve le filtrage des données par compte authentifié.

## Modèle métier

Les campagnes sont des entités `Campagne` uniques par `(amicale, annee)` ; chaque tournée y est rattachée par une clé étrangère restrictive. La migration matérialise les années historiques et le choix par défaut. La création d’une tournée et la modification des paramètres verrouillent l’amicale avant de créer une campagne manquante. La suppression d’une campagne partage ce verrou et vérifie aussi le choix par défaut stocké dans les paramètres.

Les compléments `batiment`, `etage`, `appartement`, `porte` sont conservés sur `Adresse`, avec une identité de déduplication propre au logement ; les identités des adresses sans logement restent compatibles avec les imports historiques. La duplication depuis une tournée conserve l’adresse géographique et ses coordonnées sans recopier l’identifiant BAN/OSM. Elle crée une adresse et son passage atomiquement, avec un reçu distinct. La saisie partage le verrou de secteur avec la validation des tournées, puis verrouille la tournée et l’adresse : une adresse ayant un passage validé ne peut plus changer de logement, même depuis une autre tournée. Les champs omis par un ancien client sont conservés. Les agrégats de paiement sont initialisés à partir de l’énumération, qui inclut `CB`.

La saisie libre `POST /api/tournees/{id}/adresses` partage cette transaction et ces verrous pour créer une adresse et son passage en une seule opération. Le secteur et l’amicale proviennent de la tournée contrôlée ; un sapeur doit y être affecté. Les contraintes d’adresse et de reçu annulent toute la création en cas de conflit. Le web réinitialise les filtres après cet ajout pour afficher le résultat et initialise les quatre précisions à vide lors d’une duplication de logement. Le filtre de rue retire les suffixes de numéro accolés ou séparés et regroupe les noms sans tenir compte de la casse ni des espaces répétés.

`Deletion` centralise les dépendances et protections des comptes, secteurs, adresses, tournées, campagnes, carnets et passages. Les routes de contrôle sont informatives ; chaque suppression revérifie les dépendances après verrouillage. Les écritures partagent les verrous de secteur ou de tournée selon leur opération. Les liens implicites entre adresses et tournées de leur secteur sont aussi contrôlés. Les anciennes suppressions en cascade des passages et affectations d’une tournée deviennent restrictives. Seuls les reçus entièrement inutilisés sont supprimés explicitement avec leur carnet, dans la même transaction. Les reçus consommés conservent leur historique.

Les secteurs portent un polygone GeoJSON persistant. Le navigateur propose un éditeur Leaflet avec fond OpenStreetMap ; Symfony valide les anneaux et leur surface avec PostGIS. L’extraction utilise par défaut les fichiers départementaux complets de la Base Adresse Nationale. Les départements sont détectés par intersection avec les contours officiels Etalab, élargis de 500 m pour tenir compte de leur simplification. Les fichiers sont téléchargés en flux, décompressés avec contrôle d’intégrité gzip, puis mis en cache atomiquement pendant 24 h. Les codes départementaux sont validés avant construction de l’URL. Un échec sur un département annule tout l’aperçu. Overpass reste une source explicitement sélectionnable. Dans les deux cas, les points sont filtrés avec `ST_Covers`.

L’aperçu d’import est stocké dans la session, lié au secteur et à sa géométrie, et expire après 15 minutes. L’import transactionnel verrouille le secteur, recontrôle les doublons et ajoute les adresses avec position et référence source (`ban:…` ou `osm:…`). La création manuelle partage ce verrou. Le changement de géométrie ne supprime aucune adresse.

`AddressGeocoder` assure la recherche explicite des villes, rues et numéros via le service officiel Géoplateforme/BAN, distinct de l’extraction Overpass gérée par `OpenStreetMap`. Les points GeoJSON sont convertis en coordonnées de carte avec validation des libellés et des positions. Le cache disque de 24 heures inclut le fournisseur ; un verrou partagé limite les appels à une requête par seconde par instance. `OVERPASS_URL`, `GEOCODER_URL`, `BAN_URL` et `BAN_DEPARTMENTS_URL` sont configurables. Les services publics sont externes et leur disponibilité et couverture conditionnent l’extraction ; les erreurs et réponses partielles sont remontées sans import. Le jeu de tests utilise un service de source déterministe sur le réseau Compose isolé, y compris des fichiers gzip tronqués, corrompus et des secteurs traversant deux départements.

`Amicale 1—N User`, `Amicale 1—N Secteur`, `Secteur 1—N Adresse`, `Secteur 1—N Tournee`, `Tournee N—N User` via `TourneeAffectation`, `Tournee 1—N Passage`.

Chaque tournée possède ses `Carnet`, chaque carnet ses `Recu` numérotés. Les numéros de carnets et reçus sont uniques par amicale, toutes tournées confondues. Un passage de vente ou de don référence un reçu courant unique ; le reçu conserve également son passage de consommation historique pour empêcher sa réutilisation après remplacement ou annulation. La création d’un carnet génère sa plage de reçus en une transaction ; la consommation verrouille la tournée et le reçu. Les agrégats par carnet distinguent reçus utilisés, disponibles et annulés, ventes, dons et montants actuels.

L’entité `Passage` porte le résultat du passage, la quantité, le montant et le moyen de paiement. Un refus ou une absence conserve une quantité et un montant nuls. Les agrégats utilisent les passages persistés et des centimes entiers. Les tournées sont regroupées par année de leur date de début pour le suivi des campagnes.
