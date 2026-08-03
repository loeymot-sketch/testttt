# VERIFY-17 — i18n complet + déploiement (env, secrets, build)

**Date :** 2026-04-20  **Mode :** AUDIT-ONLY (read-only)  **Origine task :** `tasks/verify-2026-04-20/17_VERIFY_I18N_DEPLOY.md`  **Audit source :** `reports/review/AUDIT_POS_110_I18N_DEPLOY_2026-04-19.md`  **Priorité :** P2

> Aucun fichier applicatif n'a été modifié. Seul ce rapport a été écrit.

---

## 0. Plan d'audit (rappel — 5 lignes)

1. Pass A — i18n : parité clés `resources/js/languages/{fr,en}.json` + `lang/{fr,en}/*.php`, recherche regex strings codées en dur dans `*.vue`.
2. Pass B — déploiement : couverture `.env.example` vs `config/*.php`, présence build artifacts `public/{js,css}`, cohérence `webpack.mix.js` ↔ artefacts, supervisord + cron `schedule:run`.
3. Cross-check audit antérieur (`F-I18N-001`, `F-DEP-001`).
4. Application checklist V1–V5.
5. Verdict global + cycles P de remédiation.

---

## 1. Pass A — i18n (FR ↔ EN)

### 1.1 Inventaire fichiers

| Couche | FR | EN | AR / BN / DE |
|---|---|---|---|
| Vue/SPA JSON | `resources/js/languages/fr.json` (834 lignes, 731 clés à plat) | `en.json` (1481 lignes, 1374 clés à plat) | présents |
| Laravel PHP | `lang/fr/*.php` (16 fichiers, ~600 lignes) | `lang/en/*.php` (16 fichiers, identiques en nom) | partiels |

### 1.2 Parité clés `fr.json` ↔ `en.json` (deep-flatten)

- **EN est sur-ensemble strict du FR** (1374 vs 731). L'écart vient de blocs admin dupliqués (`menu.*`, `label.*` payment gateways) absents en FR — admin tourne historiquement en EN par défaut.
- **20 clés présentes en FR mais absentes en EN** (toutes `label.*` ou `message.*` redondantes avec `kiosk.*`) :

```text
label.order, label.add_note, label.empty_cart, label.add_to_cart, label.you_saved,
label.order_confirm, label.order_thank_you, label.good_morning, label.good_afternoon,
label.good_evening, label.thank_you, label.please_come_again, label.kiosk_log_out,
label.no_orders_found, label.no_items_found, label.no_data_available,
label.payment_method_required, label.special_instructions_limit, label.confirm_and_print,
message.payment_method_required
```

> Toutes ces clés sont dupliquées en `kiosk.*` ou `message.*` côté EN : impact réel **faible** sur la surface client (la borne consomme `kiosk.*`, déjà traduit).

- **Surfaces critiques kiosk P1–P10** présentes **dans les deux langues** :  
  `kiosk.cash_instruction.*`, `kiosk.pay_screen.*` (TR/CB/cash), `kiosk.error.payment_refused.*`, `kiosk.error.network.*`, `kiosk.error.product_removed.*`, `kiosk.loyalty_screen.*`, `kiosk.wizard.*` (full), `kiosk.upsell_screen.*`, `kiosk.idle_screen.*`, `kiosk.admin_screen.*` (PIN, hardware health), `kiosk.consent.*`, `kiosk.offline_queue.*`, `kiosk.waiting_screen.*` (timeout/cancel/network_lost), `kiosk.hardware.*`.

### 1.3 Parité `lang/fr/*.php` ↔ `lang/en/*.php`

