# Z2bis — Inventaire des écritures dans `.env` depuis l'interface admin

Tâche T-4.1.2 (GOAL ONB-01). Lecture seule absolue — aucun fichier modifié,
aucun appel mutant exécuté, aucune écriture en base. Toutes les preuves
ci-dessous ont été obtenues par `Read`/`grep` réels ; tout `file:line` cité a
été ouvert et vérifié dans ce worktree.

---

## 1. Mécanisme d'écriture — le sink unique

Package `dipokhalder/laravel-env-editor` v1.0.0 (composer.json:17,
composer.lock:866-916). Toutes les écritures passent par
`Dipokhalder\EnvEditor\EnvEditor` :

- `vendor/dipokhalder/laravel-env-editor/config/enveditor.php:11` —
  `'pathToEnv' => base_path('.env')` → c'est bien LE `.env` de l'application
  (pas un fichier de test), aucune surcharge publiée dans `config/`.
- `vendor/dipokhalder/laravel-env-editor/src/EnvEditor.php:398-419` —
  `protected function save($array)` : construit tout le contenu du fichier en
  mémoire (`implode("\n", $newArray)`) puis fait **un seul**
  `file_put_contents($this->env, $newArray)` (ligne 414). Pas de fichier
  temporaire + rename, pas de `LOCK_EX`, pas de vérification de la valeur de
  retour de `file_put_contents` (qui peut être `false` sur ENOSPC/permission
  refusée sans lever d'exception PHP en soi). `save()` retourne
  inconditionnellement `true` après l'appel (ligne 416).
- `EnvEditor.php:488-500` — `santize()` (sic) : n'échappe que les guillemets
  en DÉBUT/FIN de valeur et entoure de guillemets si la valeur contient un
  espace. **Ne détecte ni n'échappe un `\r`/`\n` interne** ni un guillemet
  au milieu de la valeur → une valeur contenant un saut de ligne s'insère
  comme une ligne `.env` indépendante (ex. injecter `APP_DEBUG=true`).
- Écritures via `addData()` (ajoute/écrase des clés, `EnvEditor.php:462-479`)
  uniquement — aucun appel à `changeEnv()` ni `deleteData()` trouvé dans
  `app/` (grep exhaustif `->addData(|->changeEnv(|->deleteData(` sur tout
  `app/`).

## 2. Tableau des écritures (clé → écran → file:line)

| Clé .env | Écran déclencheur (route) | Contrôleur/Service/file:line | Validation anti-injection |
|---|---|---|---|
| `APP_NAME` | Dashboard → Entreprise, `PUT/PATCH /api/admin/setting/company` | `app/Services/CompanyService.php:44` | OUI — `CompanyRequest.php:33` `regex:/^[^\r\n"]+$/` |
| `APP_DEBUG` | Dashboard → Site, `PUT/PATCH /api/admin/setting/site` | `app/Services/SiteService.php:48` | Champ `numeric` (`SiteRequest.php:50`) — pas d'injection texte, mais **valeur elle-même dangereuse** (voir §Risque) |
| `TIMEZONE`, `DATE_FORMAT`, `TIME_FORMAT` | idem Site | `SiteService.php:49,54,55` | OUI — `SiteRequest.php:39-41` `regex:/^[^\r\n"]*$/` (ajouté 2026-08-13) |
| `CURRENCY`, `CURRENCY_SYMBOL`, `CURRENCY_POSITION`, `CURRENCY_DECIMAL_POINT` | idem Site | `SiteService.php:50-53` | Indirect — dérivées d'un `Currency` DB (`site_default_currency` est `numeric`), pas de saisie libre ici |
| `MIX_GOOGLE_MAP_KEY` | idem Site (skip si `DEMO`) | `SiteService.php:59-61` | OUI — `SiteRequest.php:52` regex |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Dashboard → Mail/SMTP, `PUT/PATCH /api/admin/setting/mail` | `app/Services/MailService.php:42-51` | OUI — `MailRequest.php:41-47` regex + `SafeRemoteHost` sur `mail_host` |
| `MIX_API_KEY` | Dashboard → Licence, `PUT/PATCH /api/admin/setting/license` **et** installeur `/install/license` | `app/Services/LicenseService.php:45` + `app/Http/Requests/LicenseRequest.php:53` (double écriture, même requête) + `InstallerController.php:80` (pré-install) | OUI — `LicenseRequest.php:34` regex |
| `APP_URL` | Installeur `/install/site` (pré-admin, hors dashboard commerçant) | `InstallerService.php:20-21` | NON VÉRIFIÉ ici — validé via `config('installer.site.form.rules')`, règles non lues (hors scope dashboard) |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Installeur `/install/database` (pré-admin) | `InstallerService.php:32-37` | idem, via `config('installer.database.form.rules')`, non auditées en détail |
| `APP_ENV`, `APP_DEBUG` | Installeur `/install/final-store` (pré-admin) | `InstallerService.php:119-120` | N/A (valeurs codées en dur `'production'`/`'false'`) |

Les 3 lignes Installeur sont **hors du dashboard commerçant post-onboarding**
— gardées par un middleware qui refuse l'accès si `storage_path('installed')`
existe (`InstallerController.php:36-40`, correctif documenté 2026-07-02).
Citées pour l'exhaustivité demandée au point 1 de la mission, pas comme
risque actif du dashboard.

`PaymentGatewayService` (`app/Services/PaymentGatewayService.php:18-22`)
**injecte** `EnvEditor` mais ne l'utilise jamais dans `update()` (ligne
76-101) — les identifiants de passerelle vont dans la table
`gateway_options`, pas dans `.env`. Import mort, pas un site d'écriture.

