# API web implémentée

Les routes sont implémentées avec des contrôleurs Symfony et des réponses JSON explicites dans `src/Api/Workspace.php`. Les entités Doctrine ne sont pas sérialisées directement. API Platform reste installé pour les évolutions futures ; aucune ressource Doctrine n’est exposée automatiquement.

## Session et autorisations

1. `GET /api/session` renvoie `{ "csrfToken": "…", "user": null }` ou le profil connecté.
2. `POST /api/login` reçoit `{ "amicaleId": "troyes10", "email": "…", "password": "…" }`, avec `X-CSRF-Token`. `amicaleId` désigne l’identifiant de connexion choisi, composé de 4 à 20 lettres ASCII ou chiffres, sans distinction de casse ; les UUID sont refusés. La session utilise un cookie HttpOnly, SameSite=Lax, Secure sous HTTPS.
3. Envoyer le cookie sur les requêtes suivantes et `X-CSRF-Token` sur toutes les écritures.
4. `POST /api/logout` invalide la session. Rappeler `/api/session` avant une nouvelle connexion pour obtenir un nouveau jeton.

Un e-mail peut appartenir à plusieurs amicales ; l’identifiant d’amicale ne remplace jamais le mot de passe. Après connexion, le tenant vient exclusivement du compte authentifié. Un `amicaleId` envoyé à une route métier est ignoré.

Le profil (`/api/me`, `/api/session`) expose `amicale.identifiant`, unique sur la plateforme et enregistré en minuscules. `amicale.id` reste la référence UUID interne, préservée avec les relations existantes. `app:create-admin` demande l’identifiant ou accepte `--identifiant=troyes10`. La migration attribue aux amicales existantes un identifiant dérivé de leur nom, désaccentué, tronqué et suffixé si nécessaire.

`PATCH /api/parametres` accepte aussi `identifiant` pour l’administrateur, avec les mêmes règles (422 si invalide, 409 si déjà utilisé par une autre amicale, même avec une casse différente). Les autres champs restent obligatoires ; omettre `identifiant` conserve sa valeur. L’écriture est atomique : un conflit ne modifie aucun paramètre ni campagne. Les sessions existantes restent valides ; les prochaines connexions utilisent le nouvel identifiant.

## Routes

| Méthode | Route | Accès et usage |
|---|---|---|
| GET | `/api/me` | Profil et amicale courante |
| GET | `/api/dashboard?campagne=2026` | Gestion : agrégats et tournées de l’année |
| GET/POST | `/api/secteurs` | Gestion : liste / création (`nom`, `type` et `polygone` facultatifs) |
| GET/POST | `/api/campagnes` | Gestion : campagnes enregistrées / création (`annee`, 2000 à 2100, unique par amicale) |
| GET | `/api/{collection}/{id}/suppression` | Gestion : contrôle préalable des dépendances ; comptes réservés à l’administrateur |
| DELETE | `/api/{collection}/{id}` | Suppression avec recontrôle transactionnel ; collections : `sapeurs`, `secteurs`, `adresses`, `tournees`, `campagnes`, `carnets`, `passages` |
| GET | `/api/tournees/{id}/affectations/{userId}/suppression` | Gestion : contrôle avant retrait de l’équipe |
| DELETE | `/api/tournees/{id}/affectations/{userId}` | Gestion : retrait d’une affectation si la tournée n’est pas validée |
| GET/PATCH | `/api/secteurs/{id}` | Gestion : détail / modification (`nom`, `type`, `polygone`) |
| GET | `/api/carte/recherche?q=Paris` | Gestion : recherche explicite de lieu (3 caractères minimum) |
| POST | `/api/secteurs/{id}/adresses/preview` | Gestion : extraction et aperçu (`source` = `BAN` par défaut ou `OSM` ; `codePostal`, `ville` facultatifs pour OSM) |
| POST | `/api/secteurs/{id}/adresses/import` | Gestion : import de l’aperçu (`token`) |
| GET/POST | `/api/adresses` | Gestion : liste / création (`secteurId`, `ligne1`, `codePostal`, `ville`) |
| GET | `/api/sapeurs` | Gestion : liste des comptes de l’amicale |
| POST | `/api/sapeurs` | Administrateur : création (`email`, `password`, `role`) |
| GET/POST | `/api/tournees` | Liste visible / création par la gestion (`nom`, `secteurId`, `dateDebut`, `stockCalendriers` facultatif) |
| GET | `/api/tournees/{id}` | Détail et `listeAdresses`, avec le passage de chaque adresse |
| GET/POST | `/api/tournees/{id}/carnets` | Liste des carnets et reçus / création par la gestion (`numero`, `premierRecu`, `nombreRecus` facultatif, 25 par défaut) |
| POST | `/api/tournees/{id}/affectations` | Gestion : ajout groupé et idempotent (`userIds`, liste non vide d’UUID ; `userId` conservé pour l’ajout individuel) |
| PUT | `/api/tournees/{id}/passages/{adresseId}` | Gestion ou sapeur affecté : saisie/correction d’un passage |
| POST | `/api/tournees/{id}/adresses/{adresseId}/dupliquer` | Gestion ou sapeur affecté : nouvel appartement et son passage en une transaction |
| POST | `/api/tournees/{id}/adresses` | Gestion ou sapeur affecté : nouvelle adresse libre et son passage en une transaction |
| PATCH | `/api/tournees/{id}/statut` | Gestion : `statut` = `PREPAREE`, `EN_COURS`, `TERMINEE`, `VALIDEE` |
| POST | `/api/tournees/{id}/valider` | Gestion : verrouillage d’une tournée terminée |
| PATCH | `/api/parametres` | Administrateur : `nom`, `departement`, `campagne`, `identifiant` facultatif |
| GET | `/api/mobile/tournees` | Alias de la liste des tournées visibles |
| GET | `/api/mobile/tournees/{id}/adresses` | Alias du détail de tournée avec adresses |

