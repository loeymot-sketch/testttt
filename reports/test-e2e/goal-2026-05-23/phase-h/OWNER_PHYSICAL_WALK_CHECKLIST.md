# 👤 OWNER PHYSICAL WALK CHECKLIST — Before going live

**Date** : 2026-05-24
**Branch** : `heal/cms-pr1-quickwins-2026-05-18` HEAD `eb03c96c2`
**Mandate** : « le owner doit physiquement marcher dans tous les écrans avant le go-live »

**Time estimate** : 60-90 min walkthrough total

---

## Why this matters

Aucun test automatisé (118 sentinels + 18 findings JSONs Phase F+G) ne peut remplacer cette session.

Tu vas découvrir :
- des friction UX que les agents ne ressentent pas
- des labels qui semblent OK code-statiquement mais étranges visuellement
- des transitions qui « ne sentent pas bon »
- des points où le système ne dit pas assez (silent fail) ou dit trop (toast spam)

Marche par persona, prends des notes, et reporte-moi.

---

## Pré-walk setup (5 min)

```bash
# 1. Pull latest
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
git pull origin heal/cms-pr1-quickwins-2026-05-18

# 2. Verify NF525 chain
php artisan fiscal:verify-chain --all
# Expected: CHAIN OK on every active branch

# 3. Rebuild bundles (Q12 freshness)
npx mix

# 4. Start dev server
php artisan serve --host=127.0.0.1 --port=8000 &
# Open browser
```

---

## Walk 1 — CLIENT IMPATIENT (10 min)

**Persona** : 50 ans, claustrophobe, mal aux pieds, faim, peu technique. Frustré si 8s sans feedback.

Open `http://127.0.0.1:8000/kiosk/idle`

| # | Action | Cherche |
|---|--------|---------|
| 1 | Tape l'écran pour démarrer | Est-ce **évident** qu'il faut taper ? Le subtitle est-il visible ? |
| 2 | Catalog → Tacos | Tu trouves Tacos en < 5s ? Les images sont chargées ? |
| 3 | Compose Tacos (Pain → Viande → Sauces → Garnitures) | Chaque étape s'explique elle-même ? Tu n'as pas besoin d'aide ? |
| 4 | Cart → vérifie total | Le total est-il **gros et clair** ? Apostrophes (`L'Algérienne`) bien rendues ? |
| 5 | Modifie quantité (+/-) | Intuitif ? Pas de bug ? |
| 6 | Valider | Tu vois clairement les modes de paiement ? |
| 7 | Choisis CARD (ou CASH-instruction) | L'icône + label sont clairs ? |
| 8 | Confirme | Le queue number est **bien visible** (gros) ? |
| 9 | Wait 30s sur l'écran de confirmation | Pas de toast "Trop de requêtes" ? (Owner pain F.1 healed) |
| 10 | Abandonne mid-flow (clique "Recommencer") | La confirmation est demandée ou direct ? |

**Notes possibles** :
- Wizard step Suivant disabled un peu trop strict ?
- Tooltip manquant sur un élément ?
- Icône pas évidente ?
- Couleur pas assez contrastée ?

---

## Walk 2 — CHEF-EN-RUSH (15 min) ⚠️ CRITIQUE

**Persona** : 19h-21h. 8 commandes simultanées. Glance 5-10s par order.

⚠️ **Spécifique au mandat verbatim** : « chef devrait scroller parfois, il ferait pas attention il va sortir la commande pas complète »

Open `http://127.0.0.1:8000/kds` (login admin@lecayenne.fr / 123456)

Seed 8 orders via tinker :
```bash
php artisan tinker
>>> \App\Models\Order::factory()->count(8)->create(['status' => 7, 'branch_id' => 1]);
```

| # | Vérifie | Mandat owner |
|---|---------|--------------|
| 1 | Avec 5 orders sur l'écran | TOUS visibles SANS scroll ? |
| 2 | Avec 6 orders | Layout 2-line ? OU scroll requis ? (PROPOSAL S3-CHEF-001 owner-gate) |
| 3 | Avec 8 orders | Layout 4×2 ? Bottom-row bumpable ? |
| 4 | Order avec 15 items (long order) | Visible SANS scroll dans la card ? |
| 5 | Order avec allergène | Le badge ⚠️ ALLERGIE est-il **visible en 1 seconde glance** ? |
| 6 | Bump 3 orders rapidement | Pas de UI freeze ? Tous transitionnent ? |
| 7 | Click "Historique du jour" | Drawer s'ouvre. Press Escape → ferme ? |
| 8 | Order avec custom note | Note visible, pas enterrée ? |

**Si BLOCKER-IF-RUSH trouvé** → on a déjà 3 PROPOSAL pour KDS layout (Option A/B/C). Tu choisis ?

---

## Walk 3 — CAISSIER MULTITASK (15 min)

**Persona** : Karim/Sarah, 30 ans, 8h shift, café n°3, customer impatient.

Open `http://127.0.0.1:8000/admin/pos`

