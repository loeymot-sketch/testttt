# RED — Audit adversaire BYPASS payment+printing (2026-05-08)

> **Cible** : commit `bebcf7054` "[BYPASS-P0..P6 + GSTACK-A] Mode bypass payment+impression pour validation E2E sans hardware"
> **Persona** : GSTACK Security + QA, zéro complaisance, méthode R1..R5
> **Méthode** : audit source + 6 vérifications runtime (tinker + curl + artisan boot)
> **Verdict** : **PROD-READY pour validation E2E user, conditionné HEAL léger** (1 P1 dead-code + 1 P2 sentinels statiques) — **PAS de BLOCK**.

---

## 0. Périmètre vérifié

Source audité (commit `bebcf7054`) :
- `config/payment.php` (section `'bypass'`) + `config/printing.php` (nouveau)
- `.env.example` (PAYMENT_BYPASS_MODE + PRINTING_BYPASS_MODE)
- `app/Providers/AppServiceProvider.php` (binding `NullPrinterTransport` étendu + prod-guard)
- `app/Services/Bypass/BypassAuditLogger.php` (nouveau)
- `app/Http/Controllers/Frontend/OrderController.php` (hook `paymentBypassed`)
- `app/Services/PaymentService.php` (hook `paymentBypassed`)
- `resources/views/master.blade.php` (`window.foodkingConfig.bypassMode`)
- `resources/js/components/admin/pos/ReceiptComponent.vue` (marqueur visible)
- 19 PHPUnit + 5 vitest sentinels + 1 spec Playwright
- `docs/audit/BYPASS_MAPPING_2026-05-08.md` + `docs/runbooks/BYPASS_MODE_OPERATIONAL.md`

Vérifications runtime exécutées :
- `php artisan test --filter=Bypass*` → **16/16 PASS**
- `php artisan tinker` (transport binding + flags reading)
- Toggle `.env` + `php artisan config:cache`/`config:clear` (B7)
- `APP_ENV=production php artisan inspire` avec `PAYMENT_BYPASS_MODE=true` (B1)
- `curl http://localhost:8000/login` → grep `bypassMode` payload (B8)
- Source-grep exhaustif (`printingBypassed`, `STUB-`, `hidden-print`, …)

---

## 1. Bilan par hypothèse (B1..B10)

### B1 — Prod-guard contournable ? **RÉFUTÉ (le guard tient)**

**Hypothèse** : peut-on activer `bypass=true` en prod sans déclencher le `RuntimeException` au boot ?

**Évidence runtime** :
```bash
# .env: PAYMENT_BYPASS_MODE=true
APP_ENV=production php artisan inspire
# → RuntimeException: PAYMENT_BYPASS_MODE=true is forbidden in production…
#   at app/Providers/AppServiceProvider.php:84
```

`AppServiceProvider::boot()` lève l'exception **dès le premier appel artisan** (donc aussi sur `serve`, `queue:work`, `schedule:run`, FPM request). L'unique vecteur théorique serait :
- container override **après** boot (mais `register()` lit `printing.bypass.enabled` via une closure, donc lazy → la valeur lue à l'instanciation refléterait l'override) → en pratique pas exploitable côté HTTP request (boot termine avant route resolve)
- `php artisan config:cache` exécuté avec `bypass=true` puis `.env` remis à `false` → cf. B7 (foot-gun staging, pas une faille du guard prod)

**Verdict** : guard solide. ✅

---

### B2 — Audit log fire-too-early : faux positifs si guards throw ? **CONFIRMÉ — acceptable**

**Hypothèse** : `BypassAuditLogger::paymentBypassed()` est appelé EN DÉBUT de méthode controller/service, AVANT les vérifications de validation/state machine. Donc on log un `[BYPASS-PAYMENT]` même si la transaction n'est jamais tentée.

**Évidence source** :
- `OrderController::paymentConfirm` : ligne 99 (audit log) ↔ ligne 110 (auth check) ↔ ligne 145 (state validation) ↔ ligne 188 (PAID transition).
- `PaymentService::confirmCounterPayment` : ligne 127 (audit log) ↔ ligne 162 (`assertCounterDeferredOrder` peut throw `InvalidArgumentException` 422) ↔ ligne 163 (`PaymentStateMachine::assertCanTransition` peut throw).

