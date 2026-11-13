# Installer CalendrierSP et MobileSP sur un VPS OVH

Cette procédure est prévue pour un **VPS neuf sous Ubuntu Server 24.04 LTS**, avec un utilisateur disposant de `sudo`. Adapter les commandes d'installation des paquets si une autre distribution est déjà installée. Les adresses `IP_DU_VPS`, `calendriers.exemple.fr` et `mobile.exemple.fr` sont à remplacer partout.

**Si Nginx est déjà installé sur le VPS, le conserver** : suivre la [procédure MobileSP avec Nginx](MOBILESP_NGINX.md) pour la publication web et HTTPS. Ne pas installer Caddy ni lui faire utiliser les ports 80/443 occupés par Nginx. Les étapes Docker, base de données, cookies Symfony et sauvegardes restent applicables.

Le VPS héberge PostgreSQL/PostGIS, l'API Symfony, l'administration CalendrierSP et la version web de MobileSP. Les applications Android/iOS restent installées sur les téléphones et contactent cette même API HTTPS.

Pour les applications seules, **4 vCPU, 8 Go de RAM et 80 Go de SSD** constituent un point de départ confortable, à ajuster selon l'utilisation et les fichiers embarqués. Les imports cartographiques peuvent demander nettement plus de mémoire et de disque ; voir la dernière partie avant de les lancer.

## 1. Préparer le réseau et les accès

Dans la zone DNS du domaine, créer deux enregistrements `A` vers l'IPv4 du VPS :

| Nom | Destination |
| --- | --- |
| `calendriers.exemple.fr` | `IP_DU_VPS` |
| `mobile.exemple.fr` | `IP_DU_VPS` |

Créer des enregistrements `AAAA` uniquement si l'IPv6 du VPS est configurée et accessible. Caddy utilisera ces noms pour obtenir et renouveler les certificats HTTPS.

Depuis ton ordinateur, te connecter avec l'utilisateur fourni par OVH, par exemple :

```bash
ssh ubuntu@IP_DU_VPS
```

Sur le VPS :

```bash
sudo apt update
sudo apt upgrade -y
sudo apt install -y ca-certificates curl gnupg rsync ufw openssl
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

Si SSH utilise un autre port que 22, l'autoriser avant d'activer UFW. Installer ta clé SSH et vérifier une deuxième connexion avant de modifier l'authentification SSH. Appliquer les mêmes ouvertures au pare-feu réseau OVH s'il est activé. Redémarrer le VPS après les mises à jour si Ubuntu le demande.

## 2. Installer Docker et Caddy

Docker exécutera PHP, PostgreSQL et les compilations JavaScript ; il n'est pas nécessaire d'installer PHP, Composer ou Node.js sur l'hôte.

Sur Ubuntu 24.04 neuf, installer Docker depuis son dépôt officiel :

```bash
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu noble stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo docker compose version
```

Cette commande de dépôt utilise explicitement Ubuntu 24.04 (`noble`). Ne pas la reprendre telle quelle sur Debian ou une autre version d'Ubuntu. Si Docker est déjà installé, vérifier son origine et éviter de mélanger plusieurs installations.

Sur un VPS sans Nginx ni autre proxy installé, installer Caddy pour suivre l'étape 6 :

```bash
sudo apt install -y caddy
sudo systemctl enable --now caddy
```

Le proxy HTTPS, Caddy ou Nginx, reçoit les connexions publiques sur 80/443. Les ports Docker restent accessibles uniquement sur `127.0.0.1` : PostgreSQL 5432, API 8000, administration 8080. Ne pas ouvrir ces ports dans le pare-feu.

## 3. Copier les projets

Sur le VPS, créer les répertoires, détenus par ton utilisateur de déploiement :

```bash
sudo mkdir -p /srv/mobilesp /srv/mobilesp-web
sudo chown "$(id -un):$(id -gn)" /srv/mobilesp /srv/mobilesp-web
```

Depuis ton ordinateur, copier les sources actuelles, y compris les changements qui ne seraient pas encore dans Git :

```bash
rsync -av \
  --exclude='.git/' --exclude='node_modules/' --exclude='vendor/' \
  --exclude='.env*' --exclude='var/' --exclude='dist/' --exclude='.expo/' \
  --exclude='.agents/' --exclude='.codex/' \
  /home/nguyenvanc/Projets/MobileSP/calendriersp \
  /home/nguyenvanc/Projets/MobileSP/MobileSP \
  ubuntu@IP_DU_VPS:/srv/mobilesp/
