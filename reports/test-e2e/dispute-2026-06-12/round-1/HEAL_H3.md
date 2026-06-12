# HEAL H3 — CAISSE UI — Round 1 (dispute 2026-06-12)

> Healer H3. Périmètre strict : `resources/js/components/admin/**` (hors frozen) + `tests/js/**`.
> Branche `release/v1-2026-06-10`, worktree partagé. 12 commits, 0 frozen touché (tripwire par commit ci-dessous).
> Vitest global : **2280 passed / 2 failed** — les 2 échecs = sentinels bundle-freshness PRÉ-REBUILD (attendus,
> le rebuild est centralisé après le round ; identique aux rounds précédents).
> ⚠ Les bundles servis sur :8768 datent du 11/06 → les fixes Vue ne sont PAS visibles live avant le rebuild
> central. Les preuves live ci-dessous = (a) reproduction AVANT sur le bundle courant, (b) simulation DOM du
> patch exact (mêmes valeurs CSS) avec hit-test → AFTER géométrique honnête, (c) specs Vitest montés (DOM réel).

---

## 1. ADV-F-P0-1 [P0] — Footer du modal « Encaisser la commande borne » interceptait 6 touches du pavé

- **Root cause** : régression du heal W2-G6 (2026-06-11) — `position: sticky; bottom: 0; z-index: 1; background opaque`
  sur `.cc-modal-footer` DANS le scroller `.cc-modal` (overflow-y auto, max-height 92vh). Contenu 942px vs 828
  visibles à 1440×900 → footer au-dessus des touches 7/8/9/00/0/«,». Taper « 9 » tombait sur « ✓ Confirmer &
  Imprimer ticket » (événement fiscal NF525 irréversible).
- **Repro live AVANT (bundle 11/06, :8768, 1440×900)** : hit-test elementFromPoint →
  `blocked: 7←footer, 8←footer, 9←cc-confirm-btn, C←cc-confirm-btn, 00/0/,←footer` — encore PIRE que le verdict
  (la touche « C » est AUSSI sur le CTA). Capture `heal-proofs/h3-p0-before-modal-occlusion-1440x900.png`.