**Cas concret** : un client envoie `POST /payment-confirm` sur un order déjà `CANCELED` en bypass mode → la requête arrive jusqu'au controller, le warning `[BYPASS-PAYMENT]` est écrit, puis `nonConfirmableStatus` répond 422. Le log dit "TPE call short-circuited" alors qu'aucun TPE n'aurait été appelé même hors bypass.

**Impact** : grep `[BYPASS-PAYMENT]` storage/logs surcompte les "tentatives" vs vrais succès. Faux positif d'audit, pas faux négatif. **Acceptable** pour mode local/staging — utile même (preuve que le controller a été hit).

**Recommandation HEAL (P3)** : ajouter `'attempt' => true` dans le contexte initial, puis `BypassAuditLogger::paymentBypassed(['outcome' => 'success', …])` à la fin (avant `return response`).

---

### B3 — Marker peut s'imprimer ? **RÉFUTÉ (defense en profondeur correcte)**

**Hypothèse** : la regex sentinel vérifie le wrap dans `hidden-print`, mais y a-t-il un breakpoint `@media print` qui ne respecte pas `hidden-print` ? Le marker peut-il fuiter sur le ticket réel ?

**Évidence** :
1. **v-print plugin** : `printObjClient.id = "print-receipt-client"` et `printObjKitchen.id = "print-receipt-kitchen"` (lignes 352-358). Le plugin extrait UNIQUEMENT le contenu de ces IDs. Le marker est rendu en `.modal-dialog > div[v-if="bypassPrintingActive"]`, OUTSIDE de ces IDs → **jamais inclus** dans le print job v-print.
2. **window.print() (Ctrl+P browser)** : si l'utilisateur déclenche manuellement `print()` sur la modal, la règle `<style scoped>` ligne 556 :
   ```css
   @media print {
       .hidden-print { display: none !important; }
   }
   ```
   est compilée en `@media print { .hidden-print[data-v-XXXX] { display: none !important; } }` (vérifié dans `public/js/admin-shell.js`). Comme le marker EST rendu par ReceiptComponent → l'attribut `[data-v-XXXX]` matche → règle s'applique.

**Verdict** : compliance NF525 préservée sur les 2 vecteurs print. ✅

**Nuance documentée** : si quelqu'un copie le marker dans un AUTRE composant Vue sans recopier la règle scoped, la protection saute. Sentinel `bypassPrintingMarkerHiddenPrint.spec.js` ne le détecterait pas (vérifie un seul fichier). Très peu probable mais à surveiller.

---

### B4 — Sealing fiscal préservé en bypass ? **CONFIRMÉ via lecture flow**

**Hypothèse** : y a-t-il un branch dans `paymentConfirm` qui SAUTE `finalizePaidKioskOrder` en bypass mode ? (alreadyPaid / lateAfterCleanup)

**Évidence source** (`OrderController::paymentConfirm` lignes 127-247) :
- `alreadyPaid = true` → la transaction se termine sans transition d'état, mais ligne 214 **`$promoted = $this->frontendOrderService->finalizePaidKioskOrder(...)`** est appelé inconditionnellement.
- `nonConfirmableStatus !== null` → return 422 AVANT `finalizePaidKioskOrder`. **Mais c'est correct** : l'order est CANCELED/REJECTED, pas un cas où le sealing devait avancer.
- Bypass mode n'introduit **aucun branch nouveau** : le code d'origine est intact, seul un audit log a été ajouté en tête.

`finalizePaidKioskOrder` (FrontendOrderService.php:965) est protégé par `[F-21] Defense in depth` (ligne 994) : il refuse d'avancer le statut sans `payment_status = PAID`. L'allocation `FiscalSequenceService::next` se fait dans la même transaction. Aucun chemin bypass ne contourne cela.

**Verdict** : sealing intact. ✅

---

### B5 — Outbox dispatch en runtime bypass ? **CONFIRMÉ via lecture flow + non vérifié runtime**

**Hypothèse** : avec `PAYMENT_BYPASS_MODE=true`, est-ce que `OrderPaidAtCounter::dispatch` se fait RÉELLEMENT en runtime (pas juste statiquement présent) ?

**Évidence source** :
- `PaymentService::confirmCounterPayment` ligne 215-217 : `if ($paid) { OrderPaidAtCounter::dispatch($order, $mode); }` — exécuté HORS du branch bypass (le branch bypass n'existe pas, c'est juste un `BypassAuditLogger::paymentBypassed()` ajouté au début).
- Pour le path kiosk (`OrderController::paymentConfirm` → `finalizePaidKioskOrder`), la méthode dispatch via `dispatchOrderStatusSignals` (ligne 1086-1093 : `OrderStatusChanged::dispatch`) + `dispatchNewOrderSignals` (ligne 1078-1084 : `OrderCreated::dispatch`). Aucun branch bypass.

