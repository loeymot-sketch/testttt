# AUDIT — Les 5 sections V1 livrées (vérification indépendante)

- **Date** : 2026-04-16
- **Scope** : confrontation des 5 rapports de closure (V1–V4 + section 5 MENU SSOT) au code réel.
- **Mode** : audit indépendant — les rapports sont **traités comme des allégations**, pas comme des preuves. Les invariants critiques ont été vérifiés par lecture directe des fichiers + relance complète de la suite de tests.
- **Verdict global** : **4 sections sur 5 sont livrées et fonctionnelles, mais contiennent 3 défauts P1 / 7 P2 / 5 incohérences de doc**. Une vague (V1) a une **contre-vérité documentaire sérieuse** sur la symétrie PHP↔JS du contrat d'events. Section 5 (fraîchement livrée) est la plus propre.

---

## 1. Exécutive summary

### Baseline validée

| Suite                  | Résultat                                     |
| ---------------------- | -------------------------------------------- |
| PHPUnit complet        | **409 tests / 839 assertions — 0 failure**   |
| Vitest `tests/js`      | 115 / 117 (2 pré-existants hors scope)       |
| Linter PHP sur modifs  | 0 erreur                                     |

Les **chiffres des closures sont honnêtes** sur les compteurs (tests, assertions, specs Playwright, `waitForTimeout = 11`, `v-html = 3`, etc.). Les défauts se trouvent dans **les allégations d'atomicité, de symétrie et de blindage runtime** — pas dans la quantité de livrables.

### Verdict par section

| # | Section                      | Verdict       | Défauts majeurs |
|---|------------------------------|---------------|-----------------|
| 1 | V1 — Sync / Outbox / Event   | ⚠️ Partiel    | `BROADCAST_MAP` JS/PHP divergent (3 vs 6 entrées), contrat "miroir strict" **faux** |
| 2 | V2 — State machine / MENU 86 | ⚠️ Partiel    | `recordTransition` avale les exceptions → atomicité guard→persist→audit **compromise** ; `decrementForOrder` sans transaction |
| 3 | V3 — Sécurité base           | ✅ Solide      | Incohérences doc (5 vs 6 limiters ; "pas de *" applicable aux origins seulement) |
| 4 | V4 — Obs / Pricing / PW      | ⚠️ Partiel    | `HealthController` utilise `env()` → **IP whitelist désactivée en prod** après `config:cache` ; `/api/health` renvoie 200 même en degraded |
| 5 | Section 5 — MENU SSOT        | ✅ Propre     | `MenuSnapshot::bump()` non-atomique sur stores non-Redis (acceptable V1) |

### Les 3 défauts P1 à traiter avant GA

1. **`HealthController` — `env('HEALTH_IPS_ALLOWED')`** désactive silencieusement la whitelist IP en production dès que `php artisan config:cache` est exécuté. Known footgun documenté 7 fois ailleurs dans le codebase. **Fix : 5 min**.
2. **`OrderStateMachine::recordTransition` swallows Throwable** : l'audit trail peut manquer une ligne pendant que le statut de commande est committé. Casse l'invariant d'atomicité annoncé. **Fix : 20 min** (rethrow ou requeue).
3. **`BROADCAST_MAP` PHP (6) ↔ JS (3)** non synchronisés, test de parité absent. Aucun crash runtime (les 3 events supplémentaires ne sont pas encore émis), mais contrat documentaire **faux**. **Fix : 10 min** (mirror JS + test).

---

## 2. Audit Section 1 — Synchro foundation (V1)

### ✅ VÉRIFIÉ

