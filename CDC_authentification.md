<!--
  This specification defines the authentication and session security requirements for the Nuxt SSG frontend,
  the same-origin Nginx proxy, the PHP API, native PHP sessions and SQL user data. It guides implementation and review
  across frontend -> /api -> Nginx -> PHP controller -> native session/authorization -> SQL -> response.
-->

# Cahier des charges de sécurité : Authentification (NuxtJS / PHP / SQL)

## 1. Gestion de la Session Côté Backend (PHP)

**Mécanisme de session :** L'authentification repose sur des sessions stateful via Session ID. Tout système de stockage stateless (ex. JWT pur) est proscrit pour le contrôle d'accès principal.

**Stockage serveur natif :** Les sessions utilisent le gestionnaire de fichiers natif de PHP dans un répertoire privé du conteneur backend, inaccessible depuis le serveur web et réservé à l'utilisateur système PHP.

- Le répertoire de session ne doit jamais se trouver dans le document root ou dans un volume publiquement accessible.
- Les permissions du répertoire doivent interdire la lecture et l'écriture aux autres utilisateurs système.
- Une session ne contient que les données techniques nécessaires : `user_id`, `created_at`, `last_activity` et `csrf_token`.
- Les sessions sont éphémères. Un redémarrage ou redéploiement du conteneur backend peut déconnecter les utilisateurs, sans perte de données métier.

**Configuration php.ini obligatoire :**

```ini
session.save_handler = files
session.save_path = "/var/lib/php/sessions"
session.use_strict_mode = 1
session.use_cookies = 1
session.use_only_cookies = 1
session.use_trans_sid = 0
session.name = "worldbuilding_session"
session.cookie_lifetime = 86400
session.gc_maxlifetime = 86400
```

**Regénération d'identifiant :** L'ID de session doit être impérativement regénéré avec suppression de l'ancienne session à chaque changement de privilège ou connexion réussie via `session_regenerate_id(true)`.

**Durée de vie et expiration :**

- Expiration par inactivité fixée à 24 heures et contrôlée côté serveur avec `last_activity`.
- Le cookie persistant expire après 24 heures et son expiration est renouvelée lors d'une activité authentifiée qualifiée de l'utilisateur.
- Les clics authentifiés sur les `NuxtLink` rendus par les composants `Header` et `PageDisplay` appellent un endpoint d'activité commun chargé de renouveler la session.
- Les mutations authentifiées importantes renouvellent la session après validation de l'autorisation et de la requête.
- Aucun appel de renouvellement n'est envoyé depuis `Header` ou `PageDisplay` lorsqu'aucune session authentifiée n'est connue en mémoire côté Nuxt.
- Les autres navigations, les requêtes automatiques en arrière-plan, le chargement du profil par le Header et les simples contrôles de session ne doivent pas renouveler l'expiration.
- Chaque renouvellement met à jour simultanément le cookie et `last_activity`. Le renouvellement du cookie seul ne prolonge pas la validité serveur.
- Expiration absolue de la session fixée à 7 jours avec `created_at`, quelle que soit l'activité de l'utilisateur.
- Le renouvellement de l'expiration par inactivité ne doit jamais repousser l'expiration absolue.
- À chaque renouvellement, l'expiration effective est calculée explicitement avec `min(time() + 86400, created_at + 604800)`. PHP manipule ces valeurs sous forme de timestamps Unix ; aucune chaîne de date relative n'est utilisée pour ce calcul.
- Lorsqu'une expiration par inactivité ou absolue est atteinte, la session serveur est détruite et le cookie est immédiatement effacé.

## 2. Stockage Côté Client (NuxtJS) & Transport

**Interdiction du stockage Web Storage :** Interdiction absolue de stocker l'ID de session, des identifiants utilisateur, des jetons ou des rôles dans le `localStorage` ou le `sessionStorage` du navigateur.

**Transmission par Cookie HttpOnly :** L'ID de session doit voyager exclusivement via un cookie HTTP sécurisé configuré avec les attributs suivants :

- `HttpOnly = true` (inaccessible au JavaScript exécuté dans le navigateur, protection contre le vol du cookie par une attaque XSS).
- `Secure = true` en production (transmission uniquement sous HTTPS).
- `SameSite = Lax` pour limiter les requêtes cross-site tout en conservant la navigation normale vers l'application.
- `Path = /`.
- Aucun attribut `Domain`, afin que le cookie reste limité à l'hôte exact qui l'a créé.

