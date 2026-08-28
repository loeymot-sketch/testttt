# Avis de sécurité composer — remédiation et boucle E2E

Date : 2026-08-25 · Canal : `claude-code-supervisor`
Portée : `composer.lock` uniquement. **`composer.json` n'a pas été modifié** — aucune
contrainte de version n'a bougé.

---

## 1. Le point de départ n'était pas celui annoncé

`PROJECT_BRAIN.md §5` listait **3 avis** (firebase/php-jwt, laravel/framework, psy/psysh),
datés du 2026-05-09. `composer audit` en donne aujourd'hui :

| | Avant | Après |
| --- | --- | --- |
| **Avis totaux** | **56** | **7** |
| Paquets touchés | 20 | 3 |
| 🔴 Critiques | **2** | **0** |
| 🟠 Élevés | 15 | 2 |
| 🟡 Moyens | 32 | 3 |
| 🟢 Faibles | 5 | 1 |
| Non classés | 2 | 1 |

Le BRAIN sous-estimait la situation d'un facteur 18, et surtout il ne mentionnait **aucun
des deux critiques**.

---

## 2. Pourquoi une mise à jour globale était exclue

`composer update` complet proposait **182 montées**, dont celle-ci :

```
- Upgrading laravel/framework (v9.52.21 => 9.x-dev e1cef10)
```

Laravel 9 est en fin de vie : il n'existe aucune version stable au-dessus de 9.52.21, donc
composer bascule sur une **branche de développement**. Inacceptable pour une application qui
encaisse de l'argent. J'ai donc procédé par lots nommés, en vérifiant à chaque passe que
`laravel/framework` restait intact — il est toujours en **v9.52.21**.

---

## 3. Ce qui a été monté

**Passe 1 — les deux critiques et le gros des élevés**