**Limitation honnête** : non testé en runtime live (pas de spec créée). Le sentinel `test_confirm_counter_payment_still_dispatches_OrderPaidAtCounter` est statique (`assertStringContainsString('OrderPaidAtCounter', $source)`) — il prouve que la chaîne existe en source, **pas** que le dispatch se déclenche en bypass. Cf. P2 ci-dessous.

**Verdict** : preuve forte par lecture du flow, mais sentinel runtime manque. Probablement OK.

---

### B6 — Stub kiosk `transaction_id` exploitable ? **CONFIRMÉ — pré-existant, pas une régression bypass**

**Évidence** :
- `KioskPaymentComponent.vue:566` :
  ```js
  if (!kioskHardware.isKioskBridge()) {
      return { approved: true, transaction_id: `STUB-${Date.now()}`, card_type: 'VISA' };
  }
  ```
  Code **toujours actif**, jamais gardé par `bypassMode.payment`.
- Server-side (`PaymentConfirmRequest.php:31`) : `'transaction_id' => ['required', 'string', 'max:255']`. **Aucun check de format** (prefix `STUB-` ou `BYPASS-`).
- Auth requise : Sanctum `kiosk:order` ability + `KioskMachine` row pour `user_id`. Mais en local, `KIOSK_MACHINE_USERNAME=kiosk-lecayenne` / `KIOSK_MACHINE_PASSWORD=kiosk123` permet à n'importe qui d'obtenir un token via `KioskMachineLoginController` → puis POST `/api/frontend/order/{id}/payment-confirm` avec `transaction_id=STUB-1234` → 200 OK, order PAID.

**Critique** :
1. **Pré-existait avant `bebcf7054`** (le code est intact). Le commit bypass ne l'a ni introduit ni atténué.
2. **Risque réel uniquement si** : staging exposé Internet + creds kiosk leak. En production réelle, l'app Electron sur la borne est censée fournir la vraie réponse TPE — mais rien côté serveur ne distingue stub vs réel.

**Recommandation P1 (séparée, hors scope bypass commit)** : durcir `PaymentConfirmRequest` :
```php
'transaction_id' => [
    'required',
    'string',
    'max:255',
    Rule::when(
        config('payment.bypass.enabled') === false && app()->environment(['production', 'staging']),
        ['regex:/^[A-Z0-9]{8,}$/'] // exige un format TPE réel, pas STUB-…
    ),
],
```

**Verdict** : weakness réel mais hors scope BYPASS. À tracker dans un cycle dédié (`CV1-KIOSK-TRANSACTION-ID-HARDENING-001`).

---

### B7 — Stale `config:cache` après toggle `.env` ? **CONFIRMÉ — foot-gun staging/dev (pas prod)**

**Évidence runtime** :
```bash
# Étape 1 : .env → PAYMENT_BYPASS_MODE=true ; config:cache
$ php artisan config:cache
$ php artisan tinker --execute='echo config("payment.bypass.enabled")'
# → true ✓

# Étape 2 : .env → PAYMENT_BYPASS_MODE=false (PAS de config:clear)
$ php artisan tinker --execute='echo config("payment.bypass.enabled")'
# → true  ❌ STALE — encore true en runtime !
```

**Cadrage précis** :
- En **production** : le prod-guard (B1) attrape `cache=true + APP_ENV=production` au boot, donc l'app refuse de démarrer → pas un risque silencieux.
- En **staging / dev / CI où APP_ENV != "production"** : aucune protection. Si un dev fait `config:cache` avec bypass=true puis met `.env` à false, le cache reste true jusqu'au prochain `config:clear`. Les tests E2E semblent valider "bypass désactivé" alors qu'il est actif.

**Recommandation HEAL (P3)** : ajouter au runbook §5 étape 2 une mention explicite "**`config:clear` est OBLIGATOIRE après chaque toggle .env**" + une commande de vérif à exécuter (`php artisan tinker --execute='echo config("payment.bypass.enabled")'`).

---

### B8 — `window.foodkingConfig.bypassMode` leak HTML ? **CONFIRMÉ — disclosure mineure**

