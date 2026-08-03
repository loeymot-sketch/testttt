# AUDIT_PLAN — Mobile design "perfection" cycle B — 2026-05-11

**Mission verbatim (owner)** : *"logique de kiosk et design pour une app mobile intelligente comme on a déjà mais améliorer le design, le wizard en kiosk au mobile faut le rendre parfait avec design intelligent mobile ! c'est pas d'importer kiosk design mais logique et raisonne fort pour design mobile et fluidity ! test-e2e est crucial"*

**Carte blanche** : *"je t'autorise à tout faire même les audits des tests des analyses des recherches ment fort … finir la mission complète avec perfection et raisonnement fort"*

**Run id** : `mobile-design-perfect-2026-05-11`
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**Head baseline** : `d9ee89928` (post round-2 clusters 1-4)
**Mode** : test-e2e + GStack 7 sub-agents + Adversarial Red-Team single-invocation. Loop until 2 rounds GREEN set-equality (cap 3 rounds).
**Workers** : `1` (locked)

---

## §0 — Décisions locked (CLARIFs décidés par orchestrateur sans owner-gate)

Décisions prises sous carte blanche owner, documentées explicitement.

| ID | Décision | Justification |
|---|---|---|
| CL-1 | **Wizard shape = full-screen-per-step conservé** | FSM kiosk-aligned 1:1 ; minimise disruption ; le premium polish s'installe DANS la coque (header sticky depth, step cross-fade, recap card depth, sticky CTA glassmorphism). |
| CL-2 | **Pas de photos bundlées V0 ; emoji+cream tile fallback** | Cluster-4 a déjà bouché les onb/featured/menu placeholders via `<image-slot>`. V0 garde fallback emoji. Photo bundle = Phase 6 produit + Capacitor. |
| CL-3 | **Light mode only V0** | `prefers-reduced-motion` couvert styles.css l.52. Dark mode = Phase 6+ (centaines de couleurs hard-codées à passer en data-theme). |
| CL-4 | **Mobile palette = source de vérité** | Brief explicite "pas importer kiosk design". Drift mobile = P1 ; aucune mesure kiosk visuel ce cycle. |
| CL-5 | **Cascade menu en 3 pages mobile** (drink, fritesStyle, fritesSauce) | Documenté `screens-item-steps.jsx` l.18-22. Plus lisible 390px. Divergence VOLONTAIRE FSM mobile vs kiosk single-page. |
| CL-6 | **Recap read-only** | Back arrow ramène aux steps précédents (déjà câblé l.789-795 ScreenItemWizard). Edit-in-place = Phase 6 produit. |
| CL-7 | **prefers-reduced-motion respecté partout** | Audit fluidité vérifie qu'aucune animation ne bypass la media query. |

Pas de question owner pending. Plan s'exécute.

---

## §1 — Baseline + état post-cluster

- **Mobile server** UP `127.0.0.1:8081` (PID 4886).
- **Kiosk** `127.0.0.1:8000` DOWN — NON requis (Wave-Logic = read-only Vue source, pas de capture visuelle).
- **Smoke loyalty 3 critical specs** : `loyalty-01 / loyalty-04 / loyalty-adv-A1` → **3/3 PASS** baseline. À re-vérifier après chaque cluster commit.
- **Cluster-1..4 livrés** (commits 6cb067c78 / 292b4cd69 / d9ee89928 / 8c7fbe202) : cart composition, ScreenConfirm cart binding, loyalty idempotency+RGPD, visual+image-slot.
- **TabBar A-007 round-1 P1 OUVERT** : `shared.jsx:52` reste `<div onclick>` au lieu de `<button aria-pressed>`. À fixer ce cycle.

---

## §2 — Inventaire surface (5 waves)