Les listes sont des tableaux JSON. Un sapeur voit uniquement les tournées auxquelles il est affecté. Un responsable ou administrateur d’amicale voit toutes les tournées de son amicale. Les rôles techniques n’accordent aucun accès métier implicite.

Pour ajouter plusieurs sapeurs à l’équipe d’une tournée, envoyer `{"userIds":["uuid-sapeur-1","uuid-sapeur-2"]}` à `POST /api/tournees/{id}/affectations`. Tous les comptes doivent être actifs et appartenir à l’amicale connectée. L’ajout est atomique : une référence invalide (422), hors amicale ou inexistante (404), un compte désactivé ou une tournée validée (409) n’ajoute aucun membre. Les doublons et membres déjà affectés sont ignorés, les membres existants sont conservés et la réponse contient la tournée avec son `equipe` complète. Le corps historique `{"userId":"uuid-sapeur"}` reste accepté ; envoyer les deux champs ensemble est refusé (422). L’ajout reste réservé aux responsables et administrateurs, avec CSRF. Tous les membres affectés disposent des mêmes accès à la consultation et à la saisie des passages de la tournée.

## Logements et paiements

`moyenPaiement` accepte `ESPECES`, `CHEQUE`, `CB`, `AUTRE` pour une vente. Les agrégats de tournée et du tableau de bord exposent aussi la clé `CB`, même si son total vaut `0.00`.

Les adresses exposent `batiment`, `etage`, `appartement`, `porte` (chaînes de 80 caractères maximum ou `null`) et leur ancien champ libre `complement`. `POST /api/adresses` accepte les quatre précisions structurées. `PUT /api/tournees/{id}/passages/{adresseId}` les accepte aussi : un champ omis est conservé ; `null` ou une chaîne vide l’efface. Les précisions d’adresse et le passage sont enregistrés atomiquement. Un logement déjà présent dans le secteur à la même adresse est refusé (409). Les précisions d’une adresse liée à un passage validé sont protégées (409), même depuis une autre tournée.

`POST /api/tournees/{id}/adresses/{adresseId}/dupliquer` reprend le corps d’une saisie de passage avec les précisions du nouveau logement. L’adresse source doit appartenir au secteur de la tournée et au tenant connecté. La route est accessible aux gestionnaires et aux sapeurs affectés ; une tournée validée est verrouillée. Les précisions omises sont héritées de l’adresse source ; le logement résultant doit être renseigné et différent (422 sinon). La route copie voie, code postal, ville, coordonnées et complément libre, sans copier l’identifiant de source ni l’ancien passage. Elle crée l’adresse et son nouveau passage dans la même transaction et renvoie l’adresse avec `passage` (201). Vente et don exigent un reçu disponible ; une collision d’adresse ou de reçu annule toute l’opération (409). L’identité de déduplication inclut les quatre précisions structurées, en préservant les identités historiques des adresses sans logement.

Le formulaire web d’ajout d’appartement initialise `batiment`, `etage`, `appartement` et `porte` à vide et transmet explicitement `null` pour les champs laissés vides. Les valeurs d’un appartement précédent ne sont donc pas reprises.

