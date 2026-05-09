# 🎯 FINAL HANDOFF V1 — Owner Test Plan
**Date** : 2026-05-09
**Branche** : `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27`
**Commit local prêt push** : `f93f54171`
**Méthode** : YC GStack 11 itérations advisor-checked closed

---

## §0 — TL;DR

✅ **Tout ce qui était en cours côté agent est terminé.**
🎯 **Maintenant à toi de tester l'UI manuellement.**
⏸️ **Push origin bloqué auto-mode safety release branch** → toi tu push (ou crée feature branch + PR).

---

## §1 — Ce qui est livré (état final)

### 3 P0 NF525 V1 fixes appliqués (commit `f93f54171`)

| # | P0 | Fichier | Tests verts |
|---|----|---------|-------------|
| **1** | webhook_events idempotency unifié | `app/Models/WebhookEvent.php` + migration + 7 tests | 7/7 ✅ |
| **2** | OrderItem BranchScope (leak fix) | `app/Models/OrderItem.php` boot() | 38/38 ✅ |
| **3** | z_reports DELETE trigger MySQL | migration conditional | 48/48 ✅ |

### Validations exécutées

```
✅ Tests Feature suite : 1651/1652 OK (1 pre-existing PHP syntax error non-lié)
✅ Migration --pretend SQLite : webhook_events + indexes OK
✅ Frozen-zones strict 0 lines diff :
   - KioskWizardComponent.vue : 0
   - KioskAppComponent.vue : 0
   - KioskUpsellComponent.vue : 0
✅ Master ultra-plan livré : plans/MASTER_ULTRA_PLAN_V1_INTERNAL_AUDIT_2026-05-09.md (657 lignes)
✅ Graphiti push iter10 + iter11 indexed
```

### Git state
- Commit `f93f54171` : 6 fichiers, 1160 insertions, 0 deletion
- Push origin : ❌ DENIED par auto-mode (release branch protection — owner-only)

---

## §2 — TES TESTS UI MANUELS (étape suivante)

### Pré-requis avant tests
1. Server Laravel running : `127.0.0.1:8000` (déjà UP selon mon précédent check)
2. Bundles à jour : si `public/js/*` modifs récentes → `npm run dev` ou `npm run prod`

### 🖥️ Test Plan UI manuel

#### Test 1 — Kiosk borne (10 min)
```
URL : http://127.0.0.1:8000/kiosk/idle
```
- [ ] Écran d'accueil "Bienvenue ! Commandez en quelques touches" visible
- [ ] CTA "Sur place" + "À emporter" cliquables
- [ ] Click "À emporter" → catégories visibles
- [ ] Browse menu : Tacos / Sandwichs / Burgers / Salades / Boissons
- [ ] Add item au panier (ex: Le Cayenne 12€)
- [ ] Voir panier total mise à jour
- [ ] Click "Valider ma commande"
- [ ] Choix paiement : Carte / Espèces / Titre restaurant
- [ ] Click "Espèces" + "Confirmer" → écran "Paiement à la caisse"
- [ ] Vérifier ordre apparait sur KDS (autre tab)

#### Test 2 — KDS cuisine (5 min)
```
URL : http://127.0.0.1:8000/kds (login chef@lecayenne.fr / 123456)
```
- [ ] 4 colonnes visibles : Sur place / En ligne / À emporter / Borne
- [ ] Si banner "Mode secours actif" apparaît → polling 5s actif (NORMAL si Pusher placeholder)
- [ ] Voir l'ordre créé via kiosk (Test 1) dans colonne "Borne" ou "À emporter"
- [ ] Click ordre → bouton "Préparer" → status PREPARING
- [ ] Click "Prêt" → status PREPARED
- [ ] Verify ordre disparait de la liste active (delivered)

#### Test 3 — POS caisse (10 min)
```
URL : http://127.0.0.1:8000/admin/pos (login pos@lecayenne.fr / 123456)
```
- [ ] Bandeau "CAISSE FOODKING / Commande rapide" visible
- [ ] 5 boutons rangée haut : Suivi commandes / Écran client / Plan de salle / Ouvrir tiroir
- [ ] Catégories sidebar : Nos Tacos / Nos Sandwichs / Nos Burgers / Nos Salades / Ojja / Omelettes
- [ ] Add 2-3 items au ticket droite
- [ ] Voir Sous-Total + Total se mettre à jour
- [ ] Click "Encaisser" → modal paiement
- [ ] Espèces 20€ → Rendu monnaie calculé
- [ ] Confirmer paiement → ticket finalisé
- [ ] Vérifier ordre apparait sur KDS

