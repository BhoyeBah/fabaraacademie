# Fabara Académie — vente de formations en ligne (Laravel)

Site de vente de formations numériques : catalogue multi-domaines, panier, commande avec paiement Mobile Money, livraison par Google Drive, espace client et espace admin caché.

- **Laravel 13**, PHP 8.3 ou plus récent, MySQL / MariaDB (ou SQLite pour tester).
- Aucune police, aucun script ni traceur externe : rien n'est envoyé à des tiers.
- 20 tests automatiques couvrent les parcours importants (`php artisan test`).

---

## 1. Comment ça marche

**Côté client** (il ne voit que « Connexion » et « Inscription ») :
1. Il parcourt librement le catalogue (recherche, filtre par domaine, fiches détaillées).
2. **Pour obtenir une formation, il doit avoir un compte.** En cliquant « Obtenir », un visiteur est envoyé vers l'inscription (nom, e-mail, numéro WhatsApp, mot de passe, acceptation des conditions). Dès son compte créé, ou dès qu'il se connecte s'il en a déjà un, la formation choisie est automatiquement dans son panier. Le panier n'est visible qu'aux personnes connectées.
3. Il valide sa commande : il reçoit une référence (ex. `CMD-7KQ2XA`), les instructions de paiement, et un bouton pour vous envoyer la commande sur WhatsApp.
4. Dès que vous validez le paiement, ses formations apparaissent dans **Mon compte**, avec un bouton « Ouvrir sur Google Drive ».

**Côté admin** (vous) :
- Vous vous connectez avec **le même formulaire « Connexion »** que les clients. Le site vous envoie automatiquement vers l'espace admin.
- L'espace admin répond « Page introuvable » à toute autre personne : un client ne peut pas savoir qu'il existe.
- Menu : Tableau de bord · Commandes · Formations · Domaines · Clients · Réglages · **Mon login** (changer son nom, son e-mail de connexion et son mot de passe, en confirmant avec le mot de passe actuel).

**Les liens Google Drive** ne sont jamais affichés sur le site public. Ils sont chiffrés dans la base de données et ne s'ouvrent que pour un client qui a payé la formation.

---

## 2. Installation sur votre ordinateur (pour tester)

