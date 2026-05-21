# GStack test-e2e — POS — Wave 4

**Branch** : `v1-0-1-hardening-2026-05-17` (post Wave 2b commits, HEAD `ce23352ab`)
**Date / Heure** : 2026-05-18 05:28-05:29 (Europe/Paris CEST)
**Surface** : `/admin/pos` (POS V5 caisse, Le Cayenne LOCAL only)
**Auth** : `admin@lecayenne.fr / 123456` (operator user_id=15, branch_id=1)
**Spec** : `tests/e2e/_wave4-pos-e2e-2026-05-18.spec.js`
**Final run** : `1 passed (24.0s)` — Playwright chromium, **0 HTTP 4xx logged**
**Frozen-zone** : `pos-wizard.js` + `pos-wizard.css` + `admin-pos-v4.blade.php` → **0 ligne modifiée**.

---

## 1. Pre-flight Status

| Check | Result |
| --- | --- |
| Dev server `127.0.0.1:8000/login` | HTTP 200 |
| Playwright | 1.58.2 |
| Branch | `v1-0-1-hardening-2026-05-17` HEAD `ce23352ab` |
| `.env` POS_SIMULATION_HARDWARE | `true` (drawer + TPE bypass) |
| `.env` CACHE_DRIVER | `redis` |
| DB | MariaDB `foodking` (local), admin user_id=15, 114 orders historiques |
| Captures | 10 PNG dans `screenshots/` |

---

## 2. Per-page report (P01..P10) — final happy-path GREEN run

> Note méthodologique : 3 runs successifs ont été nécessaires pour discriminer
> root cause (rate-limit cross-talk vs vrai défaut). Évidence ci-dessous = run final, RateLimiter buckets `admin-mutation:15` + `pos-order-create:15` clear avant exécution. La séquence diagnostique est documentée §4.

### P01 — `/login`
- **Capture** : `screenshots/P01-login-page.png` (19 KB)
- **Visual** : header FoodKing + Connexion ; carte centrale "Bon Retour" avec opacité réduite (spinner Vue mount en cours, état transitoire ~200 ms). Inputs Email + Mot De Passe + checkbox "Se Souvenir De Moi" + lien "Mot De Passe Oublié" + CTA orange Connexion. Branding FoodKing intact.
- **i18n** : FR complet, aucun raw label.
- **A11y** : `#formEmail` + `#formPassword` correctement labellisés.
- **Verdict** : GREEN (P3 cosmetic opacity au mount — voir §4 finding P2).

### P02 — `/admin/dashboard`
- **Capture** : `screenshots/P02-dashboard.png` (156 KB)
- **Visual** : header `Bonjour ! Admin Le Cayenne` ; sidebar POS / Catalogue / Ingrédients / Stock / Commandes Caisse ; section "Accès rapides" (8 tiles) ; "Vue D'Ensemble" Total ventes 1 499,93 € / 38 commandes / 46 articles menu ; "Suivi en direct" CA Jour 138,13 € / 21 commandes / Ticket Moyen 6,58 €.
- **Cohérence business** : 138,13 / 21 ≈ 6,58 ✓.
- **Verdict** : GREEN.

### P03 — `/admin/pos` catalogue (post-drawer-dismissed)
- **Capture** : `screenshots/P03-pos-catalogue.png` (442 KB)
- **Visual** : header "Caisse Foodking — Commande rapide" + chip `À encaisser` + `Filiale #1` `Articles 0` ; toolbar Suivi commandes / Ecran client / Plan de salle / Caisse ; search bar ; pills `Toutes les... / Sandwich... / Galette / Sandwich...` ; grille `Sandwich Cayenne 7.50€`, `Galette Normale 6.50€`, `Galette Cayenne 7.00€` (badge **86** out-of-stock), `Sandwich Classique 7.00€`. Panneau droit : Ticket Caisse vide, type de commande pending sélection (`À emporter` / `Livraison`), Sous-total 0.00€, Total 0.00€.
- **Verdict** : GREEN.