**Évidence runtime** :
```bash
$ curl -s http://localhost:8000/login | grep -A4 "bypassMode"
            bypassMode: {
                payment: false,
                printing: false,
                printingScreenMarker: "🔧 MODE TEST — IMPRESSION BYPASSÉE",
            },
```

Page **publique non authentifiée** `/login` (et toute autre page servie via `master.blade.php`) expose les 3 clés `bypassMode`. Tout visiteur peut grep depuis son DevTools si un staging est public-facing.

**Impact** :
- Disclosure d'une **caractéristique du déploiement** (bypass actif/inactif). Pas de credentials, pas de logique métier. Information utile à un attaquant pour décider quelle exploitation tenter (combinaison avec B6).
- Acceptable en local/dev. Risqué uniquement en staging accessible Internet.

**Recommandation HEAL (P3)** : conditionner l'injection en `master.blade.php` :
```blade
@if (! app()->environment('production'))
    bypassMode: { payment: ..., printing: ..., printingScreenMarker: ... },
@endif
```
(Le prod-guard garantit déjà que ces flags sont false en prod, donc ne rien exposer évite la disclosure inutile.)

---

### B9 — `$this->app['env'] = 'production'` reproduit-il vraiment prod ? **CONFIRMÉ — fidèle**

**Évidence** : `Illuminate\Foundation\Application::environment()` lignes 596-605 :
```php
public function environment(...$environments) {
    if (count($environments) > 0) {
        return Str::is($patterns, $this['env']);
    }
    return $this['env'];
}
```

`app()->environment('production')` lit littéralement `$this['env']`. Le test `BypassProductionGuardTest::test_payment_bypass_throws_in_production` (qui set `$this->app['env'] = 'production'` puis appelle `provider->boot()`) reproduit fidèlement le path runtime — vérifié par mon test live (B1).

**Verdict** : sentinel valide. ✅

---

### B10 — Logs sensibles ? **RÉFUTÉ**

**Évidence** : `BypassAuditLogger::paymentBypassed` log via `Log::warning(..., $context)`. Le contexte contient :
- `gate`, `env`, `timestamp` (statiques)
- `controller`, `order_id` (non PII)
- `transaction_id` (user-controlled, max 255 chars). En bypass mode = `STUB-<Date>` (synthétique). En prod réel = ID TPE bancaire (déjà loggé ailleurs : `ActionLog` ligne 233, `transaction_no` dans Transaction table).
- `mode` (entier enum `PosPaymentMethod`)

**Pas de PII** : pas de PAN, pas de nom client, pas de montant. Pas de log injection (Monolog encode en JSON).

**Verdict** : OWASP-clean. ✅

---

## 2. Top 5 vraies failles trouvées (P0/P1/P2)

### P1 — `BypassAuditLogger::printingBypassed()` est DEAD CODE
**Évidence grep exhaustif** :
```bash
$ grep -rn "printingBypassed" /Users/.../testttt/
app/Services/Bypass/BypassAuditLogger.php:39:    public static function printingBypassed(...)
tests/Feature/Sentinels/BypassPaymentInvariantsTest.php:52:        BypassAuditLogger::printingBypassed(['test' => 'noop']);
```
**Aucun caller** dans le code applicatif. `EscPosPrinterService::sendRaw`, `PrinterController::testPrint`, `CashDrawerController::open` ne l'invoquent pas. Le runbook §7 montre un exemple `[BYPASS-PRINTING]` log → **mensonge documentaire**. Quand `PRINTING_BYPASS_MODE=true`, les bytes ESC/POS disparaissent silencieusement dans `NullPrinterTransport::send()` sans aucune trace dans `storage/logs`.

**Impact** : grep `[BYPASS-PRINTING]` retournera toujours 0 résultat. L'audit trail promis par le runbook n'existe pas pour la moitié printing. Possibilité d'attaquant : envoyer 10 000 jobs print en bypass mode → aucune alerte, aucun log.

**HEAL (1-line fix)** : dans `EscPosPrinterService::sendRaw()` ligne 17 :
```php
public function sendRaw(Printer $printer, string $bytes): bool {
    \App\Services\Bypass\BypassAuditLogger::printingBypassed([
        'service' => 'EscPosPrinterService::sendRaw',
        'printer_id' => $printer->id,
        'branch_id' => $printer->branch_id,
        'station' => $printer->station,
        'bytes_len' => strlen($bytes),
    ]);
    $ok = $this->transport->send(...);
    ...
}
```