`POST /api/tournees/{id}/adresses` reçoit les mêmes champs de passage, ainsi que `ligne1` (180 caractères maximum), `codePostal` (10) et `ville` (120), tous requis, et les compléments de logement facultatifs. Il permet une saisie hors référentiel sans extraction cartographique préalable, y compris dans une tournée sans adresse. Le secteur et l’amicale sont déterminés par la tournée ; aucun `secteurId` envoyé par le client n’est utilisé. La route est accessible aux gestionnaires et aux sapeurs affectés, avec CSRF et protection des tournées validées. L’adresse manuelle et son passage sont créés atomiquement, avec un reçu disponible pour vente/don. Une adresse déjà existante ou un reçu consommé entraîne un refus 409 sans adresse ni consommation partielle. La réponse 201 contient l’adresse et `passage`.

## Suppression contrôlée

Le contrôle `GET …/suppression` renvoie `{autorisee, dependances: [{type, nombre, libelle}], raisons: string[], consequences: string[]}`. Les liens ne sont pas supprimés par ce contrôle. `DELETE` renvoie `{"supprime":true}` (200) ou `{"error":"…","code":"DELETION_BLOCKED", …controle}` (409). Le contrôle préalable n’accorde aucun droit à supprimer : les dépendances sont relues sous verrou dans la transaction du `DELETE`. Un lien ajouté entre les deux requêtes bloque la suppression. Les contraintes de clé étrangère restent une protection finale ; une violation concurrente renvoie 409 sans détail SQL. Un identifiant supprimé ou hors amicale renvoie 404.

Les comptes sont protégés s’ils sont connectés, affectés ou auteurs de passages. Les secteurs sont protégés par leurs adresses et tournées. Les adresses sont protégées par leurs passages et par les tournées utilisant leur secteur (liste d’adresses implicite). Les tournées sont protégées par leurs affectations, carnets et passages. La validation verrouille aussi la suppression de la tournée, des carnets, passages et affectations.

Un carnet ne peut être supprimé que si tous ses reçus sont inutilisés et sa tournée non validée ; ses reçus sont alors supprimés dans la même transaction. Aucun reçu n’est supprimé individuellement. Un passage qui a consommé un reçu, même ensuite annulé, n’est pas supprimable. Retirer une affectation conserve le compte et les saisies ; les références d’auteur continuent donc à bloquer la suppression du compte.

Les campagnes ont une identité UUID et une année unique par amicale. Leur liste expose `{id, annee, tournees, parDefaut}`. Les tournées référencent leur campagne, cohérente avec l’année de `dateDebut`. La création d’une tournée ou le choix de la campagne par défaut crée l’année si elle n’existe pas, sous verrou d’amicale partagé avec la suppression. Une campagne utilisée par une tournée ou choisie par défaut est protégée. La migration matérialise les campagnes historiques sans modifier dates, montants ni affectations.

## Polygone et import d’adresses

Un secteur contient `polygone`, nul ou GeoJSON `{"type":"Polygon","coordinates":[[[2.3515,48.8565],[2.3525,48.8565],[2.3525,48.8575],[2.3515,48.8565]]]}`. Les coordonnées suivent l’ordre longitude, latitude. Le premier point doit être répété à la fin ; un seul anneau de 3 à 500 sommets est accepté, sans croisement et avec une surface positive ne dépassant pas 100 km². `PATCH` ne modifie que les champs fournis ; `polygone: null` efface le tracé sans supprimer les adresses.

L’aperçu utilise le polygone enregistré et `source: "BAN"` par défaut. Le serveur charge les contours départementaux officiels Etalab, détecte les départements qui intersectent le secteur avec une marge de 500 m (contours simplifiés à 100 m), puis lit leurs exports BAN complets. Il préfiltre par emprise rectangulaire et applique `ST_Covers` dans PostGIS pour retenir uniquement les points dans le polygone, bord compris. Un secteur peut couvrir jusqu’à quatre départements. Le téléchargement est borné (64 Mio compressés, 512 Mio décompressés par département), vérifié jusqu’à la fin du flux gzip, puis publié atomiquement dans un cache de 24 heures. Un fichier incomplet, un CRC invalide ou un schéma CSV inattendu est refusé. Il n’y a pas de retour silencieux d’un sous-ensemble des départements.

`source: "OSM"` sélectionne Overpass. `codePostal` et `ville` complètent uniquement les champs absents d’OpenStreetMap. Les objets sans numéro, voie ou position utilisable sont ignorés ; les bâtiments et relations utilisent leur centre d’emprise. Les numéros absents de la source ne sont pas interpolés. Aucun référentiel ne garantit tous les logements et adresses réelles.