**Architecture SSG same-origin :** Le frontend reste entièrement généré par `nuxt generate`. Les appels frontend utilisent exclusivement le chemin `/api` de la même origine. Nginx relaie les requêtes `/api/*` vers le backend PHP interne. Aucun serveur Nitro ou BFF n'est requis en production et le navigateur ne contacte jamais directement le conteneur backend.

**Transport HTTPS :** Le Nginx public du VPS termine TLS, sert uniquement les challenges Certbot sur `/.well-known/acme-challenge/` et redirige les autres requêtes HTTP vers HTTPS. Les services Docker restent liés au réseau interne ou à l'interface loopback. Le backend doit refuser de créer une session avec un cookie non `Secure` lorsque l'environnement est configuré en production.

## 3. Vérification de Validité et Invalidation de Session

**Vérification à chaque requête protégée (Middleware PHP) :**

- Validation de l'existence active du Session ID dans le stockage natif PHP avant de traiter tout appel d'API protégé.
- Validation de l'expiration par inactivité et de l'expiration absolue.
- Chargement de l'identifiant utilisateur depuis la session uniquement. Les identifiants, rôles ou autres informations envoyés par le frontend ne doivent jamais servir de preuve d'authentification ou d'autorisation.

**Vérification des droits administrateur :** Chaque endpoint administrateur doit appeler une autorisation backend après validation de la session. Le rôle doit être relu depuis la base SQL avec `User::isAdmin()` à chaque action administrateur et ne doit pas être conservé comme autorité dans la session PHP ou dans le navigateur.

**Empreinte client :** Aucune adresse IP ou empreinte User-Agent n'est associée à la session. Aucun changement d'adresse IP ou de User-Agent ne doit provoquer automatiquement un verrouillage ou une déconnexion.

**Changement de mot de passe :** Le changement de mot de passe ne provoque pas obligatoirement la déconnexion de la session courante ou des autres sessions.

**Procédure de déconnexion (Logout) :**

