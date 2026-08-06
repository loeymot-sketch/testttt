# AUDIT A — STRUCTURE & PAGES du système de gestion caisse
**SHA** : `a13e1e65672c9214a515fa6fd3a7e48a5abc4e4e` (branch `pos/category-first-caisse-2026-06-23`) — 2026-08-06, lecture seule.
**Méthode** : inventaire routes (`routes/web.php`, `routes/api.php` 1718 l., 39 modules `resources/js/router/modules/`) croisé avec la nav réelle (`BackendMenuComponent.vue` V1_PRIMARY + menus DB `MenuTableSeeder.php` + Accès rapides `DashboardComponent.vue:150-180` + `CaisseSecondaryNav.vue`), puis navigation réelle sur `http://127.0.0.1:8000` : 29 URLs × 2 comptes (pos@lecayenne.fr, admin@lecayenne.fr) + `/carnet` + `/m` = 60 captures + 3 JSON dans ce dossier.

## État global
Le cœur caisse est **sain et bien maillé** : POS → À encaisser/Encaissement/Suivi/Historique/Écran client via `CaisseSecondaryNav` ; dashboard riche (12 accès rapides, PDF clôture du jour) ; pass admin = 29/29 pages rendues, **0 erreur console** (`report-admin.json`) ; 404 SPA propre ; mini-apps PIN Carnet + Stock mobile opérationnelles ; gating par permission POS correct (redirect dashboard + toast). Les trous sont des **surfaces de consultation manquantes ou orphelines** autour de ce cœur.

---

## P1 — manques majeurs

### P1-1 · Rapports Z (NF525) : API complète, AUCUNE page de consultation
- **Preuve** : `routes/api.php:1398-1408` — `admin/fiscal/z-report` index/open/close/`{z}`/`{z}/pdf` + `admin/fiscal/x-report`. Unique consommateur UI : `resources/js/components/admin/dashboard/LastZReportWidget.vue:96` (GET index, dernier Z seulement) dont le lien « voir plus » pointe vers… `admin.transactions.list` (`LastZReportWidget.vue:24-27`). `grep -r "z-report\|x-report" resources/js` (hors languages) = **zéro** appel à show/pdf/x-report.
- **Impact** : l'owner ne peut ni lister ses Z signés, ni ouvrir un détail, ni télécharger le PDF (conservation/présentation NF525), ni tirer un X intra-journée — sauf curl. Ouverture/clôture automatisées (`app/Console/Kernel.php:482,528`), la consultation, elle, n'existe pas.
- **Reco scope-minimal** : page `/admin/fiscal/z-reports` (liste paginée + bouton PDF + panneau X du jour) consommant l'API existante ; lien depuis LastZReportWidget.

### P1-2 · Imprimantes : CRUD API + service JS complet… jamais branché
- **Preuve** : `routes/api.php:1111-1119` — CRUD `admin/printers` + `POST {printer}/test-print`. `resources/js/services/posPrinter.js:12-41` implémente list/create/update/delete/testPrint — **importé par zéro fichier** (`grep -rl posPrinter resources/js` = le service seul). Aucune route SPA, aucune entrée nav. Création réelle : artisan `SetupReceiptPrinterCommand` uniquement.
- **Impact** : imprimante en panne/IP changée en plein service → aucune action possible sans terminal ; le « test-print » n'est cliquable nulle part. Modèle `Printer` pourtant BranchScope-sentinellé.
- **Reco** : mini-page « Imprimantes » dans réglages branchée sur `posPrinter.js` (déjà écrit) ; sinon assumer le CLI et supprimer le service mort.

---

## P2 — pages orphelines / promesses non tenues

### P2-1 · Observability/outbox : page critique atteignable par personne
- **Preuve** : route `resources/js/router/modules/observabilityRoutes.js` (`/admin/observability` → redirect outbox). Absente de V1_PRIMARY (`BackendMenuComponent.vue:100-123`), du menu DB (`MenuTableSeeder.php`), des Accès rapides (`DashboardComponent.vue:150-180`) ; `grep -rn observability components/` hors du dossier lui-même = 0 lien. Capture `admin-observability-outbox.png` : la page affichait **queue:work DOWN (14 h) + websockets DOWN + 10 565 événements en attente** — alarmes que personne ne voit faute de lien (env local, mais le point structurel vaut en prod).
- **Reco** : entrée sidebar admin-only ou lien depuis la pastille santé POS (`/admin/pos/system-health` existe déjà, `routes/api.php:968`).