| Wave | Source | Captures dir | States | Spec |
|---|---|---|---|---|
| **Logic** | `KioskWizardComponent.vue` read-only + `screens-item-steps.jsx` | aucune | FSM JSON inline | aucune |
| **Wizard** | `screens-item-steps.jsx` 9 steps × 7 templates | `test-e2e-mobile-design-perfect-wave-wizard/` | 28 quartets | nouvelle |
| **Fluidity** | transitions, scroll, gestures, thumb-reach, motion | `test-e2e-mobile-design-perfect-wave-fluidity/` | 12 traces + perf JSON | nouvelle |
| **Surfaces** | onb + home + menu + cart + confirm + orders + profile + loyalty + modals | `test-e2e-mobile-design-perfect-wave-surfaces/` | 32 quartets | nouvelle |
| **A11y** | role/aria/keyboard/focus/contrast + axe-core | `test-e2e-mobile-design-perfect-wave-a11y/` | 20 quartets + axe JSON | nouvelle |

**Total** : ~92 quartets + 12 traces + 20 axe = ~480 artifacts. ~70-90 findings round-1 attendu.

---

## §3 — Équipe sub-agents

| # | Nom | Type | I/O | Brief location |
|---|---|---|---|---|
| 1 | Kiosk-Logic-Extractor | Explore | Read-only | Phase 1 |
| 2 | Wizard-Mobile-Designer | general-purpose | Read-only | Phase 2 parallèle |
| 3 | Fluidity-Auditor | general-purpose | Read-only | Phase 2 parallèle |
| 4 | Surfaces-Auditor | general-purpose | Read-only | Phase 2 parallèle |
| 5 | A11y-Auditor | general-purpose | Read-only | Phase 2 parallèle |
| 6 | E2E-Spec-Author | general-purpose | Read-only ; écrit du texte | Phase 3 |
| 7 | Adversarial-Red-Team | general-purpose | Read-only ; **1 invocation toutes-waves** | Phase 5 |

