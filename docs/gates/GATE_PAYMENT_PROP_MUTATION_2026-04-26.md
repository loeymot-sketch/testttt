# Gate Brief — PaymentComponent Prop Mutation Refactor — 2026-04-26

**Statut** : OPEN — En attente d'approbation humaine
**Origine** : W0+ remediation (`AUDIT_FINAL_W0PLUS_CLAUDE_2026-04-26.md` ST-D1) — différé pour livraison atomique sous gate dédié
**Auteur du brief** : Claude (orchestrateur cycle POS_V4)
**Date limite proposée** : **2026-05-15** (3 semaines)

---

## Trigger

Audit W0+ a identifié à `resources/js/components/admin/pos/PaymentComponent.vue` lignes 251-265 une mutation directe de props parent depuis un composant enfant :

```js
// Pattern actuel (extrait conceptuel — voir lignes réelles 251-265)
this.$props.props.form.total = 0;
this.$props.props.form.payment_id = null;
// etc.
```

Cette pratique :
- Viole la règle Vue.js "props down, events up" (props sont read-only côté enfant)
- Crée un couplage temporel entre composant `PaymentComponent` et le parent qui possède `form`
- Risque d'être incompatible avec l'invariant `commit_before_dispatch` si le parent dispatche un événement avant que la mutation enfant ne soit visible (race condition)
- Est en travers du chemin critique paiement : NF525 (fiscalité française), kiosk auto, POS cash, POS card, edge cases refunds

## Affected Subsystems

| Subsystem | Type d'impact |
|---|---|
| `resources/js/components/admin/pos/PaymentComponent.vue` | Refactor principal |
| `resources/js/components/admin/pos/PosComponent.vue` | Parent qui possède `form` — recevra `emit` et appliquera mutation |
| `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` | Pattern symétrique probable — vérifier symétrie POS/Kiosk |
| Backend `OrderService` / `FrontendOrderService` | **Indirectement** : si la mutation prop affecte le payload posté au backend, vérifier que le contrat API reste identique (pas de régression NF525) |
| Tests `PaymentComponent` | Doivent être étendus AVANT refactor (filet anti-régression) |

## Invariants à risque

| Invariant | Risque |
|---|---|
| `commit_before_dispatch` | **MOYEN** — la mutation prop peut intervenir hors séquence du commit DB → backend peut recevoir un total/payment_id incorrect si l'event parent part avant le commit |
| `OrderService` / `FrontendOrderService` symétrie | **À VÉRIFIER** — `KioskPaymentComponent` doit suivre le même pattern refactoré |
| `pricing_ssot` | **FAIBLE** — la mutation côté frontend ne calcule pas, elle remet à zéro. Mais audit propre obligatoire (l'opération `total = 0` ne doit pas servir d'autorité, juste reset UX). |
| Frozen zones | **À VÉRIFIER** — `PaymentComponent.vue` n'est pas listé en frozen zone à ce jour ; confirmer avant exécution. |
| NF525 (fiscalité) | **MOYEN** — toute modification du chemin paiement POS doit être auditée pour conformité (signatures, journal des évènements de paiement). |

## Decision Required

**Approuver le refactor `PaymentComponent` prop mutation → emit-based pattern**, avec :
1. Période d'instrumentation 1 semaine (logs + télémétrie sur le chemin paiement actuel pour détecter race conditions silencieuses)
2. Tests Vitest étendus AVANT refactor (couverture des 5 cas : POS cash, POS card, kiosk auto, refund partiel, refund total)
3. Refactor en cycle dédié (PRIMARY_MODEL = `codex-terminal gpt-5.5-pro` recommandé pour la complexité du chemin paiement)
4. Validation E2E Playwright critique sur les flows POS Cash / POS Card / Kiosk Payment **avant merge**
5. Audit Claude terminal post-refactor (verdict obligatoire)

## Options

### Option A — Refactor complet sous gate (recommandée)
**Action** : exécuter le refactor selon les 5 conditions ci-dessus. Cycle dédié `POS_V4_W2_PAYMENT_REFACTOR`. Ne PAS coupler avec d'autres livrables.

**Conséquence** : ~2-3 jours de cycle (instrumentation + tests + refactor + E2E + audit). Garantie d'absence de régression NF525.

### Option B — Refactor minimaliste (props → emit) sans instrumentation préalable
**Action** : refactor direct prop mutation → emit, tests existants seulement.

**Conséquence** : 4-6h de cycle. Risque de régression silencieuse non détectée si tests existants ne couvrent pas toutes les races. **Non recommandé pour chemin paiement**.

### Option C — Différer indéfiniment + ticket tech-debt formel
**Action** : créer ticket tech-debt explicite avec date limite étendue (Q3 2026), pas d'action immédiate. Documenter la dette dans le BACKLOG W0+.

**Conséquence** : la dette reste, mais le risque connu est tracé. Acceptable SI aucune régression observée en prod sur le chemin paiement actuel ces 6 derniers mois.

### Option D — Cancel — décider que le pattern actuel reste acceptable
**Action** : déclasser officiellement la finding W0+ ST-D1 ; noter que la mutation prop est tolérée pour ce composant historique.

**Conséquence** : la dette devient officielle. Aucune action future requise. **Non recommandé** — viole les principes Vue 3 et complique tout futur refactor du chemin paiement.

---

## Approval

- [ ] Approved — option selected: ___
- [ ] Cancelled

**Approved by** : ___________________ (Tech Lead)
**Co-signed by** : ___________________ (Backend owner — pour invariant `commit_before_dispatch`)
**Co-signed by** : ___________________ (QA / Compliance — pour validation NF525)
**Co-signed by** : ___________________ (UX owner — pour validation expérience flux paiement post-refactor)
**Date** : ___________

---

## Escalation Clause (post-deadline)

**Si la décision n'est pas signée au 2026-05-15** :
1. Le gate passe automatiquement en statut `OVERDUE` dans `GATE_LOG.md`
2. Notification obligatoire à PM + Tech Lead par l'auteur du brief (Claude orchestrateur)
3. Le cycle suivant `POS_V4_W2_*` est **bloqué** (le gate étant en travers du chemin paiement)
4. Une décision rapide doit être prise sous 5 jours ouvrés (option C `différer` reste valide pour libérer le blocage si besoin urgent, mais doit être tracée dans `GATE_LOG.md`)

## Owner audit symétrie KioskPaymentComponent

**Owner désigné** : ___________________ (Frontend lead — responsable de l'audit symétrie POS/Kiosk si Option A approuvée)
**Périmètre de l'audit** : `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` — vérifier si pattern prop-mutation présent, et si oui, refactor en miroir (commit atomique avec PaymentComponent POS).

---

## Resumption Protocol (post-décision)

1. Approval section ci-dessus complétée par humain
2. Décision recordée dans `docs/gates/GATE_LOG.md`
3. Si Option A approuvée : ouverture cycle `POS_V4_W2_PAYMENT_REFACTOR` (PRIMARY=`codex-terminal gpt-5.5-pro`), création `tasks/POS_V4_W2_PAYMENT_REFACTOR.md`
4. Si Option B/C/D : mise à jour `BACKLOG_POS_V4_W0PLUS_DISCOVERIES_2026-04-26.md §3` avec décision finale

---

## Annexes

- Source du finding : `reports/audit/AUDIT_FINAL_W0PLUS_CLAUDE_2026-04-26.md` §ST-D1
- Backlog : `reports/audit/BACKLOG_POS_V4_W0PLUS_DISCOVERIES_2026-04-26.md` §3
- Pattern Vue.js officiel : https://vuejs.org/guide/components/props.html#one-way-data-flow
