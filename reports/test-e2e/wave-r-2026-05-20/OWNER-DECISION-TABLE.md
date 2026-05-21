# 📋 Wave R — Owner Decision Table — POS / Suivi / KDS / OSS / Sync

**Date**: 2026-05-20 · **Branch**: `heal/cms-pr1-quickwins-2026-05-18`
**Mode**: AUDIT + REASONING. Heal autonome UNIQUEMENT sur throttle bug (clair). Tout le reste attend ton approval.

## 🚨 R-1 — KDS THROTTLE 429 (HEALED auto — bug clair, no scope question)

| Item | Status |
|---|---|
| **Bug** | KDS bump `/api/admin/kds-order/change-status/*` n'avait pas de throttle dédié → tombait sous `admin-mutation` default **30/min** CRUD. Chef chainant 3-4 commandes rapide → 429 persistant après ~4 clicks |
| **Root cause** | `routes/api.php:1077` route avait uniquement `middleware('idempotency')`, pas de throttle bucket spécifique |
| **Heal** | Nouveau `kds-bump` RateLimiter avec env knob `KDS_RATE_LIMIT_BUMP` (default 120/min prod, 1000/min en dev local). Pattern `admin-mutation` lift aussi `api/admin/kds-order/change-status/*` à 120/min (safety net) |
| **Files** | `app/Providers/RouteServiceProvider.php` + `routes/api.php` + `config/kds.php` + `.env` + `.env.example` |
| **Test owner** | `php artisan config:clear` puis tester /kds + clic Prêt rapide x10 → no 429 |
| **Frozen-zone** | 0 touch |

---

## 📊 R-2 — Suivi commandes (POS-side) — Findings + Decisions

| # | Page | Catégorie | Issue | Comportement actuel | Recommandation | Risque | ☐ Owner Decision |
|---|---|---|---|---|---|---|---|
| **F1** | Suivi commandes | Workflow ⭐ | **3 colonnes vs 2 colonnes** | CONFIRMÉES + EN PRÉPARATION + PRÊTS À SERVIR + LIVRÉS | **Hybride** : backend NF525 conserve 3 états · frontend UI = 2 colonnes (`EN COURS` fusionnant CONFIRMÉE+EN PRÉPARATION + `PRÊT`) + petite colonne historique LIVRÉS | Med | ☐ Approve hybride / ☐ Garde 3 / ☐ Full 2-state backend (frozen!) |
| **F2** | Suivi commandes | Logic | Auto-transition CONFIRMÉE→PRÉPARATION désactivée par Wave Q-2 | Toutes commandes restent CONFIRMÉE jusqu'au bump chef | Owner mandate fast-food : commande payée = en cours immédiatement. **Re-activer auto-transition `paid && status=ACCEPT → PREPARING`** côté POS confirm | Med | ☐ Approve auto-trans / ☐ Garde manuel / ☐ Demande tooltip explicatif |
| F3 | Suivi commandes | Visual | Items summary maintenant visible (Wave Q-1 `3544c62f9`) | Cards montrent "Sandwich Cayenne · Tacos" | RAS, livré ✅ | — | RAS |
| F4 | Suivi commandes | UX | Eye icon ouvre modal détail | Cliquable, affiche items + total | RAS | — | RAS |
| F5 | Suivi commandes | UX | Pas de filtre par source bien visible | Filtres "Toutes / Caisse / Borne / En ligne" en row top | Couleurs/icônes plus distinctives pour visualiser source d'un coup d'œil | Low | ☐ Approve cosmetic / ☐ Skip |

---

## 🍳 R-3 — KDS (Kitchen Display) — Findings + Decisions