Prérequis : PHP 8.3+, Composer. ([php.new](https://php.new) installe les deux en une commande.)

```bash
cd fabara-academie
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite        # Windows : créez un fichier vide database/database.sqlite
php artisan migrate --seed            # crée les tables + 14 formations d'exemple
php artisan app:create-admin vous@exemple.com   # crée VOTRE compte admin (mot de passe demandé)
php artisan serve
```

Ouvrez http://localhost:8000, puis connectez-vous avec le compte admin créé.

---

## 3. Mise en ligne (hébergeur)

Un hébergement mutualisé avec PHP 8.3 et MySQL suffit (Hostinger, o2switch, LWS, etc.), ou un VPS.

1. Créez une base MySQL chez l'hébergeur.
2. Envoyez le dossier du projet (sans `vendor/`), puis lancez `composer install --no-dev --optimize-autoloader` (en SSH). Sans SSH : lancez cette commande sur votre ordinateur et envoyez aussi le dossier `vendor/`.
3. Faites pointer le domaine vers le dossier **`public/`** (pas la racine du projet).
4. Copiez `.env.example` en `.env` et modifiez :

```env
APP_NAME="Fabara Académie"
APP_ENV=production
APP_DEBUG=false                 # OBLIGATOIRE en ligne
APP_URL=https://votresite.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nom_de_la_base
DB_USERNAME=utilisateur
DB_PASSWORD=mot_de_passe

SESSION_SECURE_COOKIE=true      # cookies envoyés seulement en HTTPS

MAIL_MAILER=smtp                # pour l'e-mail « mot de passe oublié »
MAIL_HOST=smtp.votrehebergeur.com
MAIL_PORT=587
MAIL_USERNAME=contact@votresite.com
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=contact@votresite.com

ADMIN_PATH=espace-gestion       # adresse de l'espace admin, changez-la si vous voulez
```

5. Puis, en SSH :

```bash
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force          # optionnel : formations d'exemple
php artisan app:create-admin vous@exemple.com
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

6. Activez le **certificat HTTPS** (Let's Encrypt, gratuit chez la plupart des hébergeurs). C'est indispensable pour la sécurité et pour Google.

⚠️ **Gardez précieusement `APP_KEY`** (dans `.env`) : c'est la clé qui chiffre les liens Drive. Si elle est perdue, il faudra recoller les liens.

### Coolify sur un VPS Hetzner (Dockerfile)

Le dépôt contient un `Dockerfile` multi-étapes : il construit les assets, installe les dépendances PHP de production et sert Laravel avec Apache sur le port `80`.

1. Dans Coolify, ajoutez une ressource **Application** depuis le dépôt GitHub `BhoyeBah/fabaraacademie`, branche `main`, avec **Dockerfile** comme méthode de build. Le Dockerfile se trouve à la racine.
2. Créez une base de données MySQL dans Coolify (ou utilisez une base MySQL/MariaDB accessible depuis le conteneur). Pour `DB_HOST`, utilisez le nom d'hôte interne fourni par Coolify, pas `127.0.0.1`.
3. Ajoutez ces variables dans la configuration de l'application Coolify :

```env
APP_NAME="Fabara Académie"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...                 # générée une fois avec : php artisan key:generate --show
APP_URL=https://votredomaine.com

DB_CONNECTION=mysql
DB_HOST=nom-interne-du-service-mysql
DB_PORT=3306
DB_DATABASE=fabara
DB_USERNAME=...
DB_PASSWORD=...

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=smtp
MAIL_HOST=smtp.votrehebergeur.com
MAIL_PORT=587
MAIL_USERNAME=contact@votredomaine.com
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=contact@votredomaine.com

ADMIN_PATH=espace-gestion
```

Générez `APP_KEY` une seule fois avec `php artisan key:generate --show`, puis enregistrez-la dans Coolify et conservez-la : ne la régénérez pas lors des déploiements.
4. Dans les commandes de pré-déploiement Coolify, ajoutez `php artisan migrate --force`. Cela crée et met à jour les tables avant le démarrage de la nouvelle version.
5. Déployez. Associez votre domaine à l'application sur le port `80` et activez le HTTPS automatique dans Coolify.
6. Une fois la base migrée, ouvrez un terminal sur le conteneur et créez le compte administrateur avec `php artisan app:create-admin votre@email.com`. Pour installer le catalogue de démonstration, lancez aussi `php artisan db:seed --force` (facultatif).

Les fichiers écrits dans `storage/app` doivent survivre aux redéploiements : si l'application y stocke des fichiers, ajoutez dans Coolify un stockage persistant monté sur `/var/www/html/storage/app`. Les sessions, le cache et la file d'attente utilisent ici MySQL. Sauvegardez régulièrement la base de données et les fichiers persistants.

---

## 4. Utilisation au quotidien

**Ajouter une formation**
1. Dans Google Drive : créez un dossier `INF-04 – Nom de la formation`, envoyez-y les vidéos et PDF.
2. Clic droit → Partager → « Tous les utilisateurs disposant du lien » (Lecteur) → Copier le lien.
3. Admin → Formations → **+ Nouvelle formation** : remplissez la fiche et collez le lien.

**Traiter une commande**
1. Le client vous écrit sur WhatsApp avec sa référence et la capture du paiement.
2. Admin → Commandes → ouvrez la commande → vérifiez le montant → **Valider le paiement**.
3. Cliquez **Envoyer les formations sur WhatsApp** : le message avec les liens est prêt.

**Réglages** : nom de l'école, numéro WhatsApp, e-mail de contact, instructions de paiement (numéros Orange Money / MTN MoMo).

---

## 5. Données personnelles et règles de Google

Le site est construit pour respecter les exigences de Google Play (et de l'App Store) sur les données des utilisateurs, ainsi que la loi guinéenne sur la protection des données à caractère personnel (L/2016/037/AN) et les grands principes du RGPD.

| Exigence | Où c'est fait |
| --- | --- |
| Politique de confidentialité publique, accessible sans compte | `/confidentialite` |
| Conditions d'utilisation | `/conditions` |
| Consentement explicite à l'inscription (case non pré-cochée, date et version enregistrées) | Inscription |
| Nouvel accord demandé si les conditions changent | Changez `LEGAL_VERSION` dans `.env` |
| **Suppression du compte dans l'application** (obligatoire sur Google Play) | Mon compte → Mon profil et mes données |
| **Page web publique de suppression du compte** (lien demandé par Google Play) | `/suppression-compte` |
| Téléchargement de ses données (accès / portabilité) | Mon compte → Télécharger mes données |
| Données minimales (nom, e-mail, téléphone) ; aucune localisation, aucun contact, aucune carte bancaire | Formulaire d'inscription |
| Mots de passe hachés, liens Drive chiffrés, sessions chiffrées | Modèles + `.env` |
| Limitation des tentatives de connexion | Connexion (5 essais), inscription |
| HTTPS + en-têtes de sécurité (CSP, anti-iframe…) | `app/Http/Middleware/SecurityHeaders.php` |
| Aucun cookie publicitaire ni de mesure d'audience | Seulement les cookies indispensables |
| Après suppression, commandes gardées sans aucune donnée personnelle | `orders.user_id` mis à vide |

**Pour la fiche Google Play « Sécurité des données »**, vous pourrez déclarer :
- Données collectées : *Nom*, *Adresse e-mail*, *Numéro de téléphone* (informations personnelles) et *Historique des achats* (informations financières).
- Finalités : *Fonctionnalité de l'application* et *Gestion du compte*. Pas de publicité, pas d'analyse marketing.
- Données partagées avec des tiers : **non**.
- Données chiffrées pendant le transfert : **oui** (HTTPS).
- L'utilisateur peut demander la suppression de ses données : **oui**, lien : `https://votresite.com/suppression-compte`.

**À faire avant de publier :**
- Dans Réglages, remplacez l'e-mail de contact, le numéro WhatsApp et les instructions de paiement par les vrais.
- Relisez `resources/views/pages/privacy.blade.php` et `terms.blade.php` ; faites-les valider par un juriste si possible (c'est une base solide, pas un avis juridique).
- Si vous ajoutez plus tard Google Analytics, Facebook Pixel, une connexion « avec Google » ou des notifications, il faudra mettre à jour la politique de confidentialité et la fiche Google Play.

---

## 6. Structure du code

```
app/Http/Controllers/
    CatalogController, CartController, CheckoutController, PageController   site public
    Auth/      LoginController, RegisterController, PasswordResetController  connexion / inscription
    Account/   DashboardController, ProfileController, TermsController        espace client
    Admin/     Dashboard, Course, Category, Order, User, Setting              espace admin
app/Http/Middleware/  EnsureAdmin (404 si non admin), EnsureTermsAccepted, SecurityHeaders
app/Models/           User, Category, Course, Order, OrderItem, Setting
app/Console/Commands/CreateAdmin.php     php artisan app:create-admin
database/migrations/                     tables
database/seeders/CatalogSeeder.php       formations d'exemple
resources/views/                         pages (Blade)
public/css/app.css · public/js/app.js    design (aucune dépendance externe)
lang/fr/                                 messages en français
tests/Feature/AcademyTest.php            tests automatiques
```

## 7. Et l'application mobile ?

Le site fonctionne déjà parfaitement sur téléphone. Pour une application Android/iOS, la voie la plus simple est d'emballer le site (WebView, ou une PWA publiée avec Bubblewrap/TWA) : la connexion, la suppression du compte et les pages légales exigées par Google sont déjà là. Pour une application native (Flutter, React Native), il faudra ajouter une API (Laravel Sanctum) au même projet.