- 16 fichiers de chaque côté (mêmes noms). Vérification programmatique non exécutée (les fichiers PHP `addressType.php`, `ask.php`, `orderStatus.php` etc. dépendent d'enums `App\Enums\*` qui requièrent un bootstrap Laravel) — **read-only static** : la liste de fichiers est identique, et `validation.php` (176 lignes) et `installer.php` (74 lignes) sont structurellement alignés FR/EN.
- Confirme l'observation `F-I18N-001` de l'audit : couverture exhaustive des messages de validation Laravel **non re-vérifiée** ce run.

### 1.4 Hardcoded FR dans templates Vue

Recherche : `>[A-ZÉÈ][a-zéèàêçùâô]{3,}[\s?!.<]` sur `resources/js/**/*.vue` (330 fichiers).

**12 fichiers concernés** :

```text
resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue
resources/js/components/admin/components/ErrorBoundary.vue
resources/js/components/admin/settings/ItemCategory/ItemCategoryCreateComponent.vue
resources/js/components/admin/items/variation/ItemVariationCreateComponent.vue
resources/js/components/admin/items/variation/ItemVariationListComponent.vue
resources/js/components/admin/items/extra/ItemExtraCreateComponent.vue
resources/js/components/admin/items/extra/ItemExtraListComponent.vue
resources/js/components/admin/dashboard/AuditTrailComponent.vue
resources/js/components/admin/dashboard/ChannelStatsComponent.vue
resources/js/components/admin/dashboard/SlaAlertsComponent.vue
resources/js/components/admin/dashboard/RealtimeReportComponent.vue
resources/js/components/admin/onlineOrders/OnlineOrderShowComponent.vue
```

Échantillons :

```13:29:resources/js/components/admin/dashboard/AuditTrailComponent.vue
                            <th scope="col" class="px-6 py-3 rounded-tl-lg">Utilisateur</th>
                            <th scope="col" class="px-6 py-3">Action</th>
                            <th scope="col" class="px-6 py-3">Ressource</th>
                            <th scope="col" class="px-6 py-3 rounded-tr-lg">Temps</th>
// ...
                            <td colspan="4" class="px-6 py-8 text-center text-gray-500">Aucun historique récent.</td>
```

```65:118:resources/js/components/admin/settings/ItemCategory/ItemCategoryCreateComponent.vue
                                <option value="simple">Simple (pas de wizard)</option>
                                <option value="tacos">Tacos</option>
// ...
                            <label class="db-field-title">Borne — proposer ces articles en suggestion panier</label>
// ...
                            <label class="db-field-title">Borne — sauter l'écran suggestion si le panier n'a que cette catégorie</label>
```

> Surfaces concernées : **Admin / Dashboard / KDS** uniquement. La surface **Kiosk client** (sous `resources/js/components/frontend/kiosk/**`) n'apparaît dans **aucun** match de cette regex (au-delà du DS qui n'a que des accents inline contrôlés). H1 (clé i18n manquante EN sur composants kiosk cash/TR) : **non confirmée** côté kiosk.  
> H2 (hardcoded FR dans `.vue`) : **confirmée pour Admin/KDS, non pour Kiosk client**.

---

## 2. Pass B — déploiement

### 2.1 `.env.example` — couverture vs configs réelles

Présent (active ou commenté volontairement pour bascule prod) :  
`APP_*`, `DB_*`, `REDIS_*`, `CACHE_DRIVER`, `QUEUE_CONNECTION`, `BROADCAST_DRIVER=pusher`, `PUSHER_APP_*`, `SESSION_*`, `SANCTUM_STATEFUL_DOMAINS`, `MAIL_*`, `AWS_*`, `MIX_API_KEY` (+ note `config('app.api_key')`), `KIOSK_MACHINE_USERNAME/PASSWORD`, `STAFF_ONLY_MODE`, `KIOSK_USE_POS_WIZARD`, `PRICING_USE_SSOT`, `LOGIN_LOCKOUT_MAX_ATTEMPTS`, `API_THROTTLE_PER_MINUTE`, `HEALTH_IPS_ALLOWED`, `TIMEZONE`, `CASHIER_CURRENCY*`, `FISCAL_AUDIT_SECRET`, `FISCAL_Z_REPORT_SECRET`, `FISCAL_ARCHIVE_*`, exemples TPE kiosk.