La réponse contient `token`, `source` (libellé), `departements` (codes BAN ou tableau vide pour OSM), `adresses` (`ligne1`, `codePostal`, `ville`, `latitude`, `longitude`, `sourceId`, `doublon`), `total`, `nouvelles`, `doublons`, `incompletes`, `horsPolygone` et `ignorees`. Les références sont préfixées par `ban:` ou `osm:`. La normalisation du numéro conserve les répétitions BAN, par exemple `2 bis rue …`. `ignorees` compte les enregistrements inutilisables de la source ; une absence de coordonnées empêche de les localiser dans le secteur.

Le serveur conserve l’extraction dans la session pendant 15 minutes (quatre aperçus maximum), liée à l’amicale, au secteur et au polygone. L’import reçoit uniquement `{"token":"…"}` et renvoie `{"creees":4,"doublons":1}`. Il recontrôle le polygone et les doublons sous verrou du secteur et consomme le jeton après succès. Un aperçu expiré, consommé ou devenu obsolète renvoie 409. Les adresses envoyées par le navigateur ne remplacent jamais l’extraction conservée côté serveur.

Les index `(secteur_id, source_id)` et `(secteur_id, identite)` empêchent les doublons importés. Les adresses manuelles existantes sont comparées par identité normalisée, même si elles précèdent cette migration. L’import ajoute des adresses, sans supprimer ni modifier les anciennes. Les adresses sérialisées exposent aussi `latitude`, `longitude` et `sourceId` (nuls pour une adresse manuelle sans position).

La recherche renvoie un tableau de `{label, latitude, longitude}` (cinq résultats maximum). Elle utilise l’API officielle de géocodage Géoplateforme/BAN (`https://data.geopf.fr/geocodage/search`, paramètres `q` et `limit=5`) et convertit ses points GeoJSON `[longitude, latitude]`. Les villes, rues et numéros sont acceptés ; « rue Blanqui, Troyes » renvoie notamment « Boulevard Blanqui 10000 Troyes ». Les réponses invalides et les coordonnées inexploitables provoquent une erreur 503 ; une recherche valide sans résultat renvoie `[]`. Le cache de 24 heures inclut l’URL du fournisseur dans sa clé ; le changement de fournisseur ne reprend pas les anciens résultats vides. Les requêtes non mises en cache sont limitées à une par seconde par backend. Les URL de source sont configurées par le serveur (`OVERPASS_URL`, `GEOCODER_URL`, `BAN_URL`, `BAN_DEPARTMENTS_URL`). Une panne ou une extraction partielle renvoie 503 avec `SOURCE_UNAVAILABLE`, sans import. Une extraction de plus de 10 000 points candidats est refusée : réduire la zone. Le plafond de réponse JSON Overpass reste de 16 Mio. Un polygone hors couverture BAN renvoie 422 et propose de sélectionner OSM.

## Passage

```json
{
  "statut": "VENTE",
  "nbCalendriers": 2,
  "montant": "20.00",
  "moyenPaiement": "ESPECES",
  "carnetId": "uuid-du-carnet",
  "recuId": "uuid-du-recu",
  "commentaire": null
}
```

Une vente exige une quantité positive et un moyen de paiement (`ESPECES`, `CHEQUE`, `AUTRE`). Un autre résultat (`NON_VISITEE`, `DON`, `REFUS`, `ABSENT`, `A_REVOIR`) exige quantité et montant nuls, sans paiement. Les montants sont des chaînes décimales, avec deux décimales maximum et sept chiffres entiers maximum ; les calculs serveur utilisent des centimes entiers. Les commentaires sont limités à 2 000 caractères.

Un don exige son carnet et son reçu, avec un commentaire facultatif et sans saisie financière :

```json
{
  "statut": "DON",
  "carnetId": "uuid-du-carnet",
  "recuId": "uuid-du-recu",
  "commentaire": "Don signalé lors du passage."
}
```

Pour `DON`, la quantité et le montant omis valent respectivement `0` et `"0.00"`, et le moyen de paiement vaut `null`. Une quantité ou un montant non nul, ou un moyen de paiement renseigné, est refusé. Un don compte comme une adresse traitée et un calendrier donné, sans contribuer aux quantités vendues ni aux encaissements. Transformer une vente en don supprime les anciennes valeurs de quantité et de paiement et peut conserver le même reçu.

## Carnets et consommation des reçus

```json
{
  "numero": "C-01",
  "premierRecu": 100,
  "nombreRecus": 25
}
```

Le serveur génère atomiquement le carnet et les reçus 100 à 124. Le numéro de carnet est une chaîne de 40 caractères maximum. Les numéros de reçus sont des entiers de 1 à 999 999 999 ; la taille d’un carnet est comprise entre 1 et 1 000 reçus, avec 25 par défaut. Un index unique sur `(amicale_id, numero)` dans chacune des tables `carnet` et `recu` empêche les doublons, même dans une autre tournée ou campagne. Un chevauchement annule toute la création (409), sans laisser de carnet ou reçus partiels.