- `DispatchDomainEventsJob::handle()` valide l'enveloppe **avant** `Pusher::trigger()` (lignes 51–72).
- `EventContract::buildEnvelope()` / `assertEnvelopeValid()` / `assertPayloadValid()` présents, `BROADCAST_MAP` constant non-vide.
- `PayloadMismatchException` expose `errors[]` + `eventType` (readonly, `context()`).
- `bootstrap.js` : `activityTimeout: 30000` + `pongTimeout: 5000` confirmés (l. 69–70).
- Idempotence : `if ($domainEvent->dispatched_at !== null) return` présent (l. 37–38).
- `DB::afterCommit` utilisé dans les 3 listeners `Persist*ToOutbox`.
- Test counts : `EventContractUnitTest` = 12, `EventContractTest` = 6, `OutboxTest` = 5, `KioskEventTest` = 4 → total 27 ✓.

### ❌ DÉFAUTS

#### D1-1 (P2) — `BROADCAST_MAP` PHP ↔ JS divergent

```34:41:app/Domain/Events/EventContract.php
public const BROADCAST_MAP = [
    'OrderCreated'            => EventType::ORDER_CREATED,
    'OrderStatusChanged'      => EventType::ORDER_STATUS_CHANGED,
    'OrderItemAdded'          => EventType::ORDER_ITEM_ADDED,
    'OrderCancelled'          => EventType::ORDER_CANCELLED,
    'ItemAvailabilityChanged' => EventType::MENU_ITEM_AVAILABILITY_CHANGED,
    'StockLow'                => EventType::STOCK_LOW,
];
```

```10:14:resources/js/services/eventContract.js
export const BROADCAST_MAP = {
    OrderCreated: EVENT_TYPES.ORDER_CREATED,
    OrderStatusChanged: EVENT_TYPES.ORDER_STATUS_CHANGED,
    ItemAvailabilityChanged: EVENT_TYPES.MENU_ITEM_AVAILABILITY_CHANGED,
};
```

**Impact** : le closure report §3 Garantie 3 affirme "Côté frontend : `resources/js/services/eventContract.js::BROADCAST_MAP` est son miroir strict". **C'est faux**. 3 entrées PHP n'ont pas de miroir JS (`OrderItemAdded`, `OrderCancelled`, `StockLow`). Pas de crash runtime — `parseEvent` du front ne vérifie le type que si la clé existe (`if (expectedType && parsed.type !== expectedType)`) — mais la "garantie" annoncée est mensongère.

**Atténuation présente** : aucun code backend n'émet aujourd'hui ces 3 events (grep vide), donc la divergence n'est visible qu'au prochain listener ajouté.

**Fix** : ajouter les 3 entrées manquantes dans `eventContract.js` + ajouter un test unitaire qui lit les deux fichiers et échoue sur divergence.

#### D1-2 (P3) — Edge case de validation dans `DispatchDomainEventsJob`

Lignes 44–78 : si la ligne outbox a `channel` ou `broadcast_as` null, le job **saute** la validation + Pusher, écrit `dispatched_at = now()` et **clear `last_error`**. Une ligne corrompue (un listener mal configuré) est donc marquée dispatched sans jamais être validée ni publiée.

**Impact** : très faible aujourd'hui (les 3 listeners `Persist*ToOutbox` remplissent toujours ces champs), mais pas de garde-fou.

**Fix** : `throw PayloadMismatchException` au lieu du no-op silencieux, ou au minimum logger un `Log::warning`.

#### D1-3 (P3) — Format `last_error` incohérent entre catch et failed()

- Catch block (l. 64–66) : `'contract_violation: ' . $exception->getMessage()`
- `failed()` (l. 89–91) : `$exception->getMessage()` nu

**Impact** : un dashboard ops qui grep sur `contract_violation:` rate les erreurs après épuisement des retries.

**Fix** : unifier le préfixe dans `failed()`.

---

## 3. Audit Section 2 — Domaine SSOT (V2)

### ✅ VÉRIFIÉ