- Invalidation complète de la session côté serveur via `session_destroy()` et suppression des données correspondantes dans le stockage natif PHP.
- Effacement du cookie côté client par l'envoi d'un cookie expiré avec les mêmes attributs `Path`, `Secure`, `HttpOnly` et `SameSite` que lors de sa création (`Max-Age=0` et date d'expiration passée).

## 4. Sécurité des Identifiants & Base de Données (SQL)

**Politique de mot de passe :**

- Longueur minimale de 15 caractères en l'absence de MFA.
- Longueur maximale technique fixée à 64 caractères, sans troncature silencieuse.
- Le mot de passe doit être une chaîne UTF-8 valide et normalisée sous la forme Unicode NFC avant le contrôle de longueur et le hachage.
- La longueur est calculée en points de code Unicode avec une règle identique côté Nuxt et PHP. `strlen()` ne doit pas être utilisé pour cette validation côté PHP.
- Les espaces, y compris en début et en fin de mot de passe, les phrases de passe et les caractères Unicode sont autorisés et ne doivent pas être supprimés automatiquement.
- Aucune catégorie de caractères n'est imposée ou interdite et aucune règle de composition n'exige une majuscule, un chiffre ou un caractère spécial.
- Le formulaire doit autoriser le collage, les gestionnaires de mots de passe, `autocomplete="new-password"` et l'affichage temporaire du mot de passe.
- La validation réactive côté Nuxt fournit un retour immédiat, mais les mêmes exigences sont toujours revérifiées par `POST /api/register` et les endpoints de changement de mot de passe.

**Hachage des mots de passe :** Utilisation exclusive de l'algorithme Argon2id via la fonction native PHP `password_hash($password, PASSWORD_ARGON2ID, $options)`.

L'image PHP doit installer les extensions `intl`, afin de fournir `Normalizer` pour la normalisation Unicode NFC, et `mbstring`, afin de compter les points de code UTF-8 sans utiliser `strlen()`. Le build et les tests de démarrage doivent également vérifier que la constante `PASSWORD_ARGON2ID` est disponible avant d'accepter des opérations d'authentification.

Les paramètres minimaux suivent les recommandations OWASP et doivent être validés par un test de performance sur le serveur de production :

```php
$options = [
    "memory_cost" => 19 * 1024,
    "time_cost" => 2,
    "threads" => 1,
];
```

Après une connexion réussie, `password_needs_rehash()` doit permettre de recalculer progressivement les anciens hash lorsque l'algorithme ou ses paramètres évoluent.
Les hash bcrypt existants sont acceptés pendant cette migration progressive puis remplacés par un hash Argon2id après la première connexion réussie, sans imposer de réinitialisation du mot de passe.

**Requêtes SQL sécurisées :** Utilisation obligatoire de requêtes préparées via PDO ou ORM. Désactivation de l'émulation des requêtes préparées :

```php
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
```

**Protection contre le brute-force :**

- Lorsqu'un email ne correspond à aucun compte, PHP exécute tout de même `password_verify()` contre un hash Argon2id factice afin de limiter les différences de temps de réponse.
- Aucun compteur d'échecs ou verrouillage n'est associé au compte utilisateur afin d'éviter qu'un tiers puisse provoquer un déni de service ciblé en bloquant un compte connu.
- Réponse générique ne permettant pas de distinguer un compte inexistant d'un mot de passe incorrect.
- Nginx applique une limitation en mémoire par adresse IP de 10 requêtes par minute sur `POST /api/login` et `POST /api/register`, avec une faible tolérance en rafale. Cette protection réseau ne nécessite aucune table SQL et ne lie pas la session à l'adresse IP.
- Nginx limite également `GET /api/csrf` à 30 requêtes par minute et par adresse IP afin de protéger la création de sessions anonymes.

## 5. Protections Applicatives Complémentaires

**Protection CSRF :** Le token CSRF est une valeur aléatoire distincte du Session ID, stockée dans la session PHP et liée à celle-ci. Il est généré avec au moins `random_bytes(32)` et ne doit jamais être inclus dans le Session ID ni dérivé de celui-ci.

- Le frontend obtient le token depuis `GET /api/csrf`, un endpoint same-origin accessible avant authentification. Cet endpoint crée si nécessaire une session PHP anonyme et retourne le token avec l'en-tête `Cache-Control: no-store`.
- Le frontend conserve le token uniquement en mémoire.
- Le frontend transmet le token dans l'en-tête `X-CSRF-Token` pour toutes les requêtes de mutation `POST`, `PUT`, `PATCH` et `DELETE`.
- Le backend rejette tout token absent et compare le token reçu avec celui de la session en utilisant `hash_equals()` avant toute mutation.
- Le backend vérifie également que l'en-tête `Origin` correspond exactement à l'origine frontend autorisée.
- Les routes `POST /api/login`, `POST /api/register` et `POST /api/logout` utilisent la même protection CSRF à partir de la session anonyme ou authentifiée courante.
- Le Session ID et le token CSRF sont régénérés après une connexion, une inscription ou un changement de privilège réussi.
- L'application monolithique n'active aucun CORS. Les requêtes cross-origin ne sont pas autorisées.
- `SameSite=Lax` reste une protection complémentaire et ne remplace pas la validation du token et de l'origine.

**En-têtes de sécurité HTTP :** Le Nginx public configure les en-têtes `Strict-Transport-Security`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` et une protection `frame-ancestors`. Une CSP complète est d'abord déployée avec `Content-Security-Policy-Report-Only` afin d'observer les violations sur la version SSG sans bloquer les scripts, styles, médias ou données d'hydratation nécessaires à Nuxt. Après validation des ressources réellement utilisées, la même politique corrigée est activée avec `Content-Security-Policy`. La politique finale doit bloquer l'injection de scripts malveillants susceptibles d'intercepter les données saisies ou d'exécuter des actions avec la session de l'utilisateur.

## 6. V2 OPTIONNEL

Les fonctionnalités suivantes sont explicitement hors du périmètre de la V1, mais pourront être ajoutées ultérieurement :

- Récupération de mot de passe avec un jeton aléatoire, temporaire et à usage unique.
- Vérification de l'adresse email après inscription.
- Statut actif, suspendu ou désactivé du compte utilisateur.
- Révocation de toutes les sessions après un changement de mot de passe ou une action utilisateur explicite.
- Interface permettant de consulter et déconnecter les appareils ou sessions actives.
- Authentification multifacteur, notamment pour les comptes administrateurs.
- Notification des connexions inhabituelles.
- Journal d'audit complet de la création, du renouvellement, de l'utilisation et de la destruction des sessions.
- Fonctionnalité « Se souvenir de moi » avec un token persistant distinct du Session ID, stocké sous forme hachée en base SQL, révocable et renouvelé après utilisation.
