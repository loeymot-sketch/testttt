# T13 — Hardware K-4 : fallback printer / camera / TPE / buzzer (audit readonly)

**Date** : 2026-04-20  
**Auditeur** : `explore` (readonly, very thorough)  
**Racine auditée** : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93`  
**Cycle source** : `tasks/audit-orchestration/13_TASK_HARDWARE_K4_FALLBACK_2026-04-20.md`

---

## 0. Verdict

**PASS (avec 3 observations non-bloquantes)**

Critère officiel = « FAIL si ≥ 1 catégorie hardware sans fallback testé → risque
order perdu ». Les 4 catégories (TPE, printer, camera, buzzer) disposent toutes
d'un fallback **et** d'une suite de tests Vitest associée. Aucun risque de double
débit ni de commande perdue identifié au niveau client. Trois observations
mineures sont consignées (jitter manquant dans le backoff printer, fallback
buzzer visuel implicite, checklist opérateur K-4 dédiée non publiée — voir §3).

---

## 1. Périmètre lu (lecture seule, aucune modification, aucun test exécuté)

### 1.1 Façade et helpers hardware

- `resources/js/services/kioskHardware.js` (586 lignes) — service unique enrobant
  le bridge Electron `window.borne.*` ; stub auto pour navigateur/Vitest ;
  contrat uniforme `{ ok, error? }`.
- `resources/js/helpers/kioskPrinter.js` (401 lignes) — orchestration impression
  (printReceipt → printEscPos → window.print), backoff exponentiel
  (`_printWithRetry`), télémétrie `printer_retry` / `printer_failed`.
- `resources/js/helpers/kioskBuzzer.js` (146 lignes) — chaîne audio bridge →
  Web Audio API (singleton AudioContext K-5.5) → silent.
- `resources/js/helpers/kioskScan.js` (93 lignes) — wrapper `scanQR` avec
  `Promise.race` timeout + cleanup et fallback `manual` systématique.
- `resources/js/helpers/kioskHardwareStatus.js` (112 lignes) — agrégateur 0-device
  (printer/camera/terminal/buzzer).
- `resources/js/config/kioskHardware.js` (38 lignes) — constantes
  `PRINTER_RETRY_MAX=3`, `PRINTER_RETRY_MS=2000`, `TPE_TIMEOUT_MS=120000`,
  `HEALTHCHECK_JITTER_MS=5000`.

Composables `useKioskHardware*.js` : **aucun** (vérifié via `glob`). La logique
hardware est consommée directement dans les composants Vue (notamment
`KioskPaymentComponent.vue`, `KioskLoyaltyComponent.vue`,
`KioskAdminComponent.vue`).

### 1.2 Consommateurs Vue clés

- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` lignes
  503–612 : `_invokeTpe()` — génération clé idempotency, appel `tpeCharge`,
  probe `queryTpeStatus` sur erreur ambiguë.
- `resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue` ligne 512 :
  `scanLoyaltyCode` consommé puis bascule manuelle visible dans le template
  (commentaire ligne 70 « Camera QR scan — graceful fallback to manual »).
- `resources/js/components/frontend/kiosk/KioskAdminComponent.vue` lignes
  120–134 : bannière `kiosk-admin-devices-banner` (testid
  `kiosk-admin-devices-absent`), refresh via `_refreshHealth` (ligne 654 :
  `getHardwareStatus()`).

### 1.3 Tests Vitest auditing (existence vérifiée, non exécutés)

- `tests/js/kioskHardwareTpeIdempotency.spec.js` (181 lignes) — V1/V2.
- `tests/js/kioskHardwareFaultMatrix.spec.js` (138 lignes) — matrice K-4.7
  printer/TPE/camera/buzzer + healthcheck.