| # | Page | Catégorie | Issue | Comportement actuel | Recommandation | Risque | ☐ Owner Decision |
|---|---|---|---|---|---|---|---|
| **K1** | KDS | Workflow ⭐⭐ | **Multi-chef parallel chaining** (owner pain) | KDS V2 = kanban global file unique. Chef voit toutes commandes mais cliquer 1× → PREPARING, cliquer 2× → READY. Doit faire 2 clicks par commande × 5 commandes en parallèle = 10 clicks. | **Option A** : KDS bump = 1 seul clic (CONFIRMED→READY direct, skip PREPARING en UX) ; backend traverse les 2 transitions silencieusement. **Option B** : Auto-PREPARING sur toutes commandes confirmées (cf F2 ci-dessus) → KDS commence en PREPARING, 1 clic = READY. **Option C** : 2 boutons distincts par card ("En préparation" + "Prêt") pour contrôle granulaire. | Med | ☐ A (1-clic UX) / ☐ B (auto-PREPARING) / ☐ C (2 boutons) / ☐ Status quo |
| K2 | KDS | Allergens | Fake allergens cleared post Wave Q-4 | Badges hidden when empty ✅ | RAS jusqu'à ce qu'owner ajoute vrais allergènes via admin UI | — | RAS |
| K3 | KDS | Layout | 5+ orders simultanés — overflow ? | V2 utilise scroll horizontal ou wrap selon viewport | À vérifier sur écran TV 4K kitchen | Low | ☐ Approve TV mode / ☐ Skip |
| K4 | KDS | i18n | "Prêt" affiché correctement post Wave R2 (`39f2e695e`) | Locale boot fix landed | RAS | — | RAS |
| **K5** | KDS | Sync | Bump latency observée (Wave P R3) | POS pay→KDS visible = **5.7s** · KDS bump→OSS = **<500ms** · pickup→removal = **6.1s** | Acceptable fast-food (budget <8s). Pour optimiser : Pusher full-duplex bidirectional OR reduce polling fallback ceiling 60s→15s | Low | ☐ Approve optim / ☐ Skip V1 |

---

## 📺 R-4 — OSS (Écran Client) — Findings + Decisions

| # | Page | Catégorie | Issue | Comportement actuel | Recommandation | Risque | ☐ Owner Decision |
|---|---|---|---|---|---|---|---|
| O1 | OSS | Layout | "Articles à préparer" sidebar removed (Wave Q-3 `67c26d71d`) | 2 colonnes full-width PRÉPARATION + PRÊT | RAS ✅ | — | RAS |
| O2 | OSS | Visual | Font-size order numbers | Owner says "BIG and readable from distance" — current value ? | Vérifier sur écran TV — recommandation ≥40px pour lecture à 3m | Low | ☐ Approve sizing / ☐ Skip |
| O3 | OSS | Workflow | Auto-refresh interval | Pusher broadcast + polling fallback 30s | Acceptable | — | RAS |
| O4 | OSS | Allowlist | KIOSK + TAKEAWAY only (Wave O R-3) | DELIVERY orders correctement filtrés | RAS ✅ | — | RAS |

---

## 🔄 R-5 — Synchronization Flow — Reasoning

### Flux POS → KDS → OSS détaillé (état actuel post-Wave-Q)

```
1. Cashier confirme paid order POS
   ↓ POST /api/admin/pos
2. PosController::store crée Order(status=ACCEPT)
   ↓ DB::transaction commit
3. OrderCreated::dispatch fires (after-commit per Wave M)
   ↓ Event dispatcher
4. PersistOrderCreatedToOutbox listener (FIRST per EventServiceProvider:148)
   ↓ Insert DomainEvent row idempotency-keyed
5. DispatchDomainEventsJob picks up row (Phase 1 atomic claim + lockForUpdate)
   ↓ Pusher REST API call
6. Soketi WebSocket broadcasts on private-branch.{id} channel
   ↓ Latency ~200ms
7. KDS frontend (admin-kds.js Echo subscriber) receives event
   ↓ KdsSyncService merges
8. KDS card renders → chef voit la commande (cumul ~5.7s incl. transaction lock + network)

9. Chef clic "Prêt" sur KDS
   ↓ POST /api/admin/kds-order/change-status/{id}  [throttle:kds-bump Wave R-1]
10. KitchenDisplaySystemController::changeStatus calls OrderStateMachine (FROZEN §7)
    ↓ ACCEPT→PREPARING (or PREPARING→PREPARED transition)
11. Order saved + OrderPaymentStatusChanged dispatched
    ↓ Pusher broadcast OrderStatusChangedBroadcast
12. OSS frontend receives → moves card from PRÉPARATION column to PRÊT column
    ↓ Latency <500ms
```

### Points sync à valider via owner test
- Latence POS→KDS dans le budget 8s ?
- Pas de double-render (Pusher + polling concurrent) ?
- Pas de perte si Pusher down (polling 60s prend le relais) ?
- Pas de leak cross-branch (BranchScope sur 21 models) ?

---

## 🤔 R-6 — Mon raisonnement fort sur 3-state vs 2-state

### Contexte fast-food Le Cayenne

