# FoodKing — Registre durci des critiques (re-vérification stricte)

> Chaque critique de l'audit a été rejugé : **3 sceptiques indépendants** (chasse active à une mitigation, ouverture du code obligatoire) + **juge** sur tout doute, puis **preuve de reproduction** pour les survivants.
> Barème : un finding ne survit que si aucune mitigation réelle ne le neutralise sur *tous* les chemins.

> **Bilan : Re-vérifié : 35. Survivants : 33 (94 %). Réfutés : 2 (C12, C18). Confiance survivants : 32 PROUVÉ, 1 PROBABLE (C34). Thèmes : fiscal NF525 = 8 ; prix client = 9 ; paiement/PSP = 3 ; isolation/authz = 6 ; token kiosque=admin = 4 ; installer/secrets = 4. Exploitables sans auth : ≥ 9. Verdict : BLOCK + gate humaine.**

> ⚠️ 5 findings (C01, C02, C24, C35, C38) n'ont pas pu être rejugés ici (échecs de génération structurée de l'outil) — ils restent valides tels que documentés dans les rapports 03/04/05/08/10.

---

## 0. Synthèse

Le durcissement confirme la quasi-totalité de l'audit initial : 33 des 35 critiques survivent au triple contrôle sceptique + juge, dont 32 en PROUVÉ et 1 seule en PROBABLE (C34). Deux findings seulement sont RÉFUTÉS (C12, C18), tous deux par une mitigation réellement effective dans le code, pas par doute — ce qui augmente la crédibilité du registre restant : la passe n'a pas absous par complaisance.

Ce que change cette re-vérification vs l'audit brut : elle élève le niveau de confiance global de « suspecté » à « établi ». Les vulnérabilités ne sont plus des hypothèses de lecture mais des chemins tracés (route → contrôleur → service → mutation). Le tableau de risque est désormais actionnable sans re-instruction.

Le registre dessine six familles cohérentes, non des incidents isolés — signe d'un défaut de doctrine, pas de bugs ponctuels :
1) Intégrité fiscale NF525 rompue : scellement post-Z incomplet (C03/C22), fenêtres mortes entre Z (C04/C33), commandes annulées comptées PAID (C32), annulation KDS sans trace (C40).
2) Prix pilotés par le client sans recalcul serveur : delivery_charge (C05/C06), remises non autorisées sur endpoints non authentifiés QR/table (C10/C20/C25), subtotal falsifiable (C31), remise fantôme POS (C13).
3) Paiement déclaratif : confirmation sans PSP (C11), capture sur commande rejetée (C14), troncature du montant Stripe (C23).
4) Isolation branche/authz : BranchScope désactivé par branch_id=0 (C07), IDOR (C08/C16), /api/admin ouvert à tout token (C09), fuites cross-branch (C21/C29).
5) Kiosque = admin id=1 : ability kiosk:order jamais appliquée en HTTP → prise de contrôle admin (C15/C19/C26).
6) Installer/secrets : garde inefficace (C27), reprise DB non authentifiée (C28), clé de service en clair dans public/ (C30), purge destructrice (C17).

