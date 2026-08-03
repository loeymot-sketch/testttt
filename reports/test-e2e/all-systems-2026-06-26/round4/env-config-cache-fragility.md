# Round 4 — Classe env() fragile sous config:cache (cloud-prep PR-07)

Date: 2026-06-27 · DB foodking_e2e · READ-ONLY · Lane: env() runtime hors config/

## Méthode
`grep -rn "env(" app/ --include="*.php"` → 16 fichiers, 30 occurrences. Pour chacune :
classé runtime-read vs boot/provider/installer ; impact réel si env()=null (comportement
Laravel sous `php artisan config:cache` : env() hors config/ renvoie NULL au runtime car
.env n'est plus chargé — la config cachée bypasse .env).

## Contexte V1 (.env vérifié)
`DEMO=false`, `CURRENCY=EUR`, `CURRENCY_DECIMAL_POINT=2`, `CURRENCY_POSITION=10`,
`DATE_FORMAT=d-m-Y`, `TIME_FORMAT=H:i`, `FISCAL_AUDIT_SECRET=...` (global posé,
**aucun** `FISCAL_AUDIT_SECRET_BRANCH_N`).

---

## FINDINGS

### [P3] app/Services/Fiscal/AuditLogService.php:273 — override secret per-branch lu via env() runtime (FROZEN → escalade)
- **Repro**: `secretFor()` lit `env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId)`. Sous
  config:cache (mandaté prod par boot-guards) cet env() renvoie NULL → la branche
  override est sautée → fallback sur `Config::get('fiscal.audit_secret')` (lui
  config-cache-safe via config/fiscal.php:31). Il n'existe **aucune** clé config pour
  l'override per-branch (config/fiscal.php n'expose que le secret global).
- **Evidence**: config/fiscal.php:28 documente `FISCAL_AUDIT_SECRET_BRANCH_N (optional,
  overrides per branch)` mais cette feature est **inopérante sous config:cache**.
  .env V1 ne pose AUCUN override per-branch → en V1 le code tombe toujours sur le
  global → secret HMAC cohérent → **0 cassure de chaîne réelle en V1**. Risque = footgun
  futur (multi-branche cloud) : un op qui poserait l'override croirait le secret par-branche
  actif alors qu'il est silencieusement ignoré, ou pire un secret per-branche posé AVANT
  config:cache puis ignoré APRÈS → 2 secrets sur la même chaîne.
- **Lentille**: NF525 chain-integrity / cloud-prep. Latent en V1 mono-branche.
- **Reco**: FROZEN (AuditLogService) → **ESCALADE owner**, pas de heal. Si activé un jour :
  router l'override par une clé config (`config('fiscal.audit_secret_branch')[$id]`) au
  lieu d'env() runtime. V1 mono-branche = sûr aujourd'hui.
- **frozen_touch**: true

### [P3] app/Http/Resources/OrderItemResource.php:52 — `env('CURRENCY')` null sous config:cache (label tax FIXE)
- **Repro**: `'tax_type' => $this->tax_type === TaxType::FIXED ? env('CURRENCY') : '%'`.
  TaxType::FIXED = 5. Sous config:cache `env('CURRENCY')` = NULL → le champ `tax_type`
  affiche null/vide au lieu de "EUR" dans le détail commande admin.
- **Evidence**: `SELECT tax_type, COUNT(*) FROM order_items GROUP BY tax_type` →
  **123 lignes tax_type=5 (FIXED)**. Ces 123 items affichent un label `tax_type` null
  côté admin sous prod config:cache. Display-only (le calcul de taxe utilise tax_rate/
  tax_amount, pas ce label). Aucune clé config pour CURRENCY (seul config/menu.php:38
  a 'currency'=>'EUR', non utilisé ici).
- **Lentille**: display FR / config:cache. Cosmétique, non-fiscal.
- **Reco** (non-frozen, healable): `config('app.currency')` ou défaut `?? 'EUR'`. Hors-lane heal.
- **frozen_touch**: false

### [P3] app/Http/SmsGateways/Gateways/Nexmo.php:43 — `env('APP_NAME')` null sous config:cache (sender SMS)
- **Repro**: `->send(new SMS($code.$phone, env('APP_NAME'), $message))` — env('APP_NAME')
  = nom expéditeur. Sous config:cache → NULL → SMS envoyé avec un "from" vide.
- **Evidence**: runtime-read dans le gateway SMS. SMS non câblé en V1 LOCAL Le Cayenne
  (pas de provider SMS actif), donc impact réel = 0 aujourd'hui ; latent si SMS activé.
- **Lentille**: cloud-prep / robustesse. Devrait être `config('app.name')`.
- **frozen_touch**: false

---

## SAFE / DÉJÀ-HEALÉ (non-findings, audités)
- **app/Libraries/AppLibrary.php** date/time/money TOUS healés : l.32/40/48/59/68 `?:'d-m-Y'`/
  `?:'H:i'`, l.301/323/328/421 `?:2`, l.310 `,'€'`, l.449 `,'h:i A'`. Commentaires
  [FR-DATE-SAFE]/[MONEY-FIX]. Résidu mineur l.311 `env('CURRENCY_POSITION')` sans défaut
  mais atteint UNIQUEMENT si `NumberFormatter` absent (présent en PHP standard) → null
  basculerait symbole à droite. Latent, classe déjà traitée. Non-finding.
- **app/Http/Middleware/ApiKeyMiddleware.php:20** — déjà migré `config('app.api_key')`
  (commentaire [SEC-FIX] explicite). Safe.
- **Guards DEMO** (SiteController:34, ItemController:137, SignupController:62,
  LanguageService:112, OtpManagerService:76, SettingResource:95, WizardPerItemDemo:30) :
  sous config:cache env('DEMO')=null = falsy = identique à DEMO=false (V1). OtpManager:76
  et WizardPerItem:30 enveloppent dans filter_var(...,default false) → robustes. Guards de
  désactivation démo → null sûr. Non-findings (SettingResource:95 expose null au lieu de
  "false" = trivial display).
- **InstallerController:29/135** `env('APP_URL')` — chemin installer (avant config:cache). Hors runtime prod.
- **E2EStressCommand:274 / E2ESoakCommand:218/294** — `config('app.api_key') ?: env(...)` :
  config-first, commandes dev/e2e only. Safe.

## Verdict lane
Aucun P0/P1/P2. La classe env()-runtime est **majoritairement déjà healée** (AppLibrary,
ApiKeyMiddleware) ou **sûre par null-falsy** (guards DEMO). 3 résidus P3 cloud-prep, dont
1 FROZEN (AuditLogService:273) à escalader — tous à impact V1 réel nul (mono-branche,
SMS off, label cosmétique).