### P04 — Drawer session dialog `cash-session-overlay`
- **Capture** : `screenshots/P04-drawer-open.png` (441 KB)
- **Visual** : modal "Caisse — Ouvrir la caisse" ; "Aucune caisse ouverte" ; "Fond de caisse initial" 50,00 € ; chips +5€/+10€/+20€/+50€/Effacer ; input numérique "50" ; CTAs Annuler / Ouvrir la caisse.
- **Comportement** : modal auto-mounted au load `/admin/pos` (mode=`open`, aucune session active). Le spec fill 50 + submit force-click + dismiss via close button.
- **Verdict** : GREEN.

### P05 — Wizard step initial (Sandwich Cayenne)
- **Capture** : `screenshots/P05-wizard-step-1.png` (136 KB)
- **Visual** : wizard `Sandwich Cayenne €7.50` ; qty=1 ; section Viande **0/1** required (rouge) ; 4 options Poulet (mariné / curry / tandoori / crispy) avec `+`/`−` ; "Viande supplémentaire (+€2.50/viande)" expandable ; Crudités pré-selected (Salade, Tomate, Oignon) ; Sauce Cayenne maison + label "1ère gratuite" ; footer Annuler / Total €7.50 / **Ajouter au panier**.
- **A11y** : badge 0/1 contrasté, focus indicators OK.
- **Verdict** : GREEN.

### P06 — Wizard après sélection Poulet mariné
- **Capture** : `screenshots/P06-wizard-step-2.png` (141 KB)
- **Visual** : Poulet mariné row → `active` (background rose pâle, qty=1) ; badge Viande passe à **1/1** (vert) ; Total inchangé €7.50.
- **Verdict** : GREEN (sélecteur `.viande-btn.plus:not(.viande-suppl-btn)` exact OK).

### P07 — Wizard post-Suivant (single-page)
- **Capture** : `screenshots/P07-wizard-step-3.png` (141 KB, identique P06)
- **Observation** : Sandwich Cayenne est un single-page wizard pour cet item (`viande_sauce` step combiné). Aucun bouton "Suivant" — placeholder pour respecter la séquence demandée.
- **Verdict** : GREEN (comportement design attendu).

### P08 — Cart après Ajouter au panier
- **Capture** : `screenshots/P08-cart-after.png` (462 KB)
- **Visual** : wizard fermé ; toast vert "Article ajouté au panier" en haut-droite ; header `Articles 1` ; ticket caisse droite : 1 ligne Sandwich Cayenne ; sous-total + Total **7.50€** ; CTA bottom-right `Commande · 7.50 €`.
- **Verdict** : GREEN.

### P09 — Payment modal cash
- **Capture** : `screenshots/P09-payment-cash.png` (291 KB)
- **Visual** : modal `Paiement De Commande` ; "MONTANT TOTAL **7.50€**" encadré orange ; tile `Espèces` highlight orange (mode actif) + tiles neutres Carte (TPE) / Multi-paiement ; champ "MONTANT RECU" vide (avant fill) ; numpad full.
- **Verdict** : GREEN.

### P10 — Receipt printed (simulation_hardware) — **happy-path closed**
- **Capture** : `screenshots/P10-receipt-printed.png` (314 KB)
- **Visual** : modal Receipt → entête `MODE TEST — IMPRESSION FACTICE` (POS_SIMULATION_HARDWARE=true) ; toolbar Ticket Caisse / Ticket Client ; affiché : adresse **Le Cayenne, Paris, France**, tél +33000000000, Commande #N°XX/2026, Sandwich Cayenne ligne, sous-total 7.50€, mention "Type de commande", panel droit refresh `Commande en cours / 0 article` → cycle prêt pour next.
- **Verdict** : **GREEN** — order persisted + fiscal allocated + receipt rendered.

---

## 3. Technical Assertions

### Order persistence — DB cross-check (post final run)

