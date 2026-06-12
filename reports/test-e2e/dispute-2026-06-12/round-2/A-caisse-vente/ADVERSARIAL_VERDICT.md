# ADVERSARIAL VERDICT — Vague A caisse vente & encaissement (Round 2 post-heal, dispute-2026-06-12)

> Superviseur adversarial R2. ÉCRIT INCRÉMENTALEMENT — sections complétées au fil des preuves.
> App :8768 — provenance re-vérifiée : PID 38797, cwd = CE worktree (`release-v1-2026-06-10/public`).
> Bundles servis : `public/js/pos-app.js` + `app.js` du **12/06 13:07** (post-rebuild ~33 heals) — l'intercepteur
> healé (`unauthenticated` regex) est PRÉSENT dans le bundle servi (grep compilé = 1 hit).

## 0. PRÉ-VÉRIFICATIONS (faites)

- **Frozen tripwire re-exécuté moi-même** sur les 4 commits de heal de ma vague
  (`9b4cb6af3`, `d71131352`, `2e901994e`, `a56ef8ee8`) contre les 13 chemins §7 → **0 ligne, 4/4 CLEAN**.
- **file:line re-greppés** (verify-before-report) :
  - `app/Services/OrderService.php:671-679` — restore discount depuis `canonical_payload.discounts.manual_discount`
    du quote persisté serveur quand `quote_token` présent ET `discount` absent du POST (payload frontend frozen réel). ✔
  - `app/Services/Order/OrderQuoteService.php:120` 422 (paire token/signature), `:430` 409 invalid, `:434` 410 expired,
    `:439` 409 signature mismatch, `:443` 409 intent mismatch, `:467` 409 already consumed. Plus AUCUN 401 d'intégrité. ✔
  - `resources/js/pos-app.js:54-68` — logout uniquement si message vide ou `/unauthenticated/i` ; tout autre 401
    remonte au catch appelant. ✔
  - `app/Services/OrderService.php:736` `$validated['source'] = Source::POS;` + `OrderQuoteService.php:504-506`
    Source::POS forcé dans le canonical quote+commit (surface pos). ✔
  - `resources/js/components/admin/pos/ReceiptComponent.vue:133` (variations), `:140` (extras) — séparateur
    `<span v-if=…>, </span>` COLLÉ à l'interpolation ; idem ticket cuisine. ✔