```

Résultat attendu : `/srv/mobilesp/calendriersp` et `/srv/mobilesp/MobileSP`. Les dépendances seront réinstallées à partir des fichiers de verrouillage. Les données PostgreSQL existantes ne sont pas incluses dans cette copie ; leur reprise est décrite plus bas.

## 4. Configurer CalendrierSP pour la production

Sur le VPS :

```bash
cd /srv/mobilesp/calendriersp
umask 077
openssl rand -hex 32
openssl rand -hex 32
nano .env
```

Utiliser une valeur générée pour le mot de passe PostgreSQL et l'autre pour `APP_SECRET`. Créer ce contenu en remplaçant les deux valeurs :

```dotenv
BIND_ADDRESS=127.0.0.1
WEB_BIND_ADDRESS=127.0.0.1
DATABASE_PORT=5432
BACKEND_PORT=8000
WEB_PORT=8080
POSTGRES_DB=calendriersp
POSTGRES_USER=calendriersp
POSTGRES_PASSWORD=REMPLACER_PAR_LA_PREMIERE_VALEUR_HEXADECIMALE
APP_SECRET=REMPLACER_PAR_LA_SECONDE_VALEUR_HEXADECIMALE
MOBILE_WEB_ORIGINS=https://mobile.exemple.fr
```

```bash
chmod 600 .env
```

Garder ces secrets hors du dépôt. Le format hexadécimal évite les caractères à encoder dans `DATABASE_URL`. Le mot de passe PostgreSQL doit être défini **avant le premier démarrage** : changer ensuite `.env` ne change pas le mot de passe d'une base déjà initialisée.

### Cookies et proxy HTTPS

La configuration actuelle utilise `cookie_secure: auto`. Il faut aussi déclarer le proxy et imposer des cookies Secure pour ce déploiement HTTPS. Créer le dossier :

```bash
mkdir -p deploy
nano deploy/framework-vps.yaml
```

Contenu de `deploy/framework-vps.yaml` :

```yaml
framework:
  trusted_proxies: '127.0.0.1,::1,private_ranges'
  trusted_headers: ['x-forwarded-for', 'x-forwarded-proto']
  session:
    cookie_secure: true
```

Les connexions venant du proxy HTTPS traversent le réseau privé Docker. Cette confiance convient ici parce que le port du backend est lié à la boucle locale et que le proxy remplace les en-têtes transmis par les clients, selon les configurations proposées. Ne pas exposer ce port directement sur Internet.

Créer `docker-compose.override.yml` à la racine de CalendrierSP :

```yaml
services:
  backend:
    volumes:
      - ./deploy/framework-vps.yaml:/var/www/html/config/packages/prod/framework_vps.yaml:ro
```

Ces fichiers ne contiennent pas de secrets. Permettre au processus Symfony de lire le fichier monté :

```bash
chmod 755 deploy
chmod 644 deploy/framework-vps.yaml docker-compose.override.yml
```

Compose charge automatiquement ce fichier en complément de `docker-compose.yml`. Conserver ces deux fichiers de déploiement lors des mises à jour. Cette configuration impose HTTPS aux clients ; les connexions directes en HTTP ne conviennent plus à l'authentification.

### Démarrer et initialiser une base neuve

```bash
sudo docker compose config --quiet
sudo docker compose up -d --build --wait
sudo docker compose exec --user www-data backend php bin/console doctrine:migrations:migrate --no-interaction
sudo docker compose exec --user www-data backend php bin/console app:create-admin
sudo docker compose ps
curl --fail http://127.0.0.1:8000/health
```

La création d'administrateur demande l'amicale, son identifiant court, le département, l'e-mail et un mot de passe d'au moins 12 caractères. Créer ensuite les comptes et les données depuis l'administration. Pour reprendre la base locale, suivre la partie 8 **à la place de l'initialisation d'une base neuve**.

## 5. Compiler MobileSP pour le web

Sur le VPS, depuis le projet MobileSP :

```bash
cd /srv/mobilesp/MobileSP
sudo docker run --rm \
  -v "$PWD:/app" -w /app \
  -e CI=1 -e EXPO_NO_TELEMETRY=1 \
  -e EXPO_PUBLIC_CALENDRIERS_API_URL=https://mobile.exemple.fr/api \
  -e EXPO_PUBLIC_SERVICES_MODE=remote \
  node:22-bookworm-slim \
  sh -c 'npm ci && npx expo export --platform web --max-workers 2'