### P2 — Sentinels statiques (`assertStringContainsString`) inflate le PASS count
**Évidence** : sur les 11 cases de `BypassPaymentInvariantsTest`, **9 sont des `file_get_contents + assertStringContainsString`** :
- `test_payment_confirm_calls_BypassAuditLogger_in_controller` (grep)
- `test_confirm_counter_payment_calls_BypassAuditLogger` (grep)
- `test_payment_confirm_still_invokes_FiscalSequenceService_finalize` (grep)
- `test_confirm_counter_payment_still_dispatches_OrderPaidAtCounter` (grep)
- `test_AppServiceProvider_binds_NullPrinterTransport_when_bypass_active` (grep)
- `test_master_blade_exposes_bypassMode_to_window_foodkingConfig` (grep)
- `test_ReceiptComponent_renders_bypass_marker_in_hidden_print_zone` (grep + regex)
- `test_config_payment_bypass_keys_present` (config keys)
- `test_config_printing_bypass_keys_present` (config keys)

Les chaînes `finalizePaidKioskOrder`, `OrderPaidAtCounter` **existaient avant** le commit bypass. Les sentinels passeraient même si le wiring bypass était cassé. Seul `test_null_printer_transport_resolved_when_printing_bypass_enabled` est un test de comportement runtime réel.

**Impact** : "19 PHPUnit PASS" annoncé par BLUE est partiellement gonflé. Confidence calibration nécessaire.

**HEAL (P2)** : ajouter 1-2 vrais tests behavioural :
- Boot env `local`, set `payment.bypass.enabled = true`, créer un Order pending kiosk card, appeler `confirmCounterPayment`, asserter qu'un row `domain_events` `order.payment_confirmed` existe ET qu'un row `audit_logs` action `order.counter_payment_confirmed` existe ET qu'un log `[BYPASS-PAYMENT]` a été émis (`Log::shouldReceive`).

### P2 — `printingBypassed` documentation runbook contradictoire (déjà couvert sous P1)
Cf. runbook §7 qui décrit un format de log jamais émis. Documentation à corriger en même temps que le HEAL P1.

### P3 — Stale config:cache foot-gun (B7)
Risque staging/dev. Cf. recommandation B7. Documentation runbook §5 à durcir.

### P3 — `bypassMode` HTML disclosure (B8)
Cf. recommandation B8. Conditionner injection en non-prod.

---

## 3. Top 10 questions au blue team