**Manquants ou non documentés dans `.env.example`** :

| Variable | Référencée par | Sévérité | Note |
|---|---|---|---|
| `FCM_SERVER_KEY`, `FCM_SENDER_ID`, `FCM_TOPIC_PREFIX` | `config/services.php` (push notifications) | Moyen | Push notifications mobile clients sans valeur ⇒ silencieux |
| `LOG_CHANNEL`, `LOG_LEVEL` | `config/logging.php` (default + per-channel) | Faible | Mention indirecte ligne 163 (`production_json`) sans bloc dédié |
| `SENTRY_DSN`, `SENTRY_TRACES_SAMPLE_RATE` | aucune (composer.json sans `sentry/sentry-laravel`) | Info | Sentry **non installé** côté backend → variable inutile tant que dépendance absente. Cf. `VERIFY-15 OBSERVABILITY` et `T03B_SENTRY_FRONT`. |
| `MIX_GOOGLE_MAP_KEY` | `config/app.php` (`google_map_key`) | Faible | Map admin-only |

→ H3 (`.env.example` incomplet Pusher/fiscal) : **non confirmée pour Pusher/fiscal** (présents) ; **partiellement confirmée pour FCM/Sentry/log channels**. Même conclusion que `F-DEP-001`.

### 2.2 Build front — pipeline & artefacts

- Outil : **Laravel Mix** (webpack.mix.js, scripts `npm run prod` → `mix --production`). Pas de `vite.config.js` malgré la mention §2 du task — le projet n'a **pas migré** vers Vite (`@vitejs/plugin-vue` est présent en devDeps mais inutilisé par mix).

```1:14:webpack.mix.js
const mix = require('laravel-mix');
// ...
mix.js('resources/js/app.js', 'public/js').vue().postCss('resources/css/app.css', 'public/css', [require("tailwindcss")])
```

- **Anomalie majeure (drift build)** : `webpack.mix.js` ne déclare **qu'une seule entrée** (`app.js` + `app.css`). Or `public/js/` contient :

```text
public/js/app.js          4 643 930 B   2026-04-18 14:45
public/js/kiosk.js          525 755 B   2026-04-18 16:49
public/js/pos-wizard.js     287 207 B   2026-04-20 12:48
public/css/app.css          142 959 B   2026-04-18 16:49
public/css/pos-wizard.css    41 351 B   2026-03-25 23:44
```

- `public/mix-manifest.json` ne référence **ni `pos-wizard.js` ni `pos-wizard.css`** :

```1:5:public/mix-manifest.json
{
    "/js/app.js": "/js/app.js",
    "/js/kiosk.js": "/js/kiosk.js",
    "/css/app.css": "/css/app.css"
}
```

- Conséquence : un `npm run prod` "propre" sur un environnement neuf **ne reconstruira pas** `kiosk.js` ni `pos-wizard.js` ni `pos-wizard.css` — les artefacts actuels sont des reliquats hérités d'un build local précédent. Les bornes en prod risquent de servir une version **stale** ou cassée si on `git clean -xfd && npm ci && npm run prod`.
- H4 (build prod fail sur warnings) : **non vérifiable AUDIT-ONLY**, mais H4-bis détectée : **build incomplet par configuration**.

### 2.3 Workers & ordonnancement

- Schedule kernel : `app/Console/Kernel.php` enregistre `foodking:outbox:rescue` (`->everyMinute()`) et un autre cmd (`->everyFiveMinutes()`). Schedule fonctionnel.
- Cron documenté : `docs/DEPLOYMENT_GUIDE_V1.md:96` :  
  `* * * * * cd /var/www/foodking-web && php artisan schedule:run >> /dev/null 2>&1`