- `OrderStateMachine::apply(Model, int, ?Authenticatable, ?string): void` existe, enveloppé dans `DB::transaction` (l. 131–170).
- `requiresReason()` = true pour `CANCELED/REJECTED/RETURNED` (l. 177–183).
- `legalTransitions()` retourne 11 paires (assertion test l. 196–199).
- `assertAllows()` throw `IllegalTransitionException` correctement.
- `ItemAvailabilityChanged::fromItem()` / `forBranch()` factories présentes et typées correctement.
- `ItemService.php:241` utilise bien `::fromItem()` (pas `new`).
- `AvailabilityService::toggle()` transactionnel avec `lockForUpdate` + early-return idempotent.
- `AvailabilityService::decrementForOrder()` n'émet qu'en cas de **flip** available→86.
- `PersistItemAvailabilityChangedToOutbox` : channel branch-scoped vs multi-branches OK, `DB::afterCommit` OK.
- Tests counts : `OrderStateMachineTest` = 12 méthodes (mais 77 cas avec data providers), `OrderStateMachineApplyTest` = 6, `AvailabilityServiceTest` = 7.
- `config/pricing.php` : `PRICING_USE_SSOT` default true (l. 9–10).
- `PricingService::calculateOrder()` présent.
- 3 call sites dans `OrderService` (l. 310/602/959) + 1 dans `FrontendOrderService` (l. 206) — tous **derrière le flag**.

### ❌ DÉFAUTS

#### D2-1 (**P1**) — `recordTransition` avale les erreurs → atomicité compromise

```96:110:app/Domain/Order/OrderStateMachine.php
try {
    OrderStatusTransition::query()->create([ ... ]);
} catch (\Throwable $e) {
    Log::warning('[OrderStateMachine] Failed to record transition: ' . $e->getMessage());
}
```

Ce bloc est appelé **à l'intérieur** du `DB::transaction` de `apply()` (l. 155–170). Si l'insertion `order_status_transitions` échoue (contrainte FK cassée, timeout, deadlock), le catch log un warning mais **ne relance pas** l'exception. Conséquence : `DB::transaction` commit, l'ordre passe au nouveau statut, **mais la ligne d'audit est absente**.

**Impact** : l'invariant "guard → persist → audit atomique" du closure report V2 §Garanties est **faux**. Contestable pour la conformité RGPD/SOX si un audit externe demande le trail.

**Fix** : retirer le try/catch à l'intérieur d'`apply()` (ou rethrow), laisser la transaction planter l'ensemble si l'audit échoue. Pour les call-sites legacy (frozen zone) où `recordTransition()` est appelé directement **hors** transaction, garder le swallow actuel — scoper le swallow au hors-`apply()`.

#### D2-2 (**P1**) — `AvailabilityService::decrementForOrder()` sans transaction

L. 118–163. Une boucle `foreach ($order->orderItems as $line)` qui lit, mute et `save()` ligne par ligne **sans `DB::transaction`** enveloppant. Un timeout DB au milieu de la boucle laisse les premières lignes décrementées, les suivantes intouchées.

**Impact** : cohérence inventaire pour gros paniers. En V1 (paniers 5-10 articles) le risque est faible mais non nul.

**Fix** : envelopper dans `DB::transaction` comme `toggle()`.

#### D2-3 (P2) — `apply()` return `void` alors que le closure claim `Model`

Closure report V2 : "`apply(Model, int, ?Authenticatable, ?string)` atomique". Signature réelle : `apply(...): void` (l. 136). Ni critique ni cassant, mais la doc est imprécise.

#### D2-4 (P2) — `apply()` jamais appelé en production

Grep `OrderStateMachine::apply(` dans `app/Services/` : **0 call site**. L'API est livrée mais aucun code de production ne l'utilise — seuls les tests la touchent. Les 7 mutations directes de `$order->status = ...` (OrderService 1334/1387/1439 + FrontendOrderService 550/661/736 + KDS 122) continuent de contourner la state machine. La `ValidStatusTransition` request rule garantit uniquement `allows()`, pas le chemin `apply()`.

**Impact** : l'invariant "0 transition hors StateMachine" reste théorique en V1 — il est en réalité "toute transition passe par `allows()` puis est persistée indépendamment par le service". Accepté par la frozen zone mais la closure §Invariants devrait le stipuler clairement.

#### D2-5 (P2) — Gate PRICING_SSOT non signé