sudo chown -R "$(id -un):$(id -gn)" node_modules dist
```

Le mode cartographique `remote` utilise les services HTTPS externes. Le calcul d'itinéraires nécessite alors une clé OpenRouteService : si tu utilises cette fonction, ajouter `-e EXPO_PUBLIC_ORS_API_KEY=TA_CLE` à la commande de compilation. Cette clé sera visible dans le site compilé ; ne pas y mettre un secret serveur. Les services publics restent soumis à leurs quotas et conditions d'utilisation. La partie 10 explique l'alternative avec tes propres services.

L'URL de CalendrierSP est intégrée **lors de la compilation**. Après changement, refaire l'export. Le champ d'API de connexion peut également remplacer cette URL ; une adresse de développement déjà mémorisée dans un navigateur doit être corrigée.

Publier l'export dans un répertoire distinct des sources :

```bash
release_dir="/srv/mobilesp-web/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$release_dir"
rsync -a dist/ "$release_dir/"
chmod -R u=rwX,go=rX "$release_dir"
ln -s "$release_dir" /srv/mobilesp-web/current.next
mv -Tf /srv/mobilesp-web/current.next /srv/mobilesp-web/current
```

L'export contient les médias et documents de MobileSP ; prévoir leur volume sur disque. Le serveur Expo/Metro (`npm run web`, port 8083 en développement) n'est pas utilisé en production.

## 6. Activer HTTPS et les deux sites

Sur un VPS neuf, remplacer `/etc/caddy/Caddyfile` avec `sudo nano /etc/caddy/Caddyfile` :

```caddyfile
calendriers.exemple.fr {
    @api path /api /api/*
    handle @api {
        reverse_proxy 127.0.0.1:8000 {
            transport http {
                response_header_timeout 180s
            }
        }
    }
    handle {
        reverse_proxy 127.0.0.1:8080
    }
}

mobile.exemple.fr {
    @api path /api /api/*
    handle @api {
        reverse_proxy 127.0.0.1:8000 {
            transport http {
                response_header_timeout 180s
            }
        }
    }
    handle {
        root * /srv/mobilesp-web/current
        try_files {path} /index.html
        file_server
    }
}
```

```bash
sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy
sudo journalctl -u caddy -n 50 --no-pager
curl --fail https://calendriers.exemple.fr/api/session
curl --fail https://mobile.exemple.fr/api/session
```

Caddy obtient automatiquement les certificats lorsque les DNS pointent vers le VPS et que 80/443 sont accessibles. Les appels `/api` vont directement au backend, en conservant le chemin et les informations HTTPS. Ils contournent le proxy Nginx interne, qui transmettrait sinon son propre protocole HTTP.

MobileSP web appelle `https://mobile.exemple.fr/api`, sur sa propre origine : ce montage simplifie les cookies et évite les appels entre origines à l'origine de l'erreur CORS rencontrée en développement. `MOBILE_WEB_ORIGINS` conserve une liste explicite ; ne pas utiliser `*` pour l'authentification par cookies.

Le repli vers `index.html` permet d'ouvrir directement une route de MobileSP. Le répertoire publié contient uniquement l'export, sans sources ni fichiers de secrets.

## 7. Vérifier depuis le navigateur et les téléphones

- Ouvrir `https://calendriers.exemple.fr` et se connecter avec le compte créé.
- Ouvrir `https://mobile.exemple.fr`, vérifier l'URL de connexion et se connecter.
- Vérifier qu'un passage enregistré dans MobileSP apparaît dans CalendrierSP, puis reste présent après rechargement.
- Vérifier dans les outils du navigateur que le cookie de session porte `Secure` et `HttpOnly`, et que les requêtes ne ciblent plus `localhost` ou une IP privée.
- Tester également la connexion et une saisie sur Android/iOS réels.

Pour une application Android/iOS compilée, définir par exemple `EXPO_PUBLIC_CALENDRIERS_API_URL=https://calendriers.exemple.fr/api` dans son environnement de build, ou renseigner cette adresse sur l'écran de connexion. Il n'y a pas de serveur Android/iOS à faire tourner sur le VPS. Une compilation iOS locale nécessite macOS/Xcode ; EAS constitue une autre possibilité.

Les sessions sont actuellement stockées dans le conteneur backend : une recréation de celui-ci peut demander une reconnexion. Les données métier restent dans PostgreSQL. L'option de mémorisation du mot de passe sur le web utilise le stockage local du navigateur sans chiffrement, comme indiqué à l'utilisateur ; HTTPS ne change pas ce mode de stockage.

## 8. Reprendre les données de l'installation locale

Cette étape est facultative. Pour conserver les données existantes, exporter la base depuis ton ordinateur, dans le dossier CalendrierSP :

```bash
umask 077
docker compose exec -T database sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > calendriersp-transfert.dump
scp calendriersp-transfert.dump ubuntu@IP_DU_VPS:/srv/mobilesp/
```

Vérifier que l'export s'est terminé sans erreur avant de transférer. Sur le VPS, partir d'une **base cible neuve et vide**, sans avoir lancé les migrations ni créé d'administrateur. Si la base cible contient déjà des données, s'arrêter pour préparer une reprise adaptée, sans supprimer son volume.

```bash
cd /srv/mobilesp/calendriersp
sudo docker compose up -d --build --wait database
sudo docker compose exec -T database sh -c 'pg_restore --exit-on-error --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < /srv/mobilesp/calendriersp-transfert.dump
sudo docker compose build backend frontend-web
sudo docker compose run --rm --no-deps --user www-data backend php bin/console doctrine:migrations:migrate --no-interaction
sudo docker compose up -d --wait
```

Les comptes et leurs mots de passe viennent de la sauvegarde ; ne pas recréer le même administrateur. Les mots de passe PostgreSQL source/cible peuvent être différents. Conserver la sauvegarde en lieu sûr et retirer la copie de transfert du VPS après validation. Tester une restauration séparée avant une migration de données importantes.

## 9. Sauvegardes et mises à jour

Créer une sauvegarde quotidienne et une copie sur un autre stockage. Un volume Docker conserve les données lors d'un redémarrage, mais ne remplace pas une sauvegarde.

Créer `/srv/mobilesp/calendriersp/deploy/backup-db.sh` :

```bash
#!/usr/bin/env bash
set -euo pipefail
umask 077
cd /srv/mobilesp/calendriersp
install -d -m 0700 /var/backups/calendriersp
backup_file=$(mktemp /var/backups/calendriersp/backup.XXXXXXXX.dump.partial)
trap 'rm -f "$backup_file"' EXIT
docker compose exec -T database sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$backup_file"
mv "$backup_file" "/var/backups/calendriersp/$(date -u +%Y%m%dT%H%M%SZ).dump"
```

```bash
chmod 700 deploy/backup-db.sh
sudo /srv/mobilesp/calendriersp/deploy/backup-db.sh
sudo crontab -e
```

Ajouter cette ligne au crontab de root :

```cron
0 3 * * * /srv/mobilesp/calendriersp/deploy/backup-db.sh >> /var/log/calendriersp-backup.log 2>&1
```

Configurer aussi la copie hors VPS, une durée de rétention et une alerte en cas d'échec. Sauvegarder séparément `.env`, les fichiers `deploy/`, l'override Compose et le Caddyfile dans un emplacement protégé. Vérifier périodiquement une restauration sur une base de test distincte.

Pour mettre à jour CalendrierSP après copie des nouvelles sources, en conservant la configuration du serveur :

```bash
cd /srv/mobilesp/calendriersp
sudo ./deploy/backup-db.sh
sudo docker compose build backend frontend-web
sudo docker compose stop frontend-web backend
sudo docker compose run --rm --no-deps --user www-data backend php bin/console doctrine:migrations:migrate --no-interaction
sudo docker compose up -d --wait
```

Cette opération interrompt brièvement l'API. Si une migration échoue, examiner l'erreur avant de redémarrer l'application. Pour MobileSP, refaire l'export puis publier un nouveau répertoire comme à l'étape 5. Les anciennes versions web peuvent être conservées temporairement pour revenir au précédent export ; un retour du backend doit aussi tenir compte des migrations de base.

Ne jamais utiliser `docker compose down -v` pour une mise à jour : cela supprime les volumes de données. Surveiller l'espace disque, les sauvegardes et la taille des journaux Docker. La checklist du projet signale encore notamment l'absence de limitation des tentatives de connexion et de réinitialisation de mot de passe ; prévoir ces fonctions avant une ouverture large au public.

## 10. Héberger aussi les services cartographiques

Cette partie remplace les services externes du mode `remote`. Elle est à dimensionner selon le VPS et la zone couverte. Pour les extraits régionaux actuels, **16 Go de RAM et 150 à 200 Go de SSD** peuvent constituer une première estimation à confirmer par les imports ; une couverture France entière demande une étude distincte. Ne pas lancer tous les imports simultanément. ORS réserve déjà jusqu'à 3 Go de heap avec sa configuration actuelle, et le rendu des tuiles utilise sa propre base PostgreSQL.

1. Copier aussi `/home/nguyenvanc/Projets/MobileSP/mapservers/` dans `/srv/mobilesp/mapservers/`, sans ses `.env` ni les données générées. Installer les outils des scripts : `sudo apt install -y make python3`.
2. Dans chacun des trois sous-projets, copier `.env.example` vers `.env`, choisir la couverture et définir les ports : **géocodage 8082**, **tuiles 8090**, **itinéraires 8084**. Le port 8080 est déjà utilisé par CalendrierSP.
3. Dans leurs fichiers `docker-compose.yml`, lier explicitement chaque port publié à la boucle locale :

   ```yaml
   # serveur-geocodage, service geocodage-ign
   ports:
     - "127.0.0.1:${PORT:-8082}:8080"

   # serveur-tuiles, service tuiles
   ports:
     - "127.0.0.1:${PORT:-8090}:80"

   # serveur-itineraires, service openrouteservice
   ports:
     - "127.0.0.1:${PORT:-8084}:8082"
   ```

   Ces blocs remplacent le bloc `ports` de chaque service dans son propre fichier. Les Compose cartographiques actuels publient sinon leurs ports sur toutes les interfaces ; UFW seul ne suffit pas à filtrer les ports publiés par Docker.
4. Effectuer les imports initiaux, un service après l'autre :

   ```bash
   cd /srv/mobilesp/mapservers/serveur-geocodage
   sudo make index
   cd /srv/mobilesp/mapservers/serveur-tuiles
   sudo make import
   cd /srv/mobilesp/mapservers/serveur-itineraires
   sudo make download
   sudo make start
   sudo make status
   ```

   Les scripts utilisent la configuration locale de chaque dossier. Attendre la fin de construction du graphe ORS et vérifier les journaux. Les données géographiques actuelles ciblent l'Aube et les régions voisines ; les graphes ORS utilisent Champagne-Ardenne. Les imports de tuiles et de géocodage reconstruisent leurs données dérivées : les réserver à l'installation ou à une mise à jour planifiée.
5. Ajouter dans le bloc Caddy `mobile.exemple.fr`, **avant le `handle` final**, les routes suivantes sans retirer leurs préfixes :

   ```caddyfile
   handle /geocodage/* {
       reverse_proxy 127.0.0.1:8082
   }
   handle /tile/* {
       reverse_proxy 127.0.0.1:8090
   }
   handle /ors/* {
       reverse_proxy 127.0.0.1:8084
   }
   ```

   Valider et recharger Caddy. Ces routes permettent l'accès HTTPS aux services ; définir leurs limites d'usage avant de les proposer publiquement. Tester aussi les requêtes provenant des WebViews natives, dont l'origine peut être `null`, et adapter le CORS de ces services cartographiques si nécessaire. Cela est indépendant du CORS de l'API CalendrierSP.
6. Dans `MobileSP/components/Itineraires/ItinerairesConstants.ts`, remplacer les URL de `LocalItineraireConstants` par les URL publiques :

   ```text
   GEOCODING_URL = https://mobile.exemple.fr/geocodage
   TUILES_URL = https://mobile.exemple.fr/tile/{z}/{x}/{y}.png
   OPENROUTESERVICE_DIRECTIONS_URL = https://mobile.exemple.fr/ors/v2/directions/driving-car/json
   OPENROUTESERVICE_SNAP_URL = https://mobile.exemple.fr/ors/v2/snap/driving-car
   ```

   Conserver la syntaxe TypeScript des propriétés. Recompiler MobileSP avec `EXPO_PUBLIC_SERVICES_MODE=local`. **Changer uniquement `EXPO_PUBLIC_LOCAL_SERVICES_HOST` ne suffit pas** : le code actuel impose `http://` et les ports locaux, ce qui ferait bloquer les appels depuis le site HTTPS. Une évolution vers des variables d'URL complètes rendrait cette configuration plus simple.

L'administration CalendrierSP dispose de ses propres paramètres de géocodage, BAN et Overpass. Héberger les services MobileSP ne remplace pas automatiquement ses sources : le cache BAN et Overpass continuent à utiliser les URL documentées dans `.env.example`, sauf adaptation explicite.

Ce guide décrit la configuration à appliquer. Aucune installation ni validation sur le VPS n'a été effectuée pendant sa rédaction.