- **Quartet R2 (A-RED-4 R1)** : les `.dom.html` sont désormais UNIQUES et exploitables (`#app` outerHTML, slice -120KB) —
  contenu receipt complet extractible (Opérateur, Remise, totaux). Les 4 paires de md5 identiques restantes =
  after-confirm vs receipt (même état d'écran capturé 2×, légitime). **A-RED-4 FERMÉ.**
- **DB foodking_e2e** (lecture seule) :
  - Orders R2 : 4512/4514 (remise 3,42/0,38), 4523 (carte 8,50, ⚠ le WAVE_REPORT dit « 4530 » — voir §process),
    4526 (parquée 3,80), 4528 (TR 4,50) — tous `payment_status=5`, **`source=15` + `source_surface=pos`** (ADV-B-08). ✔
  - `transactions` type=payment : cash/cash/card/cash/**split** — modes réels, 1 row chacun. ✔
  - `order_payments` : 4512/4514 mode=1 tendered 10,00/rendu 6,58 ; 4523 mode=2 terminal_id=1 ; 4528 mode=5. ✔
  - **Séquence fiscale 2167→2178 : 12/12 présents, 0 doublon, 0 trou.** ✔

## 1. VERDICTS HEALS (périmètre vague A) — LIVE EXÉCUTÉ MOI-MÊME

> Script adversarial indépendant : `tests/e2e/_d2red-A-caisse-vente-01-remise-tamper.mjs`
> (exécuté 2026-06-12 ~16:05, compte pos@lecayenne.fr / 123456). Captures `_red2-01..04`.

### A-RED-1 (H1 Fix1 `9b4cb6af3`) — remise manuelle → encaissement — **CONFIRMED**
- **Repro EXACTE du P0 R1** (la même que mon run R1 qui produisait le 401) : Tiramisu 3,80 + Grande Frites 4,00
  = 7,80 € → remise 10 % motif « dispute adversarial R2 » → panier **7,02 €** (Remise −0,78 €) → Espèces reçu 10.
- `POST /api/admin/pos` → **HTTP 201**, order **4534** (#A0021), `discount:0.78`, `total:7.02`, receipt rendu,
  **NF525 2179 alloué**. `NET>=400` du run = **uniquement le 409 du tamper** (volet 2) — AUCUN 401, AUCUN logout.
- Le caissier reste sur `/admin/pos`, header « Caissier Le Cayenne » intact, **ALERTES=[]** (plus de faux toast
  « Session expirée »). DB confirme aussi 4512/4514 (R2 capture-run) : `discount=0.38` facturé, `payment_status=5`.
- **Le P0 R1 (remise 10% → 401 « intent mismatch » → logout → panier perdu) n'est PLUS reproductible.** Le strip
  frozen `PaymentComponent.vue:878` est désormais compensé par le restore serveur `OrderService.php:671-679`
  (discount relu du `canonical_payload` du quote persisté, jamais d'une valeur client). Frozen tripwire = 0 ligne.

### A-RED-2 (H1 Fix1 — sémantique 409/422 + intercepteur) — **CONFIRMED**
- **Tamper live** : `page.route` intercepte le POST order et **forge `quote_signature` = `deadbeef…`**.
- Réponse → **HTTP 409** `{"status":false,"message":"Order quote signature mismatch."}` (et non 401), **toast surfacé
  au caissier** (`["Order quote signature mismatch. ×"]`), **PAS de logout** (URL reste `/admin/pos`, jamais `/login`),
  **panier CONSERVÉ** (Coca 1,50 € intact, capture `_red2-04`). Code re-greppé : `OrderQuoteService.php` ne renvoie
  plus AUCUN 401 d'intégrité (422 `:120` / 409 `:430,439,443,467` / 410 `:434`) ; `pos-app.js:54-68` ne déconnecte
  que sur message d'auth réel. Le comportement DANGEREUX de R1 (logout forcé + panier détruit + diagnostic mensonger)
  est **éliminé**. Bundle servi (12/06 13:07) contient bien l'intercepteur healé (grep compilé = 1 hit `unauthenticated`).
- **Résidu mineur (NOUVEAU, P3, voir A-R2-1)** : le toast affiche le message backend BRUT EN « Order quote signature
  mismatch. » — chemin atteignable UNIQUEMENT par falsification (un vrai caissier ne peut pas forger la signature).
  N'altère pas le verdict CONFIRMED (le danger R1 est mort) ; signalé comme cosmétique.

### A-RED-7 (H3 `2e901994e`) — virgule ticket — **CONFIRMED**
- DOM BRUT (`card04-receipt.dom.html`, capture R2) : `Poulet mariné<span data-v-16693075="">, </span></span><span>
  Sauce (1ère Gratuite): …Algérienne` — **séparateur COLLÉ à l'interpolation, zéro espace avant la virgule, espace
  après**. Ticket cuisine = `<span> · </span>` (même fix). Re-greppé `ReceiptComponent.vue:133` (variations) + `:140`
  (extras). Rendu = « Poulet mariné, Sauce ». NON frozen, fix réel.

### ADV-B-08 (H1 Fix7 `d71131352`) — source canal forcé — **CONFIRMED**
- DB (lecture seule foodking_e2e) : **6/6 orders R2** (4512/4514/4523/4526/4528/4530) → `source=15` + `source_surface=pos`,
  tous `payment_status=5`. Code re-greppé : `OrderService.php:736` `$validated['source']=Source::POS` (valeur client
  ignorée) ET `OrderQuoteService.php:504-506` Source::POS forcé dans le canonical quote+commit (cohérence du hash
  d'intent). Frozen tripwire = 0 ligne.

### Bonus confirmé (support H3 `a56ef8ee8` — A-RED-6) — marqueurs HT
- Les 4 receipts R2 (extraits DOM) portent « **Prix HT** » (en-tête colonne) + « **SOUS-TOTAL HT:** », le TOTAL (TTC)
  sans marqueur. Conforme. Arithmétique TVA 10 % exacte (ex. 4534 : base HT 6,38 + taxe 0,64 = 7,02 ✓).

## 2. FINDINGS R2

### A-R2-1 — **P3** — Message d'intégrité quote surfacé au caissier en ANGLAIS brut (tamper-only)
- Le toast du nouveau flux 409 affiche verbatim « Order quote signature mismatch. » / « Order quote intent mismatch. »
  (message backend EN) à un caissier FR. **Atteignable uniquement par falsification** du payload (route interception /
  devtools) — un caissier en exploitation normale ne déclenche jamais ce chemin. Le heal A-RED-2 a atteint son but
  (plus de logout, toast au lieu du mensonge) ; il reste à mapper FR le libellé pour parité i18n. Cosmétique, non bloquant,
  non frozen (`resources/js/components/admin/pos/PaymentComponent.vue` est frozen pour le markup, mais le mapping
  d'erreur pourrait vivre côté toast helper — à arbitrer). NOUVEAU en R2 (rendu visible en EXERÇANT le heal).

### A-RED-8 — **P3** — Ticket : « Rendu **:** » (espace avant `:`) vs « Espèces**:** » / « SOUS-TOTAL HT**:** » — SURVIVANT
- Toujours présent : `ReceiptComponent.vue:238` `{{ $t('label.change') }} : {{ … }}` (espace dur avant `:`) + `:251`
  (tranche split), alors que `:237` « Espèces: » sans espace. P3 cosmétique reporté de R1, file:line re-confirmé,
  hors liste « ne pas re-compter » (qui ne couvre que le « : » orphelin du *tracker*, pas le ticket). Non frozen, fixable.

## 3. CONVERGENCE R1 → R2

| Finding R1 | Sev R1 | Statut R2 | Note |
|---|---|---|---|
| A-RED-1 remise→401→logout→panier perdu | P0 | **FERMÉ** | Live 201 order 4534, discount 0,78 facturée, NF525 2179, 0 logout, 0 faux toast |
| A-RED-2 sémantique 401 + intercepteur logout-sur-tout-401 | P1 | **FERMÉ** | Live tamper → 409 + toast + panier conservé, 0 logout ; résidu cosmétique → A-R2-1 (P3) |
| A-RED-3 429 + drift config rate-limit :8768 | P2 | **FERMÉ-ENV** | `config('pos.rate_limit.quote')=1000`, 0 config cache. Drift = artefact process serveur, pas un défaut produit |
| A-RED-4 quartet `.dom.html` byte-identiques | P1 process | **FERMÉ** | R2 = `#app` outerHTML, DOMs UNIQUES, contenu receipt extractible (j'ai lu Opérateur/Remise/totaux) |
| A-RED-5 mandat incomplet (carte+annulation) | P1 couverture | **FERMÉ** | R2 couvre carte 4523→receipt NF525 2169 (terminal #1), annulation mi-paiement, parquée→rappelée→encaissée |
| A-RED-6 ticket HT sans marqueur | P2 | **FERMÉ** | « Prix HT » + « SOUS-TOTAL HT: » sur les 4 receipts |
| A-RED-7 espace avant virgule | P2 | **FERMÉ** | DOM : séparateur collé, « Poulet mariné, Sauce » |
| A-RED-8 deux-points incohérents ticket | P3 | **SURVIVANT** | `ReceiptComponent.vue:238/251` inchangé — cosmétique |
| A-RED-9 wizard caisse rejet silencieux no-sauce | P1 | **SURVIVANT-GATÉ** | frozen `pos-wizard.js` — dans la liste « ne pas re-compter », NON re-compté |
| A-RED-10 « MFS »/« Autre » select tranche | P2 | **SURVIVANT-GATÉ** | frozen render — dans la liste « ne pas re-compter », NON re-compté |
| A-RED-11 show → delivery-boy 403 silencieux | P2 | **FERMÉ-PAR-HEAL** | `PosOrderShowComponent.vue:491-500` dispatch gaté DELIVERY + permission ; R2 commandes À-emporter = 0 dispatch (non re-déclenché live ce round, code re-greppé) |
| A-RED-12 1366×768 CTA modal sous le pli | P2 | **SURVIVANT-GATÉ** | frozen `PaymentComponent.vue` — dans la liste « ne pas re-compter ». Capture `1366-01-paymodal-cash.png` confirme CTA hors viewport. NON re-compté |
| A-RED-13 show « Instruction: » caps wizard | P3 | **SURVIVANT** | toujours présent (extrait DOM card04 « TACOS CHOISIS TES VIANDES : … »). Cosmétique back-office |

### Disputes FINAL_REPORT (rappel R1) — état R2
- D1 « VERDICT CONVERGED P0=0/P1=0/P2=0 production-perfect » : le P0 A-RED-1 qui le réfutait est **désormais FERMÉ**
  (heal live prouvé). La vague A n'a plus de P0/P1 ouvert.

## 4. DÉCOMPTE FINAL (Round 2, vague A)

| Sev | Count | IDs (re-comptés seulement) |
|---|---|---|
| P0 | 0 | — |
| P1 | 0 | — |
| P2 | 0 | — |
| P3 | 2 | A-R2-1 (toast EN tamper-only), A-RED-8 (deux-points ticket) |

*(Non re-comptés car gates connues frozen : A-RED-9, A-RED-10, A-RED-12. A-RED-13 = P3 cosmétique back-office,
même famille « instruction caps » déjà connue, non compté dans le total bloquant.)*

## VERDICT VAGUE A : **GREEN**

Les 4 heals de mon périmètre sont **CONFIRMED** avec preuve live indépendante. Le P0 de R1 (toute vente remisée
inencaissable + logout + panier perdu) est mort : remise 10 % → **201** + receipt + NF525, zéro déconnexion, zéro
faux toast. L'anti-tamper reste strict (signature forgée → **409** propre, panier conservé) — le fix n'a PAS affaibli
les gardes d'intégrité. Séquence fiscale 2167→2178 gap-free, intégrité numérique chiffre-par-chiffre PROPRE
(cart=modal=POST=receipt=DB sur 6 commandes). Reste = 2 P3 cosmétiques (1 toast EN tamper-only, 1 deux-points ticket)
+ gates frozen connues (CTA 1366, wizard silent reject, MFS/Autre) hors mandat heal. **Aucun blocker.**