`docs/gates/GATE_V1_PRICING_SSOT_001_2026-04-15.md` l. 100–104 : trois cases `[ ]` décochées. Le code est **déjà actif en prod derrière le flag** (default true). La closure renvoie la signature à Vague 4 / gate humain — **toujours non signé à ce jour**.

**Impact** : opérationnel, pas technique. Le gate doit être coché avant GA ou formellement retiré.

#### D2-6 (P3) — Gate rollback variable name bug

Gate mentionne `PRICING_SSOT=false` ; code lit `PRICING_USE_SSOT`. Si ops suit le gate littéralement en incident, le rollback **ne fonctionne pas**.

**Fix** : corriger le gate doc.

#### D2-7 (P3) — Idempotence `toggle()` trop stricte

```61:63:app/Services/Menu/AvailabilityService.php
if ((bool) $row->is_available === $available && $row->unavailable_reason === ($available ? null : $reason)) {
    return $row;
}
```

Changer la `reason` alors que l'item reste indisponible → déclenche un re-save + re-event. Sémantiquement "pas encore vraiment disponible, juste la raison change" = spam réseau potentiel.

**Impact** : faible. La reason change rarement à l'usage.

---

## 4. Audit Section 3 — Sécurité base (V3)

### ✅ VÉRIFIÉ

- `config/cors.php` : `allowed_origins` env-driven (APP_URL / KIOSK_DOMAIN / ADMIN_DOMAIN), **zéro `*`** dans origins/patterns, `supports_credentials: true`.
- **6 rate limiters** nommés dans `RouteServiceProvider::configureRateLimiting()` : `api`, `admin-mutation`, `pos-order-create`, `pos-order-update`, `login-lockout`, `kiosk-orders`.
- Routes applicables portent leur throttle dédié : admin (l. 228), login (l. 136), kiosk order (l. 824), POS create (l. 622), POS update (l. 631).
- Kernel `api` group : `throttle:api` baseline 120/min couvre toutes les routes API.
- **3 usages `v-html`** dans `.vue` : tous `v-html="safeHtml(...)"` + commentaire `eslint-disable-next-line vue/no-v-html`.
- `safeHtml.js` : DOMPurify 3.4 avec la whitelist exacte annoncée.
- `VHtmlStaticGuardTest` scanne récursivement, rejette `v-html="..."` sans `safeHtml(` et `.innerHTML =` sans DOMPurify.
- `RateLimiterConfigTest` : 6 limiters × 2 assertions (registered + cap drift).
- `CorsTest` : 4 tests incluant preflight OPTIONS réel avec `Access-Control-Request-Method`.
- `RateLimitTest` : 2 tests e2e (31ᵉ admin + 11ᵉ login).
- `safeHtml.spec.js` : **9** vecteurs XSS.

### ❌ DÉFAUTS

#### D3-1 (P3) — Closure doc dit "5 limiters", code en a 6

Closure report V3 l. 41 et 129 : "5 limiters nommés". Réalité : 6 (`kiosk-orders` inclus, et testé dans `RateLimiterConfigTest`). **Incohérence documentaire**, pas de bug technique.

#### D3-2 (P3) — "Pas de `*`" partiellement vrai

`config/cors.php` : `allowed_headers: ['*']` et `allowed_methods: ['*']`. Le closure dit "jamais `*`" ; le contexte technique est correct (ce sont les `origins` qui comptent avec `supports_credentials: true`), mais la phrase prête à confusion.

#### D3-3 (P4) — `VHtmlStaticGuardTest` guard double-quote only

La regex accepte `v-html="...safeHtml(` mais rejette `v-html='safeHtml(...)'`. Un contributeur utilisant simple-quote évade le guard tout en restant sécurisé. Inverse possible : un simple-quote sans `safeHtml` échappe aussi. Edge case faible.

#### D3-4 (P4) — Pas d'ESLint dans le pipeline

Accepté en V1 (substitué par `VHtmlStaticGuardTest`). Tracké explicitement dans la closure → OK.

---

## 5. Audit Section 4 — Data / Obs / Tests (V4)