**Workflow réel cuisine** (per owner) :
- Plusieurs cuisiniers en parallèle
- Un pour pains, un pour viandes, un pour sauces, etc.
- 3-4 commandes enchaînées simultanément
- Pas de séquentiel "1 commande à la fois"

**Workflow réel client** (POV) :
- Client paie → veut voir "ma commande est en cours" IMMÉDIATEMENT
- Voir "CONFIRMÉE mais pas EN PRÉPARATION" = inquiétude (pourquoi pas encore en cuisine ?)
- Veut juste : "EN COURS" puis "PRÊT"

### NF525 contrainte
- Audit chain doit tracer toutes transitions (CONFIRMED, PREPARING, READY, DELIVERED)
- HMAC SHA-256 chain sur audit_logs
- État dur du backend ne peut pas être réduit à 2 sans rewrite NF525 §8 (FROZEN)

### Ma recommandation hybride forte

**Backend** : 3-state inchangé (NF525 préservé). State machine ACCEPT→PREPARING→PREPARED conservée. Audit chain identique.

**Frontend UX Suivi commandes** (cashier-internal) :
- Garde les 4 colonnes actuelles pour cashier (info NF525-traçable visible)
- Ajoute un "vue simplifiée" toggle si owner veut UX cuisine-friendly

**Frontend UX KDS V2** (chef-facing) :
- Auto-transition CONFIRMED→PREPARING au moment du paiement POS (cf F2)
- KDS ne voit JAMAIS les commandes en CONFIRMED — elles arrivent directement EN PRÉPARATION
- 1 clic chef "Prêt" = transition unique PREPARING→PREPARED
- **Résultat** : owner expérience = 2 états visibles (EN PRÉPARATION + PRÊT)

**Frontend UX OSS** (customer-facing) :
- Déjà 2 colonnes post Wave Q-3 (PRÉPARATION + PRÊT)
- Client ne voit jamais "CONFIRMÉE" → expérience instantanée
- **Résultat** : owner UX intent satisfaite

### Avantages hybride
- ✅ NF525 conformité préservée (3 états backend audit)
- ✅ Owner UX fast-food (2 états visibles client + chef)
- ✅ Cashier garde traçabilité complète si besoin
- ✅ Pas de rewrite §7 frozen zones
- ✅ Migration douce, réversible via env flag

### Implementation V1 (si owner approve)
- 1 line change in `PosController::store` (or similar) : auto-transition ACCEPT→PREPARING when `payment_status=paid && order_type in [KIOSK, TAKEAWAY]`
- 1 UI change KDS : hide CONFIRMED column (or skip rendering of CONFIRMED orders since they auto-go to PREPARING)
- 1 UI change Suivi commandes : optional 2-col view toggle

---

## 🎯 Décisions owner attendues

**Critique (V1 ship-affecting)** :
- [ ] F1 — 3 colonnes vs hybride 2-col UX ?
- [ ] F2 — Auto-PREPARING activé sur payment confirmed ?
- [ ] K1 — KDS bump 1-clic OU 2 boutons distincts ?

**Confort (V1.x optionnel)** :
- [ ] F5 — Filtres source plus distinctifs ?
- [ ] K3 — TV mode optimization ?
- [ ] K5 — Sync latency optim ?
- [ ] O2 — Font sizing OSS ?

**Déjà livré (info)** :
- ✅ F3 items summary
- ✅ K2 allergens cleared
- ✅ K4 "Prêt" i18n
- ✅ O1 OSS sidebar removed
- ✅ R-1 KDS throttle (Wave R-1 this commit pending)

---

## 📸 Screenshots disponibles

Captures Wave P R3 cross-system flow (2026-05-20) :
- `reports/test-e2e/wave-p-2026-05-20/cross-system/screenshots/` (13 PNGs)
- POS → KDS → OSS journey
- Multi-chef scenario manqué (V1 single-chef envisagé) — owner clarifie

Audit-réel screenshots Wave R-2/R-3 : **À DISPATCHER après recovery API/classifier** (Anthropic 529 overload pendant écriture de ce tableau).

---

## 🛑 Garde-fous

- **Frozen zones §7** : 0 touch sans LOCK plan owner-countersigned
- **NF525** : pas de touch fiscal services / chain
- **WIP files preserved** : pos-app.js, pos-shell.js, admin-kds.js, etc.
- **Aucune correction sans owner approval** (sauf R-1 throttle bug clair)

---

**Owner : coche tes décisions ci-dessus, je dispatche les heals correspondants en parallèle.**