Les carnets sont rattachés définitivement à leur tournée. Un sapeur peut lire ceux des tournées auxquelles il est affecté ; seule la gestion peut en créer, tant que la tournée n’est pas validée. La sélection des ventes et dons exige `carnetId` et `recuId` cohérents avec la tournée. Les autres résultats refusent un reçu. Une référence hors amicale ou hors tournée renvoie 404.

Le reçu est verrouillé et consommé dans la transaction d’écriture du passage ; le lien courant est également unique en base. Une correction conservant le reçu courant est autorisée. Un reçu remplacé ou annulé garde sa consommation historique et ne peut plus être sélectionné, même pour rétablir un ancien passage. Deux écritures simultanées sur le même reçu produisent un seul succès, l’autre étant refusée en 409.

Le détail de tournée contient `carnets`, avec leurs `recus` (`id`, `numero`, `utilise`, `annule`), et chaque passage contient son `recu` courant (`id`, `numero`, `carnetId`, `carnetNumero`) ou `null`. `/api/dashboard?campagne=…` contient aussi un tableau `carnets` avec les compteurs `nombreRecus`, `recusUtilises`, `recusDisponibles`, `ventes`, `dons`, `recusAnnules` et `montant`. La campagne est celle de la date de début de la tournée. Les montants ne concernent que les passages actifs du carnet ; un don vaut zéro et une annulation n’est pas un encaissement. Les compteurs de reçus utilisés incluent les annulés.

Les passages historiques sans reçu sont conservés sans attribution automatique ; une nouvelle saisie ou correction de vente/don exige un reçu. La fonctionnalité enregistre les numéros des reçus physiques ; elle ne génère pas de document PDF.

Le serveur vérifie que l’adresse appartient au secteur exact de la tournée et que les utilisateurs affectés appartiennent à son amicale. Les écritures de passage, de statut et d’affectation verrouillent la tournée en transaction. Une tournée validée rejette ces écritures. La saisie initiale passe une tournée préparée en cours ; la gestion termine puis valide explicitement la tournée. Les adresses d’une tournée correspondent aux adresses actuelles de son secteur.

Les statistiques regroupent les tournées par année de leur date de début. Une même adresse visitée dans deux tournées compte pour deux passages prévus. La somme des montants peut être plus grande que le maximum d’un passage individuel.

Le détail et les listes de tournées exposent `calendriersVendus` (somme des quantités des ventes), `calendriersDonnes` (un calendrier par passage `DON`), `calendriersDistribues` (vendus + donnés), `stockCalendriers`, `adresses` (total actuel du secteur), `traitees` et `adressesAVisiter` (`adresses - traitees`). Une adresse sans passage ou `NON_VISITEE` reste à visiter ; les autres statuts sont déjà renseignés. Les compteurs reflètent uniquement les résultats actuels, sans compter les reçus annulés. Le champ historique `progression` reste le pourcentage d’adresses renseignées. `/api/dashboard?campagne=…` expose ces mêmes champs dans `stats`, en additionnant les tournées de la campagne et de l’amicale connectée. Une campagne vide renvoie des compteurs nuls. Les rapports vendus ou donnés / stock et adresses à visiter / total sont affichés séparément sur le web ; un stock nul ou dépassé ne masque pas les quantités réelles.

## Erreurs

Les erreurs ont la forme `{ "error": "message lisible", "code": "CODE_STABLE" }` : `AUTHENTICATION_REQUIRED` (401), `INVALID_CREDENTIALS` (401), `INVALID_CSRF` (403), `FORBIDDEN` (403), `NOT_FOUND` (404), `CONFLICT` (409), `INVALID_INPUT` (400/422), `SERVER_ERROR` (500), `SOURCE_UNAVAILABLE` (503). Une référence hors amicale renvoie 404. Les champs de mot de passe ne sont jamais renvoyés.

## Mobile offline restant

MobileSP utilise les alias de lecture et les routes métier d’écriture avec la session et le jeton CSRF de `/api/session`. Le module actif ne charge plus les données de démonstration. Une connexion est nécessaire et les écritures ne sont confirmées qu’après réponse positive du serveur.

`POST /api/mobile/sync`, le journal idempotent et la résolution de conflits de versions restent à implémenter selon `OFFLINE_SYNC.md`. Les routes actuelles ne constituent pas une synchronisation offline et le client ne rejoue pas automatiquement les écritures.