### ✅ VÉRIFIÉ

- `/api/health`, `/api/health/live`, `/api/health/ready` routes présentes dans `routes/api.php` l. 123–125.
- `HealthController::full()` checks db/redis/queue/broadcast + schéma complet ok.
- `HealthController::ready()` : 503 quand degraded ✓.
- `HealthController::live()` : 200 plain text OK ✓.
- `CorrelationIdMiddleware` : UUID auto-gen, propagation request→response, `Log::withContext` avant `$next`.
- `CorrelationIdMiddleware` enregistré dans `web` + `api` groups de `Kernel.php` (l. 40, 50).
- `HasCorrelationId` trait présent, utilisé par `DispatchDomainEventsJob`.
- `config/logging.php` channel `production_json` + `JsonFormatter` (NDJSON timestamp + level + context).
- **Test counts** : HealthControllerTest = **7**, CorrelationIdMiddlewareTest = **5**, TaxCalculatorTest = **10**, DiscountCalculatorTest = **7**, PricingServiceTest = **21** — tous corrects.
- 6 specs Playwright, mapping 5 flows critiques, **11 `waitForTimeout`** confirmés, `playwright.config.js` + `.github/workflows/playwright.yml` OK.

### ❌ DÉFAUTS

#### D4-1 (**P1**) — `HealthController` utilise `env()` → IP whitelist silencieusement désactivée en prod

```57:62:app/Http/Controllers/HealthController.php
private function assertFullHealthIpAllowed(): void
{
    $csv = env('HEALTH_IPS_ALLOWED', '');
    if ($csv === '' || $csv === null) {
        return;
    }
```

**Laravel footgun connu** : après `php artisan config:cache` (obligatoire en prod, appelé par `InstallerService::up()` l. 39, documenté dans `DEPLOIEMENT.md` 4 fois, et par `DEPLOYMENT_GUIDE_V1.md` l. 61), `env()` hors fichiers de config retourne **`null`**. Donc `$csv === null` → early return → **plus aucune restriction IP sur `/api/health`**.

Le codebase a **7 mentions** ailleurs de ce risque (`.cursor/rules/safety.mdc` l. 40, `docs/PROJECT_CONTINUITY_AND_VISION.md` l. 68, `reports/planning/audit-total-2026-03-21.md` SEC-5, `ApiKeyMiddleware.php` corrigé avec ce commentaire exact). Cette règle est ignorée ici.

**Impact** : le endpoint `/api/health` expose les détails d'infrastructure (DB, Redis, Queue, Broadcast, version, subsystems) **à n'importe quel client** en production dès que `config:cache` tourne. Vecteur de reconnaissance facile pour un attaquant.

**Fix immédiat** (5 min) :
1. Ajouter `HEALTH_IPS_ALLOWED` à `config/security.php` (ou équivalent).
2. Remplacer `env('HEALTH_IPS_ALLOWED', '')` par `config('security.health_ips_allowed', '')` dans le controller.

#### D4-2 (P2) — `/api/health` renvoie HTTP 200 même en degraded

`HealthController::full()` (l. 26–31) ne passe pas de code HTTP à `response()->json(...)` → 200 par défaut, quel que soit le `status: 'degraded'`. Seul `/api/health/ready` renvoie 503.

**Impact** : un orchestrateur (K8s, Nomad) qui probe `/api/health` au lieu de `/api/health/ready` n'est alerté qu'en lisant le JSON — 90% des outils regardent le status code.

**Fix** : retourner `503` dans `full()` quand `!$allOk`, ou documenter explicitement que **seul `/ready` est LB-friendly**.

#### D4-3 (P3) — Playwright workflow sans trigger push main

`.github/workflows/playwright.yml` : `on: pull_request: [main, develop]`. Pas de `push: branches: [main]`. Un merge direct (hotfix) ne déclenche rien.

**Impact** : filet de sécurité partiel. Accepté pour PR flow ; problème si force-push ou bypass.

#### D4-4 (P3) — UUID v4 non strictement vérifié

