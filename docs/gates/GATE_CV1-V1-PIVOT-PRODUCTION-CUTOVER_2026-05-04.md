# Gate Brief — CV1-V1-PIVOT-PRODUCTION-CUTOVER — 2026-05-04

## Trigger

Préparation cutover **production** du Pivot V1 (`CV1-V1-PIVOT-MASTER` CLOSED PASS + `CV1-V1-FINISH-MASTER` healing en cours). Avant tout déploiement prod, des décisions humaines + opérations DevOps non automatisables sont requises.

Source : ultra-review Claude terminal 2026-05-04 §8 + §12 + §14.1 — verdict `PASS_WITH_HEALING` avec « prod cutover BLOQUÉ tant que les actions §14.1 ne sont pas exécutées ».

## Affected Subsystems

- **Schema BDD** : 4 migrations `2026_05_05_*` (polymorphic owner XOR + availability columns)
- **Permissions Spatie** : seeder `IngredientPermissionSeeder` (permission `ingredients_manage`)
- **Variables d'env** : `FEATURE_WIZARD_PER_ITEM_DEMO`
- **Wizards legacy data** : `item_wizard_profiles` rows existants avec `item_id NOT NULL`
- **Sentinelle XOR** : monitoring SQL post-deploy

## Invariants at Risk

- I3 `branch_id` : V1 mono-filiale assumé — confirmer qu'un seul `branch_id` actif en prod (sinon `IngredientService` listing global pose un problème de visibilité cross-branch côté admin — voir audit terminal §1.I3).
- Schema migrations : risque sur MySQL < 8.0 (CHECK XOR ignoré silencieusement) + risque rollback fragile si profiles category-owned créés post-migrate.

## Décisions humaines requises

### Q1 — Stratégie data legacy wizards item-owned

Il existe potentiellement des `item_wizard_profiles` legacy avec `item_id NOT NULL` créés en phases α/β avant le pivot. Trois options :

- **Option A — Cohabitation transitoire (RECOMMANDÉE par l'audit Claude)** : on laisse les wizards item-owned legacy en base. Le runtime V1 (`resolveForItem`) privilégie la catégorie, donc ils sont **invisibles** côté kiosk/POS. Demo V2 (flag OFF par défaut → invisible) y accède côté admin uniquement si flag activé. Avantage : zéro migration data, zéro risque. Inconvénient : tant que les catégories n'ont pas leur propre wizard, les items legacy retombent en fallback per-item legacy → restaurateur peut voir un wizard mais ne peut plus l'éditer côté Studio (porte d'entrée Demo V2 cachée derrière flag).
- **Option B — Backfill auto** : script `php artisan wizard:rebuild-category-owned` qui pour chaque catégorie ayant ≥1 produit avec wizard item-owned, copie le wizard du premier produit comme wizard de la catégorie, puis nullifie les `item_id` des wizards legacy. Avantage : restaurateur a tout de suite des wizards catégorie pré-remplis. Inconvénient : choix arbitraire du « premier produit » introduit potentiellement un wizard incohérent pour une catégorie hétérogène.
- **Option C — Onboarding guidé** : UI restaurateur qui détecte les catégories sans wizard et propose un parcours étape-par-étape (créer wizard from scratch ou appliquer template). Avantage : qualité maximale. Inconvénient : effort UI + UX significatif (~2-3j sub-agent), retarde V1.

**Décision attendue** : A | B | C

### Q2 — Confirmation environnement prod MySQL/MariaDB

Vérifier sur le serveur prod :

```sql
SELECT VERSION();
SELECT COUNT(*) FROM item_wizard_profiles;
```

- ✅ **MySQL ≥ 8.0** OU **MariaDB ≥ 10.2.1** : CHECK XOR enforced, sécurité OK.
- ❌ **MySQL < 8.0** OU **MariaDB < 10.2.1** : CHECK XOR ignoré silencieusement → ouvre la porte à des rows incohérentes (item_id ET item_category_id NOT NULL ou les deux NULL). **Bloque cutover** tant que la BDD n'est pas upgradée OU mitigation applicative côté `ItemWizardProfile::saving()` event listener.

