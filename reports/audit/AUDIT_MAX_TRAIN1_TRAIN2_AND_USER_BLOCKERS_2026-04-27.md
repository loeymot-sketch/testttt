# Audit max Train 1 + Train 2 + blocages utilisateur - 2026-04-27

## Verdict court

VERDICT GLOBAL: PASS AVEC RESERVES DOCUMENTEES.

Train 1 / D-M13 est valide et clos cote code teste: unicite queue par branche + business_date, suppression du fallback microtime actif, parite POS/Kiosk, verrouillage route admin kiosk.

Train 2 est valide uniquement sur les lots deja executes: PH2-01 data ownership, PH2-02 catalog events/snapshot coverage, PH2-03 projection parity. Train 2 n'est pas complet: consumer migration PH2-04, dashboard stock/catalog complet, stock_levels atomique et dashboard UX restent a faire dans une mission dediee.

Les blocages utilisateur critiques traites dans cette passe sont: borne client verrouillee sans admin/maintenance, bruit "connexion perdue" supprime sur POS/kiosk, POS sans Client ID manuel, livraison calculee par distance selon la regle FoodKing V1, filtrage des branches actives pour masquer les branches demo/inactives.

## Demandes utilisateur couvertes

### 1. Borne client verrouillee

Implementation:
- `resources/js/router/modules/kioskRoutes.js`: suppression du composant admin kiosk chargeable et redirection `/kiosk/admin` vers `kiosk.idle`.
- `resources/js/components/frontend/kiosk/KioskLoginComponent.vue`: suppression du mode maintenance sessionStorage et du bouton de sortie maintenance.
- `app/Http/Resources/SettingResource.php`: `kiosk_admin_pin` renvoie toujours `null`.
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`: la borne n'affiche plus le banner terminal session_invalid.

Controle:
- Un ancien flag navigateur `sessionStorage.kiosk_maintenance_mode=1` ne bloque plus l'auto-login borne.
- Le test `tests/js/KioskLogin.spec.js` couvre ce contrat.
- Les tests securite existants confirment que le kiosk token ne peut pas acceder admin.

Limite:
- Si l'utilisateur ouvre volontairement `/admin/*`, c'est la caisse/backoffice. La borne ne fournit plus de chemin UI ou route kiosk vers admin.

### 2. Connexion perdue / aucune connexion en haut

Implementation:
- `resources/js/components/common/ConnectionStatusBanner.vue`: ajout `suppressSessionInvalid`.
- POS et kiosk utilisent maintenant `<ConnectionStatusBanner suppress-transient suppress-session-invalid />`.

Decision:
- POS/kiosk ne doivent pas afficher un gros banner terminal de connexion perdue quand le contexte client/caisse continue.
- KDS/OSS gardent le comportement strict, car la perte temps reel y est operationnelle.

Limite:
- Ceci corrige le symptome bloquant sur POS/kiosk. Si Pusher/Echo est vraiment mal configure, le flux temps reel KDS/OSS reste a diagnostiquer separement.

### 3. Caisse sans Client ID manuel

Implementation backend:
- `app/Services/Pos/WalkInCustomerResolver.php`: cree/resout un client cache "Client Comptoir".
- `app/Http/Controllers/Admin/PosController.php`: quote et store POS normalisent `customer_id` manquant.
- `app/Http/Requests/PosOrderRequest.php`: `customer_id` devient nullable.

Implementation frontend:
- `resources/js/components/admin/pos/PosComponent.vue`: emporter/caisse utilise le client comptoir automatiquement; livraison cree un client livraison si necessaire.

Controle:
- `tests/Feature/PosWalkInAndDeliveryFeeTest.php` valide quote POS sans `customer_id`.
- `tests/Feature/Pos/QuoteBindingTest.php` reste vert: quote token/signature toujours obligatoire et branche/acteur proteges.

### 4. Livraison par perimetre / distance

Regle appliquee:
- Distance invalide ou negative: 0.
- 0 a 5 km: 5 EUR.
- Au-dessus: 1 EUR par kilometre commence.
- Exemples: 5.00 km = 5 EUR, 5.01 km = 6 EUR, 10.00 km = 10 EUR, 10.01 km = 11 EUR.

Implementation:
- Backend autoritaire: `app/Services/Delivery/DeliveryFeeService.php`.
- POS request normalise `delivery_charge` depuis `delivery_distance_km`.
- Front helper: `resources/js/helpers/deliveryCharge.js`.
- POS conserve `delivery_distance_km` dans la commande et les commandes garees.

Google Maps:
- Si Google renvoie des coordonnees, le POS calcule la distance et envoie `delivery_distance_km`.
- Si l'adresse est saisie librement sans suggestion Google, fallback volontaire: frais livraison minimum 5 EUR, pas de blocage caissier.

### 5. Branches demo / anciennes donnees type "Turcotte, Pagac And Sauer Branch"

Implementation prudente:
- `resources/js/components/layouts/backend/BackendNavbarComponent.vue`: le selecteur de branche demande uniquement les branches `ACTIVE`.

Decision:
- Je n'ai pas supprime physiquement les donnees demo/inactives. C'est volontaire: suppression DB = action destructive et risque de casser l'historique, les tests, les audits ou des relations.
- L'audit existant `reports/audit/LEGACY_CLEANUP_AUDIT_2026-04-27.md` montre que le probleme observe est une branche de base locale/factory, pas une langue Bangladesh dans l'UI.

Controle:
- `php artisan test` fait tourner `MenuSeederTest`: menu FoodKing francais cree avec 13 categories, 63 items, 772 variations, 530 extras, 180 addons.
- Warning seeder existant: "Sandwich", "Burger", "Salad" sont detectes comme mots anglais meme dans des noms acceptables type "Nos Burgers". Ce n'est pas un echec fonctionnel.

## Audit Train 1

Sources verifiees:
- `reports/audit/TRAIN_A_D13_BUSINESS_DAY_FINAL_2026-04-27.md`
- `docs/decisions/D-M13-QUEUE-NUMBER-UNIQUE.md`
- `docs/runbooks/D-M13-QUEUE-NUMBER-ROLLOUT.md`
- tests `QueueNumberConcurrencyTest` et `QueueNumberUniquenessSentinelTest`

Points valides:
- Queue unique au niveau DB par `(branch_id, business_date, queue_number)`.
- Meme numero possible sur deux business_date differentes.
- Meme numero possible sur deux branches differentes.
- `queue_number` null reste permis pour anciennes lignes.
- POS et kiosk passent par le meme scope branche + business_date.
- Le fallback microtime n'est plus dans le chemin actif.
- La borne n'a plus de composant admin client.

Validation actuelle:
- `tests/Feature/QueueNumberConcurrencyTest.php`: PASS.
- `tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php`: PASS.

## Audit Train 2

Sources verifiees:
- `reports/audit/PHASE2_DATA_OWNERSHIP_MATRIX_2026-04-27.md`
- `docs/decisions/D-PH2-DATA-OWNERSHIP.md`
- `reports/audit/CV2_PH2_02_MENU_CATALOG_EVENT_SNAPSHOT_COVERAGE_2026-04-27.md`
- `reports/audit/CV2_PH2_03_MENU_PROJECTION_PARITY_SENTINELS_2026-04-27.md`

Points valides:
- Ownership catalogue/pricing/stock/documente.
- Les mutations catalog critiques emettent les evenements snapshot.
- Projection POS/Kiosk garde identite produit, prix backend et disponibilite.
- Les differences POS vs kiosk sont controlees par canal, pas par duplication sauvage.
- Kiosk legacy menu est compare a la projection canonique.

Points pas encore livres:
- PH2-04 consumer migration complete.
- Dashboard catalogue/stock complet type control plane.
- Tables `stock_levels`, `stock_movements`, reconciliation horaire et decrement atomique stock.
- Interface stock avec badge rupture et override staff audite.
- Category branch-scope final ADR + authz dashboard complete.

Conclusion Train 2:
- Le socle synchronisation catalogue est solide pour les lots deja livres.
- Le systeme stock/dashboard global demande encore un train separe, il n'est pas cache comme "fait".

## Validations executees

Commandes passees:
- `php artisan test`: PASS, 1107 tests passed, 8 skipped, 156.49s.
- `npx vitest run`: PASS, 127 test files passed, 870 tests passed.
- `npm run production`: PASS, Laravel Mix compiled successfully en 16.73s.
- `curl -I http://127.0.0.1:8000/kiosk/idle`: PASS, HTTP 200.
- `curl -s http://127.0.0.1:8000/mix-manifest.json`: PASS, assets versionnes servis (`kiosk-shell`, `pos-app`, `pos-shell`, etc.).
- `php artisan test tests/Feature/PosWalkInAndDeliveryFeeTest.php`: PASS.
- `php artisan test tests/Feature/Pos/QuoteBindingTest.php`: PASS.
- `php artisan test tests/Feature/PosOrderRequestNullableTotalTest.php`: PASS.
- `npx vitest run tests/js/KioskLogin.spec.js tests/js/userReportedBlockersRuntime.spec.js tests/js/kioskPerfChunks.spec.js`: PASS.
- `git diff --check` sur le perimetre touche: PASS.

Warnings non bloquants observes:
- Vitest affiche des warnings happy-dom reseau `localhost:3000` et quelques mocks Vuex incomplets dans des tests existants. Tous les tests passent.
- PHPUnit skippe 8 tests attendus sur SQLite/MySQL, documentes par les messages de skip.

## Safety hook

`bash .cursor/hooks/safety-check.sh` echoue encore avec:

`[HALT] Frozen zone staged: app/Services/OrderService.php - gate clearance required.`

Ce blocage est preexistant dans l'arbre git sale. Dans cette passe, je n'ai pas modifie `app/Services/OrderService.php`.

Impact:
- Les correctifs utilisateur ont ete faits sans toucher cette zone frozen.
- Pour clore un cycle gouverne strict, il faudra soit enregistrer le gate correspondant, soit nettoyer/stager separement les changements OrderService deja presents.

## Risques restants

1. Worktree tres sale: beaucoup de fichiers modifies/non suivis ne viennent pas de cette passe. Aucun revert n'a ete fait.
2. Dashboard complet produit/categorie/stock: pas livre dans cette passe. Le CRUD admin existe, mais pas encore le control plane stock/categorie complet du plan Claude.
3. Stock atomique central: pas encore en place sous forme `stock_levels` + `stock_movements`. L'existant couvre disponibilite et release stock, mais pas tout le modele cible.
4. Google Maps: le fallback est robuste, mais un test navigateur reel avec cle Google Maps doit confirmer geocoding/distance dans l'environnement final.
5. Purge demo/langues: non destructive seulement. Une vraie purge doit etre une mission data-cleanup avec backup et allowlist DB.

## Verdict final detaille

Train 1: PASS.

Train 2: PASS sur PH2-01, PH2-02, PH2-03. NON TERMINE sur PH2-04 et dashboard/stock control plane.

Demandes utilisateur critiques: les blocages immediats sont corriges au maximum sans toucher la zone frozen `OrderService`.

Release technique locale: les tests globaux et build production passent.