1. **P1 dead code printing** — Pourquoi `BypassAuditLogger::printingBypassed()` est défini mais jamais wiré dans `EscPosPrinterService::sendRaw()` / `openDrawer()` / `testPrint()` ? Oubli ou volontaire ?
2. **Runbook §7** — L'exemple `[BYPASS-PRINTING] TCP/IP printer call short-circuited` est-il aspirational ou doit-il être fonctionnel V1 ?
3. **Sentinels statiques** — Les 9 grep-style sentinels passeraient même si bypass était cassé. Est-ce voulu (sentinels = anti-régression de présence du code) ou faut-il les renforcer en runtime ?
4. **Stale config:cache** — Doit-on ajouter un check `config:clear` automatique en `composer.json` `post-update-cmd` ou un middleware dev-only qui détecte le drift `.env` ↔ cache ?
5. **B6 STUB pré-existant** — Plan séparé à ouvrir ou tolérance acceptée tant que kiosk creds restent locaux ? Si toléré : qui signe l'acceptation du risque ?
6. **B8 disclosure** — Staging FoodKing sera-t-il public-facing (préprod accessible Internet) ou intranet seulement ? Si public, conditionner l'injection.
7. **B2 false-positive logs** — OK pour audit que `[BYPASS-PAYMENT]` log fire avant validation state machine ? Veut-on un second log "outcome=success/failed" pour la métrique ?
8. **Idempotency** — Si une requête `payment-confirm` est rejouée 3x avec même `Idempotency-Key` en bypass mode, le `BypassAuditLogger::paymentBypassed` log fire-t-il 3x ou 1x ? (Probablement 3x si middleware n'a pas encore court-circuité — à vérifier.)
9. **Rollback runbook §5** — Si un dev oublie `config:clear`, comment détecter en CI/CD que le cache est stale ?
10. **NullPrinterTransport** — Quand on rebranche le vrai TPE/printer en V1.x, comment garantit-on qu'aucun environnement ne reste sur `NullPrinterTransport` après désactivation des flags ? Sentinel CI ?

---

## 4. Verdict adversaire

**PROD-READY pour validation E2E user — conditionné HEAL léger (2 items, ≤30 lignes).**

**Justifications** :
- ✅ Prod-guard solide (B1 vérifié runtime)
- ✅ Sealing fiscal NF525 préservé (B4 + lecture flow)
- ✅ Outbox events préservés (B5, sous réserve runtime — fortement probable)
- ✅ Marker print-safe (B3 double-vecteur OK)
- ✅ Logs OWASP-clean (B10)
- ✅ Audit log payment fonctionnel (B2 acceptable)
- ✅ Sentinel guard fidèle au runtime prod (B9)
- ⚠️ **P1 dead code** `printingBypassed` — fix 5 lignes
- ⚠️ **P2 sentinels** essentiellement statiques — ajouter 1 test behavioural
- ⚠️ **P3 foot-guns** : stale config:cache + HTML disclosure — runbook + 1 ligne blade

**Pas de BLOCK** : aucune faille critique introduite par `bebcf7054`. Les weakness B6 (STUB) sont **pré-existantes** au commit. Le commit bypass ne dégrade pas la posture sécurité — au contraire, le prod-guard et l'audit log payment ajoutent des défenses.

**Conditions HEAL avant V1.x release** :
1. Wire `printingBypassed()` dans `EscPosPrinterService::sendRaw` + `openDrawer` (ou supprimer la méthode + amender runbook §7).
2. Ajouter ≥1 test runtime réel pour les invariants (payment + dispatch event).
3. Documenter explicitement dans `BYPASS_MODE_OPERATIONAL.md` §5 le risque stale `config:cache` et la commande de vérif obligatoire.
4. (Optionnel P3) Conditionner injection `bypassMode` en non-prod dans `master.blade.php`.

---

## 5. Limitations honnêtes

- **B5 non testé runtime live** : la preuve repose sur lecture flow + sentinel statique. Un vrai test de `confirmCounterPayment` en mode bypass + assert sur table `domain_events` n'a pas été exécuté (pas dans le scope agent adversaire qui n'écrit pas de spec).
- **Spec Playwright BLUE non re-exécutée** : 4 PASS + 1 skipped annoncés. Sanity OK via screenshot artifacts mais pas re-validé end-to-end.
- **Pas de fuzzing transaction_id** : la rule `string|max:255` accepte tout (`<script>`, SQLi, etc.). Stockage va probablement échouer (column type / sanitization) mais non vérifié.
- **Pas d'analyse `IdempotencyKeyMiddleware`** en bypass : question Q8 ouverte.
- **Pas d'analyse perf** : `Log::warning` sur chaque payment-confirm en bypass peut spammer logs si bench E2E lance 1000 commandes/min. À surveiller.
- **Aucun audit du frontend kiosk wizard** : la cartographie cite `KioskPaymentComponent.vue` ligne 566, mais je n'ai pas exhaustivement scanné le wizard pour d'autres call paths qui pourraient stub un transaction_id différemment.

---

## 6. Annexe — résumé des vérifications runtime

| Hypothèse | Méthode | Résultat |
|---|---|---|
| B1 prod-guard | `APP_ENV=production php artisan inspire` avec `PAYMENT_BYPASS_MODE=true` | RuntimeException levée comme attendu ✓ |
| B5/B7 toggle | `.env` flip + `php artisan config:cache` puis `tinker --execute='config(...)'` | Stale cache confirmé après flip sans `config:clear` ✓ (foot-gun) |
| B7 transport binding | `tinker → app(PrinterTransportInterface::class)` avec `PRINTING_BYPASS_MODE=true` | `NullPrinterTransport` retourné ✓ |
| B8 HTML leak | `curl http://localhost:8000/login \| grep bypassMode` | 3 clés exposées en HTML public ✓ (mineure) |
| P1 dead code | `grep -rn printingBypassed app/` | 0 caller en code applicatif (uniquement test) ✓ |
| Sentinels suite | `php artisan test --filter='Bypass*'` | 16/16 PASS ✓ |
| .env restored | `diff .env .env.bak` | identical, no residue (BYPASS keys absent → defaults to false) ✓ |

---

**Auteur** : RED Adversary Agent (GSTACK Security + QA persona)
**Date** : 2026-05-08
**Référence cycle** : commit `bebcf7054` audit
**Trust level** : Independent verification, no shared evidence with BLUE.
