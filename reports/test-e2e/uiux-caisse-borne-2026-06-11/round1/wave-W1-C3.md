# Wave W1-C3 — Audit UI/UX Gestion Commandes + Historique (2026-06-11)

App `:8768` / DB `foodking_e2e` — viewport 1440×900 fr-FR. Compte audit : `bm.t2admin@lecayenne.fr`
(Branch Manager) car les re-logins des scouts parallèles sur `admin@lecayenne.fr` révoquent
mutuellement les tokens Sanctum (storm de 401 documenté, voir « Conditions d'audit »).
~26 screenshots dans `shots-c3/`. Scripts jetables : `tests/e2e/_w1-c3-*.mjs`.

## Synthèse — 0 P0 · 3 P1 · 6 P2 · 9 P3

---

## P1

**[P1] resources/js/components/admin/posOrders/PosOrderShowComponent.vue:567-575 — Statut « En attente » (et Annulé/Refusé) absent du map d'affichage → badge statut vide + bouton dropdown statut VIDE**
- reproduction: ouvrir `/admin/pos-orders/show/4511` quand la commande est PENDING (status=1).
- evidence: shots `07-show-4511-top.png`, `17-historique-detail-reuse.png` — badge jaune « — » à côté de « Non Payé », 2e dropdown sans libellé. `orderStatusEnumArray` ne mappe que ACCEPT/PREPARING/PREPARED/OUT_FOR_DELIVERY/DELIVERED/RETURNED ; PENDING(1), CANCELED(16), REJECTED(19) manquent. Le composant LISTE a déjà reçu le fix complet (`PosOrderListComponent.vue:250-262`, tag FP-25) mais pas le SHOW.
- recommendation: aligner le map du show sur le FP-25 de la liste (ajouter pending/canceled/rejected).

**[P1] PosOrderListComponent.vue:63-70 + HistoriqueListComponent.vue:77-84 — Datepicker en ANGLAIS (« Jun », « Mo Tu We Th Fr Sa Su »)**
- reproduction: ouvrir le filtre date sur `/admin/pos-orders` ou `/admin/historique`.
- evidence: shot `26-datepicker-clip.png` + `14-historique-datepicker.png` ; dump DOM « Jun | 2026 | Mo | Tu | We… ». Aucune prop `locale` passée à `@vuepic/vue-datepicker` (défaut en-US) — grep des 2 fichiers : seuls `uid/name/hideInputIcon/autoApply/range/preset-ranges` sont passés.
- recommendation: `:locale="'fr'"` (+ `:day-names`/`format` FR) sur les 2 Datepicker — surface admin mandatée FR (CLAUDE.md §3bis).

**[P1] resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:759-779 — Le tracker « Suivi commandes » est borné à AUJOURD'HUI : les commandes actives d'hier disparaissent du kanban**
- reproduction: DB contient 6 commandes actives du 10-06 (status 4/7 : 4493-4511, requête `SELECT id,status FROM orders WHERE status IN (1,4,7)`). Ouvrir `/admin/pos-orders-tracker` le 11-06 → « 0 actives », 5 colonnes vides. Mutation live `change-status/4511` 1→4 (HTTP 200) → toujours invisible après 10 s.
- evidence: shots `20-tracker-before-mutation.png`, `21-tracker-after-mutation-10s.png` ; `fetchOrders()` force `from_date/to_date = _todayRange()`. Une commande encaissée à 23h55 encore en préparation à 00h05 disparaît du suivi caissier (elle reste seulement dans la liste Commandes Caisse).
- recommendation: inclure les commandes encore actives quel que soit leur jour (ex. fenêtre glissante 24-48 h sur les statuts non terminaux), garder le filtre jour pour la colonne « Livrés ».

## P2

**[P2] vue-select ignore la prop `id` → labels de filtres orphelins (a11y)** — `PosOrderListComponent.vue:34,53` (`#searchStatus`, `#user_id`), `HistoriqueListComponent.vue:45-48,53,66` (`#searchOrigin`, `#searchStatus`, `#searchPayment`).
- reproduction/evidence: DOM live rend `id="vs53-combobox"` (probe `_w1-c3-probe.mjs`) ; `label for="searchOrigin"` ne référence aucun élément. Violation DESIGN_SYSTEM_POLICY §3 (« tout input a un label for associé »). C'est aussi ce qui casse les sélecteurs E2E par id.
- recommendation: poser `aria-label` sur le composant ou wrapper le vue-select dans le label.

**[P2] Commandes borne : le CLIENT affiché est « Admin Le Cayenne » (+ email/tél admin en « Informations Client »)**
- reproduction: `/admin/historique` filtre Origine=Borne ; détail `/admin/pos-orders/show/4225`.
- evidence: shots `24-historique-filter-borne.png`, `07c-show-kiosk-paid-4225-top.png` ; DB : commandes kiosk `user_id=1` (compte machine borne) — `SELECT user_id FROM orders WHERE order_serial_no='1006264499'` → 1. Le client anonyme borne apparaît comme l'admin avec ses coordonnées. Famille du gap connu operator-identity (ReceiptDataService).
- recommendation: afficher « Client passage » / nom kiosk quand `source_surface='kiosk'`, jamais le user d'auth machine.

**[P2 DATA] Ticket NF525 : ligne de ventilation « VAT (10%) » en anglais** — `PosOrderReceiptComponent.vue:117-122` imprime `line.tax_name` brut ; DB `taxes.name` = `VAT`/`GST`/`No-VAT`.
- evidence: shot `28-receipt-4510.png` (« VAT (10%)· Base HT 7,73 € ») ; `SELECT name FROM taxes` confirmé.
- recommendation: DATA-only — renommer la taxe en « TVA » (gate owner données prod).

**[P2] « Détails Commande » : zone blanche brute si commande sans articles** — `PosOrderShowComponent.vue:212-213` (`v-if="orderItems.length>0"` sans branche vide).
- evidence: shot `07-show-4511-top.png` — carte vide sans message (checklist DESIGN_REFERENCES §3-18 : jamais de zone blanche brute).
- recommendation: ajouter un état vide FR (« Aucun article »).

**[P2] Rendu variations : « Poulet Mariné: , Algérienne: » (deux-points/virgule orphelins)** — `PosOrderShowComponent.vue:224-229` affiche `variation_name: name` alors que les données composer mettent la valeur dans `variation_name` et laissent `name` vide.
- evidence: shot `07c-show-pos-cash-4510-top.png` ; ligne « Instruction: TACOS Viandes : +Poulet mariné ↳ Sauce frites… » redondante en dessous.
- recommendation: masquer le `:` quand `name` vide ; dédupliquer avec la ligne Instruction.

**[P2] Libellés de statut incohérents : « Accepter » (verbe) comme état + genres mélangés** — `languages/fr.json:868` (`"accept": "Accepter"`), options « Préparée/Refusée/Retournée » (fém.) vs « Livré/Annulé » (masc.).
- evidence: dump dropdown statut (`27`) + badges liste (`01`, `22-pos-orders-after-mutation.png` : statut « Accepter »).
- recommendation: « Acceptée / En préparation / Préparée / Livrée / Annulée / Refusée » — accorder sur « commande ».

## P3

1. **Presets datepicker dupliqués « Cette année » ×2** — `PosOrderListComponent.vue:229-235` (+ même setup HistoriqueListComponent) ; visible shot `26`.
2. **Titre d'impression de la liste Caisse = « menu.online_orders »** — `PosOrderListComponent.vue:272-275` (`popTitle: $t("menu.online_orders")`) : la popup d'impression des commandes CAISSE s'intitule « Commandes en ligne ».
3. **Page active pagination en BLEU** (`bg-blue-50/border-blue-500`) — `PaginationBox.vue:25-29` ; hors palette Cayenne `#F4501E` (incohérence DS, shots `01`/`06b`).
4. **Badge type « POS » vs « Caisse »** — liste Commandes Caisse affiche « POS » (`label.pos`) là où l'historique dit « Caisse » (shot `06b-pos-orders-page2.png`) ; terminologie à unifier.
5. **Aucun tri par colonne** — en-têtes non cliquables, tri fixe `id desc` (les deux listes).
6. **`alt="Not Found"` EN** sur l'image d'état vide — `PosOrderListComponent.vue:154-155`.
7. **« Référence interne: 1 »** affichée (token technique sans valeur) — shot `07c-…4510-top.png` ; le garde anti-bruit `displayedToken` (PosOrderShowComponent:63) laisse passer « 1 ».
8. **Breadcrumb « Commandes Caisse / Voir » en venant d'Historique** (réutilisation assumée de la page show, `historiqueRoutes.js:5-7`) — léger désorientement (shot `17`).
9. **Ticket : prix de ligne en HT (« Tacos 7,73 € » pour un total TTC 8,50 €)** — présentation HT+taxes cohérente mais inhabituelle pour un ticket B2C FR (TTC attendu) ; à confirmer owner/NF525 (shot `28`).

## ✅ Validations (vert)

- **Montants/dates FR partout** : `7,50 €`, `23:54, 10-06-2026` (liste, historique, détail, ticket).
- **Empty state liste** : illustration + « Aucune donnée disponible. » (shot `25`).
- **Pagination** : pages 2/3 fonctionnelles, « Affichage de 21 à 30 sur 60 entrées », Précédent/Suivant FR (shots `06b-*`).
- **Recherche N° commande** : filtre + résultat vide corrects.
- **Historique** : colonnes riches (Origine badgée Borne/Caisse/Livraison/En ligne, N° fiscal, paiement Payé/Non payé/À encaisser), chips de filtre actifs (« FILTRER : Origine : Borne ✕ Effacer », shot `24`), filtre origine fonctionnel, détail réutilise la page show (`/admin/pos-orders/show/4511`).
- **Exports** : `GET /api/admin/order-history/export` et `/api/admin/pos-order/export` → 200 XLSX (params UI requis, sinon 422/400).
- **Boutons détail** : dropdown paiement, dropdown statut (transitions gardées : revert 4→1 rejeté 422 avec message FR « Transition de statut invalide »), « Imprimer La Facture » (v-print `#print`), « 💸 Rembourser » visible uniquement sur commande payée (4225/4510) — NF525 mirror via modal, RETURNED retiré du sélecteur (V101-02).
- **Ticket (PosOrderReceiptComponent)** : SIRET/TVA intra/Opérateur « Caissier Le Cayenne »/N° ticket NF525 2166/empreinte audit/rendu monnaie ; **duplicata NF525 prouvé** : `POST /api/admin/pos/orders/4510/print-receipt` ×2 → `receipt_print_count 1→2`, `is_duplicata:true`, `audit_emitted:true`, marqueur rouge « DUPLICATA #1 » rendu (shot `28`). Route grep-confirmée `routes/api.php:933` (gate `permission:pos` + idempotency).
- **Tracker** : kanban 5 colonnes lisible, compteurs, états vides FR illustrés, onglets Toutes/Caisse/Borne/En ligne, palette conforme (shot `20`).

## Conditions d'audit / limites

- **Token war** : les scouts parallèles se reconnectent tous en `admin@lecayenne.fr` ; chaque relogin révoque les tokens précédents (politique Sanctum) → storms de 401/redirect login pendant toute la session (console pleine d'AxiosError 401 non-UI). Contourné en auditant sous `bm.t2admin`. Recos future vague : 1 compte par scout.
- **Update temps réel du tracker non prouvé positivement** : aucune commande du jour en DB ; la mutation testée (commande d'hier) est masquée par le filtre jour (cf. P1 tracker). Encaissement complet via /admin/pos non rejoué (hors budget après les 401).
- Page 2 historique : capture polluée par un bounce 401 (`16` montre le dashboard) ; pagination validée sur Commandes Caisse (même composant).
- Incident disque plein (98 %) mi-session : navigateurs Playwright manquants → bascule `channel: 'chrome'` (Chrome système), aucun fichier projet supprimé.