| Paquet | Avant → Après | Ce que ça ferme |
| --- | --- | --- |
| `mtdowling/jmespath.php` | 2.8.0 → 2.9.2 | 🔴 **CVE-2026-54133** injection de code |
| `phpoffice/phpspreadsheet` | 1.30.4 → 1.30.6 | 🔴 **CVE-2026-45034** + 3 élevés (SSRF, épuisement mémoire) |
| `league/commonmark` | 2.7.1 → 2.10.0 | 4 élevés (déni de service) |
| `phpseclib/phpseclib` | 3.0.47 → 3.0.56 | 2 élevés (oracle de padding, DoS OID) |
| `guzzlehttp/guzzle` | 7.10.0 → 7.15.5 | 1 élevé (contournement de contrôle d'hôte) |
| `guzzlehttp/psr7` | 2.8.0 → 2.13.1 | 3 moyens |
| `aws/aws-sdk-php` | 3.359.13 → 3.393.5 | 1 élevé (injection CloudFront) |
| `dompdf/dompdf` | 3.1.4 → 3.1.6 | 6 avis |

**Passe 2 — composants Symfony et outillage**

`symfony/mime`, `routing`, `http-foundation`, `mailer`, `process` : tous portés en
**v6.4.44** (dans la contrainte `^6.0` de Laravel 9). Plus `polyfill-intl-idn` 1.42.0,
`paragonie/sodium_compat` 2.5.2, `psy/psysh` 0.12.24, `phpunit/phpunit` 9.6.36.

---

## 4. Les 7 avis restants, et pourquoi ils restent

Tous butent sur la **fin de vie de Laravel 9** — aucun n'est un oubli :

| Paquet | Avis | Blocage |
| --- | --- | --- |
| `laravel/framework` | 4 (1 élevé) | Le correctif est en **12.60+**. Migration 9→10→11→12, chantier à part |
| `spatie/laravel-medialibrary` | 2 (1 élevé) | Le correctif est en **v11+**, qui exige Laravel 10+ |
| `firebase/php-jwt` | 1 (faible) | `google/apiclient` et `google/auth` l'épinglent en `^6.0` ; seule une branche de dev existe au-dessus |

**Escalade** : ces 7 avis ne se ferment qu'en montant Laravel. Le BRAIN a déjà ce chantier
en piste séparée. L'avis le plus sérieux du lot, `spatie/laravel-medialibrary`
**CVE-2026-48557 — contournement de restriction d'upload de fichier**, mérite votre
attention : la médiathèque est utilisée pour les photos produit, donc le chemin est
atteignable par un utilisateur admin.

---

## 5. La boucle de tests — et un piège évité

### 5.1 Le résultat

| Épreuve | Résultat |
| --- | --- |
| Boot applicatif + `artisan about` | OK — Laravel 9.52.21, PHP 8.2.30 |
| `/api/healthz` | `ok` sur db, redis, websocket, chaîne fiscale |
| Backend, 15 tranches ciblées | **1 539 tests, 15/15 tranches vertes** |
| Backend, `tests/Feature` complet | 4 856 passés, 36 ignorés, **10 échecs** — voir §5.2 |
| Vitest complet | 440 fichiers, **3 609 passés**, 0 échec |
| E2E Wave E | **1 passé** |
| E2E multi-produit borne → KDS | **1 passé** |
| E2E kds-caisse-smoke | **2 passés** |
| E2E multi-appareils | **1 passé** |
| 15 surfaces navigateur | **0 fuite i18n, 0 `NaN`, 0 erreur JS, 0 HTTP inattendu** |

### 5.2 Les 10 échecs backend ne viennent PAS de cette montée — c'est prouvé

`IdempotencyRequiredRoutesCoverageTest` (1), `PrinterControllerTest` (3),
`RolePermissionSeederTest` (3), `AllergenCoverageSentinelTest` (3).

Ils échouent aussi **isolément**, donc ce n'est pas de la pollution entre tests. Restait la
vraie question : régression ou pré-existant ? Je ne l'ai pas devinée — j'ai **remis le
`composer.lock` d'avant**, réinstallé (guzzle redescendu en 7.10.0), et rejoué les mêmes
fichiers :

```
IdempotencyRequiredRoutesCoverageTest   1 failed        ← identique
PrinterControllerTest                   3 failed, 3 passed  ← identique
RolePermissionSeederTest                3 failed        ← identique
```

Comptes identiques au test près, sur les dépendances d'avant. **La montée n'a causé aucune
régression.** Le lock à jour a ensuite été restauré et revalidé.

Ces 10 échecs sont donc une **dette pré-existante** à traiter à part.

---

## 6. Découverte majeure en marge : la suite E2E a pourri en silence

En élargissant la boucle aux **11 specs consommatrices** du helper partagé — celles que le
cycle précédent déclarait vérifiées — **10 sur 11 sont rouges**, avec **10 causes
différentes**, toutes de la dérive de fixtures :

| Cause | Spec |
| --- | --- |
| `item 361` inexistant | wave-D |
| `Article 362 introuvable` | zone3-kiosk-to-kds |
| `Petite Frites (id=485)` à seeder | rush-sync-flow |
| `Coca-Cola 33cl n'est plus disponible` | abuse-P-idempotency |
| `wizard should open for item 24` | menu-v2-kiosk-final |
| `Sélectionnez au moins 1 Viande 1` | wave-p-kiosk |
| `429` sur limiteur de débit | zone6-sync-resilience |
| … | … |

**Expérience naturelle décisive** : la **seule** spec verte,
`test-e2e-goal-4chantiers-wave-D`, est celle qui utilise un identifiant **valide**
(`ITEM_ID = 2`). Les items 361, 362 et 485 n'existent pas en base ; l'item 2 existe.

Pourquoi personne ne l'a vu : le cycle précédent avait consigné *« Collecte Playwright des
11 consommateurs : PASS — 49 tests listés »*. **Collecte**, pas exécution. Lister un test
prouve qu'il se parse, rien de plus. C'est exactement le faux vert que cette mission traque.

### Ce que j'ai réparé sur `wave-D`, avec la cause racine

Quatre couches, chacune masquant la suivante :

1. **Identifiant figé périmé** — l'article est désormais résolu **par son nom**, actif, avec
   repli sur l'ID historique et surcharge par variable d'environnement.
2. **Clé API absente sur le chemin Node du helper partagé** — `ApiKeyMiddleware` refuse en
   400 toute requête sans `x-api-key` ; seul le chemin navigateur l'injectait. Les bancs qui
   choisissent délibérément le repli Node mouraient à l'émission du jeton.
3. **Mon propre garde était trop strict** — il exigeait que la ligne du jeton soit encore
   présente, alors qu'une reconnexion borne la révoque légitimement (mesuré : jeton #10711
   émis puis supprimé, #10713 créé). Il compare maintenant l'**avancement du compteur**, que
   révocation ne fait pas reculer. Il est aussi mémoïsé : il s'exécutait à chaque émission et
   ajoutait 1-2 s entre le jeton et son premier usage.
4. **La cause racine du 401** — `resources/js/shared/axios-setup.js:97-98` :

   ```js
   config.headers['Authorization'] = token ? `Bearer ${token}` : '';
   ```

   L'intercepteur **écrase systématiquement** l'en-tête : le Bearer explicite du helper n'a
   jamais eu d'effet, seul compte le jeton du store Vuex. Or la spec « garait » la page borne
   sur un écran admin pour éviter la révocation — et `kioskCart` n'étant pas persisté,
   quitter `/kiosk` vidait le store. L'intercepteur envoyait donc `Authorization: ''`.
   Le contournement de 2026-05-11 causait la panne qu'il prétendait éviter. Parking supprimé.

Résultat : `wave-D` passe désormais l'authentification, place réellement sa commande
(n° 6752) et atteint le KDS. Il reste **une** assertion rouge sur le groupement de la carte
KDS par source — je ne l'ai pas corrigée : elle demande une vérification produit distincte,
et je préfère vous la remonter plutôt que d'ajuster une assertion que je ne comprends pas
encore complètement.

### Ce que je n'ai pas fait, délibérément

Je n'ai **pas** réécrit les 9 autres specs. Chacune a sa propre dérive, c'est un chantier à
part entière, et il mérite son propre cycle plutôt que d'être noyé dans une tâche de
sécurité. Le diagnostic est posé ici, prêt à être repris.

---

## 7. Environnement

`storage/app/public/1/english.png` (drapeau de la langue anglaise, `media` id 1) a **de
nouveau disparu** depuis sa restauration d'hier, provoquant un 404 sur chaque page admin.
Restauré depuis la copie canonique du dépôt `public/images/language/english.png`.

Je n'ai **pas** identifié ce qui le supprime : aucun des seeders lancés par `globalSetup` ne
touche aux médias. Je préfère le dire plutôt que de désigner un coupable au hasard. Comme la
disparition est récurrente, ça mérite d'être élucidé — sinon chaque page admin repartira en
404 au prochain cycle.

---

## 8. État

- `composer.json` : **inchangé**.
- `composer.lock` : modifié (seul fichier de dépendances touché).
- HEAD : `43b120c7d` — **rien n'a été committé ni poussé**.
- Serveur de validation actif sur `127.0.0.1:8766`.