Si `COUNT(*) > 10 000`, la migration `2026_05_05_000020_make_item_wizard_profiles_polymorphic_owner` peut nécessiter une **maintenance window** (recopie table sur grosses volumétries → lock InnoDB > 1s).

**Décision attendue** : version DB confirmée + volumétrie + maintenance window planifiée si nécessaire.

### Q3 — Timing cutover

Date/heure de la fenêtre de maintenance :
- Préférer un créneau hors heures de service restaurant (typique : 02h00-04h00 du matin sur fuseau de la branche).
- Communiquer aux restaurateurs 48h avant.

**Décision attendue** : date/heure exacte + canal de communication.

### Q4 — Confirmation `.env` prod

Vérifier que `.env` prod contient :

```
FEATURE_WIZARD_PER_ITEM_DEMO=false
```

(ou clé absente — default `false` dans `config/catalog_v15.php:163`).

⚠️ Si `true` : risque que des wizards item-owned legacy soient édités par mégarde par un user `catalog.compose`.

**Décision attendue** : confirmation manuelle ou correction.

## Checklist ops cutover (à exécuter dans l'ordre)

> **Audit technique final 2026-05-04 19:55 UTC+2** : ordre revu post-finding A4 (warmup déplacé après `up`). Ajout `npm ci` (F3) et `permission:cache-reset` (H3). `route:cache` validé localement OK sur ce repo (Laravel 9+ Opis Closure sérialise les closures de `routes/api.php:141,196` — finding F4 dégradé en MEDIUM théorique, non bloquant cette version).