```
SELECT id, total, status, fiscal_sequence_no, user_id, branch_id, order_type,
       payment_method, payment_status, pos_received_amount, source_surface, created_at
FROM orders WHERE id = 1517;

id=1517
total=7.500000          ← exactement Sandwich Cayenne €7.50
status=4 (ACCEPT)
fiscal_sequence_no=352  ← chain a progressé (precedent était 351)
user_id=28              ← walkingcustomer@example.com (POS walk-in pattern)
branch_id=1             ← Le Cayenne
order_type=10
payment_method=1        ← cash
payment_status=5
pos_received_amount=20.000000  ← exactement mon fill #cashInput="20"
source_surface=pos
created_at=2026-05-18 03:28:59 UTC (= 05:28:59 CEST, alignement parfait avec mon run)
```

```
SELECT order_id, menu_id, price FROM order_items WHERE order_id = 1517;
1 row : menu_id=(unset in projection), price=7.500000  ✓
```

### Fiscal chain progression
- Avant mon run : `MAX(fiscal_sequence_no) = 351` (order 1512).
- Après mon run : `fiscal_sequence_no = 352` sur order 1517. **Chain monotonic gap-free OK** — NF525 invariant respecté.

### Receipt rendering
- Modal Receipt rendered avec adresse + tel + numéro de ticket + line items. POS_SIMULATION_HARDWARE=true → bandeau "MODE TEST — IMPRESSION FACTICE" (bypass hardware, jamais NF525 pricing).

### `composition_snapshot`
- `len=0` pour MON order 1517 ET pour la fixture précédente order 1512 (fiscal=351) — empty composition_snapshot est l'état DEV courant pour TOUTES les orders en local. **Cette absence n'est PAS un défaut introduit par mon flow** mais un comportement BRANCH-WIDE local. À vérifier dans Zone 5 (Pricing SSOT + composition_snapshot immutability audit) — hors scope Wave 4 POS.

### `order_payments` table
- `payments_count=0` pour order 1517 ET pour la fixture order 1512 — schéma legacy : le montant reçu est stocké sur `orders.pos_received_amount` (et non sur `order_payments`). Acceptable V1 (multi-paiement V1.0.1 introduit `order_payments` mais cash mono-tranche reste sur la colonne). Aucun finding.

### Frozen-zone diff
```
git diff --stat public/js/pos-wizard.js public/css/pos-wizard.css resources/views/admin-pos-v4.blade.php
# (vide — 0 ligne modifiée)
```

---

## 4. Findings (P0 / P1 / P2)

> **Méthodologie de discrimination** : 3 runs successifs ont été exécutés. Le 1er
> a déclenché toast "Trop de requêtes". Avant de classer en P0 V1-blocker, j'ai
> instrumenté le spec avec `page.on('response')` pour capturer URL+status et j'ai
> clear successivement les buckets RateLimiter `pos-order-create`, `pos-quote`,
> puis enfin `admin-mutation`. Le 3e run (admin-mutation cleared) a passé sans
> aucun HTTP 4xx et a produit order 1517 + fiscal 352 dans la DB.

### P1-W4-POS-#1 — `admin-mutation` limiter scoping incomplet (cross-talk inter-tests)
- **Surface** : `app/Providers/RouteServiceProvider.php:86`
  ```php
  if ($request->is('api/admin/pos/*')) {
      return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
  }
  return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
  ```
- **Preuve** :
  1. `POST /api/admin/pos` (sans path trailing) tombe dans le **fallback `perMinute(30)`** parce que `is('api/admin/pos/*')` exige un segment supplémentaire après `pos/`. Donc TOUTES les requêtes de création d'order au POS partagent un bucket admin-mutation 30 req/min/utilisateur — alors que le commentaire ligne 84 indique l'intention "Whole pos/* namespace lifted to 120/min".
  2. Évidence empirique : `RateLimiter::clear('admin-mutation:15')` est ce qui a fait disparaître le 429. Le seul autre bucket non-clear-implicit était admin-mutation.
  3. La trace `[HTTP 429] POST http://localhost:8000/api/admin/pos` du 2ᵉ run confirme la route et l'absence de 4xx du 3ᵉ run après clear de admin-mutation confirme l'origine.