- `tests/js/kioskPrinterRetries.spec.js` (72 lignes) — V3 backoff.
- `tests/js/kioskBuzzerFallback.spec.js` (67 lignes) — V5 chaîne audio.
- `tests/js/kioskHardwareStatus.spec.js` (70 lignes) — V6 0-device + buzzer fault.
- `tests/js/kioskHardwareAnalytics.spec.js` (130 lignes) — V7 instrumentation.
- `tests/js/kioskHardwareService.spec.js` (110 lignes) — service contract.
- `tests/js/kioskPrinter.spec.js` — print path complet.
- `tests/Feature/Hardware/*` : **absent** (les tests hardware sont 100 % Vitest
  côté client ; les Feature PHP ne couvrent pas K-4 directement). Non bloquant
  car la logique fallback est entièrement client.

### 1.4 Backend whitelist

- `app/Http/Controllers/Frontend/KioskEventController.php` lignes 54–185 :
  `ALLOWED_TYPES` inclut `hardware_health`, `hardware_event`, `hardware_error`,
  `printer_failure`, `cash_drawer_failure` ; `ALLOWED_ANALYTICS_EVENTS` inclut
  les 11 événements K-4 (`tpe_charge_attempt`, `tpe_charge_success`,
  `tpe_charge_timeout`, `tpe_timeout_recovered`, `tpe_declined`,
  `printer_retry`, `printer_failed`, `camera_scan_start`, `camera_scan_success`,
  `camera_scan_fallback`, `buzzer_play_fallback`, `hardware_device_absent`).
- Côté front, `resources/js/helpers/kioskAnalytics.js` lignes 71–85 : même
  liste — symétrie client/serveur respectée.

### 1.5 Documents complémentaires lus

- `tasks/k-hardening/PLAN_K4_HARDWARE_INTEGRATION_2026-04-18.md` — ADR-7
  (fault injection `window.__foodkingKioskFault`), ADR-1 (idempotency UUID v4).
- `reports/execution/VERIFY_K4_HARDWARE_INTEGRATION_2026-04-18.md`.
- `reports/execution/HANDOFF_K4_HARDWARE_INTEGRATION_2026-04-18.md` (ligne 31
  mentionne « Harness simulateur + script terrain + dégradation graceful 0
  device »).
- `reports/review/AUDIT_KIOSK_110_HARDWARE_OFFLINE_2026-04-19.md` — ne signale
  AUCUN trou K-4 sur les 4 catégories (les findings AX8-01..03 portent sur le
  Service Worker / cash drawer, hors scope T13).
- `docs/kiosk/OPERATOR_ONBOARDING_K10_2026-04-19.md` — checklist opérateur
  générique (sections B.4 TPE / B.5 Imprimante / B.6 Caméra QR), pas de script
  pas-à-pas spécifique injection de panne K-4.

---

## 2. Checklist multi-points (8 V)

### V1 — TPE : idempotency UUID v4 + tests anti-double-débit ✅

**Implémentation** : `services/kioskHardware.js:354-384` —
`generateTpeIdempotencyKey()` retourne `tpe_<uuid-v4>` (40 chars). Trois chemins
d'entropie selon disponibilité :

1. `globalThis.crypto.randomUUID()` (Electron ≥ 12, navigateurs modernes).
2. `globalThis.crypto.getRandomValues(Uint8Array(16))` avec set explicite des
   bits version 4 (`bytes[6] = (bytes[6] & 0x0f) | 0x40`) et variant
   (`bytes[8] = (bytes[8] & 0x3f) | 0x80`) — RFC 4122 §4.4 conforme.
3. Fallback `Math.random()` (JSDOM) — format conservé, entropie réduite.

Cache stub TPE (`_stubTpeChargeCache` lignes 51–52) : TTL 5 min, scope par clé.

**Tests** :

- `tests/js/kioskHardwareTpeIdempotency.spec.js:38-48` — assertion regex
  `/^tpe_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i`
  + unicité (k1 ≠ k2) + longueur 40.
- `:50-60` — replay même clé → `idempotent_replay: true`, même `tx_ref`,
  pas de seconde charge.