- Supervisord queue worker : `docs/QUEUE_WORKER_SETUP.md:91` (block `[program:foodking-worker]` complet, 2 procs, max-jobs 1000, autorestart, killasgroup) + variante `[program:foodking-fcm-worker]` ligne 191.
- Realtime/Pusher : `docs/REALTIME_SETUP.md` (96 lignes) couvre soketi/pusher.
- Procédures déploiement : `docs/DEPLOIEMENT.md` (628 lignes) + `docs/DEPLOYMENT_GUIDE_V1.md` (121 lignes) + `docs/PRODUCTION_SETUP.md` (100 lignes) — `migrate --force`, `optimize`, rollback DB, checklist sécurité, extensions PHP requises.
- H5 (procédure migrations zero-downtime) : **partiellement traitée** (`docs/DEPLOIEMENT.md §3`) — pas de procédure formalisée "expand/contract" mais runbook présent.

---

## 3. Cross-check audit `F-I18N-001` / `F-DEP-001`

| Finding (audit 04-19) | Statut ce run |
|---|---|
| `F-I18N-001` UI POS/KDS : pas de couverture exhaustive `lang/*` + `$t()` | **Reconfirmé** — admin/KDS hardcoded FR, parité PHP non re-vérifiée, mais kiosk client OK |
| `F-DEP-001` parité staging=prod non démontrée + branches multi-tenant | **Reconfirmé** — pas d'évidence staging/prod identique ; `branch_id` scoping reste hors scope de ce VERIFY |

---

## 4. Checklist § 5 (résultats)

| ID | Vérification | Statut | Justification |
|---|---|---|---|
| **V1** | Toutes clés nouveaux composants P1–P10 traduites FR+EN | **WARN** | Surface kiosk client (`kiosk.*`) couverte des deux côtés. 20 clés `label.*/message.*` orphelines en EN mais doublonnées par `kiosk.*` (impact UX nul sur borne). Reste ~660 clés admin EN-only (admin historiquement EN). |
| **V2** | Aucun string hardcodé dans templates Vue | **WARN** | 12 fichiers admin/KDS contiennent du FR codé en dur (AuditTrail, ItemCategoryCreate, KDS, ErrorBoundary, etc.). **0 occurrence côté `frontend/kiosk/`** (surface client). |
| **V3** | `.env.example` à jour (Pusher, fiscal, queue, mail, sentry, etc.) | **WARN** | Pusher/Fiscal/Queue/Mail/Sanctum **présents**. Manquants : `FCM_SERVER_KEY`, `FCM_SENDER_ID`, `LOG_CHANNEL`, `LOG_LEVEL`, `MIX_GOOGLE_MAP_KEY`. `SENTRY_*` non requis tant que dépendance absente. |
| **V4** | Build prod `npm run build` sans warning critique | **FAIL (configuration)** | `webpack.mix.js` ne déclare qu'`app.js`/`app.css` ; `kiosk.js`, `pos-wizard.js`, `pos-wizard.css` présents en `public/` mais **non reconstruits par `mix --production`**. `mix-manifest.json` n'inclut pas `pos-wizard.*`. Build non auditable car non lancé (AUDIT-ONLY) — drift configuration confirmé statiquement. |
| **V5** | `php artisan optimize` + `migrate --force` documentés + cron + supervisord | **GREEN** | `docs/DEPLOIEMENT.md`, `DEPLOYMENT_GUIDE_V1.md`, `PRODUCTION_SETUP.md`, `QUEUE_WORKER_SETUP.md`, `REALTIME_SETUP.md` couvrent supervisord (`[program:foodking-worker]`), cron `* * * * * schedule:run`, `migrate --force`, `optimize`, rollback. |

---

## 5. Critères d'acceptation (§ 6)

- ALL_GREEN : **non** (V1, V2, V3 en WARN ; V4 en FAIL configuration).
- WARN si V2 quelques exceptions : ✓ (V2 WARN).
- FAIL si V1 rouge sur surface client : **non** (kiosk client correctement traduit FR+EN).

