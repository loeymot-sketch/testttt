# VERIFY P-MEGA-W7.B — Hardware Fallback 200% (Phase B.3 du cycle W7)

**Date** : 2026-04-20  
**Mode** : READONLY  
**HEAD audited** : `7459487ee`  
**Subagent** : explore very thorough  
**Verdict global** : **PASSED** (2 corrections recommandées avant synthèse)

---

## 0. Résumé exécutif

| Critère | Verdict |
|---------|---------|
| Scope contract (off-limits non touchés) | OK |
| KioskPaymentComponent.vue strict scope (L405 only) | OK (7 lignes diff seulement) |
| Bugs invisibles trouvés | 6 (2 MED, 4 LOW) |
| Tests sentinelles qualité réelle | DEGRADED (1 sentinelle incomplète) |
| Cohérence runtime E2E | DRIFT mineur (timer accueil figé) |
| Documentation report | OK |
| Linter | OK |

---

## 1. Vérifications Git (readonly)

- **HEAD** : `7459487ee` — `[P-MEGA-W7-B] Hardware fallback — printer retry+display + TPE timeout dedup + audio visual fallback`
- **Diff `c1832bf77..7459487ee`** : 16 fichiers (report, css, 3 Vue kiosk, `kioskPrinter.js`, 5 JSON, 4 tests) — **aucun** `app/Services/**`, `OrderController`, `kioskOfflineQueue.js`, migrations, routes.
- **Scope contract** : aucun chemin "off-limits" → **pas de BREACH**.

### Vérification CRITIQUE Bloc β

`git diff c1832bf77..7459487ee -- KioskPaymentComponent.vue` = **7 lignes** :
- Import `KIOSK_HARDWARE` ~L207
- 2 lignes message i18n ~L345-346
- SSOT `TPE_TIMEOUT_MS` import ~L405-411

**`confirmBackendPayment`, `change-status`, `paymentFailureCount`, `MAX_PAYMENT_FAILURES`, `_invokeTpe`, void, retry** : **NON modifiés** dans ce diff.

✅ **Pas de HARD GATE BREACH** sur zone fiscale.

### SSOT validé

`resources/js/config/kioskHardware.js` L15 : `TPE_TIMEOUT_MS: 120_000` — valeur préservée.

---

## 2. Bugs invisibles (au-delà des 16 nouveaux tests)

### [B1] MED — Impression bridge : retries n'emploient pas timeout uniforme

**Fichier** : `resources/js/helpers/kioskPrinter.js` L223-L247

Délai max ≈ N×(durée `printReceipt`+`printEscPos`)+`(N-1)×PRINTER_RETRY_MS`. Échec **immédiat** attend quand même `PRINTER_RETRY_MS` entre tours.

**Mitigation** : politique fail-fast — sauter `PRINTER_RETRY_MS` si erreur synchrone immédiate (vs timeout réel).

### [B1] LOW — Compte à rebours figé pendant escPosPrint

**Fichier** : `KioskConfirmationComponent.vue` L354-L355, L396-L398

Le compte à rebours d'accueil (timer auto-redirect) est arrêté pendant toute la durée `escPosPrint` (retries inclus).

**Mitigation** : décision produit/UX — gel volontaire ou async non-bloquant.

### [B2] LOW — Ticket fallback sans `max-height`/`overflow-y` explicite

**Fichier** : `KioskConfirmationComponent.vue` L638-L649

Reçu très long peut déborder le viewport. Pas de scroll au doigt garanti.

**Mitigation** : `max-height: 80vh; overflow-y: auto;` sur le container fallback.

### [B2] LOW — Pas d'`aria-live` sur le bloc fallback ticket

**Fichier** : `KioskConfirmationComponent.vue` template ~L89-L99

Affichage critique pour utilisateur en cas d'imprimante KO mais pas annoncé par screen reader.

**Mitigation** : `role="status"` ou `aria-live="polite"` sur le container.

### [B8] MED — Sentinelle TPE ne vérifie pas la valeur exacte

**Fichier** : `tests/js/kioskPaymentTpeTimeout.spec.js`

Le test vérifie l'import `TPE_TIMEOUT_MS` depuis config par regex source mais N'ASSERTE PAS que la valeur résolue est `120000`. Si la valeur change dans config silencieusement → comportement TPE altéré sans alerte test.

**Mitigation** : ajouter `expect(KIOSK_HARDWARE.TPE_TIMEOUT_MS).toBe(120_000)`.

### [B6] LOW — Duplication styles `#kiosk-print-receipt`

**Fichiers** : `KioskConfirmationComponent.vue` (SFC `<style>`) + `resources/css/kiosk-fallback.css`

Risque de drift CSS si modifications futures. Cascade peut donner des résultats imprévisibles.

**Mitigation** : centraliser dans le fichier CSS uniquement.

---

## 3. Tests sentinelles qualité réelle : DEGRADED

| Spec | Verdict | Détail |
|------|---------|--------|
| `kioskConfirmationFallback.spec.js` | OK | Monte le composant, `data-print-failed`, titre testés |
| `kioskPaymentTpeTimeout.spec.js` | DEGRADED | Regex import OK ; pas d'assertion `TPE_TIMEOUT_MS === 120_000` |
| `kioskWaitingAudioFallback.spec.js` | OK (acceptable) | Ctor throw + toast + classe ; pas de test `start()` throw isolé |

---

## 4. Cohérence runtime E2E : DRIFT mineur

### Scénario 1 — Imprimante offline complet
- États cohérents
- ⚠️ Timer accueil figé pendant retries (B1 LOW)
- Succès remet `printFailed` false correctement (`KioskConfirmationComponent.vue` L387-L389)

### Scénario 2 — TPE timeout
- Compteur / void inchangés par ce commit ✅ (diff n'y touche pas)
- Timeout toujours `Promise.race` (`KioskPaymentComponent.vue` L340-L372, L405-L412)
- Comportement préservé

### Scénario 3 — Buzzer absent
- AudioContext absent/suspendu/non-running → fallback OK
- `playReadySound()` non `await` dans `markReady` (`KioskWaitingComponent.vue` L307-L310)
- Pas de double toast si audio OK

---

## 5. Documentation report : OK

`reports/execution/RUN_P_MEGA_W7_B_HARDWARE_FALLBACK_EXECUTE_2026-04-20.md` :
- `EXECUTE_DELEGATION` ✅
- Blocs documentés ✅
- bug_signatures listés ✅
- LOW i18n de/bn documenté ✅
- LOC ~cohérents avec `git diff --stat` (16 fichiers, +713/-268)

---

## 6. Linter : OK

Aucune alerte sur fichiers kiosk vérifiés.

---

## 7. Recommandations correctives URGENTES (avant SYNTHESE W7) : 2

1. **[B8]** Étendre `kioskPaymentTpeTimeout.spec.js` :
   ```js
   import { KIOSK_HARDWARE } from '../../resources/js/config/kioskHardware';
   expect(KIOSK_HARDWARE.TPE_TIMEOUT_MS).toBe(120_000);
   ```
2. **[B1]** Documenter dans le RUN ou ajuster le gel du timer accueil pendant retries impression (décision produit/UX).

## 8. Recommandations différées (cycle ultérieur) : 2

1. **[B2]** `aria-live="polite"` ou `role="status"` sur conteneur fallback ticket
2. **[B1]** Politique « fail-fast » sur retry imprimante (sauter `PRINTER_RETRY_MS` si erreur synchrone immédiate)