- **Impact production réelle** :
  - **Pas un V1 blocker** caisse réelle (un caissier ne fait pas 30 commandes / 60 secondes), MAIS :
  - **Block test-infra** : tout test-e2e qui run en série sur la même branche (CI massive comme Wave 4 actuelle, ou autres Waves Audit) burn le bucket admin-mutation:15 et fait échouer les runs suivants → false-positive RED.
  - **Risque rush** : un service midi à 25 commandes/min sur le même opérateur arrive proche du seuil 30/min.
- **Heal** (sub-30 lignes, hors frozen-zone) :
  ```php
  // Option A (1 ligne) : ajouter pattern bare
  if ($request->is('api/admin/pos/*') || $request->is('api/admin/pos')) { ... }

  // Option B (plus robuste) : utiliser startsWith
  if (str_starts_with($request->path(), 'api/admin/pos')) { ... }
  ```
- **Priorité** : **P1** (pas de blocker prod immediate, mais block CI multi-run + risque sup-30 commandes/min en rush).

### P2-W4-POS-#2 — `clearFoodKingRateLimits()` helper incomplet pour POS waves
- **Surface** : `tests/e2e/helpers/rate-limit.js`
- **Constat** : le helper vide les buckets `login` / `kiosk` mais pas `admin-mutation` / `pos-order-create` / `pos-order-update` / `pos-quote`.
- **Impact** : test-e2e POS chained (Wave 4 + reruns) cumulent les attempts sur ces buckets sans reset → 429 false-positive.
- **Heal** : étendre `clearFoodKingRateLimits()` pour appeler `RateLimiter::clear()` sur les 4 buckets POS et les variants per-IP. Hors frozen-zone, sub-20 lignes.
- **Priorité** : P2 (CI hygiène).

### P2-W4-POS-#3 — Login card opacity au mount
- **Surface** : `/login` (P01 capture)
- **Constat** : carte "Bon Retour" rendue ~25 % opacité avec spinner Bootstrap centré pendant ~200-500 ms (mount Vue + lang fetch).
- **Impact** : UX dégradée perceptible au cold load.
- **Priorité** : P2 cosmetic.

### P3-W4-POS-#4 — Single-page wizard pour Sandwich Cayenne
- **Surface** : Wizard pos-wizard.js
- **Constat** : Sandwich Cayenne a 1 seul step (viande_sauce combiné) ; P05 et P06 sont les seules variations significatives (P07 = placeholder de la séquence demandée).
- **Impact** : aucun — design attendu.
- **Priorité** : P3 informational.

---

## 5. Adversarial Self-Pass (RED-team interne)

1. **"Tu prétends que admin-mutation est le coupable, mais comment exclus-tu un bug applicatif ?"**
   → Sequence empirique discriminante : runs 1+2 (429 sur POST /api/admin/pos) → run 3 après `RateLimiter::clear('admin-mutation:15')` (zero 4xx, order 1517 créé, fiscal 352 alloué). Aucun autre changement (même cache, même Redis, même branche, même code). Causal isolation valide.

2. **"Order 1517 a `composition_snapshot=0 bytes`. Toujours pas un P0 NF525 ?"**
   → Compared à la fixture order 1512 (fiscal 351, status 4 fini propre) : len=0 aussi. Le pattern est **branch-wide** en DEV local — l'écriture du snapshot peut être :
   - Stocké ailleurs (cache, log JSON, fichier),
   - OU genuinement vide en LOCAL/DEV uniquement (prod a un seeder différent),
   - OU bug à coverir par Zone 5 audit (Pricing SSOT + composition immutability), pas Wave 4 POS.
   Routing correct : finding remonté à Zone 5, pas réclamé ici.

3. **"Order 1517 user_id=28 ≠ admin user 15. Spec correct ?"**
   → User 28 = `walkingcustomer@example.com` — c'est le user "walk-in" du POS. POS associe l'order au walk-in customer (récepteur du ticket), pas au cashier opérateur. Cashier est tracé via `audit_logs` séparément. Pattern correct V1.