Verdict : BLOCK. Corrélation directe avec les invariants CLAUDE.md (backend source de vérité du prix, isolation branche, transitions d'état contrôlées). Plusieurs findings sont critiques et exploitables sans authentification. Gate humaine requise avant toute mise en production.


---

## 1. Critiques PROUVÉS (33) — reproductibles

> Chaque entrée : préconditions, séquence de reproduction, test de preuve, et une garde vérifiée par les sceptiques avant de conclure que rien ne neutralise le défaut.

### C03 · Scellement post-Z incomplet : seul destroy() est gardé ; changeStatus (override Admin) et changePaymentStatus mutent des commandes fiscalement scellée
`app/Services/OrderService.php:1596` — **✅ PROUVÉ**

**Préconditions :** Auth Sanctum, role Admin (contourne branch_id: OrderService.php:1473,1587) ou staff meme succursale. Commande avec fiscal_sequence_no non nul (alloue en 862) dont created_at tombe dans un ZReport status=STATUS_CLOSED tel que opened_at < created_at <= closed_at (memes bornes que garde destroy 1727-1728). Commande donc deja agregee/signee dans un Z clos. NF525 actif.

**Reproduction :**

```
Commande #1001, branch_id=2, fiscal_sequence_no=57, PAID, created_at dans un Z clos signe (totaux figes). POST /api/admin/pos-order/change-payment-status/1001 {payment_status:1 UNPAID}. changePaymentStatus (1573) n'a AUCUN test de scellement: 1594-1595 font save() -> 200. Le Z avait agrege ce CA via payment_status!=UNPAID (ZReportService:217); repasser UNPAID retire le montant du reel sans toucher le Z fige -> divergence permanente. Variante: POST /change-status/1001 {status:CANCELED,reason:x}: changeStatus (1421) ne teste que ValidStatusTransition et branch (Admin exempte). destroy() renvoie 409 (1730) pour ce meme etat scelle.
```

**Test de preuve :** Feature Laravel: ZReport status=closed encadrant now; commande fiscal_sequence_no=57, PAID, created_at dans la fenetre. actingAs(admin); postJson('/api/admin/pos-order/change-payment-status/'.$id,['payment_status'=>UNPAID]). Correct=assertStatus(409), payment_status inchange; Reel=200 muté. Idem change-status->CANCELED: attendu 409, reel 200.

**Garde vérifiée (3/3 sceptiques → TIENT) :** destroy() 1723-1736 = seul garde seal (ZReport CLOSED+fenetre→409). changeStatus/changePaymentStatus: aucun test ZReport/fiscal_sequence_no. ValidStatusTransition+StateMa


### C04 · Trou de fenêtre entre deux Z : commandes POS numérotées omises de tout rapport fiscal (sous-déclaration CA/TVA)
`app/Services/Fiscal/ZReportService.php:207` — **✅ PROUVÉ**

**Préconditions :** Caissier/manager POS authentifie (Sanctum) avec droit open/close Z (api.php:794-802). fiscal.z_report_secret defini. Les commandes POS creees via POST /pos-order recoivent fiscal_sequence_no + created_at=now() a la creation (OrderService.php:855-865, FiscalSequenceService), sans jamais consulter z_reports : rien n'oblige un encaissement a tomber dans une fenetre Z ouverte.

**Reproduction :**

```
Cause: la borne basse est opened_at DU Z COURANT, pas closed_at du Z precedent. close() passe $open->opened_at comme $from (l.129); aggregate filtre created_at > $from (l.213) et <= closed_at (l.210) -> fenetre (opened_at, closed_at]. Deux Z (O1,C1] puis (O2,C2] avec O2>C1 : l'intervalle (C1,O2] n'est dans aucun Z.
Sequence: 1) open Z1 (O1=10:00); 2) close Z1 a C1=23:00; 3) POST /pos-order a 23:05 -> Order PAID + fiscal_sequence_no; 4) open Z2 lendemain O2=08:00; 5) close Z2. La commande 23:05 est >C1 (hors Z1) et non >O2 (hors Z2) -> dans AUCUN Z. CA/TVA sous-declares. Idem: 1er ticket avant tout open, reouverture, cloture prematuree.
```

**Test de preuve :** PHPUnit RefreshDatabase: clore Z1 a C1; creer Order(PAID, fiscal_sequence_no=N, created_at=C1+5min); aggregate($b, O2=C1+1j, C2). Assert commande absente de z2 ET z1; assertion partition: somme order_count des Z < commandes PAID a fiscal_sequence_no. ZReportBoundaryTest.php:107 masque le bug en posant O2==C1, jamais produit par close() (O2>C1).

**Garde vérifiée (3/3 sceptiques → TIENT) :** Fenetre (from,to] ancree sur opened_at du Z (close L129). Verifie: route throttle sans borne temporelle; controleur delegue; aucune garde 'Z ouvert requis' a la creation 


### C05 · delivery_charge accepté du client sans recalcul ni borne (valeur négative => total réduit)
`app/Services/FrontendOrderService.php:514` — **✅ PROUVÉ**

**Préconditions :** Utilisateur authentifie Sanctum (route order.* auth:sanctum, api.php:846-849), client web/app ou kiosk, AUCUN role privilegie. POST .../order/ -> FrontendOrderController@store -> myOrderStore. order_type=DELIVERY exige order_setup_delivery+address_id ; KIOSK/TAKEAWAY sans condition (delivery_charge nullable). Impact monetaire si paiement en ligne: PaymentService.php:65 amount=order->total.

**Reproduction :**

```
POST .../order/ : order_type=1, branch_id, address_id, delivery_time, is_advance_order=0, source=1, items=[{item reel prix 60}], delivery_charge=-50. OrderRequest.php:40-43 valide delivery_charge 'numeric' SANS min → -50 accepte. FrontendOrderService.php:189-192 n'unset QUE total/subtotal/discount, PAS delivery_charge → create() ligne 194 persiste -50 (fillable FrontendOrder.php:33). Ligne 514: total=max(0, 60+0+(-50)-0)=10 au lieu de 60. PricingService.php:220-221 recopie aussi sans borne. Paiement debite order->total=10 → 10 paye pour 60 de marchandise. delivery_charge=0 elude les frais configures. Le max(0,...) ne borne qu'au plancher, la reduction partielle survit.
```

**Test de preuve :** Feature HTTP: Sanctum::actingAs($user); Item DB a 60. postJson('.../order', [order_type=>DELIVERY, items=>json([{id,quantity:1}]), address_id, delivery_time, is_advance_order=>0, source=>1, branch_id, delivery_charge=>-50]); $o=FrontendOrder::latest()->first(); assertEquals(-50,$o->delivery_charge); assertEquals(10.0,$o->total) (attendu>=60). Variante =0 → assertEquals(60,$o->total).

**Garde vérifiée (3/3 sceptiques → TIENT) :** OrderRequest.php:40 = 'numeric' sans min:0. unset() l.189 retire total/subtotal/discount mais PAS delivery_charge (persiste l.194). PricingService.php:220 passthrough san


### C06 · Frais de livraison (delivery_charge) pilotés par le client, sans borne min:0 ni recalcul serveur — total manipulable jusqu'à ~0
`app/Http/Requests/OrderRequest.php:40` — **✅ PROUVÉ**

**Préconditions :** Client authentifie Sanctum, customer web standard (PAS KioskMachine : FrontendOrderService:158 => null). Route protegee seulement par installed/apiKey/localization/auth:sanctum + throttle (api.php:846/849) : aucun controle de role sur le montant. order_setup_delivery = ENABLE (OrderRequest:79). Config indifferente : SSOT et legacy utilisent la valeur client.

**Reproduction :**

```
POST /api/frontend/order (OrderController::store -> myOrderStore). Corps: order_type=5 DELIVERY, address_id+delivery_time valides, items=JSON item reel, delivery_charge=-50. 1) OrderRequest:40 : DELIVERY => [required,numeric] SANS min:0, -50 valide. 2) FrontendOrderService:192 unset(total,subtotal,discount) mais PAS delivery_charge ; :194 create() persiste -50. 3) :218 PricingRequest::forKiosk recoit -50 ; PricingService:220-222 total=max(0,subtotal+tax-50-discount). 4) :514 total=round(max(0,...),2). Panier <=50 => total ~0, delivery_charge=-50 jamais borne ni recalcule serveur. Commande encaissee sur ~0. Viole invariant #1.
```

**Test de preuve :** Feature: Sanctum::actingAs(customer) ; seed branch+item(20e) ; delivery ENABLE ; postJson('/api/frontend/order',[order_type=>5, address_id, delivery_time, items=>json([item_id,quantity=>1]), delivery_charge=>-50]). Assert 200 (non bloque) + assertDatabaseHas('orders',[delivery_charge=>-50]) + order->total ~0 (<< subtotal+tax). Attendu correctif : -50 rejete 422 ou clampe 0 -> echoue aujourd'hui.

**Garde vérifiée (3/3 sceptiques → TIENT) :** OrderRequest.php:40 = numeric sans min:0. OrderService l.295/569 + FrontendOrderService l.192 unset total/subtotal/discount avant create mais PAS delivery_charge (fillabl


### C07 · Conflation branch_id=0 client/admin : un client authentifié désactive le BranchScope et voit toutes les branches
`app/Models/Scopes/BranchScope.php:33` — **✅ PROUVÉ**

**Préconditions :** Client rôle CUSTOMER créé via signup ⇒ users.branch_id=0 (SignupController:92/CustomerService:68), AUCUNE ligne DefaultAccess. Token Sanctum valide. Header x-api-key=config('app.api_key'), clé globale déjà envoyée par le front (ApiKeyMiddleware:21). Contexte HTTP ⇒ Auth::check()=true. ≥2 branches avec DiningTable. Aucun droit spatie requis: index sans permission:.

**Reproduction :**

```
1) Client authentifié, token Sanctum, branch_id=0. 2) GET /api/admin/dining-table/ avec Bearer + x-api-key. 3) Groupe (routes/api.php:229)=installed,apiKey,auth:sanctum,localization,throttle seul; index (DiningTableController:22-26) sans permission: ⇒ accepté. 4) DiningTableService::list() fait DiningTable::with('branch')->get() sans filtre branch_id (L49-70). 5) BranchScope::apply(): Auth::check()=true, branch()=0 (DefaultAccessModelTrait:21-23) ⇒ return sans where (BranchScope:33-36). Réponse: DiningTable de TOUTES branches (name,size,branch_id,slug,qr_code), alors qu'un staff branche N ne verrait que la sienne.
```

**Test de preuve :** Feature test HTTP (APP_ENV=testing ⇒ runningUnitTests active le scope comme en prod). Seed branche1 table T1, branche2 table T2. Créer User CUSTOMER branch_id=0 sans DefaultAccess, actingAs Sanctum. GET admin/dining-table/ avec x-api-key. Asserter 200 ET collection contient T1 ET T2. Contrôle: staff branch_id=1 ne voit que T1.

**Garde vérifiée (3/3 sceptiques → TIENT) :** branch() (DefaultAccessModelTrait.php:18-21) teste DefaultAccess AVANT le fallback branch_id===0. LoginController et GuestSignupController appellent storeOrUpdate(branch_


### C08 · IDOR : /api/admin/my-order/show/{user}/{order} ne vérifie jamais l'identité de l'appelant
`app/Services/OrderService.php:1286` — **✅ PROUVÉ**

**Préconditions :** Tout token Sanctum valide suffit (client/kiosque/chef/livreur) : la route admin/my-order n'a que auth:sanctum, aucun middleware permission/role, AdminController a un constructeur vide. App 'installed' + en-tete 'apiKey' (clef partagee, non liee a l'identite). L'attaquant connait/enumere le couple {user_id, order_id} victime (entiers auto-increment). La commande visee appartient a l'user_id fourni.

**Reproduction :**

```
1) Attaquant s'authentifie et obtient un token Sanctum. 2) Il vise le couple victime, ex user_id=42 / order_id=1007. 3) GET /api/admin/my-order/show/42/1007, Authorization: Bearer <token_attaquant> + X-Api-Key. 4) Controller passe $user(42) et $order(1007) a OrderService::orderDetails. 5) Ligne 1286 : $order->user_id(42) == $user->id(42) => VRAI (les deux issus de l'URL, jamais compares a Auth::user()->id). 6) Reponse 200 + OrderDetailsResource : transaction (paiement Stripe/PayPal), user (PII/roles), branch, payment_method/status, order_items. Attendu: 403. Obtenu: 200 avec fuite complete cross-compte.
```

**Test de preuve :** Feature test : creer userA (attaquant), userB (victime), $orderB(user_id=userB->id) avec transaction. Sanctum::actingAs($userA) ; $r=$this->getJson('/api/admin/my-order/show/'.$userB->id.'/'.$orderB->id). Attendu securise: $r->assertStatus(403). Aujourd'hui echoue (recoit 200) et data.transaction non nul, prouvant la lecture paiement/PII d'autrui. Miroir actingAs($userB) doit rester 200.

**Garde vérifiée (3/3 sceptiques → TIENT) :** Route api.php:520, groupe admin l.229: mw auth:sanctum seulement, pas de permission/role/can, verify.api absent. Controllers a constructeur vide. Aucune Order policy. Gar


### C09 · Groupe /api/admin protégé par auth:sanctum seul : 12+ contrôleurs sans middleware permission accessibles à tout token
`routes/api.php:229` — **✅ PROUVÉ**

**Préconditions :** Token Sanctum d'un compte NON privilégié (client, kiosque, chef, livreur), aucun rôle/permission spatie. Header partagé x-api-key = config('app.api_key'), embarqué dans tous les clients (ApiKeyMiddleware.php:21-24, pas un privilège). App installée. Groupe admin défini statiquement.

**Reproduction :**

```
routes/api.php:229 : middleware groupe = installed, apiKey, auth:sanctum, localization, throttle — AUCUN permission/role. Garde délégué ad hoc par contrôleur ; 12 l'omettent (Menu, MenuProjection, DefaultAccess, MyOrderDetails...). Preuve : GET /api/admin/menu-projection?channel=kiosk&branch_id=99 (x-api-key + token client). MenuProjectionController (docblock « Read-only admin endpoint ») extends Controller, constructeur = injection seule ; show() valide juste channel+branch_id puis renvoie forChannel() SANS contrôle rôle NI branche → 200, projection de toute branch_id (escalade verticale + fuite inter-branches). Contraste même groupe : CompanyController garde permission:settings → 403.
```

**Test de preuve :** PHPUnit : $u=User::factory()->create() SANS permission ; Sanctum::actingAs($u) ; $this->withHeader('x-api-key',config('app.api_key'))->getJson('/api/admin/menu-projection?channel=kiosk&branch_id=99')->assertStatus(200) prouve la faille (attendu 403). Contrôle positif : même acteur → PATCH /api/admin/setting/company renvoie 403.

**Garde vérifiée (3/3 sceptiques → TIENT) :** api.php:229 = auth:sanctum + apiKey (cle statique partagee), sans middleware permission. Base Controllers constructeurs vides. Permission deleguee par controleur : sur 65


### C10 · Remise manuelle contrôlée par le client sur endpoint table NON authentifié → repas gratuits
`app/Services/OrderService.php:1156` — **✅ PROUVÉ**

**Préconditions :** Aucune auth utilisateur ni rôle POS. POST /api/table/dining-order (routes/api.php:1007), middleware ['installed','apiKey','localization']+throttle, PAS de auth:sanctum. 'apiKey' (ApiKeyMiddleware.php:24) vérifie juste un header statique x-api-key partagé du front QR public. TableOrderRequest::authorize()=true, 'discount'=>nullable,numeric sans borne ni rôle.

**Reproduction :**

```
Client QR récupère branch_id/dining_table_id/customer_id, puis POST /api/table/dining-order (header x-api-key public) avec coupon_id=0, discount=<subtotal>, items existants. Défaut SSOT : discount passé à PricingRequest::forTable (OrderService.php:1010) -> PricingService.php:213 accepte car context 'table' -> DiscountCalculator::manualDiscount (l.28) renvoie $requested tant que <=subtotal. total=max(0, subtotal+tax+delivery-discount) (l.221-222). Legacy : même règle OrderService.php:1156-1163. Donc discount=subtotal accepté sans autorisation ; taxes nulles/incluses + delivery=0 -> total=0 (repas gratuit), sinon reste la seule taxe. Remise « caissier » pilotée par le client non authentifié.
```

**Test de preuve :** Test Feature PHPUnit sans acteur, seul header x-api-key : item price=20 tax nulle, ->withHeader('x-api-key',config('app.api_key'))->postJson('/api/table/dining-order',[...,'coupon_id'=>0,'discount'=>20,items])->assertOk(); assertDatabaseHas('frontend_orders',['discount'=>20.0,'total'=>0.0]). Refaire PRICING_USE_SSOT=false. Unitaire: manualDiscount(20,20)===20.0.

**Garde vérifiée (3/3 sceptiques → TIENT) :** Route api.php:1007 table/dining-order: apiKey seul, pas Sanctum/permission (L1006 'unauthenticated QR'). TableOrderRequest::authorize=true, discount nullable numeric. Pri


### C11 · Confirmation de paiement entièrement déclarée par le client : aucune vérification PSP, aucun contrôle de montant
`app/Http/Controllers/Frontend/OrderController.php:111` — **✅ PROUVÉ**

**Préconditions :** Utilisateur authentifie Sanctum (client borne standard, aucun role requis : route dans groupe order middleware auth:sanctum, api.php:846-852). Une FrontendOrder lui appartenant (seul controle : user_id === auth id, OrderController:94) en payment_status != PAID. Pour promotion KDS : payment_method CARD/TICKET_RESTAURANT + KioskMachine liee au user_id (FrontendOrderService:774-784).

**Reproduction :**

```
Client possede la commande #N (UNPAID). POST /api/frontend/order/{N}/payment-confirm avec son token et corps fabrique {"transaction_id":"FAKE-123","payment_method":3}. paymentConfirm valide seulement le format (OrderController:80-84), verifie auth+ownership, puis ecrit payment_status = PAID et transaction_id = request->transaction_id (111-115). AUCUN appel PSP (ni Stripe retrieve, ni capture PayPal, ni TPE), AUCUN controle de montant. finalizePaidKioskOrder promeut PENDING->ACCEPT (FrontendOrderService:801) et dispatche OrderCreated + KDS (821-825). Commande PAYEE et poussee en cuisine sans encaissement ; le serveur ne peut distinguer un transaction_id reel d'un fabrique.
```

**Test de preuve :** Feature test : Sanctum::actingAs($user) ; FrontendOrder(user_id=$user->id, UNPAID, payment_method=CARD) + KioskMachine(user_id=$user->id) ; postJson('.../payment-confirm',['transaction_id'=>'FAKE-123','payment_method'=>CARD]) ; assertStatus(200) + assertDatabaseHas payment_status=PAID, status=ACCEPT, sans aucun mock PSP appele. Passage a PAID sans appel PSP = preuve.

**Garde vérifiée (3/3 sceptiques → TIENT) :** Route api.php:852=auth:sanctum seul, pas de policy. validate() ne teste que le format du transaction_id; gardes auth+ownership user_id+idempotence lock. finalizePaidKiosk


### C13 · Remise manuelle « fantôme » : après un changement de quantité, form.discount périmé est envoyé et appliqué par le backend (sous-facturation)
`resources/js/components/admin/pos/PosComponent.vue:1446` — **✅ PROUVÉ**

**Préconditions :** Caissier authentifie (Sanctum) avec permission pos (PosController.php:19), ecran POS ouvert, panier non vide. Aucune config speciale : bug sur les 2 chemins de pricing, SSOT par defaut (OrderService.php:604-612) et legacy (773-777) ; manualDiscount (DiscountCalculator.php:22-29) applique le montant client si 0<discount<=subtotal. Etat : remise appliquee PUIS quantite modifiee avant soumission.

**Reproduction :**

```
1) Article 50 EUR. 2) Remise 10%+motif, Appliquer -> applyDiscount (PosComponent.vue:1367) ecrit form.discount="5.00" ET store discount=5 ; UI: remise 5, total 45. 3) Clic + (cartQuantityIncrement:1332) -> posCart/quantity (posCart.js:187-191) commit subtotal=100 ET commit('discount',0) ; UI: remise 0, total 100. Mais form.discount reste "5.00" (jamais reset ; watcher carts ne reset que si panier vide, 1920-1926). 4) Valider -> orderSubmit (1446/1451) POST admin/pos discount:"5.00" ; backend (OrderService:612 -> PricingService:213) applique 5 : order.discount=5, order.total=95. Ecran=100, encaisse=95 : sous-facturation 5 EUR, total affiche != encaisse, motif fantome -> ecart NF525.
```

**Test de preuve :** Vitest store : dispatch('posCart/discount',5) puis dispatch('posCart/quantity',{id:0,status:'increment'}) -> assert getters['posCart/discount']===0. Vitest composant : applyDiscount(), cartQuantityIncrement(0), orderSubmit() (mock modalShow) -> assert form.discount==='5.00' tandis que posDiscount===0. E2E : intercepter POST admin/pos, discount!==0 alors que recap affiche 0.

**Garde vérifiée (3/3 sceptiques → TIENT) :** posCart.js:190 remet à 0 le discount store, pas form.discount. watch carts reset seulement si panier vide. PaymentComponent:217 POST form.discount périmé. PosOrderRequest


### C14 · Paiement carte encaissé sur commande déjà auto-rejetée : argent capturé, commande jamais envoyée en cuisine, réponse "Paiement confirmé" mensongère
`app/Services/FrontendOrderService.php:797` — **✅ PROUVÉ**

**Préconditions :** Commande kiosk carte différé (order_type=KIOSK, payment_method=CARD, KioskMachine liée au user_id), restée PENDING+UNPAID pendant la fenêtre TPE, puis passée à status=REJECTED(19) via transition légale PENDING→REJECTED (rejet caissier ou auto-reject) encore UNPAID. Auth Sanctum = user propriétaire (user_id == frontendOrder.user_id). Carte physiquement approuvée au TPE avant l'appel.

**Reproduction :**

```
Etat : FrontendOrder{status=REJECTED(19), payment_status=UNPAID, KIOSK, CARD}. POST /api/frontend/order/{id}/payment-confirm {transaction_id:"TX", card_type:"VISA", payment_method:CARD}. paymentConfirm (OrderController L106-115) écrit payment_status=PAID + transaction_id (encaissement enregistré). Puis finalizePaidKioskOrder : garde L797 `(int)$locked->status >= ACCEPT` → 19>=4 = true → return, $promoted=false, sans exception, aucun OrderCreated/KDS. Contrôleur : $alreadyPaid=false → L124 sauté → HTTP 200 {status:true,"Paiement confirmé"} (L147). Résultat : carte débitée, commande figée REJECTED, jamais en cuisine, succès mensonger, aucun remboursement.
```

**Test de preuve :** Test PHPUnit feature : FrontendOrder kiosk carte status=REJECTED, payment_status=UNPAID, KioskMachine liée ; auth Sanctum ce user ; postJson payment-confirm. Asserts simultanés : réponse 200 status=true ; DB payment_status=PAID ; DB status toujours=REJECTED ; finalizePaidKioskOrder retourne false ; Event::assertNotDispatched(OrderCreated). Les 5 vrais = encaissement sans contrepartie prouvé.

**Garde vérifiée (3/3 sceptiques → TIENT) :** Route api.php:852 (auth:sanctum seul, aucune policy/statut); paymentConfirm l.77-151 (verifie auth+user_id l.94 uniquement, PAID pose l.111 sans test statut); guard l.797


### C15 · Le token borne est un token de l'utilisateur ADMIN (id=1) ; l'ability 'kiosk:order' n'est JAMAIS appliquée en HTTP → prise de contrôle admin complète
`app/Http/Controllers/Auth/KioskMachineLoginController.php:82` — **✅ PROUVÉ**

**Préconditions :** Seeders exécutés hors production (seeder kiosk bloqué en prod, l.20-23). Borne 'kiosk-lecayenne' active, mdp 'kiosk123', user_id=1. User id=1 = rôle ADMIN avec Permission::all() dont 'settings'. Groupe /api/admin protégé par auth:sanctum seul (api.php:229) ; aucun alias 'abilities' dans Kernel.php. Attaquant détenant un 'kiosk-token' valide.

**Reproduction :**

```
1) POST /api/auth/kiosk-login {username:kiosk-lecayenne, password:kiosk123} -> 201, renvoie token (ability ['kiosk:order'], propriétaire user id=1 admin). 2) Avec Authorization: Bearer <token> : PATCH /api/admin/setting/company (CompanyController::update, permission:settings) corps valide -> 200, la borne modifie les réglages. Idem POST /api/admin/setting/currency, PATCH /api/admin/setting/tax (prix/fiscal), CRUD /api/admin/setting/branch, RoleController. auth:sanctum authentifie id=1 ; permission:settings passe car id=1 a toutes les permissions ; l'ability kiosk:order n'est jamais vérifiée en HTTP -> contrôle admin complet depuis un token de borne.
```

**Test de preuve :** Feature Laravel : seed ; $token=postJson('/api/auth/kiosk-login',['username'=>'kiosk-lecayenne','password'=>'kiosk123'])->json('token') ; assertEquals(['kiosk:order'], PersonalAccessToken::findToken($token)->abilities) ; withHeader('Authorization','Bearer '.$token)->patchJson('/api/admin/setting/company',[payload valide])->assertStatus(200) (403 attendu si l'ability était appliquée).

**Garde vérifiée (3/3 sceptiques → TIENT) :** Kernel.php:61-79: aucun alias abilities/ability (CheckAbilities non cable). api.php:229 groupe admin=auth:sanctum sans scoping. tokenCan('kiosk:order') seulement Frontend


### C16 · IDOR non authentifie sur GET /table/dining-order/show/{frontendOrder} : fuite PII cross-branch par enumeration d'ID
`routes/api.php:1005` — **✅ PROUVÉ**

**Préconditions :** Aucune auth utilisateur. Route api.php:1005 protegee seulement par [installed, apiKey, localization], pas de auth:sanctum. Seul secret : x-api-key == config('app.api_key'), issu de MIX_API_KEY (config/app.php:63) donc compile dans le bundle JS public et extractible par tout visiteur. Etat requis : au moins une commande existante. Aucun role/token/session.

**Reproduction :**

```
1. Visiteur extrait x-api-key du JS compile. 2. GET /api/table/dining-order/show/1 avec ce header. 3. Binding implicite {frontendOrder} sur cle 'id' (pas de getRouteKeyName/:slug) -> FrontendOrder::find(1). 4. BranchScope::apply ne filtre que si Auth::check() vrai ; requete non authentifiee -> no-op -> commande de n'importe quelle branche renvoyee sans controle branch_id. 5. OrderDetailsResource expose PII : user name/phone/email/balance + order_address adresse/lat/long + montants + branch. 6. Iterer id=1..N exfiltre toutes les commandes de toutes branches. Aucune verif de propriete/branche entre binding et reponse.
```

**Test de preuve :** Feature test : creer BranchA(1), BranchB(2), un User (phone/email/balance) et une FrontendOrder dans BranchB avec OrderAddress. SANS actingAs(), getJson('/api/table/dining-order/show/'.$order->id, ['x-api-key'=>config('app.api_key')]). Asserter 200 puis assertJsonPath('user.email') et 'order_address.address'. Fuite PII cross-branch sans auth = preuve de l'IDOR et du no-op de BranchScope.

**Garde vérifiée (3/3 sceptiques → TIENT) :** Route L1005: middleware apiKey (cle statique, aucun user auth), pas d'auth:sanctum. Controleur Table\OrderController::show (alias L89): binding par PK, retour OrderDetail


### C17 · Migration "emergency purge" destructrice dans le chemin standard : TRUNCATE inconditionnel de tout le menu, cross-branch, irréversible
`database/migrations/2026_03_11_999999_emergency_purge_english_menu.php:74` — **✅ PROUVÉ**

**Préconditions :** Aucun rôle/auth/HTTP : migration de schéma. Précondition unique : DB où la ligne `2026_03_11_999999_emergency_purge_english_menu` est ABSENTE de la table `migrations` (nouveau tenant depuis dump, env recréé, migrate:fresh). Fichier dans `database/migrations/` → collecté par tout `php artisan migrate`. Rien ne le désactive : `environment('testing')` l.20 ne pilote que les echo, pas la purge.

**Reproduction :**

```
1) DB avec catalogue peuplé pour plusieurs branches (catalogue global ; scoping via item_branch_availability(item_id,branch_id), migr. 2026_04_15). 2) `php artisan migrate`. 3) Purge inconditionnelle : la détection English l.40-49 ne fait que COMPTER/afficher, ne conditionne rien. 4) l.57 SET FOREIGN_KEY_CHECKS=0, puis boucle l.72-83 `DB::table($t)->truncate()` sur item_addons, item_extras, item_variations, item_attributes, items, item_categories → items et item_categories à 0 pour TOUTES les branches ; item_branch_availability non truncatée → lignes orphelines (FK off). `down()` l.114-119 n'affiche qu'un message, ne restaure rien → irréversible.
```

**Test de preuve :** PHPUnit : insérer 2 branches + items/item_categories pour A et B, aucun item « English » ; retirer la ligne de la migration de `migrations`. Act : Artisan::call('migrate',['--force'=>true]). Assert : assertDatabaseCount('items',0) et ('item_categories',0) → purge cross-branch inconditionnelle. Puis migrate:rollback → items toujours 0 (down() irréversible).

**Garde vérifiée (3/3 sceptiques → TIENT) :** Lu L1-120 : env L20 ne gate que l'echo ; $englishCount L40-49 jamais utilisé en condition ; truncate L72-83 inconditionnel ; FK_CHECKS=0 L57 ; aucun filtre branch_id/WHER


### C19 · Le token borne « kiosk:order » est émis sur l'utilisateur ADMIN — accès total à /api/admin/* alors que la doc jure un blocage natif Sanctum
`app/Http/Controllers/Auth/KioskMachineLoginController.php:83` — **✅ PROUVÉ**

**Préconditions :** Seeders executes (bloques en prod). KioskMachine ACTIVE, user_id=1 = User role 'admin' ACTIVE (KioskMachineTableSeeder:33 + UserTableSeeder:43). Gate::before(admin=>true) actif (AuthServiceProvider:30). Aucun middleware abilities/ability sur les routes. Attaquant : juste username/mot de passe de borne, expose physiquement. Faille structurelle independante du mot de passe faible.

**Reproduction :**

```
1) POST /api/auth/kiosk-login {username:"kiosk-lecayenne", password:"kiosk123"} (DEMO: mirpur1/123456) -> 201 {token}. Token emis sur User id=1 admin, abilities ['kiosk:order'] (KioskMachineLoginController:83-87). 2) header Authorization: Bearer <token> : POST /api/admin/fiscal/z-report/close -> 200 (cloture Z NF525) au lieu du 403 promis (AUTHZ_MATRIX:17). Idem GET/PUT /api/admin/setting/payment-gateway (api.php:362-363) lit/ecrase cles Stripe/PayPal ; DELETE order ; branch cross-branche. Groupe admin (api.php:229) = auth:sanctum seul ; Gate::before accorde tout au role admin ; l'ability kiosk:order n'est jamais evaluee en HTTP (uniquement channels.php:27).
```

**Test de preuve :** Feature test : User role admin + KioskMachine ACTIVE(bcrypt('kiosk123')). $t=postJson('/api/auth/kiosk-login',[...])['token']. withToken($t)->postJson('/api/admin/fiscal/z-report/close')->assertStatus(403) ET withToken($t)->getJson('/api/admin/setting/payment-gateway')->assertStatus(403). Verifier abilities===['kiosk:order']. Echoue aujourd'hui (200) => escalade prouvee.

**Garde vérifiée (3/3 sceptiques → TIENT) :** Kernel: aucun middleware abilities/ability. Grep app+routes: 0 tokenCan. Groupe admin api.php:229 = auth:sanctum seul (ni role/permission/can). AuthServiceProvider:30 Gat


### C20 · Remise manuelle pilotée par le client, sans autorisation, sur l'endpoint TABLE non authentifié (QR)
`app/Http/Requests/TableOrderRequest.php:36` — **✅ PROUVÉ**

**Préconditions :** Aucune auth utilisateur (Sanctum) : seul l'apiKey publique de l'app (middleware 'apiKey' du groupe 'table') suffit, disponible via le QR. Type de commande activé (order_setup) et items existant réellement en base pour la branche. Vulnérable que PRICING_USE_SSOT soit true (défaut) ou false. Aucun rôle caissier/permission vérifié.

**Reproduction :**

```
1) Client scanne le QR -> apiKey publique + branch_id, dining_table_id, customer_id guest. 2) POST /api/table/dining-order/ avec items=JSON d'articles réels (sous-total réel S) et discount=S. 3) tableOrderStore (OrderService.php:984) ignore subtotal/total/discount du payload mais repasse (float)$request->discount a PricingRequest::forTable (l.1010). 4) PricingService.php:213-217 (contexte 'table') -> DiscountCalculator::manualDiscount l.28 : requested<=subtotal ? requested:0 -> discount=S. 5) total = S+tax+delivery-S = tax+delivery (l.221). Commande PENDING, remise 100% non tracée a un caissier. Chemin legacy identique OrderService.php:1156-1161.
```

**Test de preuve :** Test feature PHPUnit sans acteur authentifié : POST route table.dining-order (header apiKey), items = article dont le prix DB donne sous-total S, discount=S. Assert : 200 ; FrontendOrder.discount==S ; FrontendOrder.total==total_tax+delivery_charge. Test unitaire : DiscountCalculator::manualDiscount(100.0,100.0)===100.0. Rejouer avec PRICING_USE_SSOT=false pour le chemin legacy.

**Garde vérifiée (3/3 sceptiques → TIENT) :** authorize()=>true; discount nullable|numeric sans borne. Route POST dining-order api.php:1007, groupe table middleware [installed,apiKey,localization] l.992: pas d'auth n


### C21 · Fuite financière cross-branch : liste/export des transactions non scopés par branche (accessibles au Branch Manager)
`app/Services/TransactionService.php:33` — **✅ PROUVÉ**

**Préconditions :** Rôle Branch Manager (staff, branch_id non nul, ex. 5), authentifié via Sanctum, possédant la permission `transactions` (attribuée par RolePermissionTableSeeder.php:75). Base contenant des transactions rattachées à des commandes de plusieurs branches (ex. 5 et 7). Le modèle Transaction n'a aucun BranchScope, seul Order en a un.

**Reproduction :**

```
Auth Branch Manager branche 5. Appeler `GET /api/transaction/` (ou `/export`) SANS `branch_id`. Dans TransactionService::list:33 le bloc `if(isset($requests['branch_id']))` est sauté → `Transaction::with('order')->where(...)` reste non scopé (Transaction sans BranchScope) → toutes les transactions de toutes les branches sortent. Fuite des champs `transaction_no`, `payment_method`, `amount`, `sign`, `created_at` de la branche 7 (order_serial_no null car eager-load scopé). Via /export → écrits dans Transaction.xlsx. NB: passer `branch_id=7` échoue (BranchScope sur whereHas rend la sous-requête vide) ; le vecteur valide est l'OMISSION de branch_id.
```

**Test de preuve :** Feature test: créer branches A(5) et B(7), une commande+transaction dans chacune. actingAs un Branch Manager de A avec permission `transactions`. `$this->getJson('/api/transaction')->assertJsonFragment(['amount'=>montantTxB,'payment_method'=>methodeTxB])` — l'assertion passe, prouvant la fuite cross-branch.

**Garde vérifiée (3/3 sceptiques → TIENT) :** middleware permission:transactions (acces, pas de scope) ; PaginateRequest authorize=true n'impose pas branch_id ; AdminController vide ; modele Transaction SANS global s


### C22 · Scellement Z incomplet : une commande scellée reste mutable via changeStatus / changePaymentStatus (aucun garde hors destroy)
`app/Services/OrderService.php:1499` — **✅ PROUVÉ**

**Préconditions :** Auth Sanctum, permission `pos-orders`, meme branch_id que la commande (ou Admin). Commande : PAID, avec `transaction`, fiscal_sequence_no non nul, statut PENDING/ACCEPT/PREPARING, created_at dans la fenetre (opened_at < created_at <= closed_at) d'un ZReport STATUS_CLOSED de la branche = deja agregee/signee dans un Z clos (meme predicat que destroy(), OrderService.php l.1724-1729).

**Reproduction :**

```
Z clos agrege la commande #X (PAID, transaction, statut ACCEPT, created_at dans la fenetre). 1) POST /pos-order/destroy/{X} -> 409 "sealed by a closed Z" (destroy l.1730-1735) : elle EST scellee. 2) POST /pos-order/change-status/{X} {status:CANCELED,reason:"x"} -> changeStatus l.1467-1548 : AUCUN garde Z ; transition ACCEPT->CANCELED permise (StateMachine l.42), donc l.1487-1493 cashBack (mouvement d'argent car transaction existe) + l.1494 refundPoints, l.1498-1499 status=CANCELED save -> 200. La vente reste comptee encaissee dans le Z signe mais est annulee+remboursee : divergence total Z / etat reel, violation NF525.
```

**Test de preuve :** Feature PHPUnit : seed Order PAID+transaction (created_at=now) + ZReport CLOSED (opened_at<created_at<=closed_at) ; user permission pos-orders. Assert1: POST destroy/{id} -> 409 (prouve scellee). Assert2: POST change-status/{id} {status:CANCELED,reason:'t'} -> 200 + status=CANCELED + cashBack/refund crees. Coexistence 409/200 prouve la faille.

**Garde vérifiée (3/3 sceptiques → TIENT) :** Garde Z seulement dans destroy() l.1723-1736 (ZReport clos englobant created_at → 409). Absent de changeStatus l.1499 et changePaymentStatus l.1595. OrderStatusRequest = 


### C23 · Stripe : montant tronqué envoyé au PSP (précédence de cast PHP) → perte d'argent déterministe, montant != total
`app/Http/PaymentGateways/Gateways/Stripe.php:47` — **✅ PROUVÉ**

**Préconditions :** Rôle : client frontend (routes payment, middleware installed). Config : Stripe activé (PaymentGateway slug=stripe, status=ENABLE) + option stripe_secret présente. État : Order UNPAID sans Transaction, total à partie fractionnaire non nulle. Order->total casté decimal:6 (Order.php:63) → chaîne "12.990000", cas normal de tout prix (12.99, 9.50).

**Reproduction :**

```
1) Commande total=12.99. 2) POST /payment/{order}/pay (PaymentController::payment, paymentMethod=stripe, stripeToken=tok_visa). 3) Stripe.php:47 : (int)$order->total*100. Précédence PHP : (int) lie avant * → ((int)"12.990000")*100 = 12*100 = 1200. PSP encaisse 12,00$ au lieu de 1299. Vérifié via php -r : 1200 vs 1299. 4) Callback POST /payment/stripe/{order}/success → PaymentService::payment (PaymentService.php:20) écrit Transaction.amount = $order->total = 12.99. Résultat : PSP=12,00, Transaction.amount=12,99, perte 0,99/commande, montant!=total sur chaque carte à total fractionnaire.
```

**Test de preuve :** Test unitaire de Stripe::payment avec StripeClient mocké : Order->total="12.990000" ; capturer l'argument amount de charges->create ; asserter amount===1299 (échoue aujourd'hui à 1200). Variante pure : asserter (int)$total*100 == (int)round($total*100) — faux dès qu'il y a une fraction (12.99→1200 vs 1299).

**Garde vérifiée (3/3 sceptiques → TIENT) :** Stripe.php:47 precedence : (int) lie plus fort que * => ((int)total)*100 tronque. Order.total cast decimal:6 (L63). PaymentService::payment L20 : amount=total complet. Ab


### C25 · Commande à table (QR, NON authentifiée) accepte une remise manuelle arbitraire jusqu'au sous-total entier -> repas quasi gratuits
`app/Services/Pricing/PricingService.php:213` — **✅ PROUVÉ**

**Préconditions :** Route POST /api/table/dining-order/ (api.php:1007), groupe prefix 'table' middleware ['installed','apiKey','localization'] (api.php:992): pas d'auth:sanctum, pas de role. Auth = seule cle API statique SPA (client QR). TableOrderRequest::authorize()=true, 'discount' valide nullable|numeric sans borne. Defaut use_ssot_service=true; chemin legacy applique aussi la remise. >=1 Item en DB.

**Reproduction :**

```
Attaquant lit prix DB (Item id=5 price=20). POST /api/table/dining-order/ header apiKey, corps: dining_table_id=1, customer_id=1, branch_id=1, order_type=3, is_advance_order=0, source=1, items=[{"item_id":5,"quantity":1}], discount=20.00 (=sous-total serveur). tableOrderStore passe (float)$request->discount a forTable context='table' (OrderService:1003). PricingService:213 (context pos/table) appelle manualDiscount(20,20)=20 car requested<=subtotal (DiscountCalculator:28). rawTotal=20+tax-20=TVA seule (PricingService:221). Total~=TVA sans caissier; journal discount_type='manual_cashier' (OrderService:931) => atteinte NF525. Repetable 20/min/IP.
```

**Test de preuve :** Feature sur la vraie route: postJson('/api/table/dining-order/',[...,'items'=>json([[item_id,quantity=>1]]),'discount'=>$item->price]) header apiKey; asserter $order->total==round($order->tax,2), $order->discount==$item->price. Unitaire: calculateOrder(forTable(0,1,[$line],0,0,$subtotal,0.0))->discount==$subtotal && finalTotal==totalTax. Base: CrossItemGuardTest.php.

**Garde vérifiée (3/3 sceptiques → TIENT) :** Route api.php:1007 = middleware apiKey+throttle:20,1, sans auth ni permission. ApiKeyMiddleware:24 = cle statique embarquee, pas d'identite/role. TableOrderRequest:19 aut


### C26 · La machine kiosque est liée à l'utilisateur admin (id=1) au lieu d'un compte à privilège minimal
`database/seeders/KioskMachineTableSeeder.php:33` — **✅ PROUVÉ**

**Préconditions :** Base seedée hors prod (User+KioskMachine seeders). Borne kiosk-lecayenne ACTIVE liée user_id=1 = compte admin (1er créé, rôle ADMIN). RolePermissionTableSeeder:21 donne Permission::all() à ADMIN; AuthServiceProvider:30-31 Gate::before renvoie true pour rôle 'admin' (bypass total). Attaquant a un token borne fuité + la x-api-key (clé applicative unique, embarquée côté front, non secrète).

**Reproduction :**

```
1) POST /api/kiosk-login {username:"kiosk-lecayenne",password:"kiosk123"} -> 201, token Sanctum d'abilities ['kiosk:order'] mais créé SUR admin id=1 (LoginController L63 user_id=1, L83 $user->createToken). 2) Appeler route admin, ex PATCH /api/admin/setting/company ou DELETE /api/admin/setting/currency/{id}, headers Authorization: Bearer <token> + x-api-key. 3) Groupe /admin (routes/api.php:229) = auth:sanctum,apiKey,localization,throttle seulement; aucun middleware abilities, aucun tokenCan. auth:sanctum résout user=admin id=1, Gate::before accorde tout -> 200, mutation admin exécutée. L'ability kiosk:order n'est jamais contrôlée: scope cosmétique, compromission admin totale.
```

**Test de preuve :** Feature test: $t=postJson('/api/kiosk-login',['username'=>'kiosk-lecayenne','password'=>'kiosk123'])->json('token'); withHeaders(['Authorization'=>"Bearer $t",'x-api-key'=>config('app.api_key')])->patchJson('/api/admin/setting/company',[...])->assertStatus(200). Le 200 (au lieu de 403) prouve l'accès admin via le token borne. Après fix: 403 attendu.

**Garde vérifiée (3/3 sceptiques → TIENT) :** Seeder L33 user_id=1; UserSeeder:43 id=1=admin; AuthServiceProvider:30 Gate::before donne tout a admin; api.php:229 groupe admin=auth:sanctum+permission:* SANS abilities/


### C27 · Garde du constructeur Installer inefficace: Redirect->send() sans exit laisse s'executer les routes /install/* en prod
`app/Http/Controllers/Installer/InstallerController.php:29` — **✅ PROUVÉ**

**Préconditions :** App installee: storage/installed existe, donc la garde du constructeur (InstallerController.php:28) est active. Aucun role/auth requis: routes/web.php:21-32 declarent /install avec le seul middleware ['web'], PAS 'installed' -> aucun autre garde-fou. APP_URL defini. Attaquant anonyme. GET /install/final-store: aucun jeton CSRF; POST: XSRF via cookie d'un GET web.

**Reproduction :**

```
App installee. Anonyme -> GET /install/final-store (route installer.finalStore, web.php:32). Le routeur instancie InstallerController; constructeur voit file_exists(storage/installed)=true et fait Redirect::to(env('APP_URL'))->send() (l.29). send() emet le 302 mais N'appelle PAS exit/die: le constructeur retourne, le dispatcher invoque finalStore() (l.131) execute ENTIEREMENT: reecriture .env APP_ENV/APP_DEBUG, storage:link --force, append storage/installed (InstallerService.php:104-123). Le 302 masque la mutation. Variante grave: POST /install/database (databaseSetup l.26-48) reecrit DB_* vers hote attaquant puis migrate:fresh --force (DROP tables)+seed.
```

**Test de preuve :** Feature Laravel: 1) touch storage/installed; 2) $this->mock(InstallerService::class)->shouldReceive('finalSetup')->once(); 3) $r=$this->get('/install/final-store'); 4) $r->assertStatus(302); 5) le mock prouve que finalSetup() s'execute MALGRE la garde -> send() n'interrompt pas. La correction (exit/die apres send, ou middleware 'installed' sur /install) ferait echouer once().