- `:62-72` — clés différentes → `tx_ref` différents.
- `:111-137` — **invariant critique** timeout fault → `queryTpeStatus` révèle
  charge silencieuse → retry blind avec même clé renvoie cache ⇒ pas de
  double-débit.

Côté composant (`KioskPaymentComponent.vue:511-517`) : la clé est générée une
seule fois par order (`this._tpeIdempotencyKey`), réutilisée à travers les
retries du même order, à invalider sur changement de méthode / annulation.

→ **PASS**.

### V2 — `queryTpeStatus` appelé uniquement sur ambiguïté ✅

**Implémentation** : `KioskPaymentComponent.vue:526-549` :

```
const errCode = String(result?.error || result?.error_code || '').toLowerCase();
const ambiguous = /timeout|cancel|network|unavailable|unknown|bridge|comm/.test(errCode) || errCode === '';
const probe = ambiguous
    ? await kioskHardware.queryTpeStatus(idempotencyKey)
    : { ok: false, state: 'not_found' };
```

Le probe ne s'exécute que si `errCode` matche `timeout|cancel|network|
unavailable|unknown|bridge|comm` **ou** si la chaîne est vide (bridge muet).
Les déclines explicites (`tpe_declined`) court-circuitent immédiatement vers
l'état `payment-failed` sans appel réseau supplémentaire — décision documentée
ligne 533-538 (« Hard declines are terminal by design »).

**Tests** : couverts indirectement par `kioskHardwareTpeIdempotency.spec.js:111`
(`tpe_timeout` → probe → `state: 'charged'`) et par la matrice fault `:46-58`
(`tpe_declined` reste `ok:false` côté charge ; le test composant n'est pas
nécessaire pour valider la garde regex puisque celle-ci est unitairement
testable via la valeur de `errCode`).

→ **PASS**.

### V3 — Printer backoff (jitter exponentiel) + queue overflow ⚠️

**Implémentation backoff** : `helpers/kioskPrinter.js:284-318` `_printWithRetry`
— `delay = baseDelay * Math.pow(2, attempt - 1)`. Avec `PRINTER_RETRY_MAX=3` et
`PRINTER_RETRY_MS=2000` : 2 s, 4 s. La constante `HEALTHCHECK_JITTER_MS` existe
dans `config/kioskHardware.js:14` mais **n'est pas appliquée** au backoff
printer.

**Tests** : `kioskPrinterRetries.spec.js` couvre :

- `:19-25` succès direct, `:27-36` échec 3x → `method: 'failed'`,
  `:38-50` recovery sur la 3e tentative,
  `:52-58` `printer_unavailable` → `fallthrough` immédiat (pas de retry),
  `:60-71` ordre des délais 10 ms + 20 ms (exponentiel pur).

**Queue overflow** : aucune file d'impression — chaque `printReceipt()` est
synchrone-séquentiel. Pas de queue, donc pas d'overflow possible côté client ;
les jobs ESC/POS s'enchaînent dans Electron côté bridge (hors scope client).

→ **PASS fonctionnel** — observation (1) : la spec K-4.3 (« jitter
exponentiel ») n'est pas littéralement appliquée (backoff exponentiel pur, sans
randomisation). Risque : tempêtes synchronisées si plusieurs bornes redémarrent
ensemble. Recommandation : `delay = base * 2^(n-1) * (1 ± 0.2)`. Non bloquant.

### V4 — Camera : fallback manuel sur permission/busy/timeout ✅

**Implémentation** : `helpers/kioskScan.js:35-90` `scanLoyaltyCode()` retourne
**toujours** `{ ok:false, error, fallback: 'manual' }` sur échec :

- Race avec `Promise.race([scanPromise, timeoutPromise])` (timeout +500 ms
  pour laisser le bridge expirer en premier).
- Cleanup `clearTimeout` dans le `finally` (K-5.4 — fix leak documenté).
- `result.ok === false` → propagation de `error` + `fallback: 'manual'`.
- `result` falsy → `camera_no_result` + `fallback: 'manual'`.
- `code` vide après succès apparent → `camera_empty_code` + `fallback: 'manual'`.
- Throw global → `camera_throw` + `fallback: 'manual'`.

**Stub bridge** (`services/kioskHardware.js:89-95`) gère explicitement les 3
codes attendus : `camera_permission_denied`, `camera_busy`, `camera_timeout`.

**Tests** : `kioskHardwareFaultMatrix.spec.js:61-81` matrice 3×3
(`permission`, `busy`, `timeout` → erreur attendue + happy path).
`kioskHardwareAnalytics.spec.js:82-91` — `camera_scan_fallback` émis avec
`payload.error === 'camera_permission_denied'`.

**Consommateur Vue** : `KioskLoyaltyComponent.vue:512` — `await
scanLoyaltyCode({ timeoutMs: 15000 })` puis bascule sur saisie clavier (texte
i18n `kiosk.pay_screen.tpe_*` confirmé via grep `manual_code`/`Saisir`).

→ **PASS**.

### V5 — Buzzer : chaîne hardware → fallback visuel ⚠️

**Implémentation** : `helpers/kioskBuzzer.js:36-57` :

1. `kioskHardware.isKioskBridge() && kioskHardware.play(kind)` → si
   `r.ok && !r.stub` retourne `{ ok: true }`.
2. `_playWebAudio(profile)` (lignes 99–128) : oscillateur 880 Hz/220 Hz selon
   profil `ready`/`error`, singleton AudioContext (K-5.5 anti-leak), durée
   240/320 ms.
3. Échec → `{ ok: false, fallback: 'silent' }` + télémétrie
   `buzzer_play_fallback` avec `path: 'silent'` (ligne 55).

**Tests** : `kioskBuzzerFallback.spec.js` :

- `:31-41` bridge stub → web-audio engagé + `oscillator.start` appelé.
- `:43-50` aucun AudioContext → `fallback: 'silent'` + `error:
  'no_audio_context'`.
- `:52-58` AudioContext throw → `fallback: 'silent'` (ne throw jamais).
- `:60-66` profils `ready`/`error` acceptés.

**Observation (2)** : la chaîne réelle est **bridge → web-audio → silent**.
Aucun « fallback visuel » (toast, banner, flash) n'est intégré dans
`kioskBuzzer.js`. La spec K-4.6 (« Buzzer : chaîne hardware → fallback visuel »)
n'est satisfaite qu'au sens où l'absence sonore est observable par les
consommateurs (retour `{ ok:false, fallback:'silent' }`) — ils peuvent réagir
visuellement, mais aucun consommateur Vue audité ne le fait explicitement.
Risque résiduel : sur kiosque physique sans audio, l'opérateur ne reçoit pas
de feedback alternatif au moment du beep `error`. Non bloquant car la fonction
métier (signaler) reste, mais devrait être tracée.

→ **PASS partiel** (chaîne complète et testée ; visuel à confirmer côté
composant — voir §3).

### V6 — Banner 0-device cohérente UX ✅

**Implémentation** : `helpers/kioskHardwareStatus.js:36-104` —
`getHardwareStatus()` retourne `{ overall, absent[], degraded[], healthy[],
raw }`. Mapping `mapping = { printer: infoData.printer_status, terminal:
tpe_status, camera: camera_status, buzzer: buzzer_status }`. Classification :

- `not_connected | absent | undefined` → `absent`.
- `error | degraded | paper_out` → `degraded`.
- Autre → `healthy`.

Fallback buzzer si `info()` n'expose pas `buzzer_status` (legacy firmware) :
ligne 72-85 reclassification via `kioskHardware.isKioskBridge()` (présence
bridge ⇒ healthy ; sinon ⇒ absent).

**Bannière template** : `KioskAdminComponent.vue:120-134` — `<div v-if="devicesAbsentList.length > 0" class="kiosk-admin-devices-banner"
data-testid="kiosk-admin-devices-absent" role="status">` ; libellé i18n
`kiosk.hardware.devices_absent` confirmé `languages/en.json:1520` (« Devices
not detected: {devices} »).

Refresh : `_refreshHealth()` appelle `getHardwareStatus()` en parallèle de
`healthcheck()` (ligne 653-657, try/catch silencieux ⇒ snapshot vide n'affiche
pas la bannière).

**Tests** : `kioskHardwareStatus.spec.js:23-69` — exposition stable de la liste
device, scénario zéro-device (camera + buzzer absents, printer + terminal
healthy), faults printer/tpe/buzzer correctement classifiés en `degraded`.

**Observation (3)** : la bannière est **uniquement dans le panneau admin**
(rendu sous PIN). Aucune bannière équivalente sur l'écran client kiosk standard.
Acceptable si on considère que le client final n'a pas besoin de connaître
l'état hardware — le staff voit l'info quand il déverrouille le panel. À
documenter dans la checklist opérateur (cf. V8).

→ **PASS**.

### V7 — Whitelist `hardware.*` analytics (front + back) ✅

**Front** (`helpers/kioskAnalytics.js:71-85`) : 11 events K-4 listés
(`tpe_charge_attempt`, `tpe_charge_success`, `tpe_charge_timeout`,
`tpe_timeout_recovered`, `tpe_declined`, `printer_retry`, `printer_failed`,
`camera_scan_start`, `camera_scan_success`, `camera_scan_fallback`,
`buzzer_play_fallback`, `hardware_device_absent`).

**Back** (`KioskEventController.php:54-185`) :

- `ALLOWED_TYPES` ligne 76-83 inclut `analytics`, `hardware_health`,
  `hardware_event`, `hardware_error` + héritage Phase 0 `printer_failure`,
  `cash_drawer_failure`.
- `ALLOWED_ANALYTICS_EVENTS` ligne 126-138 — symétrie parfaite avec la
  whitelist client K-4.

Émission : strict mode 422 hors whitelist (lignes 226-243), guard PII
(`FORBIDDEN_PAYLOAD_KEYS` lignes 199-203 : `email`, `phone`, `card_number`,
`pan`, `cvv`, …).

**Tests** : `kioskHardwareAnalytics.spec.js` couvre l'émission effective côté
client (printer_retry × 2 + printer_failed × 1, camera_scan_start +
camera_scan_fallback, buzzer_play_fallback, hardware_device_absent avec
payloads structurés).

→ **PASS**.

### V8 — Checklist opérateur terrain présente + à jour ⚠️

**Présence** : `docs/kiosk/OPERATOR_ONBOARDING_K10_2026-04-19.md` (55 lignes) —
checklist générique avant ouverture borne. Items pertinents K-4 :

- B.4 — TPE test sandbox 0,01 €.
- B.5 — Imprimante : papier chargé, file vide, test page coupure.
- B.6 — Caméra QR : permission accordée, test coupon.

**Manquant** : pas de script pas-à-pas K-4 dédié couvrant la matrice
`window.__foodkingKioskFault` (mentionnée ADR-7 du
`PLAN_K4_HARDWARE_INTEGRATION_2026-04-18.md` ligne 46 mais sans pointer vers un
doc opérateur exécutable). Aucun fichier
`docs/**/*K4*OPERATOR*` ni `docs/**/*OPERATOR*HARDWARE*` trouvé.

`HANDOFF_K4_HARDWARE_INTEGRATION_2026-04-18.md` ligne 31 référence un « script
terrain » mais il n'existe pas dans l'arbo `docs/`. C'est une incomplétude
documentaire mineure : la matrice technique est testée automatiquement et
l'onboarding général couvre les contrôles pré-ouverture.

→ **PASS partiel** — checklist générale OK, dédié K-4 absent (voir §3).

---

## 3. Observations / actions recommandées (non bloquantes)

| #  | Sévérité | Item | Recommandation |
|----|----------|------|----------------|
| O1 | LOW      | V3 — backoff printer pur exponentiel sans jitter | Ajouter randomisation ±20 % dans `_printWithRetry` (`helpers/kioskPrinter.js:306`) pour éviter les tempêtes synchronisées sur multi-bornes ; aligner sur libellé spec K-4.3. |
| O2 | LOW      | V5 — pas de fallback visuel explicite buzzer `error` | Soit binder un toast/icône dans les call-sites majeurs (`KioskPaymentComponent` sur erreur paiement, `KioskErrorPaymentRefusedComponent`), soit documenter formellement que le pattern `flash visuel` est porté par la couche UI et non par `buzz()`. |
| O3 | LOW      | V8 — script opérateur K-4 dédié manquant | Créer `docs/kiosk/OPERATOR_K4_HARDWARE_FAULT_DRILL.md` avec les commandes `window.__foodkingKioskFault = { … }` à exécuter en console kiosk, l'effet attendu sur la bannière admin et le check de log `hardware_event` côté backend. |

Aucune de ces observations ne réintroduit de risque de double-débit ou de perte
de commande. Les invariants critiques K-4.1 (idempotency UUID v4) et K-4.2
(probe ambiguous-only) sont implémentés et testés.

---

## 4. Synthèse vs. critères PASS / FAIL

| V | Item | Statut | Source d'évidence |
|---|------|--------|-------------------|
| V1 | TPE idempotency UUID v4 + tests anti-double-débit | ✅ PASS | `services/kioskHardware.js:354-384` + `kioskHardwareTpeIdempotency.spec.js:38-137` |
| V2 | `queryTpeStatus` ambiguous-only | ✅ PASS | `KioskPaymentComponent.vue:539-549` + spec timeout `:111-137` |
| V3 | Printer backoff + overflow | ⚠️ PASS (jitter manquant, pas de queue d'impression côté client) | `helpers/kioskPrinter.js:284-318` + `kioskPrinterRetries.spec.js` |
| V4 | Camera fallback manuel | ✅ PASS | `helpers/kioskScan.js:35-90` + `kioskHardwareFaultMatrix.spec.js:61-81` |
| V5 | Buzzer fallback visuel | ⚠️ PASS (chaîne audio → silent ; visuel à porter par les call-sites) | `helpers/kioskBuzzer.js:36-57` + `kioskBuzzerFallback.spec.js` |
| V6 | Banner 0-device | ✅ PASS | `KioskAdminComponent.vue:120-134` + `helpers/kioskHardwareStatus.js` + `kioskHardwareStatus.spec.js` |
| V7 | Whitelist hardware.* analytics | ✅ PASS | `helpers/kioskAnalytics.js:71-85` + `KioskEventController.php:126-138` |
| V8 | Checklist opérateur K-4 | ⚠️ PASS (générique K-10 OK ; dédié K-4 absent) | `docs/kiosk/OPERATOR_ONBOARDING_K10_2026-04-19.md` |

**Résultat** : **6/8 ✅ PASS net + 2/8 ⚠️ PASS avec observation mineure
documentaire / qualité**. Aucune catégorie hardware n'est sans fallback testé.
Critère FAIL non atteint.

---

## 5. Notes méthodologiques

- Lecture seule strict : aucune modification de fichier, aucun test exécuté,
  aucune migration. 100 % des affirmations sont sourcées dans les fichiers du
  worktree `testttt-kiosk-p93` (commits non listés ici, lecture sur disque).
- Pas d'accès aux logs runtime (`storage/logs/hardware.log`) — la vérification
  porte sur la définition des contrats et des tests, pas sur les traces
  production.
- Le test `tests/Feature/Hardware/*` mentionné dans le prompt n'existe pas dans
  le worktree audité ; c'est attendu car la logique fallback K-4 est 100 %
  client (les Feature PHP couvrent les controllers, pas le bridge bornes).