Pattern-Benchmarker (#8) **dropped** per advisor note (training knowledge suffit).

Adversarial-Red-Team = **1 seule invocation** qui lit les 4 waves' artifacts et émet 4 JSONs (token-efficient vs 4 invocations séparées).

---

## §4 — Wave structure

### Wave-Logic
- **Sub-agent owner** : #1 Kiosk-Logic-Extractor
- **Artifact** : `reports/test-e2e/mobile-design-perfect-2026-05-11/kiosk-fsm-extracted.json` + commentaire divergences mobile vs kiosk
- **Gate** : Wave-Wizard ne démarre pas avant qu'il existe.

### Wave-Wizard (28 states, fork wave-B)
Owner sub-agent #2 (audit/redesign plan) + #6 (spec author) + #7 (review).

States exhaustifs : 18 Tacos XXL (8 steps × variantes + recap qty + add-to-cart), 2 Terminator (sandwich), 2 Cheese Burger, 1 Assiette Poulet, 1 Ojja V3.8, 1 Salade D1, 1 Wings snacking, 1 Tiramisu direct, 1 Frites grande.

**Pricing assertions invariants** :
- Tacos XXL full combo : 12,50 + 0,50 (2e sauce) + 1,00 (Œuf) + 3,00 (Menu) + 1,00 (Cheddar) + 0 (BBQ) = **18,00 €**
- Terminator no-formule : 9,00 €
- Cheese Burger no-formule : 6,00 €

**Cross-wave** : recap == cart == confirm.

### Wave-Fluidity (12 traces, trace:on)
Owner sub-agent #3 + #6 + #7.

**Seuils tightened (advisor: emulator faster than device, set 30-50% stricter)** :
- Step transition <**200ms** (was 300ms ; emulator measurement, directional only)
- Scroll FPS ≥**58** (was 55)
- CTA bottom ≤80px du bord viewport
- prefers-reduced-motion ON → toutes animations <10ms
- lc-btn :active scale(0.97) <100ms

États : step→step transitions × 3, menu scroll FPS, cart scroll FPS, modal-pay open/close, toast slide-down, confetti paint, thumb-reach, reduced-motion check, touch feedback.

### Wave-Surfaces (32 states, fork wave-A + wave-D)
Owner sub-agent #4 + #6 + #7.

Splash + 4 onb + login + OTP (vérifier A-001 démo gated) + home AM/PM + menu + cart + confirm + points-gain + orders + profile + loyalty 6 sections + WizardRedeem 3-step + 3 modals.

### Wave-A11y (20 states, axe-core inject)
Owner sub-agent #5 + #6 + #7.

États : splash axe, onb axe, OTP aria, **TabBar aria-pressed** (A-007 vérification), icon-buttons aria-labels, menu chips keyboard, item wizard radiogroup role, item wizard keyboard nav, item wizard transition focus mgmt, CTA disabled aria-describedby, cart stepper aria-live, pay-modal aria-modal+ESC, confetti aria-hidden, WizardRedeem step1+step3, loyalty QR aria-label, barcode toggle aria-pressed, opt-out focus trap, toast aria-live.

**Cible** : 0 axe critical + 0 axe serious.

---

## §5 — Cross-wave invariants

| Invariant | Severity P |
|---|---|
| 0 raw label (`Label\.X`, `kiosk\.x`, `^0undefined$`, `NaN\s*€`) | P1 |
| 0 white-on-white pixel sweep | P2 |
| 0 console error hors allowlist | P1 |
| 0 page error (React unhandled, Babel parse fail) | P0 |
| Pricing reconcile cart==recap==confirm | P0 |
| Palette tokens présents `:root` | P1 |
| prefers-reduced-motion respecté | P2 (P1 si bloque a11y) |

---

## §6 — Adversarial protocol

Référence : `REVIEWER_PROTOCOL.md` (12 catégories + allowlist) + `FINDINGS_SCHEMA.md` (output JSON shape).

**Règles additionnelles** :

1. **Every finding = 3 fields obligatoires** : `evidence` (file:line OU PNG:bbox OU JSON:key), `fix_hint` (patch sketch file:line + before/after), `effort` (S<1h / M 1-4h / L>4h).
2. **Cross-validation 2 reviewers** : sub-agent 2/3/4/5 + Adversarial #7 doivent tous deux toucher chaque wave.
3. **Primary-source check** : claim "code fait X" → file:line. Claim "PNG montre Y" → PNG-path:bbox. Sinon rejeté.
4. **Anti-confirmation-bias** : pour chaque "OK" sub-agent, Adversarial cherche 1 contre-exemple.
5. **Severity rules** : numeric integrity P0 jamais downgrade ; a11y critical=P0, serious=P1, moderate=P2 ; truncation bouton critique=P1, body=P2.
6. **Finding ID immutability** : ID stable cross-rounds. Round-N OPEN → MÊME ID round-N+1, bump `rounds_open`. Nouveau finding round-N+1 = nouvel ID.
7. **Stale baseline check** (advisor) : si finding cite file:line qui n'existe plus = close `pre-existing-fix` ; ne pas re-fix.

---

## §7 — Convergence

**GREEN** = `sum(open_P0) + sum(open_P1) == 0` sur 4 waves capturables (Logic exclu).

**Stop** = 2 rounds GREEN avec **set-equality** (même finding IDs, même statuses).

**Cap** = 3 rounds. Si round-3 ne converge pas → escalade owner avec diff finding round-2 vs round-3.

---

## §8 — Plan fix priorisation

- Ordre : P0 → P1 → P2 (P3 informational, jamais commité avant cycle suivant).
- Cluster bundling : 1 commit = idéalement 1 fichier (2 si CSS+JSX siblings).
- **Max 5 commits / round** : si plus de 5 clusters → (severity × 1/effort) top-5, reste glissé.
- **Pre-commit smoke gate** : `loyalty-01 + loyalty-04 + loyalty-adv-A1` doit rester vert AVANT chaque commit.
- **Frozen-zone touch** = blocker. Si finding requiert kiosk Vue / NF525 / pos-wizard → skill `lock-plan`, owner approval.

Commit message format :
```
mobile design: <bref purpose>

Findings closed: <W-001>, <W-002> (P1 + P1)
Round: <N>
Effort: S+M = ~2h
File touched: mobile/<filepath>.<ext>

<motivation prose>
```

---

## §9 — Risk register

| # | Risque | Sev | Mitigation |
|---|---|---|---|
| 1 | Token budget 7 sub-agents × N rounds | HIGH | Adversarial collapsed à 1 invocation ; Pattern-Benchmarker dropped |
| 2 | Scope creep vers kiosk visuel (frozen) | HIGH | Wave-Logic stamp "READ-ONLY logic-only" ; Adversarial reject tout finding kiosk visuel |
| 3 | Fluidity false-positives PNG-only | MED | Wave-Fluidity instrumentée trace+perf JSON, JAMAIS findings PNG-only |
| 4 | Babel parse fails après refactor JSX | MED | Pas de JSX exotique (no top-level await, no nullish assignment) ; smoke après chaque commit |
| 5 | Convergence drift : ID immutability cassée | MED | Adversarial réutilise IDs ; orchestrateur reject JSON où ID closé re-utilisé |
| 6 | Image-slot placeholder re-flaggué chaque round | LOW | CL-2 allowlist explicite |
| 7 | 20 loyalty specs cassent sur refactor wizard | MED | Pre-commit smoke 3 specs critiques + full suite avant merge final |
| 8 | Emulator timing != device timing | MED | Thresholds tightened 30-50%, documenté "directional only" |
| 9 | Captures précédentes stale 1-2 commits | LOW | Re-capture frais round-1 — pas de réutilisation captures `mobile-design-full` |

---

## §10 — Run order orchestrateur

### Phase 0 — Pre-flight ✓ DONE
1. Mobile server :8081 UP ✓
2. Reports dirs created ✓
3. Smoke loyalty 3/3 PASS ✓
4. REVIEWER_PROTOCOL + FINDINGS_SCHEMA copiés ✓
5. AUDIT_PLAN écrit ✓

### Phase 1 — Wave-Logic
6. Invoke #1 Kiosk-Logic-Extractor
7. Orchestrateur écrit `kiosk-fsm-extracted.json` à partir du retour

### Phase 2 — Audits parallèles (SINGLE message, 4 Agent calls)
8. Invoke #2 Wizard-Mobile-Designer (avec FSM JSON en input)
9. Invoke #3 Fluidity-Auditor
10. Invoke #4 Surfaces-Auditor (avec baseline round-1 findings)
11. Invoke #5 A11y-Auditor

### Phase 3 — Spec authoring
12. Invoke #6 E2E-Spec-Author avec outputs #2-5
13. Orchestrateur écrit 4 spec files

### Phase 4 — Capture round-1
14. `npx playwright test --config=tests/mobile-e2e/playwright.config.js tests/e2e/test-e2e-mobile-design-perfect-wave-*.spec.js`
15. Vérifie artifacts peuplés

### Phase 5 — Adversarial single-invocation
16. Invoke #7 Adversarial-Red-Team (4 waves dans 1 message) → 4 JSONs
17. Orchestrateur écrit `round-1/wave-{wizard,fluidity,surfaces,a11y}-findings.json`

### Phase 6 — Agrégat verdict
18. Compute open_P0 + open_P1
19. Si GREEN et 2e round consécutif set-equality → STOP
20. Sinon → Phase 7

### Phase 7 — Fix clusters
21. Group findings par fichier. Top-5 par severity × effort_inv.
22. Smoke loyalty pre-commit pour chaque cluster.
23. Edit + commit format §8.
24. Goto Phase 4 round-2 (cap round-3).

### Phase 8 — FINAL_REPORT
25. `reports/test-e2e/mobile-design-perfect-2026-05-11/FINAL_REPORT.md` + BRAIN §2/§3 update + Graphiti episode push.

---

**Plan ready.** Phase 1 démarre maintenant.