### P2-2 · Réglages TPE : page fonctionnelle mais orpheline
- **Preuve** : route `settingRoutes.js:551` + `PaymentTerminalsComponent.vue` (rendue OK, capture `admin-settings-payment-terminals.png`) ; le sous-menu réglages `settings/MenuComponent.vue:9-113` n'a **aucune** entrée `admin.settings.paymentTerminals` (visible sur la capture : 7 onglets, pas de TPE). Accessible uniquement par URL directe.
- **Reco** : 1 router-link dans MenuComponent.vue (miroir « Bornes » ligne 21).

### P2-3 · Repas/pertes (stock_outflows) : registre append-only sans page de consultation
- **Preuve** : écriture via modale POS (`PosStockOutflowModal.vue`) ; lecture unique = `GET admin/pos/stock-outflow/recent` (`routes/api.php:976`) limitée aux **50 derniers** (`app/Http/Controllers/Admin/PosStockOutflowController.php:94-99` `->limit(50)`), sans filtre date, ni total période, ni export ; aucun index paginé côté API.
- **Impact** : un registre à valeur justificative (pertes, repas personnel) invisible au-delà de 50 lignes.
- **Reco** : onglet « Pertes/Repas » dans Historique (ou filtre) + endpoint index paginé par dates.

### P2-4 · Vue Caisse Unifiée : « (à venir) » affiché en prod, écart tiroir non calculable
- **Preuve** : capture `admin-cash-overview.png` — « Pour calculer l'écart, saisir le comptage physique du tiroir (à venir). » ; chaîne `cash_drawer_count_pending_note` `resources/js/languages/fr.json:1232`, rendue par `CashOverviewComponent.vue`. L'API reconcile existe pourtant (`routes/api.php:1099` `cash-drawer/sessions/{session}/reconcile`).
- **Reco** : champ comptage → reconcile existant, ou retirer la phrase (promesse morte).

---

## P3 — frictions mineures / dettes assumées

- **P3-1 Rôles & fidélité masqués V1** : `resources/js/config/v1-hidden-modules.js:24-46` masque `settings.role`, `settings.permission`, `settings.loyalty-setup` — pages vivantes (rendues OK par URL directe, `report-admin.json` settings-roles-hidden len=1253) mais inatteignables via nav, alors que la **fidélité est active** en caisse (redeem-loyalty) / borne / web. Intentionnel documenté ; à réévaluer (au minimum ré-exposer loyalty-setup).
- **P3-2 Floorplan : fixture + i18n EN** : capture `admin-pos-floorplan.png` — table « ABUSE-T1 », « 0 seats », « 1 tables » (chaînes non traduites) ; gestion dining-tables V1-hidden → nettoyage impossible depuis la nav.
- **P3-3 404 admin habillée vitrine** : `/admin/nonexistent-page-xyz` → NotFoundComponent avec header « Compte » de la vitrine supprimée (capture `admin-spa-404-probe.png`) ; « Retour à l'accueil » → `/` → `/login` (2 sauts).
- **P3-4 Deep-links caissier : cascade 401 → logout** : pass POS (`report-pos.json`) — 4 pages permission-denied redirigent proprement (dashboard + toast), puis dès `/admin/stock/rupture` **toutes** les API → 401 et éjection /login (57 erreurs console). Observé sous navigation automatisée rapide ; à reproduire manuellement — signal que l'intercepteur purge la session sur 401 transitoire.
- **P3-5 Historique : chevauchement colonne ACTION** : capture `admin-historique.png`, en-tête « ACTION » et heures « 12:27 » superposés aux icônes sur certaines lignes (1440px). Cosmétique → relève de l'audit visuel.
- **P3-6 Scan facture sans historique** : API = `POST purchasing/scan` + `POST {document}/validate` uniquement (`routes/api.php:369-377`) ; aucun endpoint/écran listant les documents d'achat validés (la page elle-même est propre, bandeau démo honnête, capture `admin-purchasing-scan.png`).

## Faux positifs écartés (anti-hallucination)
- « Labels bruts » sur outbox (`menu.item_availability_changed`) = **noms d'événements en donnée**, pas d'i18n cassée (capture vérifiée).
- `realtime-report` / `sla-alerts` / `audit-trail` semblaient orphelins en grep direct : consommés via store (`store/modules/dashboard.js:227-251` → `RealtimeReportComponent`, `SlaAlertsComponent`, `AuditTrailComponent` montés dans `DashboardComponent.vue:50-55`). Non retenus.

**Bilan** : 2 P1 · 4 P2 · 6 P3. Toutes les pages nav-atteignables rendent sans erreur console (admin). Les manques sont des surfaces de *consultation* (Z/NF525, imprimantes, pertes, outbox), pas des trous du flux d'encaissement.
