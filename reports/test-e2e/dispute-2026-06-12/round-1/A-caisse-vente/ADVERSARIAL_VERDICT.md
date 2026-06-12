# ADVERSARIAL VERDICT — Vague A caisse vente & encaissement (Round 1, dispute-2026-06-12)

- Superviseur adversarial Round 1. App :8768 — **provenance vérifiée** : PID 38797, cwd = CE worktree (`release-v1-2026-06-10/public`) → tous les file:line cités sont re-greppés dans CE checkout.
- L'agent capture a été COUPÉ : son WAVE_REPORT.md (02:45) ne documente que les commandes (a) et (b) ; les artefacts c01→c06 + 3 runlogs (02:58→03:10) sont POSTÉRIEURS au rapport et n'ont jamais été analysés par lui. **C'est dans ces runlogs orphelins que dormait le P0 de la vague** — l'agent l'avait même mal diagnostiqué (« token révoqué par relogin parallèle », `tests/e2e/_d1-A-31-discount-card.mjs:89`) sans lire le body de la réponse.
- Disque : 5,6 Go libres au moment de ma passe (l'incident 406 Mo du capture-run est résolu) — mais il a dégradé les artefacts (cf. A-RED-4).
- Mes 7 captures supplémentaires : `_red01-discount-panel.png`, `_red01-after-confirm.png`, `_red01-cart-after-relogin.png`, `_red02-show-4522.png`, `_red02-show-4536.png`, `_red03-dblclick-result.png`, `_red04-pos-1366-paymodal.png`. Scripts : `tests/e2e/_d1red-A-caisse-vente-0{1,2,3,4,5}*.mjs`.

---

## FINDINGS

### A-RED-1 — **P0** — Remise manuelle caisse → encaissement IMPOSSIBLE : POST order 401 « Order quote intent mismatch » → toast trompeur « Session expirée » → caissier DÉCONNECTÉ → panier PERDU
- **Catégorie** : silent_error / prod-breaking (cat. 6+10+11). Déterministe, 4 reproductions indépendantes (3 du capture-run + 1 mienne).
- **Repro live adversariale** (script `tests/e2e/_d1red-A-caisse-vente-01-remise401.mjs`, exécuté 2026-06-12 09:39) :
  - Tiramisu 3,80 + Grande Frites 4,00 = 7,80 € ; remise 10 % motif « repro adversarial R1 » → Total 7,02 € (cart + quote serveur d'accord, quote **200**, X-RateLimit-Limit 120/remaining 57).
  - Confirm Espèces (reçu 10) → `POST /api/admin/pos` → **401** body exact : `{"status":false,"message":"Order quote intent mismatch."}`.
  - Le caissier voit : toast **« Session expirée. Reconnectez-vous puis relancez le paiement. »** (FAUX diagnostic — la session était valide) puis cascade de 401 sur toutes les API + `POST /api/auth/logout` → atterrit sur **/login**. Captures `_red01-after-confirm.png` (login), `_red01-cart-after-relogin.png`.
  - Après re-login : **panier VIDE** (`CART après re-login: []`). La commande à 7,02 € est perdue, à refaire entièrement — et elle re-échouera à l'identique.
  - Contre-épreuve d'isolation (runlog du capture-agent `_runlog-iso401.txt`) : V1 cash SANS remise → **201** (order 4552) ; V2 cash + remise → **401 intent mismatch**. La remise est LA variable.
- **Root cause (chaîne complète, file:line re-greppés)** :
  1. `resources/js/components/admin/pos/PaymentComponent.vue:866` — au confirm, `refreshQuote(preparedForm)` POSTe `admin/pos/quote` AVEC le champ `discount` (0,78) → `app/Services/Order/OrderQuoteService.php:413` inclut `manual_discount = money(request.discount)` dans le payload canonique → `intent_hash` H1 (avec remise).
  2. `PaymentComponent.vue:878` (FROZEN) — `const { total: _t, subtotal: _s, discount: _d, ...saveForm } = aligned;` (commit `aafa8c8f1` 2026-05-17, « POS-A6 client-totals strip ») → le POST `/api/admin/pos` part **SANS `discount`** mais avec `quote_token`+`quote_signature`.
  3. `app/Services/Order/OrderQuoteService.php:109-118` `sealForCommit` → re-canonicalise la requête order : `manual_discount = 0` → hash H2 ≠ H1 → `resolveReplay` `:350-351` : `throw new HttpException(401, 'Order quote intent mismatch.')`.
  4. `resources/js/pos-app.js:53-62` — l'intercepteur global POS traite **tout 401** comme session morte : `store.dispatch('logout')` → login, panier perdu. Le retry de `PaymentComponent.vue:800-819` (authcheck puis 2e tentative) est tué par ce logout global.
- **Aggravant** : la remise manuelle est officiellement ACTIVE en V1 — kill-switch `config/pos.php:172-176` `manual_discount_enabled` default **true** depuis la levée F1 (2026-05-31, gate owner). La feature est donc exposée au caissier, acceptée au quote, et écrasée au commit — depuis au moins la réactivation.
- **Pourquoi les suites vertes ne le voient pas** : les tests backend envoient `discount` dans le POST order (ex. `tests/Feature/QuoteTamperTest.php:137`, `tests/Feature/Pos/QuoteBindingTest.php:176` — `'discount' => 0`), jamais le payload RÉEL du frontend (quote AVEC discount + POST SANS le champ). Vert en CI, mort en prod. Illustration parfaite du principe §3.10.
- **Fix non trivial** : PaymentComponent est FROZEN ; correctif possible côté backend (exclure du canonical un champ que le client est sommé de ne pas envoyer, ou statut 409/422 + message dédié), ou LOCK frontal. Décision = gate owner. JE NE CORRIGE RIEN.

### A-RED-2 — **P1** — Sémantique 401 sur les gardes d'intégrité quote + intercepteur logout-sur-tout-401 = déconnexion forcée systémique
- `OrderQuoteService.php:338` (`Invalid order quote.`), `:347` (`signature mismatch`), `:351` (`intent mismatch`), `:115` (`token and signature required together`) renvoient **401** pour des échecs d'intégrité **non-auth** (seul le 410 expired `:342` est différencié). Combiné à `pos-app.js:58-62` (tout 401 → logout global), n'importe quel déclenchement de garde éjecte le caissier en plein service et vide son panier, avec un message mensonger « Session expirée ».
- A-RED-1 est le déclencheur reproductible ; ceci est l'amplificateur architectural — fix distinct (statuts + intercepteur), d'où finding séparé.

### A-RED-3 — **P2** — 429 sur `/api/admin/pos/quote` pendant le run + **drift de config rate-limit sur :8768**
- Observé (capture-run) : `c05/c06.network.txt` `[01:07:00] HTTP 429 POST /api/admin/pos/quote` → `NO ORDER POST CAPTURED`, `RECEIPT ABSENT` — le confirm meurt sans commande.
- Ma mesure live : header **`X-RateLimit-Limit: 120`** alors que `.env:94`/`.env.e2e:94` = `POS_RATE_LIMIT_QUOTE=1000` (`RouteServiceProvider.php:155-158`, défaut 120) → le serveur :8768 tourne sur une **config rance** (config:cache antérieur aux env). Et `remaining: 57` sur MON unique quote → le bucket (clé = user id 3, partagé par tous les agents parallèles loggés pos@lecayenne.fr) était déjà à moitié brûlé → le 429 du capture-run est un artefact de charge multi-agents, pas un comportement caissier-seul.
- P2 (environnemental) MAIS deux queues à tirer : (1) la config du serveur de test ne reflète pas les .env — toute conclusion « rate-limit OK » d'une autre vague est suspecte ; (2) un confirm qui meurt en 429 ne laisse AUCUNE commande — le feedback visible n'a pas pu être confirmé depuis les artefacts (toast éventuel expiré avant la capture c05, prise 3,5 s après).

### A-RED-4 — **P1 (process/evidence)** — Les 26 `.dom.html` du quartet sont BYTE-IDENTIQUES : le volet DOM du contrat de preuve est VIDE
- `md5 *.dom.html` → 26× `c4aad7e4fc82c9022cef41e1c30ec2dd`. Cause re-greppée : `tests/e2e/_d1-A-lib.mjs:57` `dom.slice(0, 80*1024)` — le `<head>` de la page (fonts+CSS swal inlinés) dépasse 80 Ko, donc CHAQUE fichier est le même tronçon de head, **0 octet de `<body>`**.
- Conséquence : toutes les vérifications « DOM » du WAVE_REPORT (i18n leaks, aria, sondes toast) ne sont re-vérifiables que via les logs de script, pas via les artefacts. Le quartet du REVIEWER_PROTOCOL est rompu sur 26/26 états. Round 2 : capturer le `outerHTML` du conteneur applicatif (pas le document entier), ou tronquer par la FIN.

### A-RED-5 — **P1 (couverture)** — Mandat de vague NON rempli (constat, partiellement comblé par mes scripts)
- Le WAVE_REPORT laisse « (à relever) » TOUTES les colonnes show/historique/DB de sa propre table d'intégrité ; aucun artefact show/historique, aucun test double-clic, aucune capture 1366×768, et **aucune vente Carte/Terminal aboutie** (la (c) carte+remise est morte sur A-RED-1 ×2 ; iso401-V3 carte simple est mort en cascade post-logout). 2 receipts sur 4 commandes mandatées.
- J'ai comblé : show/historique (LIVE-3 ✔), double-clic (LIVE-4 ✔ PASS), 1366×768 (LIVE-5 → A-RED-12). **Reste non couvert au Round 1 : une vente Carte (TPE) simple aboutie jusqu'au receipt + annulation de commande.** À exiger du Round 2.

### A-RED-9 — **P1** — Wizard caisse : rejet SILENCIEUX de « Ajouter au panier » quand une section Obligatoire (sauce) est vide
- `b10-invalid-add-no-sauce.png` + crop `_z-b10-invalid.png` relus : viandes 1/4 ✓, sauce Obligatoire vide, clic « Ajouter au panier » → wizard inchangé, item non ajouté, **zéro feedback** (sondes du run : toasts=[], `[role=alert]`=0, invalidMarks=0 ; visuel : aucune différence d'état, aucun marqueur rouge sur la section sauce).
- Chemin primaire de vente : un caissier en rush ne sait pas pourquoi « ça ne marche pas » → P1 (cat. 8, bloque la tâche principale), pas un P2 cosmétique. FROZEN `public/js/pos-wizard.js` → fix gaté owner ; absent de la liste des gates connus → compté.

### A-RED-6 — **P2** — Ticket client : colonne « Prix » et « SOUS-TOTAL » en HT sans marqueur, et « Sous-total » TTC sur le show de la MÊME commande
- Receipt #1206264522 (`_z-a06-receipt.png`) : « 3 | Coca-Cola 33cl | **4,09 €** », « SOUS-TOTAL: **4,09 €** » pour une vente 3×1,50 = 4,50 TTC ; ni prix unitaire ni TTC ligne. Et `/admin/pos-orders/show/4522` (LIVE-3, `_red02-show-4522.png`) affiche « 3 Coca-Cola 33cl **4,50 €** … Sous-Total **4,50 €** » — **le même mot « Sous-total » vaut 4,09 sur le ticket et 4,50 sur le back-office** pour la même commande, sans mention HT/TTC nulle part.
- Pas un P0 intégrité (arithmétique interne juste : 4,0909×1,1 = 4,50 ✓, taxes affichées) mais une incohérence inter-surfaces qui fera perdre du temps au gérant et déroutera un client. Code : `resources/js/components/admin/pos/ReceiptComponent.vue` (~143-155, choix « per-line HT » assumé). P2 à discloser.

### A-RED-7 — **P2** — Ticket : « Viande 1: Poulet mariné ,Sauce (1ère Gratuite): Algérienne » — espace AVANT la virgule, rien après
- Root cause re-greppée : `resources/js/components/admin/pos/ReceiptComponent.vue:126-128` — `{{ variation.name }}` + retour-ligne template + `<span>, </span>` ; dupliqué ticket cuisine `:311-313`. NON frozen → fixable sans gate. Visible sur tout ticket ≥2 options (`_z-b17-receipt.png`).

### A-RED-10 — **P2** — Tranche multi-paiement : « MFS » (et « Autre ») proposés au caissier FR
- Options select observées : `1:Espèces, 2:Carte, 3:MFS, 5:Ticket Restaurant, 4:Autre` (`b15-payment-multi-tr.png`). « MFS » = `resources/js/languages/fr.json:917` `"mobile_banking": "MFS"` (NON frozen), rendu par `PosV5TrancheRow.vue:22` (FROZEN, observé). Hors mandat owner V1 (Espèces/TR/Terminal manuel) + cryptique. Le retrait de l'OPTION = gate (frozen) ; le LIBELLÉ est fixable. Question NF525 « Autre » (traçabilité type d'encaissement) à discloser.

### A-RED-11 — **P2** — `/admin/pos-orders/show` tire `GET /api/admin/delivery-boy` → **403 silencieux** pour le rôle caissier
- Observé LIVE ×2 (show 4522 et 4536) : `HTTP 403 GET /api/admin/delivery-boy?...status=5`, aucune alerte UI. Source : `resources/js/components/admin/posOrders/PosOrderShowComponent.vue:474` — `this.$store.dispatch('deliveryBoy/lists', …)` sans garde rôle/type de commande. Conséquence latente : sur une commande LIVRAISON, le sélecteur livreur du caissier serait vide sans explication. P2 (4xx silencieux de fond, pas d'action utilisateur échouée sur À-emporter).

### A-RED-12 — **P2** — 1366×768 : le CTA « Confirmer & Imprimer ticket » du modal paiement est SOUS la ligne de flottaison
- Mesure live : confirm `top:758 / bottom:814` pour viewport 768 → ~10 px visibles (`_red04-pos-1366-paymodal.png` : liseré orange coupé). Le modal est scrollable (`.pos-v5-payment-modal` scrollHeight 871 / clientHeight 768 — sonde `_d1red-A-caisse-vente-05-scrollprobe.mjs`) donc atteignable, mais aucun indicateur de scroll (overlay macOS) et le « Monnaie à rendre » passe aussi sous le pli. Résolution de caisse courante. Le heal W2 « CTA encaissement sticky <900px » ne couvre PAS ce modal.

### A-RED-8 — **P3** — Ticket : deux-points incohérents (« Rendu : » avec espace vs « Espèces: »/« SOUS-TOTAL: ») + wrap « À / emporter »
- `_z-a06-receipt.png`. Famille du gate « : » orphelin tracker mais occurrences distinctes (ticket). Cosmétique.

### A-RED-13 — **P3** — Show back-office : ligne « Instruction: » auto-générée en MAJUSCULES dupliquant les choix wizard
- `/admin/pos-orders/show/4536` : « Instruction: TACOS CHOISIS TES VIANDES : Poulet mariné CHOISIS TA SAUCE : Algérienne » sous une ligne d'item qui liste déjà « Poulet Mariné, Algérienne ». Bruit visuel + headers de sections wizard criés en caps dans le back-office. Cosmétique.

### Vérifications négatives (pas de finding — dit explicitement)
- **Intégrité numérique des commandes ABOUTIES : PROPRE.** (a) 4522 : cart 4,50 = modal 4,50 = receipt TOTAL 4,50 (rendu 5,50/10) = POST 4.5 = show 4,50 = historique 4,50. (b) 4536 : 8,50 partout, multi couvert 8,50/reste 0,00, show « Ticket Restaurant (multi-tender) ». TVA 10 % exacte aux centimes sur les deux. Le défaut d'intégrité de la vague n'est pas l'arithmétique, c'est le flux remise (A-RED-1).
- **Double-clic/triple-clic Confirmer : PASS** — 1 seul POST 201 (order 4568), un seul receipt (`_red03-dblclick-result.png`).
- **Opérateur du receipt** : « Caissier Le Cayenne » (DB users id=3 vérifiée) — pas de « Client passage », claim du WAVE_REPORT UPHELD.
- **« APPLIQUER » remise** : libellé complet, non tronqué (`_red01-discount-panel.png`) ; gate motif-obligatoire fonctionne (c02 : APPLIQUER disabled + flag « (obligatoire) »).
- Layouts 1440×900 a01→c06 relus : pas de chevauchement cliquable, pas de clé i18n brute, palette conforme.
- Consoles a/b/c : uniquement ws:6001 (allowlist) + « step skipped viande_2 » (warning wizard frozen, famille spam-log gaté) ; les ERROR de c05/c06 sont les 401/429 d'A-RED-1/3.

---

## DISPUTES — FINAL_REPORT GOAL UI/UX caisse+borne 2026-06-11 (claims touchant la vague A)

| # | Claim (FINAL_REPORT) | Verdict | Preuve |
|---|---|---|---|
| D1 | « VERDICT CONVERGED — Cycle 2 : P0=0 · P1=0 · P2=0 … production-perfect » (l.4-7) | **REFUTED** (sur le périmètre caisse-vente) | A-RED-1 : P0 déterministe au cœur de l'encaissement (remise active par défaut depuis 2026-05-31 → 401 → logout → panier perdu), reproduit 4×. Le bug PRÉ-EXISTE au goal (strip `aafa8c8f1` 2026-05-17 + garde quote antérieure) : les 2 cycles de convergence n'ont jamais mené UNE vente remisée jusqu'au ticket, sinon ils l'auraient heurté à coup sûr. « Production-perfect » ne survit pas à la première remise du premier service. |
| D2 | W2 heal « CTA encaissement sticky <900px » (l.14) | **WEAKENED** | Le CTA primaire du MODAL de paiement (« Confirmer & Imprimer ticket ») reste sous le pli à 1366×768 (bottom 814/768, ~10 px visibles, scroll sans affordance) — A-RED-12, `_red04-pos-1366-paymodal.png`. Le sticky ne couvre pas la surface la plus critique de l'encaissement. |
| D3 | W2 heal « formats € FR partout (appService Intl fr-FR) » (l.14) | **UPHELD** | Tous mes relevés : `4,50 €`, `7,02 €`, `- 0,78 €`, « Monnaie à rendre 5,50 € », receipts, show, historique — format FR partout. Seule exception = wizard frozen `€8.50` déjà disclosée au gate G4. |
| D4 | W2 heal « toasts 429 » (l.14) | **WEAKENED** (non prouvé sur ce flux) | Le seul 429 réel du run (quote au confirm, c05/c06) n'a laissé AUCUNE trace de toast sur les captures et aucune commande ; les artefacts ne permettent pas de prouver que le caissier a vu quoi que ce soit (capture 3,5 s après). Pas réfuté (TTL toast), mais le claim n'est pas démontré là où il compte. |

---

## DÉCOMPTE FINAL (Round 1, vague A)

| Sev | Count | IDs |
|---|---|---|
| P0 | 1 | A-RED-1 |
| P1 | 4 | A-RED-2, A-RED-4 (process), A-RED-5 (couverture), A-RED-9 |
| P2 | 6 | A-RED-3, A-RED-6, A-RED-7, A-RED-10, A-RED-11, A-RED-12 |
| P3 | 2 | A-RED-8, A-RED-13 |

## VERDICT VAGUE A : **RED**
P0 ouvert (toute vente avec remise = inencaissable + déconnexion + perte du panier, avec un diagnostic UI mensonger), 2 P1 produit, 2 P1 process, et le mandat de vague incomplet (vente Carte aboutie + annulation toujours non couvertes). Round 2 obligatoire. Le fix du P0 touche un FROZEN (PaymentComponent) ou la sémantique des gardes backend → **gate owner requis avant tout heal**.