## 3. Panne mi-course

`EnvEditor::save()` (ci-dessus) écrase le fichier en un seul
`file_put_contents` sans fichier temporaire ni `LOCK_EX`, et ignore la
valeur de retour. Une écriture interrompue (disque plein, permission perdue
en cours de déploiement, kill du worker PHP) laisse `.env` tronqué —
PHP tronque la destination avant d'écrire. Au redémarrage suivant,
`vlucas/phpdotenv` (chargé par Laravel au boot) échoue à parser un `.env`
tronqué/corrompu → l'application ne démarre plus (500 sur toutes les pages,
y compris `/login`). Confirmé par lecture de code — non testé en exécution
(interdit par la mission).

## 4. `config:cache` — l'écran ment-il ?

**Non, dans la posture de prod documentée actuelle** — mais c'est fragile :
- `bootstrap/cache/` dans ce worktree ne contient **pas** `config.php`
  (seulement `packages.php`, `services.php`) → pas de cache config actif ici.
- `tools/deploy-lecayenne.sh:128-137` (script de déploiement canonique
  Le Cayenne) saute **délibérément** `config:cache` — commentaire
  `[FIX TAMPER-FAUX-POSITIF 2026-08-17]` : `AuditLogService::secretFor()`
  (`app/Services/Fiscal/AuditLogService.php:324`, zone gelée) lit
  `env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId)` directement — si le config
  était caché, `env()` renverrait toujours `null` hors fichiers config →
  faux positif TAMPER sur la chaîne fiscale (reproduit 2026-08-12/17).
- Chaque écran (Site/Mail/Company/License) appelle
  `Artisan::call('optimize:clear')` juste après l'écriture
  (`SiteService.php:64`, `MailService.php:52`, `CompanyService.php:45`,
  `LicenseService.php:46`) — donc même si un cache traînait, il est purgé.
- **MAIS** d'autres scripts historiques dans `tools/` (`deploy-now-2026-07-15.sh:44`,
  `deploy-final-2026-07-07.sh:36`, `deploy-now-2026-07-14.sh:35`,
  `deploy-owner8.sh:24`, `kds-poll-tune-2026-07-14.sh:17`) appellent bien
  `config:cache`. Si l'un de ces scripts (non le canonique) est relancé après
  coup, le piège fiscal ci-dessus revient ET les écrans admin continueraient
  de fonctionner seulement parce qu'ils s'auto-purgent — mais toute AUTRE
  lecture `env()` ailleurs dans l'app resterait figée jusqu'au prochain
  déploiement complet.
