# Wave 2 — Repro caisse (agent REPRODUCTION, aucune correction)

- **SHA** : `96e0b35dcc01b17dff987fece730a7da091929f6`
- **Date** : 2026-08-05 (~02:40)
- **Serveur** : Playwright baseURL `http://127.0.0.1:8766` (défaut config `PLAYWRIGHT_BASE_URL` de la session ; 8000 et 8766 répondent 200, même app). Login `pos@lecayenne.fr` via helper `tests/e2e/helpers/login.js` (`loginAsPosOperator`).
- **Specs** : `tests/Playwright/wave2-repro-phone-name.spec.js`, `tests/Playwright/wave2-repro-cb.spec.js` (jetables, code applicatif intouché).
- **JSON bruts** : `repro1-report.json`, `repro2-report.json` (même dossier).
- Effet de bord : 3 commandes de test créées (taguées `ZZ-WAVE2-CB` / `ZZ-WAVE2-CB-MULTI`, dont #0508266059 ticket NF525 2703) — à sweeper via `iter15:cleanup-test-orders` si besoin.

---

## Repro 1 — « Je ne trouve pas où noter le nom du client (commande téléphone) »

### Verdict : hypothèses (b) absent du DOM, (c) masqué par condition, (d) bundle périmé = **ÉLIMINÉES avec preuve**. Cause = **découvrabilité UX**, pas absence : le champ est présent et DANS le viewport, mais discret/tronqué, et le CTA « Commande téléphone » est **clippé sous la ligne de flottaison à 1366×768**.

Mesures (état FROID, tous scrollers remis à 0, panier 1 article, order_type=10 à emporter) — `repro1-report.json` :

| Élément | 1366×768 | 1920×1080 |
|---|---|---|
| `pos-customer-name` | inDom ✔ visible ✔ **inViewport ✔** rect y=360, 178×36 | inViewport ✔ y=360 |
| `pos-customer-phone` | inViewport ✔ (placeholder **tronqué** « Téléphone (optionne… ») | inViewport ✔ |
| CTA `pos-phone-order` | **partiallyBelow ✔** — rect y=746→818, viewport h=768 → **50 px du bas coupés**, le hint « encaissée à l'arrivée du client » invisible | inViewport ✔ y=848 |
| Bouton payer `pos-v5-pay` | inViewport ✔ y=688 | inViewport ✔ |

- **(c) condition** : `order_type` observé = `10` (à emporter) ≠ delivery → la condition `v-if` de `PosComponent.vue:924` est SATISFAITE ; champ rendu (`display:block`, `visibility:visible`).
- **(d) bundle** : chunk réellement chargé par le navigateur = `js/pos-shell.c51e6763.js` (capture réseau, `repro1-report.json .bundles`), mtime **3 août 19:34** > `PosComponent.vue` mtime 2 août 05:42 → **bundle FRAIS**. Nota : `public/js/pos-app.js` (blade `admin-pos-v4.blade.php:120`) ne contient PAS le composant — POS est code-splitté en `pos-shell.<hash>.js` (85 chunks orphelins dans `public/js/`, seul c51e6763 est servi).
- **(a) sous la ligne de flottaison** : FAUX pour le champ nom (colonne ticket sticky : y=360 constant même scrollTop=758). VRAI partiellement pour le **CTA téléphone** à 1366×768 (bas du bouton + hint coupés).
- Le CTA téléphone (`PosComponent.vue:1345`, sous le bouton payer, rendu seulement si `carts.length > 0`) vit sur le MÊME écran que le champ nom → le nom EST saisissable AVANT soumission ; `phoneOrderSubmit` (`:4948`) envoie `phone_order: true` avec `pos_customer_name`.
- Lecture visuelle (`repro1-1366x768-viewport.png`) : le champ est un petit input gris « Nom du client (optionnel) » de 178 px coincé entre le segmented type-de-commande et « ⏰ Programmer », sans libellé — facile à rater ; le champ téléphone est tronqué à 1366.

**Captures** : `repro1-1366x768-viewport.png`, `repro1-1366x768-fullpage.png`, `repro1-1366x768-after-scroll.png`, idem `1920x1080`.

---

## Repro 2 — « Le paiement carte bleue n'est pas fonctionnel »

### Verdict : cause prouvée = **422 backend `pos_payment_note` (« Last 4 digits of card is required »)** quand le caissier confirme sans saisir les 4 derniers chiffres de la carte — le bouton Confirmer est **actif** malgré le champ vide, et le seul feedback est un **toast fugace en ANGLAIS**. Le TPE n'est PAS en cause (pré-sélectionné).

Chronologie prouvée (`repro2-report.json`, logs `R2|…`) :

1. Onglet **Carte (TPE)** : dropdown TPE **pré-sélectionné** `value=1` « TPE Le Cayenne #1 · simulation » (fetch `admin/payment-terminals` OK) → `canConfirmCard` vrai → bouton « ✓ Confirmer & Imprimer ticket » **enabled** (`disabled:false`). Capture `repro2-03-onglet-carte.png`.
2. Clic Confirmer, champ « 4 derniers chiffres » vide (geste caissier naturel) → `POST /api/admin/pos` → **422** body exact :
   `{"message":"Last 4 digits of card is required","errors":{"pos_payment_note":["Last 4 digits of card is required"]}}`
3. Feedback : toast transitoire « Last 4 digits of card is required » (capturé à +900 ms, `repro2-03b-toast-immediat.png`) — **en anglais brut**, disparu à +5 s ; **aucune erreur inline** sous le champ, modal reste ouvert, panier intact (`repro2-04-apres-confirm-carte.png`) → perçu « rien ne se passe / pas fonctionnel ».
4. **Contre-épreuve** : mêmes conditions + `#cardInput` = `1234` → `POST /api/admin/pos` **201**, ticket imprimé « Type de paiement: Carte », NF525 n°2703 (`repro2-05c-carte-apres-confirm-avec-chiffres.png`). → La carte FONCTIONNE dès que les 4 chiffres sont saisis.

Ancrage code (lecture seule) :
- Backend : `app/Http/Requests/PosOrderRequest.php:161` — CARD single-tender ⇒ `pos_payment_note` `['required','numeric','min_digits:4','max_digits:4']` ; message :354 (anglais hardcodé).
- Frontend : `PaymentComponent.vue:144` — `#cardInput` **sans v-model** (lu via ref à la soumission :705-707), **sans gate** : `canConfirmCard` (:449) ne vérifie QUE `terminal_id`, pas les 4 chiffres → le bouton reste actif et laisse partir la requête vouée au 422. Le 422 est bien mappé en toast (:967-970) mais éphémère.

### Onglet Multi-paiement
- **Visible** ✔ (`pos-payment-mode-multi`). 2 tranches (Répartir également → Espèces + Carte), COUVERT 2,50 € / RESTE DÛ 0,00 € → Confirmer → `POST /api/admin/pos` **201** ✔ ticket imprimé. **Aucun 4-chiffres exigé en split** (`$hasBreakdown ⇒ pos_payment_note nullable`, `PosOrderRequest.php:161`) — incohérence UX vs le chemin carte simple. Captures `repro2-06-onglet-multi.png`, `repro2-07-multi-2-tranches.png`, `repro2-08-multi-apres-confirm.png`.

Console : aucune erreur JS applicative (seulement les 404/422 ressources + un `ERR_CONNECTION_REFUSED` websocket sans impact).

**AUCUNE correction appliquée** — repro pure.