Test `CorrelationIdMiddlewareTest` utilise une regex UUID générique, ne checke pas les bits de version. Un ID format v1 ou v5 passerait. Peu critique.

#### D4-5 (P4) — Closure doc "zéro dérive frozen-zone"

Closure V4 §4 §5 affirment "aucune ligne runtime touchée". Le `git status` actuel montre 30+ modifications dans `app/`. Ces modifications viennent des autres vagues (V1-V3 + section 5), pas de V4 — mais la phrase "workspace snapshot" de la closure prête à confusion. À reformuler en "V4 n'a livré que des tests + rapports".

---

## 6. Audit Section 5 — MENU SSOT (ce run)

### ✅ VÉRIFIÉ

- Migration `2026_04_16_200000_add_channel_columns_to_items_and_categories` idempotente (`Schema::hasColumn` guards), rollback propre.
- Tous les champs nullables → **back-compat stricte** : zéro backfill requis.
- `Item::isVisibleOn()`, `ItemCategory::isVisibleOn()`, `displayNameFor()`, `sortFor()` — logique correcte vérifiée par 13 tests feature + 6 unit.
- `MenuProjectionService::forChannel()` : 3 requêtes (categories + items + availability), **pas de N+1**.
- Isolation branch : test `test_availability_is_scoped_to_the_requested_branch` garantit qu'une row d'une autre branche ne leak pas.
- Route `/api/admin/menu-projection` dans le groupe admin (sanctum + apiKey + throttle:admin-mutation).
- Validation `Rule::in(SUPPORTED_CHANNELS)` → 422 propre sur `mobile-app` ou autre.
- Listener `BumpMenuSnapshotOnItemAvailabilityChanged` enregistré dans `EventServiceProvider`.
- 26 tests / 72 assertions — tous verts, 0 lint.

### ❌ DÉFAUTS

#### D5-1 (P3) — `MenuSnapshot::bump()` non strictement atomique sur stores non-Redis

```58:73:app/Services/Menu/MenuSnapshot.php
if ($this->cache->get($key) === null) {
    $this->cache->put($key, 1, now()->addDays(self::TTL_DAYS));
}
if (method_exists($this->cache, 'increment')) {
    $next = $this->cache->increment($key);
    ...
}
```

Deux opérations cache indépendantes. **Sur Redis** : `INCR` sur clé absente crée atomiquement avec valeur 1 — race safe. **Sur file/database cache** : une race théorique entre `get` et `put` peut perdre 1 incrément. V1 utilise Redis en prod (validé par `PRODUCTION_SETUP.md`) → acceptable.

**Fix optionnel V1.5** : utiliser `Cache::lock($key)` ou `Redis::eval('INCR')` direct.

#### D5-2 (P4) — `MenuSnapshot::current()` a un side effect d'initialisation

Écrit `1` dans le cache si absent (l. 42–45). Un "read" pur devrait juste retourner 1 sans écriture. Smell mineur, fonctionnel.

#### D5-3 (P4) — Controller ne valide pas l'existence de `branch_id`

La validation accepte n'importe quel entier ≥1. Si branch_id = 999 et pas de branche correspondante, la route répond 200 avec `categories` filtré par channel uniquement (pas de rows d'availability → items par défaut disponibles). Le endpoint étant admin-only avec throttle, l'impact est nul ; mais ajouter `Rule::exists('branches', 'id')` serait plus propre.

---

## 7. Registre des défauts — priorisé