- `MIX_API_KEY`/`MIX_GOOGLE_MAP_KEY` (Mix build-time) : le risque classique
  « rebuild JS requis » est **atténué** par une injection runtime côté
  serveur — `resources/views/master.blade.php:147-150` pousse
  `window.foodkingConfig = { apiKey: config('app.api_key'), googleMapKey:
  config('app.google_map_key'), ... }` (Blade, lu à chaque requête tant que
  config n'est pas caché) ; le frontend (`resources/js/config/env.js:44,47`)
  préfère `window.foodkingConfig` à `process.env.MIX_*`. `config/app.php:63`
  et `:135` font le pont `env('MIX_API_KEY')`/`env('MIX_GOOGLE_MAP_KEY')`.
  Donc la valeur écrite prend effet dès la requête suivante — sous la même
  condition (pas de config:cache) que le reste.

## 5. Secrets réécrits par ces écrans

- `MAIL_PASSWORD` (mot de passe SMTP) — écrit en clair par
  `MailController::update` (`app/Http/Controllers/Admin/MailController.php:34-41`).
  `MailController::index` (ligne 25-32) **renvoie ce mot de passe en clair**
  dans la réponse JSON — déjà repéré et gardé par
  `permission:settings` sur `index` ET `update`
  (`MailController.php:22`, commentaire `[GOAL-2026-05-30 SET-02]`).
  Le mot de passe est aussi dupliqué dans la table `settings` DB via
  `Settings::group('mail')->set($request->validated())` (`MailService.php:41`)
  — un second endroit où le secret persiste en clair.
- `DB_PASSWORD` — écrit par l'installeur (`InstallerService.php:36`), hors
  dashboard commerçant, non ré-exposé après coup dans ce périmètre.
- `MIX_API_KEY` (licence) — moins un secret qu'une clé de vérification
  publique compilée dans le bundle JS ; néanmoins réécrite en clair et
  revalidée côté API distante (`InstallerService::licenseCodeChecker`).

## 6. Risque le plus grave — preuve

**Un commerçant qui coche « Debug » sur l'écran Dashboard → Site peut
mettre l'application hors service dès la requête suivante, en production.**

Chaîne exacte, vérifiée par lecture de code :
1. `resources/js` (formulaire Site) poste `site_app_debug` → validé
   uniquement `['required','numeric']` (`app/Http/Requests/SiteRequest.php:50`,
   aucune restriction de valeur au-delà du type).
2. `SiteService::update()` traduit ça en écriture directe du fichier
   `.env` : `'APP_DEBUG' => $request->site_app_debug == Activity::ENABLE
   ? 'true' : 'false'` (`app/Services/SiteService.php:48`).
3. `app/Providers/AppServiceProvider.php:251-259` — boot guard :
   ```
   if ((bool) config('app.debug', false)) {
       throw new \RuntimeException(
           'APP_DEBUG=true is forbidden in production: enabling debug leaks '
           ...
       );
   }
   ```
   actif uniquement `if (app()->environment('production'))` (ligne 190),
   et le commentaire ligne 242-250 nomme **explicitement**
   `SiteService:48` comme le vecteur de l'attaque, avec la mention
   « Until the EnvEditor allowlist heal lands » — c'est un défaut CONNU,
   documenté dans le code, non corrigé (backlog V1.0.2), pas une
   découverte inventée par cet audit.
4. Sans `config:cache` actif (confirmé §4 — c'est la posture prod réelle du
   déploiement canonique), `config('app.debug')` relit `.env` **à chaque
   boot**, donc à **chaque requête HTTP** en PHP-FPM classique. Le
   `RuntimeException` non catché à ce stade du boot fait tomber
   TOUTES les pages (admin, kiosk, POS, API) — pas seulement l'écran Site.

Autrement dit : la case à cocher « Debug » sur un écran de réglages destiné
à un commerçant peut, en un clic + enregistrer, transformer l'app entière en
page blanche/500 permanente jusqu'à intervention manuelle sur le serveur
(éditer `.env`, `config:clear`). C'est exactement le scénario « écriture qui
casse l'appli » de la mission, avec preuve `file:line` des deux bouts de la
chaîne (l'écriture ET le garde qui la punit).

Second risque notable, moins sévère : `EnvEditor::save()`
(`vendor/dipokhalder/laravel-env-editor/src/EnvEditor.php:414`) n'est pas une
écriture atomique — toute panne mi-écriture tronque `.env` et tue l'appli au
redémarrage (§3), sur **n'importe lequel** des 4 écrans (Entreprise, Site,
Mail, Licence), pas seulement Site/Debug.
