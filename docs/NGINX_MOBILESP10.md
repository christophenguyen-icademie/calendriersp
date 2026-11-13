# Domaines mobilesp10.fr et conteneurs CalendrierSP

Le fichier [deploy/nginx/mobilesp10.conf](../deploy/nginx/mobilesp10.conf) remplace le fichier Nginx qui regroupait les quatre domaines dans un meme bloc HTTPS. Il suppose que Nginx est installe sur l'hote et que les conteneurs publient leurs ports sur `127.0.0.1`.

| Adresse | Destination |
| --- | --- |
| `https://mobilesp10.fr` et `https://www.mobilesp10.fr` | Export web MobileSP dans `/var/www/mobilesp` |
| `https://amicale-calendriers.mobilesp10.fr` | Conteneur `frontend-web`, port hote 8082 |
| `https://amicale-calendriers.mobilesp10.fr/api/...` | Conteneur `backend`, port hote 8000 |
| `https://api-calendriers.mobilesp10.fr/api/...` | Meme conteneur `backend`, port hote 8000 |

## Ports et HTTPS

Le port 8082 correspond au `.env` local du projet. Verifier `WEB_PORT` et `BACKEND_PORT` **sur le VPS** avec `docker compose ps` ; si le frontal utilise 8080, remplacer 8082 dans ce fichier Nginx. Garder `BIND_ADDRESS=127.0.0.1` et `WEB_BIND_ADDRESS=127.0.0.1`.

Les chemins du certificat sont repris du fichier fourni. Verifier que le certificat contient bien les quatre noms :

```bash
sudo openssl x509 -in /etc/letsencrypt/live/mobilesp10.fr/fullchain.pem -noout -ext subjectAltName
```

S'il faut completer ce certificat, avec les DNS des quatre noms pointant vers le VPS :

```bash
sudo certbot --nginx --cert-name mobilesp10.fr \
  -d mobilesp10.fr -d www.mobilesp10.fr \
  -d api-calendriers.mobilesp10.fr -d amicale-calendriers.mobilesp10.fr
```

Les blocs `location` preservent le chemin des requetes, car `proxy_pass` ne comporte pas de chemin supplementaire. L'URL de base de l'API publique est **`https://api-calendriers.mobilesp10.fr/api`**. La racine de ce domaine peut renvoyer une erreur 404 si Symfony n'y definit aucune route.

Le front CalendrierSP est compile avec `VITE_API_BASE_URL=/api`. Le routage `/api` sur son domaine lui permet de fonctionner avec sa configuration actuelle. Les appels vont directement au backend pour conserver les informations HTTPS transmises par le Nginx de l'hote.

## Authentification et MobileSP

Appliquer la configuration Symfony et le montage de production decrits dans [Cookies et proxy HTTPS](VPS_OVH.md#cookies-et-proxy-https). Elle definit les proxies de confiance et impose les cookies Secure. Si un cache de production avait deja ete genere avant de monter cette configuration, recreer le conteneur backend pour repartir avec un cache neuf.

Pour MobileSP web qui appelle le sous-domaine API, definir dans le `.env` Compose :

```dotenv
MOBILE_WEB_ORIGINS=https://mobilesp10.fr,https://www.mobilesp10.fr,https://amicale-calendriers.mobilesp10.fr,https://api-calendriers.mobilesp10.fr
```

Puis, depuis le dossier CalendrierSP du VPS :

```bash
docker compose up -d --force-recreate --wait backend
```

Ajouter `sudo` si l'utilisateur n'a pas acces au daemon Docker. Le client MobileSP transmet deja les cookies avec ses requetes ; les domaines HTTPS appartiennent au meme site `mobilesp10.fr`, ce qui convient aux cookies SameSite=Lax actuels.

Configurer lors de la compilation de MobileSP :

```dotenv
EXPO_PUBLIC_CALENDRIERS_API_URL=https://api-calendriers.mobilesp10.fr/api
```

Refaire l'export web si cette valeur change et verifier le champ de connexion si une ancienne URL a ete memorisee. Les sessions restent propres a l'hote utilise pour acceder a l'API.

## Corriger « Origine web non autorisée »

Ce message JSON vient de `MobileCorsSubscriber` dans Symfony. Il signifie que l'en-tête `Origin` reçu ne correspond ni à l'origine reconnue par Symfony, ni à une valeur de `MOBILE_WEB_ORIGINS`. La configuration Compose par défaut n'autorise que des origines de développement locales.

Modifier la ligne `MOBILE_WEB_ORIGINS` du fichier **`.env` à côté de `docker-compose.yml` sur le VPS**, avec les quatre origines HTTPS ci-dessus. Ne pas ajouter de `/` final ni de chemin `/api` : une origine contient uniquement le protocole, le nom d'hôte et le port éventuel. Modifier `backend/.env` ne remplace pas la variable fournie au conteneur par Compose.

Depuis ce même dossier, recréer le conteneur pour charger la nouvelle variable, puis vérifier sa valeur effective :

```bash
sudo docker compose up -d --force-recreate --wait backend
sudo docker compose exec -T backend printenv MOBILE_WEB_ORIGINS
```

Un simple `docker compose restart backend` conserve l'ancien environnement. Une variable `MOBILE_WEB_ORIGINS` exportée dans le shell, un fichier `--env-file` ou un override Compose peut également remplacer la valeur attendue : la commande `printenv` ci-dessus permet de voir ce qui est réellement utilisé.

Tester avec l'origine du navigateur, par exemple celle de l'administration :

```bash
curl -i 'https://api-calendriers.mobilesp10.fr/api/session' \
  -H 'Origin: https://amicale-calendriers.mobilesp10.fr'
```

Le résultat attendu est un statut 200, du JSON et `Access-Control-Allow-Origin: https://amicale-calendriers.mobilesp10.fr`. Tester aussi avec `https://mobilesp10.fr` et `https://www.mobilesp10.fr` pour MobileSP. Si le refus persiste, consulter l'en-tête **Origin** de la requête dans l'onglet Réseau du navigateur, et comparer exactement sa valeur à la sortie de `printenv`.

Conserver aussi les réglages de proxy HTTPS Symfony mentionnés plus haut : ils permettent à Symfony de reconnaître le protocole HTTPS et d'émettre les cookies adaptés. Le CORS est déjà géré par le backend ; il n'est pas nécessaire d'ajouter un deuxième jeu d'en-têtes CORS dans Nginx.

## Installation et verification sur le VPS

Sauvegarder le fichier Nginx actif, puis remplacer son contenu par le fichier propose. Ne pas conserver en parallele l'ancien bloc HTTPS qui declarait les quatre domaines : ils doivent desormais etre repartis dans les blocs specifiques. Conserver les liens `sites-enabled` existants si le chemin du fichier reste identique.

Avant de recharger :

```bash
sudo nginx -t
```

Si la validation reussit :

```bash
sudo systemctl reload nginx
curl --fail https://api-calendriers.mobilesp10.fr/api/session
curl --fail https://amicale-calendriers.mobilesp10.fr/api/session
```

Les deux routes de session doivent renvoyer du JSON. Ouvrir ensuite l'administration et MobileSP, verifier la connexion et une saisie. Aucune configuration n'a ete appliquee sur le VPS pendant la preparation de ces fichiers.