**Garde vérifiée (3/3 sceptiques → TIENT) :** Constructeur l.28-30: seule garde file_exists(installed)+Redirect->send(). routes/web.php:21-32: groupe install a UNIQUEMENT ['web'], pas 'installed'. Kernel.php:33-41: g


### C28 · Reprise DB non authentifiee: reecriture .env DB + migrate:fresh + reseed admin par defaut (123456)
`app/Services/InstallerService.php:40` — **✅ PROUVÉ**

**Préconditions :** Aucune auth: routes /install/* (web.php:21-33) sous ['web'] seul, sans auth:sanctum ni middleware 'installed'. Attaquant joignant l'URL et controlant un MySQL accessible du serveur. Si deja installe, le garde InstallerController:28-30 fait Redirect->send() SANS exit, donc databaseStore s'execute quand meme. Jeton CSRF obtenu via GET prealable.

**Reproduction :**

```
1) GET /install/database (constructeur ne termine pas le script) -> vue + jeton/cookie CSRF. 2) POST /install/database, champs valides (config/installer.php): database_host=<IP attaquant>, database_port=3306, database_name, database_username, database_password, _token. 3) checkDatabaseConnection (:50-78) injecte ces valeurs en config, getPdo() OK sur le MySQL attaquant -> true. 4) databaseSetup (:26-48) ecrit DB_* dans .env (EnvEditor), config:cache, puis migrate:fresh --force (DROP tables prod) + db:seed --force. 5) UserTableSeeder:33-38 recree admin@lecayenne.fr / 123456 / branch_id=0. Resultat: prod repointee/detruite, super-admin connu, isolation branch_id et verite pricing effondrees.
```

**Test de preuve :** Feature test: sans acteur authentifie, creer storage/installed puis Artisan::spy(). POST installer.databaseStore avec database_* valides (PDO mocke OK). Asserter que l'action s'execute malgre installed, qu'Artisan recoit 'migrate:fresh' et 'db:seed' avec --force, qu'EnvEditor reecrit DB_HOST, et qu'un admin admin@lecayenne.fr s'authentifie avec 123456.

**Garde vérifiée (3/3 sceptiques → TIENT) :** Constructeur InstallerController:28 (file_exists installed -> Redirect->send()) inefficace: send() ne stoppe pas l'exec, databaseStore continue. Routes install/* web.php:


### C29 · Fuite temps réel cross-branch: la garde kiosque autorise la mauvaise branche via ->first() non lié à la borne authentifiée
`routes/channels.php:28` — **✅ PROUVÉ**

**Préconditions :** >=2 lignes kiosk_machines partageant le meme user_id mais des branch_id differents (mode nominal: token cree sur le user proprietaire, jamais sur la machine — login L83; EnsureKioskMachineCommand L52-54 rattache par defaut a user_id=1; logout L103 itere N machines/1 user). Token Sanctum kiosque valide (ability kiosk:order), Echo/soketi actifs. Aucun admin requis.

**Reproduction :**

```
Etat: machine id=10(user_id=1,branch_id=1) et id=20(user_id=1,branch_id=2, borne B). 1) Borne B: POST /api/auth/kiosk-login -> token lie a user_id=1, sans reference a la machine 20. 2) POST /broadcasting/auth channel_name=private-branch.1 (branche A, pas la sienne): channels.php:28 KioskMachine::where('user_id',1)->first() renvoie l'id le plus bas=10 (branch_id=1); test 1===1 -> TRUE. Borne B autorisee sur private-branch.1, recoit OrderCreated (total) et OrderStatusChanged (token client, new_status) de la branche A. A l'inverse private-branch.2 (sa vraie branche) -> ->first()=10, 1===2 -> FALSE. Le ->first() ordonne par PK ne depend jamais de la borne authentifiee.
```

**Test de preuve :** Feature Sanctum: user U(id=1); M_A(user_id=1,branch_id=1) et M_B(user_id=1,branch_id=2) avec M_A.id<M_B.id; actingAs(U) abilities=['kiosk:order']. Invoquer le callback Broadcast 'branch.{branchId}'. Assert: private-branch.1 == TRUE (fuite) et private-branch.2 == FALSE, alors que la borne est en branch 2. Squelette: tests/Feature/KioskPhase7/KioskEventBranchIsolationTest.php.

**Garde vérifiée (3/3 sceptiques → TIENT) :** channels.php:25-39; login L83 (token=user_id+ability, sans machine_id); KioskMachine sans scope; migration L19 user_id non-unique; seeder: kiosk-lecayenne(br1)+gulshan1(b


### C30 · Clé privée de compte de service Firebase/GCP committée en clair dans le docroot web public/
`public/file/service-account-file.json:5` — **✅ PROUVÉ**

**Préconditions :** Aucun role/auth requis. public/file/service-account-file.json COMMITTE en clair (git ls-files -> tracke, hors .gitignore). Cle Firebase Admin: project foodking-inilabs, client_email firebase-adminsdk-kpdbd@...iam.gserviceaccount.com, private_key RSA-2048 PKCS8 VALIDE (DER 1218o, en-tete 308204be020100). public/ = docroot Laravel (public/index.php present). Usage: NotificationTableSeeder.php:34-36.

**Reproduction :**

```
1) Statique (PROUVE): `git ls-files public/file/service-account-file.json` -> tracke ; Read ligne 5 -> private_key complet. Tout clone/fork detient la cle admin. 2) Web: GET https://<host>/file/service-account-file.json -> 200, JSON sans auth. 3) getAccessToken(): JWT signe RS256 avec private_key (iss=client_email, scope firebase.messaging ou cloud-platform, aud=token_uri) -> POST https://oauth2.googleapis.com/token -> access_token OAuth2. 4) Push arbitraire: POST https://fcm.googleapis.com/v1/projects/foodking-inilabs/messages:send, Bearer token, message.topic=foodking_customers -> notif a toute la base clients. Scope cloud-platform ouvre d'autres API GCP.
```

**Test de preuve :** Test de garde parcourant public_path() recursivement: assertStringNotContainsString('BEGIN PRIVATE KEY') et pas de '"type": "service_account"' sur chaque fichier -> ECHOUE sur public/file/service-account-file.json. CI: `git grep -l 'BEGIN PRIVATE KEY' -- public/` doit etre vide. Remediation: revoquer la cle dans GCP IAM, purger l'historique, deplacer hors docroot.

**Garde vérifiée (3/3 sceptiques → TIENT) :** Clé RSA privée complète l.5, fichier git-tracké, non gitignoré, world-readable. public/.htaccess : aucune règle Deny .json ; RewriteRule seulement si !-f, donc fichier st


### C31 · Contournement du plafond d'autorisation de remise via le champ subtotal client
`app/Http/Requests/PosOrderRequest.php:143` — **✅ PROUVÉ**

**Préconditions :** Caissier authentifie (auth:sanctum+apiKey) avec UNIQUEMENT `pos-discount-up-to-10` (ni manager ni owner). branch_id != 0 et = celui de la commande (passe le controle l.579). Un article existe en DB a prix reel 100€. Vulnerable que `pricing.use_ssot_service` soit true (defaut) ou false : les deux chemins clampent la remise seulement a <= realSubtotal.

**Reproduction :**

```
POST /api/admin/pos : items=[{item_id=article 100€, quantity:1}], subtotal=10000, discount=90, discount_reason="promo", + champs requis (customer_id, branch_id du caissier, order_type, source, pos_payment_method). GATE PosOrderRequest:143 -> pct=90/10000*100=0,9% <=10% : validation passe. SERVEUR posOrderStore : subtotal client ignore (l.569), realSubtotal recalcule=100€ (DB), remise=min(90,100)=90€ (SSOT DiscountCalculator::manualDiscount l.28 ou legacy l.776). Resultat order.discount=90€, order.total=10€ = 90% de remise sans approbation manager/owner. Le meme payload avec subtotal=100 serait rejete (pct=90%>10%) : seul le subtotal gonfle change le verdict.
```

**Test de preuve :** Feature test : caissier avec la seule permission pos-discount-up-to-10, Item a 100€. actingAs(caissier)->postJson('/api/admin/pos',[items=1x item 100€, subtotal=10000, discount=90, discount_reason=>'promo', ...requis]). Bug prouve si reponse 2xx ET order->discount==90.0 ET order->total==10.0. Attendu apres fix : 422 sur 'discount'. Miroir : subtotal=100 -> deja 422.

**Garde vérifiée (3/3 sceptiques → TIENT) :** Gate PosOrderRequest:143 pct=discount/subtotal, 2 du payload; subtotal nullable L53, pas de prepareForValidation. OrderService L569 recalcule realSubtotal mais ré-appliqu


### C32 · Les commandes annulées/remboursées restent PAID et sont comptées dans le Z report (CA et cash surévalués)
`app/Services/Fiscal/ZReportService.php:217` — **✅ PROUVÉ**

**Préconditions :** Caissier POS (permission 'pos') ou Admin authentifié Sanctum, rattaché à branch_id. Config fiscal.z_report_secret définie. État: commande POS espèces payment_status=PAID(5), fiscal_sequence_no assigné, non soft-deleted, pos_payment_method='cash', total>0, passée CANCELED/RETURNED. Un Z ouvert pour la branche. Le remboursement (cashBack) n'altère pas payment_status; aucun statut REFUNDED n'existe.

**Reproduction :**

```
1) POST /pos: commande espèces branche B, payment_status=PAID, fiscal_sequence_no=1, total=100, total_tax=10, pos_payment_method='cash'. 2) POST /pos-order/change-status/{order} {status:16,reason:"x"} (CANCELED): OrderService 1480-1499 -> cashBack émet transaction -100, puis status=16, save(); payment_status RESTE 5. 3) POST z-report/close branche B: aggregate() (ZReportService:207-218) filtre payment_status!=UNPAID SANS filtre status -> commande annulée incluse: total_ttc+=100, total_by_method['cash']+=100, total_tva+=10, signés HMAC. Ligne 'cash' du Z=100 alors que tiroir=0 (cashback rendu) -> écart de caisse, CA/TVA surdéclarés. cancel_count=1 journalisé mais jamais déduit.
```

**Test de preuve :** Feature RefreshDatabase: Branch B; Order::factory(status=CANCELED, payment_status=PAID, fiscal_sequence_no=1, total=100, pos_payment_method='cash', branch_id=B). $t=aggregate(B, now()->subDay(), now()->addMinute()). Attendu: assertSame(0,$t['order_count']); assertArrayNotHasKey('cash',$t['total_by_method']). Actuel: order_count=1, total_ttc=100, cash=100 -> prouve l'inclusion des annulées.

**Garde vérifiée (3/3 sceptiques → TIENT) :** l.217: seul filtre=payment_status!=UNPAID. PaymentStatus: que PAID/UNPAID. changeStatus l.1435-1548 sur CANCELED/RETURNED appelle cashBack mais ne remet pas payment_statu


### C33 · Fenêtre morte entre Z : recettes encaissées hors de tout Z signé (borne basse = opened_at)
`app/Services/Fiscal/ZReportService.php:129` — **✅ PROUVÉ**

**Préconditions :** Auth Sanctum, caissier POS branche B (branch_id>0), permissions creation POS et z-report open/close. Config fiscal.z_report_secret presente. Etat : Z_n deja ferme (closed_at=Cn), puis Z_{n+1} ouvert plus tard (opened_at=On>Cn). Il suffit qu'une commande POS soit encaissee entre Cn et On (service apres cloture, ou delai cloture-veille/ouverture-lendemain).

**Reproduction :**

```
1) POST /api/fiscal/z-report/close (B) 22:00 -> Z_n closed_at=22:00. 2) POST /api/pos (B) 22:05 : posOrderStore cree l'order PAID (OrderService:591) + fiscal_sequence_no (ligne 862), sans exiger un Z ouvert. 3) POST /api/fiscal/z-report/open (B) lendemain 08:00 -> Z_{n+1} opened_at=08:00. 4) POST /api/fiscal/z-report/close -> aggregate(B, opened_at=08:00, closed_at) (ZReportService:129). Fenetre (08:00, closed_at] : order de 22:05 a created_at<08:00 -> exclu par borne basse stricte >from (ligne 213) ; il est aussi >Cn=22:00 -> exclu de Z_n (borne haute <=to, ligne 210). Present dans AUCUN Z signe : total_ttc/tva/order_count sous-declares, signature HMAC valide malgre le trou.
```

**Test de preuve :** Feature test (RefreshDatabase, cf ZReportBoundaryTest). Branche B, Cn=22:00, On=lendemain 08:00. Order factory [B, PAID, fiscal_sequence_no=k, total=25.00, created_at=22:05]. Appeler aggregate(B, prevOpen, Cn) puis aggregate(B, On, close) comme close() (from=opened_at). Assert : order absent des deux fenetres, 25.00 manquant du cumul. Contre-preuve : aggregate(B, Cn, close) le capterait.

**Garde vérifiée (3/3 sceptiques → TIENT) :** close() L129 agrege borne basse=opened_at (pas closed_at precedent). open() L78: opened_at=now(), aucun lien au close. OrderService L862 assigne fiscal_sequence_no a la c


### C36 · Points fidélité débités à la création mais jamais remboursés à l'auto-rejet d'une commande abandonnée
`app/Jobs/CleanupStalePendingKioskOrders.php:29` — **✅ PROUVÉ**

**Préconditions :** Config loyalty_setup active. Client avec loyalty_code, User.status=ACTIVE(1), solde suffisant. Requete kiosk auth:sanctum. Commande OrderType KIOSK(25)/TAKEAWAY(10), source_surface='kiosk', reglee au TPE (paiement differe) laissee PENDING, non payee dans les 15 min.

**Reproduction :**

```
1) POST /api/frontend/order (store), loyalty_code+discount>0. FrontendOrderService:463-492 DEBITE : decrement('loyalty_points',P) + LoyaltyTransaction type='redeem' points=-P ; pose loyalty_customer_code, status reste PENDING. 2) Client part sans payer ; commande PENDING >15min. 3) Job CleanupStalePendingKioskOrders (Kernel:35) la selectionne, appelle OrderStateMachine::apply(order,REJECTED) (Jobs:29). apply() mute juste statut+recordTransition ; ni le job ni apply() n'appellent refundPoints(). Points jamais recredites. A contrario, changeStatus (FrontendOrderService:667) appelle refundPoints() avant CANCELED ; l'auto-rejet l'omet.
```

**Test de preuve :** Feature test : User actif N points ; FrontendOrder PENDING kiosk (order_type=KIOSK, source_surface='kiosk', loyalty_customer_code=code, created_at=now()->subMinutes(20)) + LoyaltyTransaction type='redeem' points=-P. Executer (new CleanupStalePendingKioskOrders())->handle(). Asserter status===REJECTED (passe) ET user->fresh()->loyalty_points===N : echoue (reste N-P).

**Garde vérifiée (3/3 sceptiques → TIENT) :** Job:29 appelle apply(REJECTED) sans refundPoints. apply() ne fait que status+save+recordTransition. Listeners OrderStatusChanged (Award/Fcm/Outbox) ne remboursent pas. Gr


### C37 · Remboursement carte/ticket-resto impossible : aucune Transaction créée, et cashBack crédite le compte machine
`app/Services/FrontendOrderService.php:660` — **✅ PROUVÉ**

**Préconditions :** Borne authentifiee Sanctum (kiosk:order) : FrontendOrder.user_id = user de la KioskMachine, pas le client (FrontendOrderService:516). Commande order_type KIOSK/TAKEAWAY, payment_method CARD(4)/TICKET_RESTAURANT(5), total>0, encaissee via TPE (payment-confirm) : PAID, statut ACCEPT(4)<PREPARING(7), mais AUCUNE ligne Transaction (seule colonne transaction_id remplie).

**Reproduction :**

```
1) POST order/{id}/payment-confirm {transaction_id:"TPE-1",payment_method:4} -> 200, PAID, statut ACCEPT. paymentConfirm (OrderController:101-118) ne cree PAS de Transaction. 2) POST order/change-status/{id} {status:16}. ACCEPT(4)<PREPARING(7) => annulation autorisee (FrontendOrderService:654-658). 3) Ligne 660 if($frontendOrder->transaction) = null (HasOne Transaction where order_id, aucune ligne) => bloc saute => cashBack jamais appele. Aucun refund gateway Stripe/PayPal dans app/. Carte debitee, commande annulee, zero remboursement, zero trace. Variante : si Transaction existait, cashBack (PaymentService:44-48) crediterait balance du user machine avec slug fige credit, pas la carte.
```

**Test de preuve :** Feature test Laravel : creer KioskMachine+user, actingAs (kiosk:order). FrontendOrder(order_type=KIOSK,user_id=machine,payment_status=UNPAID,total=20). POST payment-confirm(payment_method=4)->assertOk; Transaction::where(order_id)->count()===0. POST change-status(status=16)->assertOk. Verifier : Transaction count reste 0, balance user machine inchangee, mock Stripe/PayPal refund jamais invoque.

**Garde vérifiée (3/3 sceptiques → TIENT) :** Garde l.660 if($order->transaction), HasOne(Transaction,order_id). Transaction creee seulement par PaymentService::payment (gateways Order, jamais kiosk). Carte/TR kiosk=


### C39 · Remise fidélité ET code promo affichés au client mais jamais appliqués au paiement
`resources/js/store/modules/kioskCart.js:21` — **✅ PROUVÉ**

**Préconditions :** Borne kiosk authentifiee (token Sanctum ability kiosk:order). Config PRICING_USE_SSOT indifferente (bug present SSOT on par defaut ET off). Client a un code fidelite valide (loyaltyDiscount>0, loyaltyCustomer.loyalty_code) et/ou un promo valide (promoDiscount>0, promoCode). Panier non vide.

**Reproduction :**

```
1. Fidelite -> SET_LOYALTY, loyaltyDiscount=X. Getter total (l.89)=subtotal-X-Y, UI affiche remise. 2. Promo valide via POST frontend/promo/validate -> promoDiscount=Y. 3. Paiement -> submitOrder -> buildKioskOrderPayload (l.21): payload {loyalty_code, kiosk_promo_code, items} SANS discount ni coupon_id. 4. FrontendOrderService SSOT (l.211): forKiosk(coupon_id=0) ignore kiosk_promo_code -> discount=0. Bloc fidelite l.444 exige request->discount>0 (jamais envoye) -> non execute, points non decrementes. 5. l.513 discount=0; l.514 total=subtotal+tax+delivery = plein tarif debite au TPE. Affiche subtotal-X-Y, facture plein tarif.
```

**Test de preuve :** Test Feature: actingAs token kiosk:order; seed User loyalty_code+points et kiosk_promo actif. POST frontend/order avec loyalty_code+kiosk_promo_code, SANS discount ni coupon_id. Asserter bug: orders.discount==0, orders.total==subtotal+tax, users.loyalty_points inchange, aucune loyalty_transactions type=redeem. Un test correct exigerait discount>0 et total reduit.

**Garde vérifiée (3/3 sceptiques → TIENT) :** l.444 branche loyalty gardee par discount>0 jamais envoye (OrderRequest:39 nullable). kiosk_promo_code dans app/ uniquement PricingPreview/KioskPromoService (preview), ja


### C40 · Annulation depuis le KDS d'une commande payée : aucun remboursement ni trace fiscale NF525
`app/Services/KitchenDisplaySystemOrderService.php:115` — **✅ PROUVÉ**

**Préconditions :** Acteur Sanctum avec permission `kitchen-display-system` (controller:21) ET role Chef/Cashier/POS Operator/Admin/Branch Manager (OrderStatusRequest::authorize:24). Cible : commande PAYEE (transaction + points fidelite) au statut ACCEPT(4) ou PREPARING(7), visible sur le KDS (list() filtre 4/7/8). Aucune config speciale ; le chemin KDS n'inspecte jamais payment_status.

**Reproduction :**

```
Order id=42 payee (transaction credit + points credites), status=4, branch_id=1. Chef POST /api/admin/kds-order/change-status/42 {"status":16}. authorize OK (role), ValidStatusTransition OK (4->16, OrderStateMachine::allows:42). KitchenDisplaySystemOrderService::changeStatus (l.128-152) fait seulement status=16+save, recordTransition, notifs -> 202. Erreur : AUCUN PaymentService::cashBack (argent non rembourse malgre $order->transaction), AUCUN LoyaltyService::refundPoints, AUCUN AuditLogService::write(order.cancelled) donc trace NF525 absente (total/payment_status/fiscal_sequence_no non journalises). OrderService::changeStatus (l.1480-1547) ferait les trois. reason non exige.
```

**Test de preuve :** Feature test : Order payee (transaction credit + LoyaltyTransaction earn), status=ACCEPT ; actingAs Chef (perm kitchen-display-system). postJson change-status/{id} {status:16} -> assertStatus(202), orders.status=16. Puis assertDatabaseMissing : transaction de remboursement, loyalty_transactions type=refund, audit_logs action=order.cancelled. Miroir via OrderService : les trois lignes existent.

**Garde vérifiée (3/3 sceptiques → TIENT) :** Middleware KDS=permission seul; authorize autorise Chef/Cashier; state machine ACCEPT/PREPARING vers CANCELED sans check paiement; requiresReason non applique; listeners 


### C34 · Identifiants machine injectés en clair dans la page borne + défaut kiosk123 documenté en prod
`config/kiosk.php:68` — **🟡 PROBABLE (dépend d un comportement runtime)**

**Préconditions :** Attaquant anonyme. Requis: (1) auto-login actif: KIOSK_REQUIRE_MACHINE_LOGIN=false et spa_payload non nul (KIOSK_MACHINE_* definis, ou APP_ENV=local -> fallback kiosk123, kiosk.php:59-66); (2) acces a une route /kiosk*; (3) attaque distante: borne seedee hors production (kiosk123/user_id=1) et password non re-parametre. apiKey expose dans la meme page (master.blade.php:80).

**Reproduction :**

```
Fuite (prouvee statiquement): GET /kiosk -> view-source: window.foodkingConfig expose apiKey (master.blade.php:80) et kioskAutoLogin={username,password} en clair (master.blade.php:84 lisant kiosk.spa_payload, kiosk.php:68-71). Prise de controle (dependante config): POST /api/auth/kiosk-login (header apiKey) {"username":"kiosk-lecayenne","password":"kiosk123"} -> 201 {token}. Token cree sur user_id=1 (Controller:83-87), or id=1 = role ADMIN (UserTableSeeder:31-43). Ability=['kiosk:order']; escalade admin depend du finding #1. kiosk123 exploitable seulement si borne seedee hors prod et non re-parametree (seeder bloque en production, kiosk.php:20-23/59-66).
```

**Test de preuve :** Feature: (A) config(kiosk.spa_payload=[kiosk-lecayenne,kiosk123]); GET /kiosk; assertSee('kiosk123',false)+assertSee(app.api_key,false) -> prouve fuite. (B) run KioskMachineTableSeeder; postJson('/api/auth/kiosk-login',[kiosk-lecayenne,kiosk123]) -> assertStatus(201); PersonalAccessToken::findToken(token) et assertEquals(1,tokenable_id) -> token sur admin id=1.

**Garde vérifiée (3/3 sceptiques → TIENT) :** kiosk.php:68 password clair; master.blade.php:84 injecte spa_payload dans HTML pour toute route kiosk*; web.php:46 Route::get('/{any}') sert la vue avec middleware ['inst



---

## 2. Critiques RÉFUTÉS par la passe stricte (2) — requalifiés

> Le juge a trouvé une mitigation valide couvrant tous les chemins. **À retirer du backlog critique.**

### ❌ C12 · Fuite temps réel cross-branche : la sentinelle branch_id===0 accorde TOUS les canaux de branche à chaque client
`routes/channels.php:33`

**Mitigation trouvée (verdict juge) :** Mitigation channels.php:27-29 valide sur tous les chemins client. Tout token client=['*'] (Login:78, GuestSignup:140, Refresh:25, Forgot:167); Sanctum ^3.0, HasApiTokens sans override => tokenCan('kiosk:order')=true pour wildcard, currentAccessToken() toujours truthy sous auth:sanctum. Donc client entre dans bloc kiosk (l.27), aucune KioskMachine, l.29 renvoie false: n'atteint jamais la sentinelle branch_id===0 (l.33), code mort pour tout token reel. Aucune fuite cross-branche.

**Gardes citées :**
- [TOMBE] Guard=auth:sanctum (BroadcastServiceProvider:22); tokens client ['*'] (LoginController:78, GuestSignupController:140), kiosk ['kiosk:order'] (KioskMachineLoginController:85); Sanctum 3.x can() traite '*' en wildcard; ord
- [TOMBE] channels.php:25-38; createToken: Login:78/GuestSignup:140=['*'], Kiosk:85=['kiosk:order']; User HasApiTokens Sanctum sans override; BroadcastServiceProvider:22 auth:sanctum. tokenCan('*')=>true.
- [TOMBE] channels.php:25-39 + BroadcastServiceProvider:22 (auth:sanctum) + abilities tokens: Login:76, GuestSignup:140, Refresh:24, Forgot:165 tous ['*'], seul Kiosk:84=['kiosk:order']. Migration users:28 (branch_id default 0), S


### ❌ C18 · Isolation par branche absente sur le changement de statut KDS — la doc affirme le contraire (écriture cross-branch)
`app/Services/KitchenDisplaySystemOrderService.php:116`

**Mitigation trouvée (verdict juge) :** Mitigation valide. Order.php:82 attache BranchScope global; BranchScope.php:39 force WHERE branch_id=userBranch pour staff (branch>0) si Auth::check(). Binding {order} (route 778, ctrl l.33 Order $order) resout via Eloquent scopee: aucun override resolveRouteBinding ni withoutGlobalScope ici (grep). Kernel n'override pas middlewarePriority: auth avant SubstituteBindings, donc Auth::check()=true. Chef branche A postant ID branche B -> 404 AVANT changeStatus() l.116. Ecriture cross-branch impossible; seul admin branch_id=0 traverse (voulu).

**Gardes citées :**
- [TOMBE] Order.php:82 addGlobalScope(BranchScope). BranchScope.php:39 force WHERE branch_id=userBranch (staff branch_id>0), incl. binding {order}. Kernel.php sans priorite custom: auth:sanctum avant SubstituteBindings, Auth::chec
- [TOMBE] Route 778: binding implicite {order}. Middleware = permission:kitchen-display-system seule. OrderStatusRequest::authorize autorise Chef sans branch. MAIS Order.php:82 addGlobalScope(BranchScope); BranchScope.php:39 force
- [TOMBE] Order.php:82 attache BranchScope global. BranchScope.php:39: si Auth::check() et branch>0, ajoute where(branch_id=user). Le binding {order} (route:778, sous auth:sanctum) applique le scope (SubstituteBindings apres auth)


---

## 3. Chaînes d'exploitation (pires scénarios)

### 🔗 De la clé committée au vol total du PII multi-branches via prise de contrôle admin par la borne kiosque.
1. 1. Recon non authentifiée : télécharger public/file/service-account-file.json (C30, public/file/service-account-file.json:5) → clé privée GCP/Firebase en clair ; lire le défaut kiosk123 (C34, config/kiosk.php:68).
2. 2. Login borne avec kiosk123 : le token émis est celui de l'ADMIN id=1 et l'ability kiosk:order n'est jamais vérifiée en HTTP (C15/C19, KioskMachineLoginController.php:82-83 ; C26, KioskMachineTableSeeder.php:33).
3. 3. Avec ce token borne = admin, attaquer /api/admin/* : le groupe n'exige que auth:sanctum, 12+ contrôleurs sans middleware permission sont ouverts (C09, routes/api.php:229).
4. 4. Fixer branch_id=0 : la conflation client/admin désactive le BranchScope et l'appelant voit toutes les branches (C07, BranchScope.php:33).
5. 5. Exfiltrer la liste/export des transactions non scopées par branche → toutes les finances cross-branch aspirées (C21, TransactionService.php:33).
6. 6. Énumérer les commandes de tous les clients via IDOR /api/admin/my-order/show/{user}/{order} sans contrôle d'identité (C08, OrderService.php:1286) → PII nominative en masse.
7. 7. Canal de secours sans token : IDOR non authentifié GET /table/dining-order/show/{frontendOrder}, énumération d'ID → PII cross-branch (C16, routes/api.php:1005).
8. 8. S'abonner au canal temps réel d'une autre branche via la garde ->first() non liée à la borne (C29, channels.php:28) et réutiliser la clé Firebase (C30) pour push à toute la base clients.

**Impact :** Exfiltration irréversible de la base clients multi-branches (noms, téléphones, adresses, historiques), de toutes les transactions financières cross-branch et du flux temps réel des commandes, plus compromission GCP/Firebase et contrôle admin total : violation RGPD/PII massive et durable.


### 🔗 Prise de contrôle admin via token borne kiosque, puis fraude fiscale NF525 et effacement des preuves
1. 1. ACCÈS BORNE — C34 (config/kiosk.php:68): identifiants machine en clair dans la page borne + défaut 'kiosk123' en prod. Un client devant la borne lit le source ou utilise le défaut pour s'authentifier.
2. 2. ESCALADE ADMIN — C26 (KioskMachineTableSeeder.php:33) lie la machine à l'ADMIN id=1; C15 (KioskMachineLoginController.php:82) émet donc un token Sanctum qui EST celui de l'admin. Un login borne = un super-admin.
3. 3. PORTÉE TOTALE — C19 (KioskMachineLoginController.php:83): l'ability 'kiosk:order' jamais vérifiée en HTTP; C09 (routes/api.php:229): /api/admin sous auth:sanctum seul, sans permission → 12+ contrôleurs admin pilotables.
4. 4. EXFILTRATION — C07 (BranchScope.php:33) branch_id=0 désactive le scope; C21 (TransactionService.php:33) transactions non scopées; C08 (OrderService.php:1286) IDOR → siphonnage cross-branche.
5. 5. MINORATION CA — C31 (PosOrderRequest.php:143) subtotal client contourne le plafond de remise; C13 (PosComponent.vue:1446) remise fantôme; C05/C06 (FrontendOrderService.php:514 / OrderRequest.php:40) delivery_charge négatif → totaux ~0.
6. 6. DÉSCELLEMENT POST-Z — C03/C22 (OrderService.php:1596 et 1499): seul destroy() est gardé; changeStatus et changePaymentStatus mutent des commandes déjà scellées → repasser des ventes en 'annulé' APRÈS la signature du Z.
7. 7. Z FALSIFIÉ — C32 (ZReportService.php:217) annulées PAID comptées; C04/C33 (ZReportService.php:207/129) fenêtre morte omet des commandes → Z signé faux, CA/TVA sous-déclarés.
8. 8. EFFACEMENT — C27 (InstallerController.php:29) sans exit laisse /install/* actif; C28 (InstallerService.php:40) réécrit .env + migrate:fresh + reseed admin 123456. Alternative: C17 (migration:74) TRUNCATE du menu → preuves détruites.

**Impact :** CA et TVA sous-déclarés de façon déterministe (fenêtre morte + annulés PAID + remises/livraison à ~0), cash détourné. Prise de contrôle admin totale depuis une borne physique, exfiltration cross-branche, puis réécriture .env/reseed ou TRUNCATE détruisant les preuves NF525.


---

## 4. Notes

- Taux de survie 94 % : l'audit initial n'était pas alarmiste. Les 2 réfutations valident la rigueur du process (absolution sur mitigation réelle, pas sur bénéfice du doute).
- C12 réfuté proprement : tout token client porte ['*'], entre dans le bloc kiosk (channels.php:27), sans KioskMachine renvoie false en l.29 — la sentinelle branch_id===0 (l.33) est code mort. Pas de fuite.
- C18 réfuté : BranchScope global sur Order force WHERE branch_id=userBranch ; le route-binding {order} résout via Eloquent scopé → 404 avant changeStatus. Écriture cross-branch impossible.
- C15/C19/C26 forment une seule vulnérabilité racine : la borne kiosque authentifie sur l'utilisateur admin id=1 et l'ability 'kiosk:order' n'est jamais vérifiée en HTTP. À traiter comme un incident unique, priorité maximale.
- Contradiction directe avec la doc : plusieurs findings (C15/C19 vs blocage Sanctum « juré », C21/C29 vs isolation branche) montrent que la doc affirme des garanties que le code ne tient pas. Anti-drift à surfacer.
- C34 seul en PROBABLE : identifiants machine en clair + défaut kiosk123 documenté. À confirmer dynamiquement (inspecter le HTML servi de la page borne en prod) pour passer PROUVÉ ou classer.
- Angles à re-tester en dynamique : delivery_charge<0 (C05/C06), remise=subtotal sur endpoint QR non authentifié (C10/C20/C25), énumérer frontendOrder (C16), rejouer confirmation paiement sans PSP (C11), vérifier montant Stripe tronqué (C23).
- Cluster fiscal NF525 (8 findings) = risque de non-conformité légale, pas seulement technique : sous-déclaration CA/TVA (C04/C33), CA surévalué (C32), mutabilité post-scellement (C03/C22). Escalade réglementaire recommandée.


*Re-vérification stricte par orchestration adversariale (3 sceptiques + juge par finding, preuve de reproduction). 32 critiques prouvés en statique, reproductibles par test. Aucune modification de code.*