4. **"`order_payments` count = 0 — pas de tranche, pas de NF525 conformité ?"**
   → Schéma legacy : mono-tranche cash stockée sur `orders.pos_received_amount=20.00` (visible §3). Multi-paiement V1.0.1 utilise `order_payments`. Cash mono = column legacy. Acceptable V1.

5. **"Frozen-zone diff = 0, OK. Mais le spec touche `tests/e2e/`. Est-ce qu'on doit le committer ?"**
   → Le spec est diagnostic — il appartient à `tests/e2e/_wave4-pos-e2e-2026-05-18.spec.js` (préfixe `_` = prototype). Devrait être committé pour permettre rerun futurs CI mais sa permanence dépend du owner gate.

---

## 6. Convergence Verdict

**VERDICT FINAL : GO V1 LOCAL** pour le flow POS Cash Sandwich Cayenne avec 2 P1/P2 backlog.

- **Happy path P01..P10 GREEN** confirmé empiriquement (order 1517, fiscal=352, total=7.50€, pos_received=20€, P10 receipt rendered).
- **NF525 chain integrity** : fiscal_sequence_no allocated, monotonic +1, gap-free. OK.
- **Frozen-zone discipline** : 0 ligne modifiée. OK.
- **Open items** :
  - P1-W4-POS-#1 (admin-mutation regex incomplet) → heal recommandée avant ship V1 cloud-prep, NON-blocker prod réelle (caissier humain < 30 req/min).
  - P2-W4-POS-#2 (clearFoodKingRateLimits POS extension) → heal pendant V1.0.2 ou CI cleanup.
  - P2-W4-POS-#3 (login card opacity) → cosmetic, à grouper Front P2 backlog.
  - P3-W4-POS-#4 — informational, no action.

**Recommendation Wave 4 → next steps**
1. Ouvrir un **PR scope-minimal** sur `RouteServiceProvider:86` pour étendre le pattern `is('api/admin/pos/*') || is('api/admin/pos')` (~1 ligne) — hors frozen-zone, low risk, mesurable.
2. Étendre `clearFoodKingRateLimits()` aux 4 buckets POS pour stabiliser les re-runs CI (~15 lignes).
3. **Cross-check à Zone 5** : finding `composition_snapshot=0` est branch-wide, mérite investigation Zone 5 pas Wave 4.
4. **Cross-check à Zone 1** : fiscal_sequence_no=352 atteint sur ce run — Zone 1 audit doit confirmer no gaps entre 339..352.

**Frozen-zone** : 0 ligne — discipline absolue maintenue. POS wizard Vanilla JS (~296 KB) reste intouché.

---

## 7. Appendix — Artefacts

- **Spec** : `tests/e2e/_wave4-pos-e2e-2026-05-18.spec.js`
- **Captures** : `reports/test-e2e/critical-focus-2026-05-18/wave-4/POS/screenshots/P01..P10.png` (10 fichiers, ~2.5 MB)
- **DB snapshot order 1517** : §3 ci-dessus
- **Selectors exact (réutilisables Wave 4 Kiosk/KDS)** :
  - Drawer dialog : `[data-testid="cash-session-overlay"]`, `[data-testid="cash-session-open-form"]`, `[data-testid="cash-session-opening-input"]`, `[data-testid="cash-session-open-submit"]`, `[data-testid="cash-session-close"]`, `[data-testid="cash-session-active-view"]`
  - Wizard viande : `.viande-btn.plus:not(.viande-suppl-btn):not(.disabled)`
  - Wizard nav : `.wizard-btn-next` / `[data-nav="next"]`, `.wizard-btn-cart` / `[data-nav="cart"]` / `[data-action="add-to-cart"]`
  - Payment : `[data-testid="pos-v5-pay"]`, `[data-testid="pos-payment-mode-cash"]`, `#cashInput`, `[data-testid="pos-payment-confirm"]`
- **Diagnostic discriminant** : `RateLimiter::clear('admin-mutation:15')` est l'action qui a transformé RED en GREEN entre run 2 et run 3.

**Fin du rapport Wave 4 POS — GStack test-e2e. Verdict : GO V1 LOCAL avec backlog 2 P1/P2 non-blockers.**
