# Publier MobileSP web avec Nginx

Cette procédure utilise Nginx déjà installé sur le VPS, sous Ubuntu/Debian. Elle suppose que l'API CalendrierSP est accessible depuis le serveur sur `http://127.0.0.1:8000/api`. Remplacer `mobile.ton-domaine.fr`, `IP_DU_VPS` et l'utilisateur SSH `ubuntu` par tes valeurs.

## 1. Compiler l'application Expo

Sur ton ordinateur, avec Node.js 22 :

```bash
cd /home/nguyenvanc/Projets/MobileSP/MobileSP
npm ci
EXPO_PUBLIC_CALENDRIERS_API_URL=https://mobile.ton-domaine.fr/api \
EXPO_PUBLIC_SERVICES_MODE=remote \
npx expo export --platform web
```

Le dossier `dist/` contient le site à publier. Le mode cartographique `remote` utilise les services HTTPS externes. Le calcul d'itinéraires nécessite également une clé OpenRouteService, fournie par `EXPO_PUBLIC_ORS_API_KEY` à la compilation. Cette variable est publique dans le site compilé. Pour utiliser tes propres services, adapter leurs URL selon la partie cartographique du [guide VPS](VPS_OVH.md).

## 2. Copier les fichiers

Sur le VPS :

```bash
sudo mkdir -p /var/www/mobilesp
sudo chown "$(id -un):$(id -gn)" /var/www/mobilesp
```

Depuis ton ordinateur, dans le dossier MobileSP :

```bash
rsync -av dist/ ubuntu@IP_DU_VPS:/var/www/mobilesp/
```

Sur le VPS, permettre à Nginx de lire cet export public :

```bash
sudo chmod -R a+rX /var/www/mobilesp
```

Ce dossier doit contenir seulement le contenu de `dist/`. Si tu utilises déjà les répertoires de versions du guide VPS, tu peux conserver `/srv/mobilesp-web/current` et utiliser ce chemin comme `root` Nginx à l'étape suivante.

## 3. Ajouter le site Nginx

Créer le DNS `A` de `mobile.ton-domaine.fr` vers l'IP du VPS. Si un enregistrement `AAAA` existe, son IPv6 doit aussi joindre ce VPS. Autoriser les ports TCP 80/443 sur le serveur et le pare-feu OVH éventuel.

Créer `/etc/nginx/sites-available/mobilesp` sans remplacer les sites existants :

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name mobile.ton-domaine.fr;

    root /var/www/mobilesp;
    index index.html;

    location ~ ^/api(?:/|$) {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 180s;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

Le `proxy_pass` sans chemin conserve `/api/...` lors du transfert au backend. `X-Forwarded-For` est remplacé par l'adresse du client à cette entrée publique, plutôt que de faire confiance à une valeur fournie par le client. Si un autre proxy ou CDN se trouve devant Nginx, configurer ses adresses de confiance séparément.

Activer le nouveau site :

```bash
sudo ln -s /etc/nginx/sites-available/mobilesp /etc/nginx/sites-enabled/mobilesp
sudo nginx -t
sudo systemctl reload nginx
```

Si le lien existe déjà, ne pas le recréer. Si Nginx dispose déjà d'un bloc pour ce domaine, adapter ce bloc au lieu d'en créer un second. Pour une installation utilisant `conf.d` plutôt que `sites-enabled`, placer le bloc dans un fichier chargé par cette configuration.

## 4. Activer HTTPS

Après propagation DNS, sur Ubuntu/Debian :

```bash
sudo apt update
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d mobile.ton-domaine.fr --redirect
sudo nginx -t
sudo systemctl reload nginx
sudo certbot renew --dry-run
```

Certbot ajoute le certificat et la redirection HTTP vers HTTPS au site. Si le domaine a déjà un certificat géré par ton installation, conserver son mécanisme existant et intégrer les blocs `location` dans son serveur HTTPS. Vérifier la planification du renouvellement avec `systemctl list-timers --all`.

## 5. Vérifier l'API et les cookies

L'URL intégrée à MobileSP est `https://mobile.ton-domaine.fr/api`. Le site et l'API partagent ainsi la même origine. Le backend peut conserver cette origine explicite :

```dotenv
MOBILE_WEB_ORIGINS=https://mobile.ton-domaine.fr
```

Appliquer également la configuration Symfony `trusted_proxies`, `trusted_headers` et `cookie_secure: true`, ainsi que son montage Compose, dans la section **Cookies et proxy HTTPS** du [guide VPS](VPS_OVH.md#cookies-et-proxy-https). Elle convient aussi à ce proxy Nginx. Après toute modification de la configuration de déploiement, recréer le backend depuis son dossier :

```bash
cd /srv/mobilesp/calendriersp
sudo docker compose up -d --wait backend
curl --fail https://mobile.ton-domaine.fr/api/session
```

Cette route doit renvoyer du JSON avec un jeton CSRF et `user: null` avant connexion. Ouvrir le site sur téléphone, se connecter, puis vérifier un enregistrement et sa persistance après actualisation. Corriger l'URL de connexion si une ancienne adresse locale est mémorisée. Les cookies de session doivent porter `Secure` et `HttpOnly`.

## Mises à jour

Refaire l'export Expo et transférer le nouveau contenu de `dist/`. Un export statique ne demande aucun serveur Expo permanent ni rechargement Nginx lorsque seuls ses fichiers changent. La modification d'une variable `EXPO_PUBLIC_*` nécessite une nouvelle compilation. Le module Calendriers nécessite une connexion réseau à l'API.

Ces exemples décrivent les opérations à exécuter sur le VPS ; ils n'ont pas été appliqués ni validés sur ton serveur.