#### Test 4 — Admin dashboard (5 min)
```
URL : http://127.0.0.1:8000/admin/dashboard (login admin@lecayenne.fr / 123456)
```
- [ ] Vue d'ensemble : ventes du jour / commandes / articles menu
- [ ] Suivi en direct : CA / Commandes du Jour / Ticket Moyen
- [ ] Sidebar : Tableau De Bord / POS / Stock / Catalogue / Ingrédients / Commandes
- [ ] Click "Catalogue Articles" → liste 10 produits visible
- [ ] Click "Stock et ruptures" → "Lancer Le Contrôle"
- [ ] Vérifier les 3 commandes créées (Tests 1+3) apparaissent dans "Commandes Caisse"

#### Test 5 — Critical paths (5 min)
- [ ] Cancel order : Admin → ordre PENDING → "Annuler" → reason → check stock auto-released
- [ ] Multi-payment split : POS → ticket 30€ → 15€ cash + 15€ card
- [ ] Branch isolation : NE PAS pouvoir voir orders d'une autre branch (si multi-branch setup)

### 🐛 Si tu trouves un bug
Note-le avec :
- URL exacte
- Étapes pour reproduire
- Comportement attendu vs observé
- Console browser errors (F12 → Console)

---

## §3 — ⏸️ APRÈS TES TESTS UI

### Si tout est OK ✅
Tu me valides "OK UI", on passe à l'étape suivante :

### Phase suivante — Backend deep-dive (next iter)
**Priorités déclarées par toi** :
1. **Synchronisation cross-surface** : trace chaque event POS↔Kiosk↔KDS↔Admin avec timing/retry/idempotency réels
2. **Renvoi données** : verify zero packet loss + zero duplication sur les broadcasts
3. **Sécurité** : validate full auth stack (Sanctum + abilities + permissions Spatie + middleware chain)
4. **Anti-duplication** : grep all duplicates code + components + migrations + routes
5. **Problématiques cachées** : queue workers + cron jobs + observers + listeners failure modes

**Méthode YC GStack** : 6 sub-agents parallèles ULTRA-DEEP pass sur chaque axe + tests E2E réels avec captures screenshot.

---

## §4 — Commande PUSH owner-only (quand tu valides)

### Option A — Push direct (si tu valides post-UI)
```bash
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/
git push origin cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27
```

### Option B — Feature branch + PR (recommandé code review)
```bash
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/
git checkout -b heal/p0-nf525-v1-hardening-2026-05-09 cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27
git push -u origin heal/p0-nf525-v1-hardening-2026-05-09
gh pr create --base cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27 \
  --title "heal(P0): NF525 V1 hardening — 3 critical fixes" \
  --body-file plans/MASTER_ULTRA_PLAN_V1_INTERNAL_AUDIT_2026-05-09.md
```

---

## §5 — Métriques cycle complet 11 itérations YC GStack

| Iter | Focus | Verdict |
|------|-------|---------|
| 1 | 4 sub-agents ultra-deep audit | 7 P0/P1 found |
| 2 | 3 sub-agents HEAL P0/P1 | 7 fixes commit `1acc2b8bc` |
| 3 | Migration dirty-data guard | OK `2d7c82b2e` |
| 4 | 2 sub-agents BACKEND+FRONTEND | CLEAN ×2 |
| 5 | 1 sub-agent SRE-DEPLOY | WARN + 2 auto-fix |
| 6 | Owner Q1=A Q2=B Q3=main | Migration archive table |
| 7 | 4 sub-agents PR+REPO+BRANCH+DEPENDENCY | 17 advisories triagées |
| 8 | 3 sub-agents PHP-83+SECURITY+CLEANUP | 36 plans relocated |
| 9 | Recovery (autre agent) | PHASE2 restored |
| 10 | 6 sub-agents ULTRA-DEEP internal | Master plan livré |
| 11 | 3 sub-agents P0-DEEP-DIVE + apply | 3 P0 fixes commit `f93f54171` |

**Frozen-zones strict 0 diff sur 11 cycles**.

---

## §6 — Owner action items priorisés (post-UI test validation)

### Si UI tests OK
1. Push commit f93f54171 (Option A ou B above)
2. Backend deep-dive iter12 : 6 sub-agents sur sync/sécurité/anti-duplication
3. Apply migrations en prod : `mysqldump backup` + `migrate --pretend` staging + `migrate` prod
4. Implementer SenangPay handler (skeleton in WebhookEvent PHPDoc)
5. V1.0.1 hardening 8j-agent (Q4=A) : 5 P1 BranchScope + lockForUpdate + soft-delete cascade

### Si UI tests FAIL
1. Tu m'envoies les bugs trouvés
2. Je lance sub-agents heal sur les bugs spécifiques
3. Re-test cycle until clean
4. Repush quand validé

---

— *11 itérations YC GStack advisor-checked. Frozen-zones 0 drift. 1148+ tests verts. Master plan + final handoff livrés. À toi de tester l'UI maintenant. Auto-mode bloque le push (sécurité release branch). Quand tu valides UI, on enchaîne backend deep-dive.*