| # | Action | Vérifie |
|---|--------|---------|
| 1 | POS empty page | Q10 Prêt-à-livrer panel visible avec empty state italic ? Ticker "Mis à jour il y a Xs" se met à jour ? |
| 2 | Place 10 orders successives | **AUCUN toast "Trop de requêtes 30s/60s"** ? (Owner pain — healed F.1) |
| 3 | Seed un kiosk-cash order (via tinker) → encaisser depuis Prêt panel | Modal s'ouvre vite. MONTANT REÇU pre-fill = **`8,50`** (virgule FR, pas point) ? Hero affiche **`8,50 €`** ? |
| 4 | Tape `10,00` dans MONTANT REÇU | cashChange affiche `1,50 €` ? (FR comma decimal) |
| 5 | Tape `10.00` (point — backward compat) | Pareil, cashChange = `1,50 €` ? |
| 6 | Confirme | Receipt s'imprime ? Bouton se désactive pendant submitting ? |
| 7 | V5 Split-tranche (si owner support) | 4 modes mix CASH+CARD+MOBILE+TICKET fonctionne ? |
| 8 | Refund counter-entry (si UI exists) | **REMBOURSEMENT marker visible sur receipt** ? (G2-HEAL-01+03 livrés) |
| 9 | Receipt avec menu_formule (Coca bundled Big Burger) | Coca **visible sur le ticket** ? (G2-HEAL-03 addons) |
| 10 | Drawer reconciliation fin de service | UI existe ? Ou manual workaround toujours ? (V1.0.2 backlog) |

---

## Walk 4 — OWNER-NIGHT (10 min)

**Persona** : Toi, 23h, café froid, fatigué 12h jour.

Open `http://127.0.0.1:8000/admin`

| # | Vérifie | Mandat |
|---|---------|--------|
| 1 | CA jour visible en haut | ≤ 3 secondes pour lire ? |
| 2 | Drill into recent orders | Cliquable, rapide ? |
| 3 | `/admin/cash-overview` | 3-cell reconciliation honnête (drop diff cell Wave Polish C-013) ? |
| 4 | Filter Source = Borne | URL contient `?source=borne` ? F5 reload → filtre persistant ? (Q8) |
| 5 | Reset filters | URL nettoyée ? |
| 6 | Filter date no-data | Empty-state avec SVG + copy + bouton "Réinitialiser" ? (Q6) |
| 7 | Mode dropdown | Pas d'option "Autre" ? (Q7) |
| 8 | `php artisan fiscal:verify-chain` | CHAIN OK ? |
| 9 | Backup last successful | `tail storage/logs/backup.log` montre OK récent ? |
| 10 | Z-close trigger | **Tu sais comment faire un Z-close** ? Bouton ? Cron ? (G.6 → G2-HEAL-06 safety-net 23:55 daily Paris) |

---

## Walk 5 — TZ + dates (5 min)

| # | Vérifie | Pourquoi |
|---|---------|----------|
| 1 | À 23:00 Paris ce soir, ouvre Dashboard | CA jour reflète bien TOUTES les transactions du jour (pas tronqué à 21:00 UTC = 23:00 Paris) ? G2-HEAL-04 fix verifié |
| 2 | Sales Report sur range hier-aujourd'hui | Inclut dernière heure correctement ? |
| 3 | Z-report close à 23:55 cron | Le 24/05 matin, vérifie qu'un Z a été créé pour le 23/05 (safety-net cron G2-HEAL-06) |

---

## Walk 6 — Owner-gates pending decisions (10 min)

Tu dois prendre des décisions pour fermer ces 5 owner-gates :

| # | LOCK / Proposal | Décision attendue |
|---|-----------------|-------------------|
| 1 | `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` + addendum | **Countersign pour appliquer le XSS heal** ? (9+ jours holding, P0 security) |
| 2 | `plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md` | Countersign pour `4.90€` → `4,90 €` ? (P3 polish) |
| 3 | `proposals/PROPOSAL_KDS_LAYOUT_5plus_orders_S3-CHEF-001.md` | Option A (5×1≤5 puis 5×2 ≥6) / B (compaction) / C (strict 5-col) ? |
| 4 | `proposals/PROPOSAL_PricingService_003_*.md` | Owner clarification : V1 cart single-rate-only OR multi-rate possible ? Détermine fix tier |
| 5 | `proposals/PROPOSAL_ZCLOSE_VUE_UI_BUTTON.md` | Vue button V1 (8h dev) OR V1.0.X ? Safety-net cron déjà actif `c98e94459` |

---

## Walk 7 — Hardware integration smoke (skipped tant que TPE pas branché)

Quand tu auras Senangpay TPE branché (D8) :
- Test 1 vrai charge CARD (10€ test) → DUPLICATA print + audit_logs
- Test 1 vrai refund (5€) → REMBOURSEMENT marker + counter-entry
- Test drawer kick après cash payment
- Test printer paper out → graceful error

---

## Compte-rendu attendu

Après ta walk, envoie-moi :
1. **Score per persona** : Client / Chef / Cashier / Owner (note /10 + 1 friction par persona)
2. **5 owner-gate decisions** (Walk 6)
3. **TZ Walk 5 vérifié** ? (CA jour @ 23h reflects toute la journée Paris)
4. **Surprises** : tout ce qui « ne sent pas bon » que les sentinels ne capturent pas
5. **GO/NO-GO V1 LOCAL** : production-ready selon ton ressenti physique ?

---

## Ce que la walk va valider (cumulé avec automated tests)

- ✅ 118 sentinels GREEN (auto)
- ✅ NF525 chain bit-identical 67/67 forensic walk
- ✅ Soak 200 orders 0×429 (G.1)
- ✅ Multi-surface stress 24 simultaneous (F.5)
- ✅ Backup restore drill bit-identical (G.12)
- 👤 + **TON RESSENTI PHYSIQUE** (cette walk)

**Ensemble = V1 LOCAL PRODUCTION-READY.**

---

*Phase H.8 — Owner physical walk checklist. Aucun agent ne peut faire ça à ta place. 60-90 min pour valider le « feel » du système. Puis tu décides : GO ou loop V1.0.1 d'abord ?*