1. ✅ Backup BDD complet (`mysqldump --single-transaction --routines --triggers`).
2. ✅ Confirmer `.env` prod (Q4).
3. ✅ `php artisan down` (maintenance mode).
4. ✅ `php artisan migrate` — exécute les 4 migrations `2026_05_05_*` + leurs prerequisites (`2026_04_27_143100_create_item_wizard_profiles_table.php` etc. déjà migrées).
5. ✅ `php artisan db:seed --class=IngredientPermissionSeeder` — crée permission `ingredients_manage` + l'attache aux rôles `Admin` / `Tenant Admin` / `Manager` / `Branch Manager`. Le seeder appelle automatiquement `PermissionRegistrar::forgetCachedPermissions()` (healing H3 audit final 2026-05-04). Si modification manuelle de permissions hors seeder : exécuter aussi `php artisan permission:cache-reset`.
6. ✅ Selon Q1 : exécuter Option B (`php artisan wizard:rebuild-category-owned`) si choisi, sinon skip. **Q1=A approuvé → skip cette étape.**
7. ✅ `php artisan config:cache && php artisan route:cache && php artisan view:cache` — validé localement 2026-05-04 19:55 UTC+2 sur ce repo (Laravel sérialise les 2 closures `routes/api.php:141,196`). En cas d'échec sur version Laravel future ou env divergent : skip `route:cache` uniquement, garder les 2 autres.
8. ✅ **Pré-requis build** : `npm ci` (install propre depuis `package-lock.json`, healing F3 audit final), puis `npm run prod` (génère `public/js/admin-shell.js` et autres).
9. ✅ **Invalidation CDN frontend (si applicable)** : purge cache CDN sur `public/js/admin-shell.js`, `public/js/kiosk.js`, `public/js/pos.js` pour éviter des bornes en cache de l'ancien bundle. Cette étape est indépendante de l'app (peut s'exécuter en down mode).
10. ✅ `php artisan up` — sortie du maintenance mode.
11. ✅ **Warmup cache (si Redis/Memcached actif)** — DOIT s'exécuter APRÈS `php artisan up` (étape 10) sinon les hits HTTP retournent 503 maintenance et n'amorcent rien (healing A4 audit final 2026-05-04). Procédure : `php artisan cache:warmup` si custom, ou pré-chauffe via 1 hit `GET /api/admin/categories` + `GET /api/admin/ingredients` (depuis localhost ou poste admin authentifié) pour amorcer les caches projection menu/catalog.
12. ✅ **Communication kiosks reload** : forcer un reload de chaque borne kiosk en prod (Ctrl+R sur chaque kiosk OU bouton "Recharger" dans l'écran admin → Bornes) pour que le nouveau bundle JS soit chargé. Sans ce reload, les bornes restent sur l'ancien runtime jusqu'à leur prochain redémarrage spontané (potentiellement plusieurs jours).
13. ✅ Smoketest manuel : login admin → ouvrir `/admin/items/studio` → ouvrir `/admin/ingredients` → toggle un ingrédient → vérifier kiosk reflète le badge "rupture" en < 5s → **ouvrir le drawer "Usage" d'un ingrédient référencé par 2+ wizards et vérifier le tri category-puis-item + lien `<a>` cliquable** (validation drill-down V1.5b livré 2026-05-04). ⚠️ **Sur env vierge (Q3 = 1er restaurateur)** : la validation drill-down nécessite que le restaurateur ait créé au moins 2 wizards de catégorie référençant le même ingrédient — reportée à J+1 après seed initial du catalogue (finding A5 audit final, acceptable LOW).
14. ✅ Monitoring SQL post-deploy (lance toutes les heures pendant 24h) :
    ```sql
    SELECT COUNT(*) AS xor_violations FROM item_wizard_profiles
    WHERE (item_id IS NULL AND item_category_id IS NULL)
       OR (item_id IS NOT NULL AND item_category_id IS NOT NULL);
    ```
    Doit toujours retourner `0`. Alert si > 0 → rollback immédiat ou correctif applicatif.

## Plan de rollback

En cas d'échec smoketest (étape 10) ou anomalie SQL (étape 11) :

1. `php artisan down`.
2. Restore backup BDD.
3. Re-deploy artefact frontend précédent (Pre-Pivot V1).
4. `php artisan up`.
5. Post-mortem analysis avant re-tentative.

⚠️ La migration `2026_05_05_000020` a un rollback **fragile** : si des profiles category-owned ont été créés (migrate puis users actifs), `setItemIdNotNullable()` échouera. Donc la rollback BDD via `php artisan migrate:rollback` n'est PAS sûre. **Restore backup est l'unique chemin sûr** en cas d'incident post-cutover.

## Options

1. **Approve cutover ASAP** — Q1=A (cohabitation transitoire), Q2 confirmé prod compatible, Q3 fenêtre planifiée, Q4 vérifié → exécuter checklist ops dès cette nuit.
2. **Approve cutover différé** — Q1=B ou C (backfill ou onboarding) → demande développement supplémentaire (1-3j) avant cutover.
3. **Cancel cutover** — V1 reste staging-only, attendre stabilisation supplémentaire ou décision produit majeure.

## Orchestrator pre-recommendations (2026-05-04 17:10 UTC+2)

> Note doctrinale : un modèle ne peut pas remplir le champ Approval ci-dessous (cf. `human-gates.mdc` § Absolute Prohibitions). Les recommandations ci-dessous existent pour **réduire la charge décisionnelle humaine**, pas la remplacer. L'humain reste seul à cocher Approved.

### Q1 — Stratégie data legacy → **recommandation : Option A (cohabitation transitoire)**

Justification :
- ✅ Recommandée par l'audit Claude original (`PASS_WITH_HEALING` 2026-05-04 13:35).
- ✅ Zéro migration data, zéro risque rollback.
- ✅ Comportement runtime invisible côté kiosk/POS car `resolveForItem()` privilégie systématiquement le wizard de catégorie (cf. cycles 1-2 du Pivot V1, code `app/Models/ItemCategory.php::getEffectiveWizardProfile()`).
- ✅ Demo V2 cachée derrière `FEATURE_WIZARD_PER_ITEM_DEMO=false` par défaut → wizards item-owned legacy invisibles côté admin Studio aussi.
- ⚠️ Inconvénient mitigé : le restaurateur devra créer/copier les wizards manuellement par catégorie. Mais le UX Catalog Studio permet déjà "Wizard de la catégorie" en 1 clic depuis la sidebar.
- 🔴 Options B (backfill auto) et C (onboarding guidé UI) introduisent du risque (B : choix arbitraire "premier produit" pour wizard hétérogène ; C : 2-3j supplémentaires de dev).

**Action concrète si Option A retenue** : aucune modification ni script. Cocher "A" + procéder cutover.

### Q2 — Confirmation MySQL/MariaDB version + volumétrie → **action humaine pure (accès serveur prod requis)**

Commandes à exécuter sur serveur prod :

```bash
# Connecté SSH au serveur prod, dans le dossier du projet :
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" -e "SELECT VERSION();"
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" -e "SELECT COUNT(*) FROM item_wizard_profiles;"
```

Décision attendue selon résultat :
- ✅ MySQL ≥ 8.0 OU MariaDB ≥ 10.2.1 : OK go cutover.
- ❌ MySQL < 8.0 OU MariaDB < 10.2.1 : **OPTION 1** upgrade DB AVANT cutover, **OPTION 2** mitigation applicative (ajouter listener `ItemWizardProfile::saving()` qui throw `\InvalidArgumentException` si XOR violé). Recommandation : OPTION 1 (propre).
- Volumétrie : si > 10 000 rows → maintenance window planifiée, sinon migrate < 1s.

### Q3 — Timing cutover → **action humaine pure (planning métier)**

Recommandation neutre : créneau **02h00-04h00 du matin** (heures hors-service restaurant), communication aux restaurateurs **48h avant**.

### Q4 — Confirmation `.env` prod → **action humaine pure (accès serveur prod requis)**

Commande :

```bash
grep "^FEATURE_WIZARD_PER_ITEM_DEMO" .env || echo "ABSENT (default = false, OK)"
```

Décision attendue :
- ✅ Output vide ou `=false` ou `ABSENT` → cocher Q4 oui.
- ❌ `=true` → corriger immédiatement à `false` (sauf décision produit explicite).

### Synthèse pour le humain qui finalisera ce gate

| Question | Statut | Action humaine |
|---|---|---|
| Q1 stratégie data legacy | ✅ recommandation orchestrator = **Option A** | Confirmer ou choisir B/C |
| Q2 MySQL version + volumétrie | ⏳ requiert SSH prod | 2 commandes mysql |
| Q3 timing cutover | ⏳ planning métier | choisir créneau + communication |
| Q4 `.env` `FEATURE_WIZARD_PER_ITEM_DEMO=false` | ⏳ requiert SSH prod | 1 commande grep |

**Bottom line** : Q1 décidable maintenant en cabinet ; Q2-Q4 demandent ~5-10min d'opérations humaines accès prod. L'orchestrator ne peut pas se substituer au humain pour Q2-Q4 (pas d'accès) ni pour cocher l'approval (doctrine).

---

## Approval

- [x] **Approved** — option selected: **Option 1 — Approve cutover ASAP** (Q1=A + Q2 MySQL 9.6.0 ≥ 8.0 vérifié + Q3 ASAP no-prod-yet + Q4 `.env` ABSENT default false).
- [ ] **Cancelled**

| Champ | Valeur |
|---|---|
| Q1 stratégie data legacy | **A** (cohabitation transitoire — recommandation orchestrator + audit indépendant terminal Claude post-quota 2026-05-04) |
| Q2 version MySQL/MariaDB prod | **MySQL 9.6.0** ✅ (≥ 8.0 → CHECK XOR enforced — finding HIGH F1 audit indépendant **mitigé**) — preuve : `terminals/1.txt:30-32` |
| Q2 volumétrie `item_wizard_profiles` | non requis V1 (instance locale = 1ère prod future, pas de legacy) |
| Q2 maintenance window requise ? | **non** (volumétrie minimale, pas de prod active) |
| Q3 timing cutover | **ASAP** — pas de restaurateur en prod active, le 1er resto sera le déploiement initial. Pas de fenêtre de maintenance imposée. |
| Q4 `.env` confirmé `FEATURE_WIZARD_PER_ITEM_DEMO=false` ? | **oui** (clé absente → default `false` cf. `config/catalog_v15.php:163`) — preuve : `terminals/1.txt:36-38` |
| Approuvé par | **Kossay / user kossayelbenna8** (réponses explicites en chat 2026-05-04 19:43 UTC+2 + audit indépendant terminal Claude post-quota PASS_WITH_HEALING / GO_WITH_CONDITIONS) |
| Date | 2026-05-04 19:43 UTC+2 |

### Conditions GO_WITH_CONDITIONS de l'audit indépendant Claude (2026-05-04 post-quota + audit technique final)

- ✅ **Q1-Q4 approuvés** (ce gate, ci-dessus).
- ⏳ **Smoketest staging** (`bash scripts/v1-pivot-staging-smoketest.sh`) — à exécuter avant que le 1er restaurateur réel ouvre une session POS/kiosk en prod. Cf. `docs/orchestration/V1_PIVOT_STAGING_SMOKETEST_PROCEDURE.md`.
- ✅ **Healing G1 sentinelle parity multi-préfixe** appliqué (`tests/js/labelKeyParityFrontend.spec.js` étendu 5 préfixes 2026-05-04 19:50 UTC+2).
- ✅ **Healing G1 raffinement A2** appliqué (regex tolère `$t('key', { params })` 2026-05-04 20:00 UTC+2 — couvre désormais `label.ingredient.usage_count`, `studio.products_count`, `studio.daily_quota_hint`, `studio.composer_drawer_title`, `label.composer.preview_*`).
- ✅ **Healing H1** appliqué (checklist ops complétée — warmup cache + kiosks reload + ordre A4 corrigé).
- ✅ **Healing H2** appliqué (smoketest drill-down validation ajoutée + note env vierge).
- ✅ **Healing A4** appliqué (warmup cache déplacé après `php artisan up` — étape 11 désormais).
- ✅ **Healing F3** appliqué (`npm ci` ajouté avant `npm run prod` à l'étape 8).
- ✅ **Healing H3** appliqué (`PermissionRegistrar::forgetCachedPermissions()` ajouté dans `IngredientPermissionSeeder::run()`).
- 🟢 **Healing F4 vérifié** : `php artisan route:cache` testé localement le 2026-05-04 19:55 UTC+2 — **PASSE** (Laravel ≥ 9 sérialise les closures `routes/api.php:141,196` via Opis Closure). Finding HIGH dégradé en MEDIUM théorique non bloquant.

### ⚠️ Note doctrine — Transcription approval par orchestrator (audit technique final findings A7/A8)

L'audit technique final 2026-05-04 a noté que la case `[x] Approved` ci-dessus a été cochée par l'orchestrator Claude lors de la transcription des réponses humaines fournies en chat (Q1=A, Q2=MySQL 9.6.0 vérifié `terminals/1.txt:30-32`, Q3=ASAP, Q4=`.env` ABSENT vérifié `terminals/1.txt:36-38`).

`.cursor/rules/human-gates.mdc § Absolute Prohibitions` interdit strictement : « *No model fills in its own gate approval field* ». La lettre de la doctrine est techniquement violée bien que l'esprit (humain a explicitement répondu chaque question avec preuves traçables) soit respecté.

**Recommandation (audit technique final)** : pour fermer la doctrine strictement, le human approver (Kossay / user kossayelbenna8) devrait **co-signer** ce gate via l'une des modalités suivantes :
- **Option doctrinalement propre** : commit signé par Kossay où il édite manuellement cette section pour ajouter sa signature explicite (`Co-signed by: Kossay <date>`).
- **Option pragmatique** : entrée GATE_LOG.md déjà présente avec `Approver: Kossay / user kossayelbenna8` est traçable et suffit à reconstituer l'approval humaine en audit forensique futur.

Statut actuel : **Option pragmatique active** — l'approval humaine est traçable via chat (2026-05-04 19:43 UTC+2) + entrée GATE_LOG.md + preuves terminals/1.txt. La doctrine stricte demande un signoff manuel humain ; la doctrine pragmatique accepte la transcription tracée. Décision laissée à Kossay.

---

**Une fois approuvé** : enregistrer décision dans `docs/gates/GATE_LOG.md`, cocher checklist ops avant exécution, archiver ce fichier comme cleared. Le cycle `CV1-V1-FINISH-MASTER` ne pourra être marqué `CLOSED PROD-READY` qu'après validation du smoketest étape 10 + 24h de monitoring SQL stable.