- **Fix** (`resources/js/components/admin/pos/PosCounterCollectModal.vue`) :
  1. **Structure** (commit `34d1e0769`) : colonne flex — `.cc-modal { display:flex; flex-direction:column; overflow:hidden }`,
     nouveau `.cc-modal-body { flex:1; min-height:0; overflow-y:auto }` (header+hero+modes+pavé), footer = sibling
     FIXE hors du scroller (`flex:0 0 auto; position static; border-top`). Chevauchement géométriquement impossible
     à toute hauteur ; CTA toujours visible (intention G6 préservée sans l'effet de bord).
  2. **Compaction** (commit `bbb79630d`) : le structurel seul laissait la dernière rangée derrière un scroll à 900px.
     Compaction mesurée live (hero 40→32px, tuiles mode 84→64px, input, touches 56→**48px = plancher tactile**,
     atome partagé PosV5Numpad INTACT — override `:deep` scopé au modal) + media query `≤820px` (hero 26px,
     modes 52px sans sous-libellé, max-height 96vh).
- **Preuve AFTER (simulation DOM du patch exact sur le live, hit-test)** :
  - 1440×900 : body 736/736 (AUCUN scroll), **14/14 touches hit-test OK**, CTA visible — `h3-p0-after-sim-modal-1440x900.png`
  - 1366×768 : body 664/664 (AUCUN scroll), **14/14 touches hit-test OK**, CTA visible — `h3-p0-after-sim-modal-1366x768.png`
- **TDD** : `tests/js/posCounterCollectFooterNumpadOverlap.spec.js` (8 tests) — mount réel (numpad réel) : footer
  sibling hors du scroller + numpad dans le body ; contrat CSS source (plus de sticky, scroller = body, media query,
  plancher 48px). RED avant fix (5 fails) → GREEN.
- **Sentinel W2-G6 mis à jour** (`tests/js/uiuxW2H2HealSentinel.spec.js`) : il verrouillait le sticky REFUTÉ (D-2) ;
  re-pointé sur le nouveau canonical (pas un affaiblissement : le nouveau contrat couvre l'intention G6 ET interdit
  le retour du sticky).
- **SHAs** : `34d1e0769` + `bbb79630d`. **Post-rebuild** : re-run hit-test réel aux 2 résolutions attendu `blocked: []`.

## 2. A-RED-12 [P2] — CTA modal paiement POS sous la ligne de flottaison à 1366×768 → **SKIPPED [GATE-OWNER]**

- **Vérifié** : `grep -rln 'pos-v5-payment-modal'` → UNIQUE fichier `resources/js/components/admin/pos/PaymentComponent.vue`
  (markup ligne 12, `<style scoped>` vide ligne 1024 — la géométrie vient de la structure du composant FROZEN §7).
- Un override CSS global ciblant les internals frozen = exactement la classe de change qui a créé ADV-F-P0-1
  (PaymentComponent contient AUSSI un PosV5Numpad → même risque d'occlusion). Refus d'un contournement cavalier.
- **Blueprint prêt pour le LOCK owner** : porter le pattern validé du sibling (colonne flex + scroller interne +
  footer statique + compaction ≤820px — valeurs déjà hit-testées live aux 2 résolutions sur le sibling).

## 3. B-R1-04+E-ADV-4 [P0 risk, part UI] — N° de file dupliqués inter-jours sans date — commit `d377da185`

- **Root cause UI** : queue_number réutilisés chaque business date (`FrontendOrderService.php:1022-1033`, constat
  verdict), 48+ zombies du 10/06 dans la file, cartes sans AUCUNE date, tri ancienneté-seule → le run GStack a
  encaissé les mauvaises commandes. **Repro live** : `heal-proofs/h3-before-queue-no-date-1440x900.png`
  (63 en attente, 319,60 €, A0011/A0013/A0014 dupliqués, zéro date).
- **Fix** (3 surfaces, périmètre admin uniquement) :
  - `EncaissementComponent.vue` : badge ambre « hier » / « jj/mm » sur toute carte d'un autre jour
    (`enc-day-badge`, testid `enc-day-badge-{id}`) + computed `sortedOrders` — **jour le plus récent d'abord,
    FIFO intra-jour** (zombies en bas, badge attente W2.2 conservé).
  - `PosComponent.vue` (panneau file caisse) : même badge (`kiosk-cash-day-badge`) + même tri dans
    `loadKioskCashOrders` (le tri ancienneté-seule de `readyOrders` — commandes PRÊTES — reste légitime, non touché).
  - `PosCounterCollectModal.vue` : chip « ⚠ Commande d'hier » / « ⚠ Commande du jj/mm/aaaa »
    (testid `pos-counter-collect-day-badge`) dans le header AVANT confirmation.
- **NON FAIT (décision owner, hors mandat)** : purge backend des PENDING_COUNTER expirés.
- **TDD** : `tests/js/encaissementQueueDayBadge.spec.js` (8 tests, fake timers 2026-06-12) — tri [4537,4544,4400,4330],
  badges « hier »/« 10/06 », zéro badge aujourd'hui, modal 3 cas, source PosComponent.

## 4. A-RED-7 [P2] — « Poulet mariné ,Sauce » : espace avant virgule — commit `2e901994e`

- **Root cause re-greppée** : `ReceiptComponent.vue` — span séparateur sur sa propre ligne de template après
  `{{ variation.name }}` → le whitespace condense de Vue rend « nom , ». 4 occurrences (variations + extras,
  ticket client « , » + ticket cuisine « · »).
- **Fix** : séparateur collé à l'interpolation (`{{ variation.name }}<span …>, </span>`) sur les 4 spots.
- **TDD** : `tests/js/receiptVariationSeparatorWhitespace.spec.js` (RED 2 fails → GREEN ; verrouille l'adjacence
  + la survie des séparateurs « , » et « · »).

## 5. A-RED-6 [P2] — Ticket « Prix »/« SOUS-TOTAL » HT sans marqueur — commit `a56ef8ee8`

- **Fix minimal, AUCUN calcul changé** : le ticket EST i18n-isé ($t partout) mais aucune clé `*_ht` n'existe →
  suffixe « HT » dans le template (précédent existant : fallback `'HT'` du bloc tax_lines:189) :
  en-tête colonne « Prix HT » + « SOUS-TOTAL HT: ». Le TOTAL (TTC payé) reste sans marqueur.
- **TDD** : `tests/js/receiptHtMarkers.spec.js` (4 tests — marqueurs présents, TOTAL épargné, projections backend inchangées).
- **Pour H2** : clés propres `label.price_ht` / `label.subtotal_ht` (FR + EN).

## 6. A-RED-11 [P2] — show caisse : `GET /admin/delivery-boy` → 403 silencieux caissier — commit `c76b7080f`

- **Root cause** : `PosOrderShowComponent.vue` mounted() dispatchait `deliveryBoy/lists` inconditionnellement ;
  la route backend est gatée `permission:delivery-boys` (`DeliveryBoyController.php:29`).
- **Fix** : dispatch déplacé dans le `.then` du show, conditionné `order_type === DELIVERY` **ET**
  `appService.permissionChecker('delivery-boys')` + `.catch` propre (sélecteur livreur vide, pas de console error).
  Le dropdown livreur n'est rendu que sur les commandes LIVRAISON (v-if existant) → aucun comportement perdu.
- **TDD** : `tests/js/posOrderShowDeliveryBoyGate.spec.js` (3 tests).

## 7. B-R1-19 [P0, part front] — /admin/transactions : AxiosError non gérée (BM) — commit `2bef9dfc4`

- **Repro live AVANT (bundle courant, login bm.t2admin)** : console = `403 /api/admin/setting/payment-gateway`
  + `AxiosError: Request failed with status code 403` non interceptée — capture
  `heal-proofs/h3-before-transactions-bm-403.png` + log console `.playwright-mcp/console-2026-06-12T10-37-16-611Z.log`.
- **Fix (défense en profondeur — H1 corrige l'authz backend en parallèle)** : `.catch` sur le dispatch
  `paymentGateway/lists` → flag `paymentGatewaysUnavailable` → le filtre « Mode de paiement » est masqué proprement
  (pas de select vide cassé), la liste des transactions reste servie (elle a déjà son propre catch FR).
- **TDD** : `tests/js/transactionsPaymentGateway403Defense.spec.js` (3 tests).

## 8. E-ADV-8 [P2] — Échec 401 mid-confirm d'encaissement sans message — commit `6d038372d`

- **Fix** : branche 401 dédiée dans le catch d'`onConfirm` (PosCounterCollectModal) — toast FR explicite :
  « Erreur lors de l'encaissement — session expirée : la commande n'a PAS été encaissée. Reconnectez-vous puis
  réessayez depuis la file d'encaissement. » + `submitting` libéré (réessayable). Plus jamais le brut EN
  « Unauthenticated. ». Garde 409/429 existantes inchangées (aucun contrôle affaibli).
- **TDD** : `tests/js/posCounterCollect401Feedback.spec.js` (3 tests comportementaux, axios + alertService mockés —
  RED 1 fail → GREEN ; fallback 500 vérifié non régressé).
- **Pour H2** : clé propre `label.encaisser_failed_session_expired`.

## 9. E-ADV-7 [P2] — Panneau « Réconciliation (session en cours) » liée à une autre session → **SKIPPED [BACKEND → H1/owner]**

- **Investigation (component + API)** : le front (`CashOverviewComponent.vue:450`) affiche tel quel
  `payload.cash_session` — UNE session choisie par le BACKEND :
  `CashOverviewController::resolveOpenCashSession` (≈:490-515) = `status OPEN` + branch → **`orderByDesc('opened_at')->first()`**.
  Avec 3 sessions ouvertes simultanément (prouvé DB par le verdict E), le panneau montre la plus récemment OUVERTE,
  pas celle du caissier qui encaisse ; le payload (`:240-248`) n'expose ni user_id ni liste → **fix purement front impossible**.
- **Note pour H1/owner** : (a) backend — préférer la session ouverte du user courant
  (`->orderByRaw('user_id = ? DESC', [$user->id])` ou where user_id first, fallback branche) + exposer
  `opened_by` dans le payload ; (b) produit — arbitrage owner sur N sessions ouvertes simultanées
  (V1 single-box = 1 caisse ; le harness en a créé 3 et le produit l'autorise).

## 10. ADV-F-P2 quick-wins (4/4, ≤10 lignes effectives, commits séparés)

| QW | ID | Fix | SHA |
|---|---|---|---|
| 1 | ADV-F-P2-1 | `'✓ Encaisser'` hardcodé (PosComponent:1285) → `$t('label.pos_shortcut_cash_cta')` (clé existante du jumeau) + `.kiosk-cash-collect-btn` VERT → couleur brand du jumeau `.pos-shortcuts__cta--cash` (une action = un traitement) | `9c93920c0` |
| 2 | ADV-F-P2-8 / B-R1-12 | show caisse : classe `capitalize` retirée sur « Imprimer La Facture » → casse FR naturelle (fr.json déjà correcte) | `00cc81a16` |
| 3 | ADV-F-P2-7 (part) | show caisse : « 2· N° » collé → espaces insécables `&nbsp;·&nbsp;` autour du point médian | `09e0a09ac` |
| 4 | ADV-F-P2-2 | « Clôturer la caisse » (modal Session active consulté en routine) : plein rouge → variante `--danger-outline` ; la CONFIRMATION du flux close garde le plein rouge | `7fccbab3b` |

---

## Tripwire frozen — PAR COMMIT (12/12 CLEAN)

`git diff --stat <sha>~1..<sha> -- <frozen §7 + app/** + resources/js/languages/**>` :

```
34d1e0769 => CLEAN   6d038372d => CLEAN   d377da185 => CLEAN   2e901994e => CLEAN
a56ef8ee8 => CLEAN   c76b7080f => CLEAN   2bef9dfc4 => CLEAN   9c93920c0 => CLEAN
00cc81a16 => CLEAN   09e0a09ac => CLEAN   7fccbab3b => CLEAN   bbb79630d => CLEAN
```
(également vérifié : `PosV5Numpad.vue` — atome partagé — INTACT.)

## Récap tests

- Spec créés : 7 (`posCounterCollectFooterNumpadOverlap`, `posCounterCollect401Feedback`,
  `encaissementQueueDayBadge`, `receiptVariationSeparatorWhitespace`, `receiptHtMarkers`,
  `posOrderShowDeliveryBoyGate`, `transactionsPaymentGateway403Defense`) + 1 sentinel mis à jour
  (`uiuxW2H2HealSentinel` G6 → nouveau canonical).
- Vitest full `tests/js` : **2280 passed / 2 failed** (= `appBundleFreshnessSentinel` +
  `posAppBundleFreshnessSentinel`, bundles 11/06 — verts après le rebuild central) / 3 skipped.
- PHPUnit : non lancé (aucun changement backend ; `.env.testing` vérifié → `foodking_test`, DEVDB-GUARD ok).

## Captures (heal-proofs/)

- `h3-before-queue-no-date-1440x900.png` — file 63 cartes, A0011/A0013/A0014 dupliqués SANS date (avant).
- `h3-p0-before-modal-occlusion-1440x900.png` — modal pré-fix (7 touches interceptées, hit-test joint).
- `h3-p0-after-sim-modal-1440x900.png` — patch simulé : pavé complet sans scroll, CTA visible, 14/14 OK.
- `h3-p0-after-sim-modal-1366x768.png` — idem à 768 (media query), 14/14 OK.
- `h3-before-transactions-bm-403.png` — AxiosError 403 BM (avant), console log joint.

## POST-REBUILD (à exécuter par l'orchestrateur après le rebuild central)

1. Hit-test réel modal encaissement 1440×900 + 1366×768 (pattern `tests/e2e/_d1red-F-design-vision-3.mjs`)
   → attendu `blocked: []` + body sans scroll + CTA visible.
2. `/admin/encaissement` : badges « hier »/« jj/mm » visibles + tri jour-récent-d'abord + chip date dans le modal.
3. `/admin/transactions` (BM) : plus d'AxiosError console (nécessite AUSSI le fix authz H1 pour faire disparaître
   la ligne 403 elle-même).
4. Ticket : « Prix HT », « SOUS-TOTAL HT: », « Poulet mariné, Algérienne » sans espace avant virgule.
5. Les 2 sentinels bundle-freshness repassent verts.