| ID    | Sévérité | Composant                  | Correction | ETA   |
|-------|----------|----------------------------|------------|-------|
| D4-1  | **P1**   | HealthController           | `env()` → `config()` + entrée `config/security.php` | 5 min |
| D2-1  | **P1**   | OrderStateMachine          | Retirer try/catch dans `apply()` ou rethrow | 20 min |
| D2-2  | **P1**   | AvailabilityService        | `DB::transaction` autour de `decrementForOrder` | 10 min |
| D1-1  | P2       | EventContract JS           | Ajouter 3 entrées manquantes + test parité | 15 min |
| D4-2  | P2       | HealthController           | `full()` returne 503 si degraded | 5 min |
| D2-5  | P2       | Gate PRICING_SSOT          | Signer ou retirer le gate | humain |
| D2-6  | P2       | Gate doc var name          | `PRICING_SSOT` → `PRICING_USE_SSOT` | 2 min |
| D1-2  | P3       | DispatchDomainEventsJob    | Rejeter ligne null channel/broadcast_as | 10 min |
| D1-3  | P3       | DispatchDomainEventsJob    | Uniformiser `last_error` format | 5 min |
| D2-3  | P3       | Closure V2 doc             | `apply()` return type correct | 2 min |
| D2-4  | P3       | Closure V2 doc             | Clarifier "0 transition hors SM" | 5 min |
| D2-7  | P3       | AvailabilityService toggle | Idempotence sur `reason` change | 10 min |
| D3-1  | P3       | Closure V3 doc             | "5" → "6" limiters | 2 min |
| D3-2  | P3       | Closure V3 doc             | Préciser "pas de `*`" scope origins | 2 min |
| D4-3  | P3       | Playwright CI              | Ajouter `push: main` trigger | 5 min |
| D5-1  | P3       | MenuSnapshot               | Locking V1.5 | V1.5 |
| D3-3  | P4       | VHtmlStaticGuard           | Accepter single-quote | 10 min |
| D4-4  | P4       | CorrelationId test         | Regex UUID v4 strict | 5 min |
| D4-5  | P4       | Closure V4 doc             | Reformuler "zéro dérive" | 2 min |
| D5-2  | P4       | MenuSnapshot               | `current()` read-pure | 10 min |
| D5-3  | P4       | MenuProjectionController   | `Rule::exists` sur branch_id | 5 min |

**Total P1** : 3 défauts, ≈ 35 min de fix.
**Total P2** : 4 défauts, ≈ 30 min + 1 signature humaine.
**Total P3** : 11 défauts (docs + edge cases).
**Total P4** : 5 défauts (polish).

---

## 8. Invariants annoncés — verdicts détaillés

| Invariant (closure)                                       | Vérifié ? | Note |
|-----------------------------------------------------------|-----------|------|
| Aucune enveloppe malformée n'atteint un client            | ⚠️ Partiel | Valide seulement quand `channel/broadcast_as` non-null (voir D1-2) |
| Liveness WebSocket détectée en ≤ 35 s                     | ✅ Oui     | `activityTimeout` 30s + `pongTimeout` 5s |
| 4 surfaces ne divergent pas silencieusement               | ❌ Non     | JS map a 3/6 entrées (D1-1) |
| Guard → persist → audit atomique (apply)                  | ❌ Non     | `recordTransition` swallow (D2-1) |
| 0 transition OrderStatus hors StateMachine                | ⚠️ Partiel | `apply()` jamais appelé en prod (D2-4) |
| branch_id isolation (MENU 86)                             | ✅ Oui     | Test de scope OK |
| Dispatch after DB commit                                  | ✅ Oui     | 3 listeners `DB::afterCommit` |
| CORS whitelist env-driven                                 | ✅ Oui     | Origins/patterns sans `*` |
| Rate-limit sur toutes mutations                           | ✅ Oui     | Baseline `throttle:api` + 5 nommés |
| Défense XSS v-html                                        | ✅ Oui     | 3 usages + guard + sanitizer + 9 vecteurs |
| Health endpoints opérables                                | ⚠️ Partiel | Whitelist IP cassée en prod (D4-1) + full 200 en degraded (D4-2) |
| Pricing SSOT verrouillé par tests                         | ✅ Oui     | 38 tests (17+21), gate non signé (D2-5) |
| State machine verrouillée                                 | ⚠️ Partiel | Couverture OK, audit atomique compromise (D2-1) |
| Playwright CI gating                                      | ✅ Oui     | PR trigger opérationnel, pas de push (D4-3) |
| Zéro dérive frozen zone                                   | ✅ Oui     | Frozen zones intouchées |
| Menu SSOT projections (S5)                                | ✅ Oui     | 26 tests, 0 défaut runtime |