---

## 6. Risques & impact

1. **V4 build drift** — Risque opérationnel : un déploiement propre (`git clean -xfd && npm ci && npm run prod`) **ne livrera pas** `kiosk.js`, `pos-wizard.js`, `pos-wizard.css`. Bornes/POS Wizard servent alors un asset manquant ou stale → **panne potentielle borne au prochain release**. Probabilité élevée si CI/CD lance `mix --production` sur un workspace clean.
2. **V2 admin/KDS FR hardcoded** — Bloque la traduction EN/AR/BN/DE pour admin et KDS. Pas de risque immédiat client, mais dette i18n.
3. **V3 FCM env manquant** — Push notifications clients silencieusement désactivées si `.env` recopié de l'exemple sans ajouter manuellement les clés.
4. **V1 doublons clés** — Cosmétique, peut générer warnings dans certaines libs i18n (`vue-i18n` v9 silencieux par défaut).

---

## 7. Conclusion

**GLOBAL: WARN**

Détail : V1 WARN (parité label/message, kiosk client OK) · V2 WARN (12 fichiers admin/KDS hardcoded FR, kiosk client clean) · V3 WARN (FCM/LOG manquants) · V4 **FAIL config build** (drift `webpack.mix.js` ↔ artefacts `kiosk.js`/`pos-wizard.*`) · V5 GREEN (supervisord + cron + migrate/optimize documentés).

Le verdict global est ramené à **WARN** plutôt qu'à FAIL parce que la critère bloquant déclaré (V1 rouge sur surface client) n'est pas atteint ; toutefois **V4 doit être traité avant tout déploiement clean** sinon la borne perdra ses assets.

---

## 8. Cycles de remédiation proposés

| Priorité | Cycle | Périmètre | Triggered by |
|---|---|---|---|
| **P0** | `P12B_BUILD_PIPELINE_RESTORE_KIOSK_POSWIZARD` | Restaurer dans `webpack.mix.js` les entrées `mix.js('resources/js/kiosk.js', 'public/js')` + `mix.js('resources/js/pos-wizard.js', 'public/js')` + `.postCss('resources/css/pos-wizard.css', 'public/css')` ; régénérer `mix-manifest.json` ; ajouter test CI `npm run prod && test -f public/js/kiosk.js` | V4 |
| **P1** | `P12_DEPLOY_PROCEDURE_DOC` (existant audit task) | Compléter `.env.example` (FCM_*, LOG_CHANNEL, LOG_LEVEL, MIX_GOOGLE_MAP_KEY) ; expliciter procédure migrations zero-downtime | V3, H5 |
| **P2** | `P11_I18N_COMPLETE_FR_EN` (existant audit task) | Extraire FR hardcoded des 12 fichiers admin/KDS vers `lang/*` ou `*.json` ; aligner doublons `label.* ↔ kiosk.*` ; valider parité PHP enums avec bootstrap Laravel | V1, V2 |
| **P3** | `P_OBS_SENTRY_BACKEND` (lié à task `03_TASK_SENTRY_FRONT`) | Décider install ou non `sentry/sentry-laravel` ; si oui, ajouter `SENTRY_*` à `.env.example` | V3 (Sentry) |

---

## 9. Liens

- Audit source : `reports/review/AUDIT_POS_110_I18N_DEPLOY_2026-04-19.md`
- Tâches connexes : `tasks/audit-orchestration/03_TASK_SENTRY_FRONT_REGRESSION_2026-04-20.md`, `tasks/verify-2026-04-20/15_VERIFY_OBSERVABILITY_PERF.md`
- Documentation déploiement : `docs/DEPLOIEMENT.md`, `docs/DEPLOYMENT_GUIDE_V1.md`, `docs/PRODUCTION_SETUP.md`, `docs/QUEUE_WORKER_SETUP.md`, `docs/REALTIME_SETUP.md`, `docs/FISCAL_SECRETS.md`