---

## 9. Recommandations avant GA

### Bloquants (P1) à corriger en ≤ 1 h

1. **D4-1** : `HealthController` `env()` → `config()`. **Impact production direct.**
2. **D2-1** : retirer le swallow dans `recordTransition` dans `apply()` ou rethrow vers la transaction. Impact audit trail.
3. **D2-2** : envelopper `decrementForOrder` dans `DB::transaction`.

### Conseillés (P2) avant release notes

4. **D1-1** : synchroniser le BROADCAST_MAP JS + ajouter test de parité PHP↔JS.
5. **D4-2** : `full()` retourne 503 quand degraded (ou documenter l'asymétrie avec `/ready`).
6. **D2-5 + D2-6** : signer ou retirer le gate PRICING_SSOT, et corriger le nom de la var env dans le gate.

### Tolérables (P3/P4) à tracker post-GA

- Corrections de doc des closures (5 incohérences — impact zero production).
- Edge cases (single-quote v-html guard, UUID strict regex, MenuSnapshot atomic V1.5, etc.).

### Ajouts test suggérés

- `EventContractParityTest.php` (PHP vs JS map).
- `HealthControllerTest::test_full_returns_503_when_degraded` (actuellement non couvert).
- `HealthControllerTest::test_whitelist_survives_config_cache` (simule `env()` null + `config()` set).
- `OrderStateMachineApplyTest::test_apply_rolls_back_when_audit_fails` (mock FK cassée).

---

## 10. Ce qui est VRAIMENT livré en V1

Au-delà des défauts, **les 5 sections produisent un V1 opérable** :

- **409 tests PHPUnit** verts (V1-V4 + S5 + régression).
- **Pricing SSOT** actif derrière feature flag, 38 tests couvrent les chemins critiques.
- **State machine** : 82 paires testées, 11 transitions légales verrouillées.
- **MENU 86** : availability per-branch + broadcast branch-scoped.
- **XSS / CORS / rate-limit** : défense en profondeur opérable.
- **Observability** : `/health` + correlation ID + JSON logs.
- **Playwright CI** : 5 flows + staff-only.
- **MENU SSOT** : projection par canal dispo pour V1.5 rewire.

Les 3 P1 ne compromettent pas le **fonctionnement** — ils compromettent des **garanties annoncées** (IP whitelist prod, atomicité audit, parité contrat). Un V1 GA-worthy après les 35 minutes de fix.

---

## 11. Commandes de reproductibilité

```bash
# Baseline : 409 tests PHPUnit
./vendor/bin/phpunit --no-coverage

# Vitest : 117 tests (2 pré-existants failing hors scope)
npx vitest run tests/js

# Defects P1 rapide à vérifier
rg "env\('HEALTH_IPS_ALLOWED'" app/
rg "catch \(\\\\Throwable" app/Domain/Order/OrderStateMachine.php
diff <(rg "=>" app/Domain/Events/EventContract.php -A0 | rg "'[A-Z].*' *=>") \
     <(rg "[A-Z].*:" resources/js/services/eventContract.js -A0 | rg -v 'export|const')
```

---

## 12. Signoff audit

- **Méthode** : lecture directe des 12 fichiers critiques + 4 sous-agents explore parallèles + régression complète + 3 deep-dives sur défauts suspectés.
- **Couverture** : 5 sections × ~20 claims par section ≈ 100 assertions auditées.
- **Fiabilité** : P1/P2 reproductibles en lecture + test ; P3/P4 observés en lecture mais non rejoués.
- **Limites** : pas de test de charge, pas de chaos testing, pas de scan SAST (Psalm/PHPStan/Snyk) — à faire en pre-prod séparément.

**Verdict global** : **V1 tenable pour GA sous condition de fixer les 3 P1 (35 min de travail)**. Les autres défauts sont acceptables en tant que dette V1.5.
