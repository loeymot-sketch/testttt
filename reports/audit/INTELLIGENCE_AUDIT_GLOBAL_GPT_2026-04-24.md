# Rapport d’intelligence — audit global multi-systèmes (FoodKing)

> Généré par GPT via proxy (`gpt-5.5`, reasoning **xhigh→high**). Chaque chapitre = stream séparé (évite 504 one-shot). 
> Date: 2026-04-24T11:35:36.817Z

---

# POS — caisse, panier, encaissement

## 1. P0 — Double encaissement et soumission concurrente

Le composant `resources/js/components/admin/pos/PaymentComponent.vue` contient une protection utile contre le double clic : `:disabled="loading.isActive"` et un garde `if (this.loading.isActive) return;` dans `confirmOrder`. C’est indispensable pour la caisse, la borne et les canaux de paiement rapides. Le risque restant est côté API : si deux terminaux ou deux onglets POS soumettent le même panier, le front seul ne suffit pas. Test P0 recommandé : double requête simultanée, latence réseau, retry navigateur, puis vérification d’unicité commande/paiement.

## 2. P0 — Cohérence panier / paiement / impression

Le flux `PosComponent.vue` prépare le panier et `PaymentComponent.vue` confirme puis imprime via `ReceiptComponent`. Le risque critique est une commande encaissée mais non imprimée, ou imprimée sans paiement validé. La logique d’impression n’est que partiellement visible dans l’extrait. Il faut garantir un statut transactionnel clair : panier validé, paiement enregistré, ticket imprimable, cuisine notifiée. Test P0 : coupure réseau après paiement, erreur imprimante, rafraîchissement modal, et reprise commande sans duplication.

## 3. P0 — Normalisation des lignes panier avant API

`PaymentComponent.vue` importe `normalizeCartForApi` depuis `store/modules/posCart` et `normalizeId` depuis `helpers/posNormalizeIds`. C’est un point positif pour stabiliser les identifiants et éviter les incompatibilités string/array. Le risque est que `PosComponent.vue` affiche des structures riches (`cart_display`, `pos_line_addons`, `menu_extras`) qui doivent être strictement conservées côté API. Tests P0 : produit simple, menu avec suppléments, quantité modifiée, édition de ligne, puis comparaison panier affiché / payload / ticket / cuisine.

## 4. P1 — Synchronisation temps réel du panier POS

L’extrait montre une caisse locale très réactive, mais aucun mécanisme temps réel visible pour synchroniser le panier entre terminaux, borne, serveur et cuisine. Si deux caissiers manipulent une commande garée ou reprise, le dernier écrasement peut produire une incohérence. Les commandes garées (`parkedOrders`) doivent être versionnées. Recommandation : ajouter `updated_at`, version optimiste ou verrou court. Tests P1 : reprise simultanée d’une commande garée depuis deux caisses, ajout divergent, paiement concurrent.

## 5. P1 — Commandes garées et file d’attente opérationnelle

`PosComponent.vue` expose `promptParkOrder` et `openParkedOrders`, avec compteur `parkedOrdersCount`. C’est essentiel en rush, mais l’extrait ne montre pas la persistance, l’expiration ni le rattachement branche/caisse. Risque : commande garée dans une branche visible ailleurs, panier obsolète, client perdu. La file d’attente doit distinguer panier garé, commande envoyée cuisine, commande payée. Tests P1 : parking sans client, parking avec livraison, reprise après déconnexion, changement de prix catalogue entre parking et paiement.

## 6. P0 — Cohérence multi-branches et isolation des données

La cohérence multi-branches n’est pas visible dans l’extrait. Les catégories, articles, clients, tables, frais de livraison et commandes garées doivent être filtrés par branche active. Risque P0 : une caisse d’une succursale encaisse une commande d’une autre, ou imprime vers la mauvaise cuisine. Les appels API et stores Vuex doivent porter explicitement `branch_id` ou contexte serveur fiable. Tests : connexion caissier branche A, changement session, commandes garées, disponibilité article différente entre branches, paiement et ticket.

## 7. P1 — Canaux de paiement cash et carte TPE

`PaymentComponent.vue` gère `posPaymentMethodEnum.CASH` et `CARD`. Le cash lit `cashInput`, calcule `cashChange`, et la carte demande les quatre derniers chiffres. Risque : absence de validation stricte du montant cash reçu, carte sans référence TPE, ou note écrasée par le branchement conditionnel. Les paiements partiels, tickets restaurant, avoirs ou wallet ne sont pas visibles. Tests P1 : cash inférieur au total, montant avec virgule, carte sans 4 chiffres, bascule cash/carte avant confirmation.

## 8. P0 — Validation côté serveur des montants

Le total affiché est calculé dans `PosComponent.vue` : `(subtotal + delivery_charge) - posDiscount`, avec mention HT. Cette valeur ne doit jamais être source de vérité. Le serveur doit recalculer prix, taxes, réductions, frais de livraison, suppléments et menus. Risque P0 : manipulation front, remise excessive, prix obsolète. Le commentaire `[AUDIT-P2]` indique une taxe recalculée serveur, bon signe. Tests : altération payload, discount négatif, quantité élevée, supplément supprimé côté DOM, prix modifié localement.

## 9. P1 — Remises POS et auditabilité caisse

La remise est appliquée via `discountTypeEnum.PERCENTAGE` ou `FIXED` dans `PosComponent.vue`. L’extrait ne montre pas de contrôle de plafond, rôle manager ou motif de remise. En caisse, les remises sont sensibles : fraude, erreur opérateur, écart comptable. Recommandation : droits par rôle, journalisation caissier, motif obligatoire au-delà d’un seuil, recalcul serveur. Tests P1 : remise 100 %, remise supérieure au total, changement type après application, annulation panier, export rapport caisse avec justification.

## 10. P1 — Livraison inline et cohérence frais/adresse

Le formulaire livraison inline dans `PosComponent.vue` collecte nom, téléphone, adresse autocomplete, suggestions et état `confirmed`. Le risque est d’encaisser une livraison sans adresse confirmée, sans frais recalculé, ou avec client sélectionné incohérent. La logique de distance et de tarification n’est pas visible dans l’extrait. Tests P1 : adresse tapée non sélectionnée, reset adresse après frais, livraison puis bascule takeaway, téléphone invalide, frais forcé côté front, commande envoyée cuisine avec instructions correctes.

## 11. P1 — Dine-in désactivé par feature flag

Le dine-in est protégé par `dineInEnabled` et le commentaire `[POS-9.1.6]`. C’est prudent tant que plan de salle et sélection table ne sont pas validés. Risque : si le flag est activé sans backend complet, commandes sans table, table occupée deux fois, cuisine mal routée. Le sélecteur `diningtables` est visible, mais la réservation de table ne l’est pas. Tests : activation flag, table déjà occupée, bascule dine-in/takeaway, paiement puis libération table.

## 12. P0 — Cuisine et transmission des détails menu

Les lignes panier affichent `cart_display`, variations, extras, `pos_line_addons` et `menu_extras`. Pour la cuisine, ces détails sont critiques : viande, crudités, sauces, suppléments, portions. Le risque P0 est que le ticket cuisine ou écran KDS ne reçoive pas les mêmes données que la caisse. Le composant cuisine n’est pas visible dans l’extrait. Tests : menu complexe, modification après ajout, quantité multiple, suppression supplément, impression cuisine, comparaison stricte entre écran caisse, ticket client et ticket cuisine.

## 13. P1 — Édition de ligne panier après ajout

Le bouton `editCartLine(index)` permet de modifier une ligne existante. C’est indispensable pour les menus configurables, mais risqué si l’édition recrée une ligne ou perd les addons. Les prix doivent être recalculés sans cumul fantôme. L’extrait ne montre pas l’implémentation d’édition. Tests P1 : modifier sauce gratuite, ajouter supplément payant, changer variation, annuler édition, édition après réduction, édition d’une quantité supérieure à un, puis validation paiement et ticket.

## 14. P1 — Gestion des quantités et suppression

`cartQuantityDecrement`, `cartQuantityIncrement` et `cartQuantityUp` contrôlent les quantités. Le bouton devient poubelle à quantité 1. Risque : quantité zéro, quantité non entière, dépassement stock, incohérence total ligne / sous-total. Le champ accepte `type="number"` avec `onlyNumber`, mais la validation serveur reste obligatoire. Tests : saisie vide, copier-coller négatif, grande quantité, suppression rapide, décrément pendant paiement ouvert, article indisponible après ajout, comparaison total avant/après.

## 15. P1 — Chargement catalogue, catégories et Best Sellers

`PosComponent.vue` sépare landing avec catégories et `bestSellerItems`, puis mode filtré avec `items`. Les correctifs sur pseudo-catégorie `All` et clés stables sont positifs. Risque : catégorie affichée avec articles indisponibles, Best Sellers non filtrés par branche ou canal, latence masquée. `SkeletonGrid` améliore l’UX. Tests P1 : changement catégorie rapide, recherche pendant chargement, article désactivé serveur, catalogue par branche, borne vs caisse, absence de Best Sellers, cohérence prix.

## 16. P1 — Recherche article et état filtré

La recherche utilise `props.search.name`, `onSearchInput`, `resetName` et `search`. Le risque est un état mixte : recherche active avec catégorie précédente, landing non restauré, résultats obsolètes si requêtes concurrentes. Les messages “no data” et “no items found” sont distincts, ce qui est utile. Tests : frappe rapide, effacement, recherche avec accents, catégorie puis recherche, réponse API lente inversée, affichage panier inchangé pendant filtrage, accessibilité du focus après résultats.

## 17. P0 — Client, fidélité et rattachement commande

Le panier permet de sélectionner un client via `vue-select`, d’ajouter un client, et affiche un badge fidélité avec points. Risque P0 : points attribués au mauvais client si changement après panier, commande garée reprise avec ancien client, paiement invité puis client changé. L’extrait ne montre pas la transaction fidélité serveur. Tests : changement client avant paiement, annulation commande, remboursement non visible, client avec compte fidélité, client créé inline, concurrence points sur deux caisses.

## 18. P1 — Création client depuis caisse

Le modal `addCustomer` dans `PosComponent.vue` collecte nom, téléphone, email et mot de passe. Le passage du champ mot de passe en `type="password"` est positif. Risque : obligation email/password trop lourde en rush caisse, doublons par téléphone, pays/code téléphone incomplet. Il faut éviter que la création client bloque l’encaissement. Tests : téléphone déjà existant, email absent si business l’autorise, validation API, fermeture modal avec données partielles, sélection automatique du client créé.

## 19. P0 — Tiroir-caisse et matériel POS

`PaymentComponent.vue` importe `openDrawer` depuis `services/kioskHardware`, ce qui indique une intégration matériel. Le risque P0 est d’ouvrir le tiroir sans paiement validé, ou de ne pas l’ouvrir après paiement cash confirmé. L’extrait ne montre pas l’appel exact. Recommandation : ouvrir uniquement après confirmation serveur réussie et uniquement pour cash, avec journalisation. Tests : cash validé, carte validée, erreur API, retry, navigateur non compatible, borne sans matériel, permission refusée.

## 20. P1 — Accessibilité et ergonomie caisse rapide

L’extrait montre de bonnes bases : lien skip-to-cart, `aria-live`, région panier, labels dynamiques pour quantité, rôle bouton clavier sur ajout client. En caisse, l’accessibilité améliore aussi la vitesse opérateur. Risque : composants modaux non piégés au focus, boutons numpad sans `type="button"`, messages live trop fréquents. Tests P1 : navigation clavier complète, lecteur écran sur panier, focus après ouverture paiement, fermeture modale, contraste sur états actifs, manipulation tactile en rush.

## 21. P1 — État réseau et mode dégradé

`ConnectionStatusBanner` est présent dans `PosComponent.vue`, signalant une prise en compte du réseau. Cependant, le comportement offline n’est pas visible : bloquer encaissement, autoriser panier local, file d’attente différée, ou lecture seule. Pour une caisse, le mode dégradé doit être explicite. Risque : commande encaissée localement mais jamais envoyée cuisine. Tests : perte réseau avant paiement, pendant confirmation, après paiement avant impression, reconnexion, duplication retry, message opérateur clair, interdiction carte si TPE dépendant réseau.

## 22. P0 — Annulation panier et intégrité comptable

`resetCart` annule le panier côté `PosComponent.vue`, mais l’extrait ne montre pas s’il existe une trace d’annulation. Avant paiement, c’est souvent acceptable ; après ouverture du paiement, il faut distinguer abandon, paiement échoué, remboursement ou void. Risque P0 : écart de caisse si une commande est partiellement créée serveur puis supprimée localement. Tests : annuler panier après remise, après sélection client, après commande garée, après tentative paiement échouée, contrôle rapports fin de journée.

## 23. P1 — Totaux HT/TTC et perception opérateur

Le total affiché porte explicitement “HT” et le commentaire indique une taxe recalculée côté serveur. Pour une caisse restaurant, l’opérateur s’attend souvent au TTC à encaisser. Risque : confusion, rendu monnaie faux si `props.form.total` diffère du total visuel, ticket client contesté. Il faut aligner `subtotal`, `delivery_charge`, `posDiscount`, taxes et total paiement. Tests : article taxable, article non taxable, remise fixe, livraison, comparaison affichage panier, modal paiement, reçu et backend.

## 24. P1 — Couverture tests bout-en-bout recommandée

La priorité est une suite E2E POS couvrant caisse, borne, cuisine, file d’attente et paiements. Scénarios minimaux : takeaway cash, takeaway carte, livraison avec adresse, menu complexe, commande garée reprise, annulation, double clic paiement, coupure réseau, multi-branches. Les assertions doivent comparer UI, payload API, ordre cuisine, ticket reçu et rapport caisse. Les composants citables sont `PosComponent.vue`, `PaymentComponent.vue`, `ReceiptComponent.vue`, `posCart`, `kioskHardware`; les endpoints API restent non visibles dans l’extrait.

THEME_DONE: mots=2048|chars=13972

---

# Paiements & transactions (backend + fiscalité aperçu)

## 1. Périmètre audité — paiements, transactions et fiscalité

Le périmètre visible couvre principalement `App\Services\PaymentService` et de larges portions de `App\Services\OrderService`. Les flux observés concernent la création de commandes Web/App, POS caisse, paiement immédiat POS, cashback assimilé à remboursement, transactions, statuts de paiement et amorce de traçabilité fiscale NF525. Les composants borne, cuisine/KDS, temps réel WebSocket et file d’attente sont évoqués indirectement via `OrderCreated`, `queue_number`, notifications et statut commande, mais leurs implémentations complètes ne sont pas visibles dans l’extrait.

## 2. Criticité globale du sous-système

Le sous-système paiements & transactions est critique P0 car il conditionne le chiffre d’affaires, la conformité fiscale, la cohérence caisse/cuisine et la confiance multi-branches. L’extrait montre plusieurs correctifs déjà intégrés : recalcul serveur, idempotence POS, verrouillage de file d’attente, séquence fiscale, audit HMAC du cashback. Le risque résiduel majeur porte sur l’atomicité entre commande, transaction, paiement et événements temps réel. Toute divergence entre `payment_status`, `Transaction`, audit fiscal et affichage cuisine peut créer des écarts comptables ou opérationnels.

## 3. `PaymentService::payment()` — création de transaction

Dans `App\Services\PaymentService`, la méthode `payment($order, $gatewaySlug, $transactionNo)` recherche une transaction par `order_id`, puis en crée une si absente. Elle marque ensuite la commande comme `PaymentStatus::PAID`. Le modèle est simple et efficace, mais le verrouillage concurrentiel n’est pas visible. Deux callbacks gateway simultanés pourraient théoriquement créer deux transactions si aucune contrainte unique n’existe sur `transactions.order_id` ou `transaction_no`. Recommandation P0/P1 : contrainte DB unique, transaction SQL, idempotence par référence gateway.

## 4. Synchronisation entre statut commande et transaction

La cohérence entre `orders.payment_status` et `transactions` est essentielle. Le code marque la commande payée même si une transaction existante est réutilisée, sans vérifier son montant, son signe, son type ou son gateway. Cela peut être acceptable pour un callback idempotent, mais risqué si une transaction antérieure incomplète ou mutée existe. Test recommandé : simuler commande avec transaction montant différent, puis appel `payment()`. Attendu : rejet ou alerte audit. Actuellement, la vérification n’est pas visible dans l’extrait.

## 5. `PaymentService::cashBack()` — logique de remboursement

La méthode `cashBack()` crée une transaction négative de type `cash_back` seulement si une transaction initiale existe. Elle crédite ensuite le solde utilisateur. Cette intention est cohérente : pas de cashback sans paiement initial. Cependant, le nom `cashBack` peut mélanger remboursement, avoir client et retour de monnaie. En caisse, ces cas doivent être fiscalement distincts. Le code écrit un audit fiscal via `AuditLogService`, point positif, mais ne montre pas de validation contre cashback multiple ou montant supérieur au paiement initial.

## 6. Risque de cashback multiple

Le cashback crée une nouvelle transaction à chaque appel si une transaction initiale existe. Rien dans l’extrait ne bloque plusieurs remboursements sur la même commande. C’est un risque P0 : un caissier, un bug ou un retry réseau pourrait créditer plusieurs fois le solde utilisateur. Il faut contrôler le cumul des transactions `sign='-'` et `type='cash_back'` par `order_id`, comparer au total payé, puis verrouiller la commande. Tests nécessaires : double clic caisse, retry API, appel concurrent multi-terminal, cashback après annulation.

## 7. Audit fiscal NF525 du cashback

Le bloc `[POS-9.4.BL.2]` dans `PaymentService::cashBack()` écrit un audit via `App\Services\Fiscal\AuditLogService`. Les données incluent branche, utilisateur, action, commande, transaction, moyen de paiement, montant et séquence fiscale. C’est une bonne pratique : le remboursement reste détectable même si la ligne `Transaction` est modifiée. Limite : la robustesse HMAC, l’immutabilité physique et le stockage append-only ne sont pas visibles dans l’extrait. À auditer dans `AuditLogService` et migrations associées.

## 8. Transactions et multi-branches

Les transactions visibles sont liées à `order_id`, mais la table `transactions` ne reçoit pas explicitement `branch_id` dans `PaymentService`. Si le modèle `Transaction` n’a pas de relation fiable vers `Order`, les rapports multi-branches peuvent dépendre d’un join permanent. En environnement FoodKing multi-sites, la caisse d’une branche ne doit jamais voir, rembourser ou rapprocher une transaction d’une autre branche. Recommandation : ajouter `branch_id` dénormalisé, contrainte cohérente avec `orders.branch_id`, scopes par branche et tests d’isolation admin/cashier.

## 9. POS caisse — paiement marqué payé dès création

Dans `OrderService::posOrderStore()`, la commande POS est créée avec `payment_status => PaymentStatus::PAID`. Cela correspond à un flux caisse où le paiement est supposé immédiat. Le code valide ensuite le cash reçu contre le total recalculé, ce qui est positif. Risque : si une erreur survient après la création mais avant sauvegarde finale, la transaction SQL annule normalement. En revanche, la création d’une ligne `Transaction` POS n’est pas visible dans l’extrait tronqué. À vérifier impérativement.

## 10. Validation cash contre total réel

Le bloc `[AUDIT-P1-B]` dans `OrderService::posOrderStore()` compare `pos_received_amount` au total serveur recalculé. C’est une correction importante : la validation client ou request peut être basée sur un total manipulé. Pour la caisse, c’est P0/P1 car un montant reçu insuffisant ne doit pas générer un ticket payé. Tests recommandés : prix client falsifié, remise excessive, taxes recalculées, paiement cash partiel, arrondis. Il faut aussi tester les moyens non cash : carte, terminal externe, wallet, bon, borne.

## 11. Canaux de paiement visibles et non visibles

Les canaux explicitement visibles incluent gateway Web/App (`gatewaySlug`), POS cash via `PosPaymentMethod::CASH`, `pos_payment_method`, et transactions `payment_method`. Les flux borne, terminal carte intégré, paiement fractionné, wallet, titres restaurant ou QR paiement ne sont pas visibles dans l’extrait. Le modèle doit distinguer canal commercial, moyen d’encaissement et prestataire technique. Sinon, les rapports fiscaux et rapprochements bancaires deviennent ambigus. Recommandation : référentiel normalisé des moyens de paiement, mapping fiscal, tests de reporting par canal.

## 12. Recalcul serveur des montants

`OrderService::myOrderStore()` et `posOrderStore()` suppriment les champs financiers client (`total`, `subtotal`, `discount`) puis recalculent depuis la base. C’est une défense P0 contre manipulation de prix. Les variations, extras, taxes et coupons sont recalculés, soit via `PricingService`, soit via chemin legacy. Ce pattern doit être appliqué à tous les canaux : caisse, borne, Web/App, agrégateurs livraison. Tests nécessaires : payload prix à zéro, extra gratuit falsifié, quantité négative, coupon modifié, taxe absente.

## 13. Pricing SSOT et divergence legacy

Le code utilise `config('pricing.use_ssot_service', true)` pour choisir `PricingService` ou un chemin legacy. Cette double logique augmente le risque de divergence : un même panier peut donner un total différent selon configuration. Pour paiements, c’est dangereux car transaction, ticket, cuisine et audit fiscal doivent partager les mêmes montants. Recommandation P1 : faire du SSOT l’unique source en production, garder legacy seulement en tests contrôlés, et comparer automatiquement les résultats durant une période de shadow mode.

## 14. Coupons, remises manuelles et traçabilité

Les coupons sont résolus côté serveur via `CouponService`. En POS, une remise manuelle est acceptée si elle ne dépasse pas le sous-total. C’est cohérent opérationnellement, mais fiscalement sensible. Toute remise manuelle caisse doit être auditée avec utilisateur, branche, justification éventuelle, ancien total, nouveau total et référence ticket. L’extrait indique que la remise “will be logged below”, mais la partie tronquée ne permet pas de confirmer. Si l’audit n’existe pas, c’est un point P1 à corriger.

## 15. Idempotence POS et double clic caisse

`posOrderStore()` lit l’en-tête `X-Idempotency-Key` et recherche une commande existante par clé et branche cible. C’est un bon correctif contre double clic ou réseau instable. Le commentaire souligne un risque multi-branches déjà traité. Limite : une recherche puis création sans contrainte unique DB reste vulnérable à deux requêtes concurrentes strictement simultanées. Recommandation : index unique `(branch_id, idempotency_key)` nullable, gestion de collision, test de concurrence avec deux workers caisse.

## 16. File d’attente et numéro de ticket

La file d’attente est gérée via `queue_number`, verrou `Cache::lock`, et calcul du maximum quotidien par branche. Cela couvre la caisse, la borne et potentiellement Web/App si même logique appliquée. Le format `A0001` est stable. Risque : fallback sur microtime en cas de timeout peut créer collision ou rupture de séquence opérationnelle. Pour cuisine/KDS, un doublon de queue number perturbe la préparation. Recommandation : contrainte unique `(branch_id, date, queue_number)` ou colonne dédiée `queue_date`.

## 17. Séquence fiscale par branche

`OrderService::posOrderStore()` réserve `fiscal_sequence_no` via `FiscalSequenceService::next((int) branch_id)`. Le commentaire indique verrou cache, `lockForUpdate`, transaction et séquence monotone sans trou par branche. C’est central NF525. Il faut néanmoins auditer l’implémentation réelle, non visible ici. Attention aux transactions imbriquées : si un numéro est calculé depuis `MAX()` et rollback externe, le comportement annoncé doit être prouvé par tests. Cas à tester : concurrence caisse, rollback après séquence, multi-branches, restauration backup.

## 18. Temps réel et événements après commit

Dans `myOrderStore()`, les notifications et `OrderCreated` sont dispatchées après la transaction, ce qui évite des commandes fantômes en cuisine/KDS. C’est une excellente pratique. Pour `posOrderStore()`, la partie post-commit est tronquée ; il faut vérifier que caisse, borne, cuisine et écrans temps réel reçoivent aussi les événements après commit. Un dispatch avant commit peut afficher un ticket annulé par rollback. Tests : exception après insert items, erreur paiement, rollback fiscal, absence de notification cuisine.

## 19. Cuisine/KDS et cohérence paiement-préparation

Les composants cuisine ne sont pas visibles directement, mais les événements `OrderCreated`, statuts `OrderStatus::ACCEPT/PENDING`, notifications et `queue_number` les impactent. Pour le POS, la commande est acceptée et payée immédiatement ; elle peut donc partir en cuisine sans validation ultérieure. Pour Web/App, elle est pending puis paiement séparé possible. Il faut définir une règle claire : la cuisine prépare-t-elle avant paiement confirmé ? Si oui, risque d’impayé ; sinon, risque délai client. Cette politique doit être uniforme par canal.

## 20. Borne de commande — points non visibles

Le canal borne n’apparaît pas explicitement dans l’extrait, sauf via concepts génériques : POS, queue number, source, paiement, temps réel. Une borne introduit des risques spécifiques : abandon de panier, paiement terminal asynchrone, ticket imprimé après autorisation, retry callback, commande envoyée cuisine avant capture. Les mêmes garanties doivent s’appliquer : idempotence, transaction unique, recalcul serveur, audit fiscal, verrou file d’attente. Si la borne utilise une API séparée, elle doit réutiliser `PricingService`, `FiscalSequenceService` et règles multi-branches.

## 21. Cohérence des statuts d’annulation et remboursement

`OrderCanceled` est importé dans `OrderService`, mais le flux complet d’annulation n’est pas visible. Paiement et annulation doivent être strictement couplés : une commande payée annulée doit déclencher remboursement, avoir ou justification de non-remboursement. Le cashback ne suffit pas si les gateways externes nécessitent refund API. Recommandation P0 : machine d’état unique `OrderStateMachine`, transitions autorisées selon `payment_status`, transaction compensatrice obligatoire, audit fiscal. Tests : annulation avant paiement, après paiement, après cuisine commencée, multi-paiement.

## 22. Modèle `Transaction` — complétude comptable

Les champs visibles lors de création sont `order_id`, `transaction_no`, `amount`, `payment_method`, `sign`, `type`. C’est minimal. Pour une comptabilité fiable, il manque potentiellement : devise, gateway status, raw gateway reference, branch_id, cashier_id, captured_at, refunded_at, parent_transaction_id, idempotency key, fees, reconciliation batch. Ces éléments ne sont pas visibles dans l’extrait. Sans eux, les rapprochements bancaires et audits multi-branches seront fragiles. Priorité P1 : enrichir le modèle ou créer une table ledger append-only.

## 23. Ledger fiscal append-only

Le cashback dispose d’un audit HMAC, mais le paiement lui-même dans `PaymentService::payment()` ne semble pas écrire d’audit fiscal dans l’extrait. Pour NF525, chaque encaissement, remboursement, annulation, remise et clôture de caisse doit être inscrit dans une chaîne infalsifiable. Recommandation P0 : écrire un événement fiscal pour paiement accepté, capture gateway, transaction POS, cashback, annulation et correction. L’audit doit référencer branche, caisse, utilisateur, commande, transaction, montants HT/TTC/taxes, séquence et hash précédent.

## 24. Tests de concurrence prioritaires

Les tests doivent cibler les collisions et écarts : double callback gateway sur `PaymentService::payment()`, double cashback, double clic POS avec même idempotency key, deux caisses même branche créant une commande simultanément, deux branches même clé, rollback après allocation queue/fiscal sequence. Il faut aussi tester le temps réel : aucun événement cuisine avant commit. Les tests doivent être automatisés avec transactions parallèles, contraintes DB et assertions sur `orders`, `transactions`, audit fiscal, queue number, `payment_status`.

## 25. Priorisation P0/P1 recommandée

P0 : empêcher double transaction, double cashback, divergence payé/non payé, absence audit encaissement, fuite multi-branches et envoi cuisine avant commit. P1 : enrichir `Transaction`, normaliser moyens de paiement, supprimer divergence SSOT/legacy, renforcer contraintes DB queue/idempotence, auditer remises manuelles. La base code montre une direction saine : recalcul serveur, audit cashback, séquence fiscale, idempotence branchée. Mais pour une certification robuste, la cohérence doit être prouvée par contraintes, tests concurrents et journal fiscal exhaustif.

THEME_DONE: mots=1975|chars=13280

---

# Commande en ligne & FrontendOrder

## 1. Périmètre audité et source de vérité

Le sous-système audité couvre la commande en ligne et la commande borne via `App\Services\FrontendOrderService`, avec appui sur `App\Services\OrderService` selon le document de flux. La source de vérité est clairement définie : MySQL plus les services applicatifs, les clients locaux ne faisant qu’émettre des intentions. C’est positif pour la cohérence caisse, borne, cuisine et OSS. Risque P1 : plusieurs chemins historiques persistent encore avec mutation directe puis `recordTransition()`, ce qui impose une vigilance continue sur les invariants de statut.

## 2. Création de commande et recalcul serveur

Dans `FrontendOrderService::myOrderStore()`, les champs financiers fournis par le client sont supprimés avant création : `total`, `subtotal`, `discount`. Le recalcul serveur protège contre la manipulation côté borne ou web. La logique récupère prix, variations, extras et taxes depuis la base, ou délègue au `PricingService` via `PricingRequest::forKiosk()`. Risque P0 historiquement critique bien couvert. Test recommandé : soumettre un panier avec prix client falsifiés, extras croisés et remise abusive, puis vérifier que la commande persiste uniquement les montants recalculés.

## 3. Idempotence borne et double-tap

Le traitement de l’en-tête `X-Idempotency-Key` utilise `Cache::lock()` et une recherche sur `FrontendOrder::where('idempotency_key', ...)`. C’est essentiel pour les bornes, sujettes aux retries réseau, doubles clics ou retours de paiement ambigus. Le code prévoit aussi la récupération après contrainte unique SQL `23000`. Point P1 : la recherche de commande existante n’est filtrée que par clé, pas explicitement par branche dans l’extrait. Le verrou inclut la branche, mais la requête de récupération devrait idéalement aussi intégrer le contexte multi-branches.

## 4. Cohérence borne, type de commande et branche

La borne est détectée via `KioskMachine::where('user_id', Auth::user()->id)`, puis la branche est forcée depuis la machine. C’est une bonne défense contre l’usurpation de `branch_id`. Le code accepte `OrderType::KIOSK` et `OrderType::TAKEAWAY`, utile pour les flux comptoir. Risque P1 : la cohérence entre utilisateur machine, branche, mode de paiement et type d’ordre repose sur ce service ; les autres endpoints ne sont pas visibles dans l’extrait. Des tests multi-branches doivent vérifier qu’une borne A ne peut jamais créer, finaliser ou lire une commande de branche B.

## 5. Canaux de paiement et acceptation différée

Le service distingue paiement immédiat cash borne et paiements différés carte ou ticket restaurant. Les commandes cash borne sont marquées `PAID` et auto-acceptées ; les paiements carte/ticket restent en attente jusqu’à `finalizePaidKioskOrder()`. La défense P0 importante est présente : `finalizePaidKioskOrder()` revérifie `payment_status === PAID` sous `lockForUpdate()`. Cela évite qu’un appel prématuré fasse entrer une commande impayée en cuisine. Test recommandé : deux callbacks concurrents, un non payé et un payé, avec vérification d’un seul passage à `ACCEPT`.

## 6. Machine à états et transitions métier

Le document impose `OrderStateMachine` comme règle canonique. Dans l’extrait, `changeStatus()` utilise `ValidStatusTransition`, puis mutation et `OrderStateMachine::recordTransition()`. `finalizePaidKioskOrder()` enregistre aussi la transition `PENDING → ACCEPT`. C’est cohérent avec la zone historique gelée. Risque P1 : certaines mutations internes assignent encore directement `$order->status`, ce qui exige discipline et tests de non-régression. Le chemin préféré `OrderStateMachine::apply()` est documenté, mais son usage complet n’est pas visible dans l’extrait.

## 7. Annulation client et remboursement

`FrontendOrderService::changeStatus()` autorise uniquement l’annulation par le propriétaire de la commande. Pour les commandes borne ou takeaway, le seuil d’annulation est `PREPARING`, alors que les autres utilisent `ACCEPT`. Le remboursement transactionnel est déclenché via `PaymentService::cashBack()` si une transaction existe, puis `LoyaltyService::refundPoints()`. Risque P0/P1 : ces compensations sont hors transaction visible ici, et leur idempotence dépend de composants non visibles dans l’extrait. Tests indispensables : annulation répétée, remboursement partiel, fidélité déjà remboursée, et commande passée en préparation simultanément.

## 8. Cuisine, KDS et signaux temps réel

Les notifications post-commit sont envoyées après `DB::transaction()`, ce qui réduit les commandes fantômes côté KDS. `OrderCreated::dispatch()` et `OrderStatusChanged::dispatch()` alimentent les flux temps réel, supposés être outbox ou after-commit selon les événements. C’est critique pour cuisine, caisse et OSS. Risque P1 : `SendOrderMail`, `SendOrderSms`, `SendOrderPush` sont aussi dispatchés directement ; leur stratégie after-commit n’est pas visible dans l’extrait. Tests recommandés : rollback forcé après insertion d’items, puis absence totale de commande visible cuisine.

## 9. Numéro de file d’attente

L’allocation de `queue_number` utilise un verrou cache par branche et date : `queue_lock_{branch}_{today}`. La requête calcule `MAX(CAST(SUBSTRING(queue_number, 2) ...))`, puis génère `A0001`, `A0002`, etc. C’est adapté à la file d’attente borne/caisse et évite la collision au premier ordre. Risque P1 : le fallback sur microtime peut produire une valeur non séquentielle, voire conflictuelle en cas de forte concurrence. Ajouter une contrainte unique `(branch_id, date, queue_number)` ou équivalent renforcerait la garantie.

## 10. Cohérence multi-branches

La branche est présente sur `FrontendOrder`, `OrderItem`, et dans la génération de file. Les items sont vérifiés via `AvailabilityService::assertItemsOrderableForBranch()`, ce qui évite qu’une borne d’une branche vende un produit indisponible localement. Risque P1 : les variations et extras sont vérifiés par appartenance à l’item, mais leur disponibilité spécifique par branche n’est pas visible dans l’extrait. Pour une chaîne multi-sites, il faut tester menus, ruptures, taxes, prix et extras par branche. Les scénarios doivent inclure synchronisation tardive entre back-office, borne et cuisine.

## 11. Sécurité IDOR sur commandes et adresses

La méthode `show()` refuse l’accès si `user_id` ne correspond pas à `Auth::id()`. Pour les adresses, `Address::where('id', ...)->where('user_id', Auth::user()->id)` empêche de snapshotter l’adresse d’un autre client. C’est une bonne couverture IDOR. Risque spécifique borne : les commandes borne ont souvent `user_id = machine`, pas client final ; la consultation client via code ticket ou OSS n’est pas visible dans l’extrait. Il faudra auditer séparément les endpoints de suivi public afin d’éviter exposition multi-clients.

## 12. Fidélité et remises inline

La remise fidélité est appliquée serveur côté `myOrderStore()` avec verrou `lockForUpdate()` sur l’utilisateur via `loyalty_code`. Les points requis sont calculés depuis un taux paramétré, avec seuil minimal. Le code écrit aussi `LoyaltyTransaction`, ce qui améliore l’audit. Risque P0 mentionné dans les commentaires : double déduction possible si un client appelle aussi `LoyaltyController::redeem()` avant commande. Le contrat d’API doit être strict. Tests recommandés : deux commandes simultanées même code fidélité, coupon prioritaire, points insuffisants, rollback après débit.

## 13. Coupons et priorité des remises

Les coupons sont résolus via `CouponService::resolveCouponById()` et le discount calculé serveur. Si coupon valide et loyalty_code présent, la fidélité est ignorée avec log explicite. Le stockage `OrderCoupon` associe coupon, commande, utilisateur et remise. Risque P1 : dans la branche SSOT, `PricingService` calcule déjà la remise, puis le coupon est à nouveau résolu pour persistance ; la cohérence exacte entre montant SSOT et `OrderCoupon.discount` doit être testée. Scénarios requis : coupon expiré, seuil non atteint, usage unique, et coupon valable dans une autre branche.

## 14. Composition, allergènes et snapshots immuables

Les lignes de commande enregistrent `composition_snapshot`, variations, extras, instruction, prix unitaires et totaux. Les allergènes sont hydratés via `OrderItemAllergenSnapshot::hydrate()`. C’est essentiel pour conformité, cuisine et litiges client. Le code legacy utilise `CompositionSnapshotBuilder`, tandis que le chemin SSOT fournit `orderItemInsertRows`. Risque P1 : la parité exacte entre POS, borne et web dépend de helpers partagés. Tests recommandés : item avec allergènes, extra avec allergène, variation sans allergène, modification ultérieure du menu, puis vérification que l’ancienne commande reste inchangée.

## 15. Disponibilité menu et injection croisée

Le code rejette les items inexistants, variations inexistantes, extras inexistants, et vérifie que variation/extra appartient à l’item demandé. C’est une protection P0 contre l’injection d’un extra peu cher sur un produit cher ou l’utilisation de prix client. `AvailabilityService` confirme aussi que les items sont commandables dans la branche. Point à compléter : la validation de disponibilité des extras et variations par branche n’est pas visible dans l’extrait. Tests de sécurité : IDs valides mais mauvaise branche, variation d’un autre item, extra supprimé, produit désactivé pendant panier.

## 16. Taxes et cohérence fiscale

Les taxes sont calculées ligne par ligne avec `TaxType::FIXED` ou pourcentage. Le total fiscal est stocké dans `total_tax`, puis intégré au total final. C’est cohérent pour tickets caisse, reporting et comptabilité. Risque P1 : l’arrondi par ligne puis agrégation peut diverger d’un calcul global, surtout avec coupons, fidélité et quantités multiples. La stratégie doit être documentée et identique entre `PricingService`, POS et frontend. Tests recommandés : TVA fixe, TVA pourcentage, remise totale, multi-quantités, et comparaison ticket caisse / back-office.

## 17. Statuts paiement et statuts commande

Le modèle distingue `payment_status` et `status`. Cette séparation est indispensable : une commande peut être créée mais non payée, payée mais pas encore acceptée, ou annulée avec remboursement. `finalizePaidKioskOrder()` garantit que la cuisine ne reçoit pas une commande carte/ticket sans paiement confirmé. Risque P1 : les événements de paiement externes ne sont pas visibles dans l’extrait. Il faut auditer le contrôleur PSP, la signature webhook, l’idempotence de transaction et la corrélation entre paiement et commande. Tests : callback tardif sur commande annulée, double callback, paiement refusé.

## 18. Notifications caisse, client et cuisine

Après création, le service envoie `SendOrderMail`, `SendOrderSms`, `SendOrderPush`, puis selon le cas `SendOrderGotMail/Sms/Push` et `OrderCreated`. Pour les commandes borne carte/ticket, les signaux de nouvelle commande sont différés jusqu’à paiement confirmé. C’est une bonne séparation entre intention et ordre exploitable cuisine. Risque P1 : la caisse doit néanmoins voir les intentions en attente de paiement selon besoin opérationnel ; ce comportement n’est pas détaillé. Tests temps réel : POS ouvert, KDS ouvert, paiement différé, perte réseau, puis rattrapage correct.

## 19. Gestion des transactions et effets de bord

`DB::transaction()` encapsule création, items, remises, fidélité, file et sauvegarde finale. Les notifications sont hors transaction, ce qui est correct. Les exceptions SQL de doublon idempotent sont traitées. Risque P1 : certains appels externes dans la transaction, comme paramètres `Settings`, services de pricing ou disponibilité, peuvent augmenter la durée de verrouillage. La déduction fidélité sous verrou utilisateur est nécessaire mais doit rester brève. Tests de charge : 50 bornes créant simultanément, même branche, même code fidélité, coupons variés, et vérification absence de deadlock durable.

## 20. Lecture utilisateur et filtrage des commandes

`myOrder()` filtre les commandes par `auth()->user()->id` et exclut `OrderType::POS`. Le tri est protégé par whitelist de colonnes et direction `asc/desc`, ce qui réduit le risque d’injection SQL. Les filtres status et excepts sont simples. Risque P2 : pour les bornes, `user_id` peut représenter la machine, donc cette méthode pourrait retourner les commandes de la borne si exposée au mauvais client. Le routage API et guards ne sont pas visibles dans l’extrait. Tests : client web, borne, employé caisse, tentative de pagination abusive.

## 21. Observabilité et audit métier

Le document impose `order_status_transitions` avec `order_id`, `order_type`, statuts, acteur, raison et date. Dans l’extrait, `OrderStateMachine::recordTransition()` est appelé sur auto-accept, finalisation paiement et annulation. Les logs couvrent aussi fidélité, queue lock timeout et notifications échouées. Risque P1 : les créations de commande ne semblent pas enregistrées comme transition initiale dans l’extrait, sauf événement `OrderCreated`. Selon les exigences d’audit, il peut être utile de tracer explicitement `null → PENDING` ou un événement métier séparé.

## 22. Tests prioritaires P0/P1 proposés

Les tests P0 doivent couvrir : recalcul financier serveur, idempotence, paiement confirmé avant `ACCEPT`, absence de commande cuisine avant commit, et concurrence file d’attente. Les tests P1 doivent couvrir : multi-branches, fidélité concurrente, coupon prioritaire, annulation avec remboursement, snapshots allergènes, et transition illégale. Il faut des tests d’intégration réalistes caisse-borne-cuisine : borne crée commande carte, PSP confirme, KDS reçoit `OrderCreated`, caisse livre, OSS voit `DELIVERED`. Les composants cités sont visibles : `FrontendOrderService`, `OrderStateMachine`, `PricingService`, `CouponService`, `AvailabilityService`.

THEME_DONE: mots=1940|chars=12880

---

# Cuisine KDS & affichage

## 1. P0 — Continuité d’affichage KDS en cas de perte temps réel

Le composant `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` affiche un bandeau `!wsConnected` indiquant une reconnexion ou actualisation toutes les 10 secondes. C’est critique pour la cuisine : une commande caisse, borne ou en ligne ne doit jamais disparaître du flux opérationnel. Risque P0 : si WebSocket tombe et que le polling ne couvre pas tous les statuts, la file d’attente cuisine devient incohérente. Tests requis : coupure réseau, reconnexion, doublons, commandes modifiées pendant déconnexion, multi-branches simultanées.

## 2. P0 — Cohérence entre caisse, borne et cuisine

L’extrait montre des colonnes distinctes : sur place, en ligne, à emporter et borne. Le KDS doit agréger les commandes issues de la caisse, de la borne et des canaux web sans perte de contexte. Risque majeur : une commande borne peut apparaître dans “takeaway” avec badge `BORNE`, tandis qu’une colonne dédiée existe aussi. Il faut vérifier côté API la classification `order_type`, `queue_number` et canal de paiement. La logique serveur n’est pas visible dans l’extrait, donc un audit back-end est indispensable.

## 3. P1 — Déduplication des commandes borne et takeaway

Dans `KitchenDisplaySystemComponent.vue`, un commentaire indique qu’une commande kiosk peut apparaître en colonne takeaway si `order_type=10` et `queue_number` est présent. Ce comportement peut être voulu, mais il expose un risque de double préparation si la même commande est aussi listée dans `filteredKioskOrders`. P1 : contrôler que les filtres `filteredTakeawayOrders` et `filteredKioskOrders` sont mutuellement exclusifs ou explicitement annotés. Tests : commande borne payée CB, commande borne paiement caisse, annulation partielle, refresh KDS et changement de statut.

## 4. P1 — Statuts cuisine et synchronisation des actions

Les boutons `start_preparing` et `mark_done` appellent `orderStatus(order.id, status)` depuis le KDS. La cuisine devient donc un acteur métier modifiant les statuts de commande. Risque : la caisse, la borne ou l’écran file d’attente peuvent afficher un état différent si la propagation temps réel échoue. Il faut vérifier transaction serveur, idempotence, événements WebSocket et rollback UI. Les canaux de paiement doivent aussi être pris en compte : une commande impayée ne devrait pas entrer en préparation sans règle métier explicite.

## 5. P1 — Bump local uniquement et risque de divergence

Le bandeau `kds_bump_local_only_notice` indique que le “bump” est local. Les fonctions `kdsBump`, `kdsRecall`, `kdsIsBumped` et `kdsCanRecall` sont citées dans le template, mais leur implémentation n’est pas visible dans l’extrait. Risque P1 : un poste cuisine masque un item, tandis qu’un autre poste ou une autre branche le voit encore actif. Ce choix doit être documenté. Tests : deux écrans KDS ouverts, bump simultané, refresh navigateur, changement de statut commande et rappel après expiration.

## 6. P1 — File d’attente borne et numéro de retrait

La colonne “Borne” affiche `queue_number` avec un badge `N°`. Ce numéro est central pour la file d’attente client et le retrait comptoir. Risque : si la cuisine marque `PREPARED`, l’écran d’appel ou la caisse doit recevoir l’information immédiatement. Le code KDS montre l’affichage, mais pas le système d’appel file d’attente ; il est donc non visible dans l’extrait. Tests prioritaires : paiement borne accepté/refusé, génération du numéro, ordre FIFO, rappel client, affichage multi-écrans et collision de numéros entre branches.

## 7. P1 — Cohérence multi-branches et administration centrale

Le bandeau `kdsIsCentralAdmin` affiche `label.kds_admin_polling_hint`, suggérant un mode administrateur central avec polling. Dans un contexte multi-branches FoodKing, un admin central ne doit pas mélanger les commandes cuisine de restaurants différents. Risque : mauvaise préparation, fuite de données opérationnelles ou modification de statut sur la mauvaise branche. Il faut auditer les filtres d’API par `branch_id`, rôles et permissions. Non visible dans l’extrait : requêtes serveur, middleware, scopes Eloquent, payload WebSocket et stratégie de cache.

## 8. P1 — Limite de volume et saturation visuelle

Les bandeaux `kdsOrderApproachingCap` et `kdsOrderListAtCap` préviennent d’un plafond de commandes. C’est une bonne défense UX, mais elle peut masquer un problème critique : si la liste est pleine, des commandes caisse, borne ou en ligne peuvent ne pas être visibles. Risque P1 voire P0 en rush. Il faut définir la politique : pagination, priorité par ancienneté, statut, station cuisine ou paiement confirmé. Tests : 200 commandes simultanées, tri, recherche, polling, mémoire navigateur et garantie qu’aucune commande active n’est ignorée.

## 9. P2 — Filtre station cuisine et routage des items

Le template propose `stationFilter` avec `bar`, `cuisine_chaude`, `cuisine_froide`. C’est utile, mais le routage réel des items par station n’est pas visible dans l’extrait. Risque : une boisson apparaît en cuisine chaude ou un plat froid disparaît si la métadonnée station est absente. Il faut vérifier mapping produit, variations, extras et menus composés. Tests : item multi-stations, commande mixte caisse/borne, modification produit après commande, station “all” et impression ticket cuisine par zone.

## 10. P1 — Regroupement par table et service en salle

Le KDS supporte `groupByTable`, `toggleTableGroup`, `dineinTableKey` et `kdsDineinTableHeaderVisible`. Cette logique améliore le service sur place, mais présente un risque si plusieurs commandes existent pour une même table. La cuisine doit distinguer additions séparées, commandes envoyées à des temps différents et statuts partiels. Tests : table 12 avec deux tickets caisse, ajout d’items après acceptation, changement de table, fusion de commandes et statut `PREPARED` partiel. Le back-end de regroupement n’est pas visible dans l’extrait.

## 11. P1 — Instructions, allergènes et responsabilité métier

Le fichier utilitaire visible, probablement importé par le KDS, contient `isLikelyExclusionOrHoldInstruction` et `kdsInstructionVisualClass`. Il classe visuellement les instructions via regex : allergènes, exclusions, “sans”, “no”, “hold”. C’est pertinent, mais ne doit pas remplacer un vrai modèle d’allergènes structuré. Le commentaire précise que `allergens_snapshot` doit gagner si présent. Risque P1 : faux négatif sur allergène non listé ou faux positif. Tests : français, anglais, fautes de frappe, accents, pluriels, instructions longues, allergènes saisis en extras.

## 12. P1 — Badge allergènes et cohérence avec snapshot API

Le template affiche un badge `kds-allergens-badge` via `orderHasAllergens(order)` et ouvre `openAllergensModal(order)`. L’implémentation n’est pas visible dans l’extrait, donc l’audit doit vérifier la source des données : `allergens_snapshot`, produits, variations, extras ou instruction libre. Risque : badge absent sur commande borne si le snapshot n’est pas généré au moment du paiement. Tests : produit allergène, extra allergène, retrait d’ingrédient, commande modifiée caisse, impression ticket et affichage modal synchronisé entre plusieurs écrans cuisine.

## 13. P2 — Impression ticket cuisine

Le bouton `printKitchenTicket(order)` est présent dans les cartes dine-in, online et takeaway. Pour la borne, la portion tronquée ne permet pas de confirmer l’existence du bouton, donc c’est non visible dans l’extrait. Risque : la cuisine imprime des tickets différents de l’écran si les données ne sont pas figées. Il faut tester format, station, allergènes, instructions, queue number, canal de paiement et statut. Important : l’impression ne doit pas déclencher de changement métier implicite ni masquer une commande non préparée.

## 14. P2 — Recherche et filtres de statut

Le KDS utilise `props.search.status`, `list(status)`, `search`, `searchReset` et `props.search.order_serial_no`. Le filtrage par statut est utile mais dangereux en cuisine si un opérateur oublie un filtre actif. Les boutons changent l’état visuel, mais il faut vérifier une indication persistante claire. Risque : commandes confirmées invisibles pendant que la vue affiche seulement “préparé”. Tests : recherche par numéro borne, recherche par ticket caisse, reset, refresh, changement de branche, combinaison filtre statut + station + groupement table.

## 15. P1 — Sons de nouvelle commande et autorisations navigateur

Le KDS contient un audio `/sounds/kds-new-order.mp3`, avec `soundEnabled` et `soundVolume`. En cuisine, le son est un mécanisme opérationnel important, mais les navigateurs bloquent parfois l’autoplay sans interaction utilisateur. Risque : une commande borne payée ou une commande caisse urgente arrive sans alerte sonore. Tests : Chrome tablette, Safari iPad, volume zéro, préférence persistée, reconnexion WebSocket, commandes multiples en rafale. Il faut également éviter les sons doublés lors du polling et des événements temps réel reçus simultanément.

## 16. P1 — Tri, ancienneté et classes d’attente

Les cartes utilisent `kdsWaitClass(order)`, ce qui suggère un code couleur selon temps d’attente. L’implémentation n’est pas visible dans l’extrait. C’est critique pour prioriser la file cuisine, surtout entre caisse, borne et commandes planifiées. Risque : une commande future en ligne paraît urgente, ou une commande borne immédiate reste neutre. Tests : commande immédiate, advance order, timezone, changement d’heure, fermeture/ouverture branche, tri par ancienneté et statut. Les seuils doivent être configurables par branche ou type de service.

## 17. P2 — Variations et extras hérités des anciennes commandes

Des gardes `Array.isArray(item.item_variations)` et `Array.isArray(item.item_extras)` sont présents, avec commentaires indiquant des commandes legacy où les données pouvaient être objets JSON. C’est une correction pertinente pour éviter des warnings Vue et des rendus cassés. Risque restant : une variation objet non affichée peut priver la cuisine d’une information de préparation. Tests : anciennes commandes, borne avec options, caisse avec extras, API mobile, données nulles, tableau vide, objet sérialisé. Une normalisation serveur serait préférable au simple guard UI.

## 18. P1 — Sécurité des actions depuis le KDS

Le template permet de changer les statuts via `orderStatus` et de déclencher impression, bump et rappel. Les permissions ne sont pas visibles dans l’extrait. Risque : un utilisateur non autorisé, ou une session admin central, modifie la production d’une branche. Il faut auditer guards front, routes API, middleware, policies et journalisation. Tests : rôle cuisine, rôle caisse, rôle manager, admin central, branche différente, session expirée, CSRF/JWT invalide. Les boutons doivent être masqués côté UI, mais surtout refusés côté serveur.

## 19. P2 — Cohérence UI mobile, tablette et écran cuisine

Le template propose des onglets mobiles `items_board` et `todays_order`, avec classes Tailwind et Swiper. En cuisine, l’écran peut être tablette, poste mural ou navigateur TV. Risque : colonnes cachées, scroll interne bloqué, boutons de statut inaccessibles ou cartes repliées par défaut. Tests : 1024x768, écran tactile, zoom navigateur, orientation paysage/portrait, longues instructions, allergènes, file borne chargée. Le comportement `openFilterSlide` avec hauteur animée doit être robuste au clic accidentel et ne pas masquer les boutons critiques.

## 20. P1 — Contrôle de non-régression bout-en-bout

Un plan de test KDS doit couvrir le cycle complet : création commande caisse, commande borne, paiement, entrée file d’attente, affichage cuisine, son, impression, préparation, prêt, rappel client et synchronisation multi-écrans. Le code visible donne de bons points d’ancrage : `KitchenDisplaySystemComponent.vue`, `kdsInstructionVisualClass`, `orderStatus`, `filteredKioskOrders`, `wsConnected`. Les parties non visibles sont l’API, les WebSockets, le routage branche et les paiements. Priorité : tests automatisés API + tests Cypress temps réel + scénarios manuels rush.

THEME_DONE: mots=1764|chars=11842

---

# Borne Kiosk & attente & wizard

## 1. P0 — Orchestration centrale de la borne et état critique de branche

Le composant `resources/js/components/frontend/kiosk/KioskAppComponent.vue` porte une responsabilité très large : chargement de branche, idle timer, panier flottant, offline queue, temps réel Echo, accessibilité, hardware et analytics. Le risque principal est qu’une défaillance de branche bloque toute la borne, ce qui est correct fonctionnellement mais doit être traité comme P0 exploitation. La caisse, la cuisine et la file d’attente dépendent implicitement du `branchId` initial. Test prioritaire : démarrage sans branche, branche invalide, session expirée, puis récupération après retry.

## 2. P0 — Cohérence multi-branches et isolement des événements temps réel

L’extrait montre une garde défensive dans `KioskAppComponent.vue` via `_getActiveBranchId`, `_normalizeBranchId` et `_handleItemAvailabilityChanged`. C’est un point très positif : une borne ne doit jamais réagir aux changements d’une autre branche. Le risque P0 serait une suppression de lignes panier ou d’ordres offline causée par un événement Echo mal routé. Il faut tester des événements `ItemAvailabilityChanged` avec `branch_id` différent, nul, absent, et `subscribedBranchId` explicite. La cohérence caisse/cuisine multi-sites dépend de cette barrière.

## 3. P1 — Distinction catalogue global versus disponibilité de branche

Le code distingue correctement les mises à jour globales de catalogue des changements de disponibilité branche : absence de `is_available` implique refresh éventuel, mais pas pruning du panier. C’est critique pour éviter qu’une modification de prix ou de catégorie annule artificiellement des commandes en borne. Le flux caisse et cuisine doit rester stable : seule une indisponibilité réelle branche doit invalider un produit. Tests recommandés : événement `type: full` sans disponibilité, événement `branch_availability` avec `false`, et contrôle du panier, menu cache et queue offline.

## 4. P0 — File offline, commandes abandonnées et conflits d’indisponibilité

`KioskAppComponent.vue` expose `offlinePending`, `offlineAbandoned`, `offlineConflictEntries` et une CTA de conflit. La borne peut donc accepter ou stocker des commandes hors-ligne, mais le risque P0 est opérationnel : une commande payée ou promise au client peut devenir impossible si le produit devient indisponible. La caisse et la cuisine doivent recevoir une vérité synchronisée. Il faut auditer `helpers/kioskOfflineQueue` non visible dans l’extrait complet : idempotence, retry, abandon, annulation, force retry, limites quota, et affichage personnel.

## 5. P1 — Idempotence des rejouages offline vers la caisse

L’appel `startAutoSync((url, data, config) => axios.post(url, data, config || {}), syncCb)` mentionne explicitement le header `X-Idempotency-Key`. C’est indispensable pour éviter les doublons de commande en caisse ou cuisine après reconnexion. Le risque est une double création si l’idempotency key n’est pas conservée côté stockage local ou ignorée serveur. Tests à prévoir : coupure réseau après validation paiement, retry multiple, refresh borne, reconnexion WebSocket, et vérification qu’une seule commande apparaît en caisse, KDS et file d’attente client.

## 6. P1 — Waiting screen, polling et Echo : redondance saine mais sensible

`resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` combine Echo (`OrderCreated`, `OrderStatusChanged`) et polling toutes les 15 secondes. Cette redondance est bonne pour la résilience temps réel. La garde `_pollInFlight` évite les collisions Echo + intervalle. Le risque est une latence de file d’attente si Echo tombe et que le polling échoue trois fois, avec bannière réseau. Tests : Echo indisponible, polling 500, ordre prêt côté cuisine, ordre livré, et transition visuelle client. La cuisine doit rester source de vérité du statut.

## 7. P0 — Statuts commande et alignement cuisine/caisse/borne

Le composant waiting importe `orderStatusEnum` et s’aligne sur `PREPARED`, `DELIVERED`, `PREPARING`, `CANCELED`. C’est positif : pas de nombres magiques dispersés, sauf l’appel d’annulation qui poste `{ status: 16 }`, malgré la constante `STATUS_CANCELLED`. Risque P1 : divergence si l’enum change côté backend. La caisse, la cuisine et la borne doivent partager le même contrat statut. Test critique : passage `PREPARING` masque l’annulation, `PREPARED` déclenche son/auto-reset, `CANCELED` retourne idle sans panier résiduel.

## 8. P1 — Annulation client avant préparation cuisine

L’écran attente permet l’annulation après 30 secondes, tant que la cuisine n’a pas commencé (`PREPARING+`). C’est cohérent commercialement, mais fragile si l’état cuisine change entre affichage du bouton et clic. Le backend doit refuser et le composant affiche `cancel_blocked`. Le risque caisse est un remboursement ou une annulation partielle mal reflétée. Tests : annulation acceptée, refusée car cuisine démarrée, perte réseau pendant annulation, double clic, modal fermé puis réouvert. Non visible dans l’extrait : logique serveur de remboursement ou réintégration stock.

## 9. P1 — Timeout de 15 minutes et reprise de polling

`KioskWaitingComponent.vue` déclenche `timedOut` après 900 secondes. Le commentaire indique une correction : cliquer hors modal reprend le polling et remet `elapsedSeconds` à zéro. Attention : l’extrait tronqué montre `this.startElapsedTimer` sans parenthèses à la fin visible, ce qui pourrait être une coupure de l’extrait ou un bug réel. Si réel, P1 : le timeout ne redémarre pas correctement. Test automatisé avec timers fake : timeout, dismiss, reprise polling, ordre prêt ensuite, puis auto-reset.

## 10. P1 — Auto-reset après commande prête et nettoyage panier

Après `markReady`, le composant lance un compte à rebours de 20 secondes puis `newOrder`. Le code de `newOrder` est tronqué, donc il faut vérifier qu’il réinitialise bien panier, route, timers, éventuels états fidélité et wizard. Risque : une borne laissée sur une commande précédente ou un panier conservé pour le client suivant. Cela touche la file d’attente et la confidentialité. Tests : commande prête, clic nouvelle commande, auto-reset, changement de route, absence de données personnelles et absence de polling résiduel.

## 11. P0 — Paiement : création commande avant TPE et cohérence financière

`resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` crée d’abord la commande via `submitOrder`, puis gère le TPE pour carte ou ticket restaurant. Le risque P0 est une commande créée en caisse/cuisine alors que le paiement échoue ensuite. Il faut vérifier la suite tronquée : statut de paiement, annulation automatique, compensation, ou passage “payer au comptoir”. La borne ne doit jamais envoyer en cuisine une commande non payée sauf canal cash/comptoir assumé. Tests : refus TPE, timeout TPE, annulation client, succès tardif, double validation.

## 12. P0 — Source de vérité du total et protection contre manipulation panier

Le composant paiement commente une correction importante : total serveur obligatoire (`OrderDetailsResource.total` ou `order_amount`), sauf offline. C’est fondamental pour la caisse : le TPE doit encaisser le montant validé côté serveur, pas le panier local potentiellement obsolète. Risque P0 : montant différent entre borne, caisse et terminal. Tests : prix modifié pendant checkout, remise fidélité expirée, panier local altéré, réponse serveur sans total, total string invalide, total à zéro. Le canal cash doit aussi utiliser la même source serveur.

## 13. P1 — Gestion des échecs TPE et parcours client

Le composant paiement introduit `paymentFailureCount` et `MAX_PAYMENT_FAILURES: 2`, avec redirection probable vers un écran d’erreur dédié après deux refus. C’est cohérent UX borne, évitant une boucle infinie. Le risque opérationnel est l’état de commande intermédiaire : la caisse doit savoir si l’ordre est annulé, à payer au comptoir, ou en attente paiement. La suite est tronquée : vérifier explicitement les routes d’erreur, statuts backend, et analytics. Tests : premier refus puis retry succès, deuxième refus, changement vers espèces, annulation.

## 14. P1 — Canaux de paiement : carte, espèces, ticket restaurant

La borne propose `card`, `cash`, `tr`. Les canaux ont des contraintes différentes : carte/TR impliquent TPE, cash peut impliquer paiement au comptoir ou caisse. Le code visible ne montre pas les règles serveur associées. Risque : commande cash envoyée en cuisine sans encaissement, ou TR traité comme carte sans plafond/éligibilité. Il faut auditer les contrôleurs non visibles. Tests : chaque canal, total nul, total élevé, annulation, disponibilité TPE, offline. La caisse doit recevoir un mode de paiement normalisé et traçable.

## 15. P1 — Hardware kiosk : TPE, haptique, son et healthcheck

`KioskAppComponent.vue`, `KioskWaitingComponent.vue` et `KioskPaymentComponent.vue` utilisent `services/kioskHardware`. Le paiement importe aussi `KIOSK_HARDWARE`. C’est positif : abstraction du matériel. Les risques sont doubles : échec silencieux du TPE et incompatibilité navigateur borne pour audio/haptique. Le waiting screen prévoit fallback visuel si `AudioContext` échoue. Tests : hardware absent, TPE indisponible, haptic exception, AudioContext bloqué, healthcheck périodique. Non visible dans l’extrait : contenu complet de `_bootHardware` et reporting d’erreurs.

## 16. P1 — Accessibilité, idle timer et écran d’inactivité

`KioskAppComponent.vue` applique les préférences via `applyKioskA11yFromStore`, watchers Vuex et `KioskInactivityOverlayComponent`. Le compte à rebours est borné entre 3 et 60 secondes. C’est important pour EAA 2025, PMR, contraste, audio, langue et direction. Risque : l’idle timer se déclenche pendant paiement ou attente TPE, interrompant une transaction. Il faut vérifier `resetIdleTimer`, `startIdleTimer` et exclusions de routes dans la partie tronquée. Tests : interaction clavier/touch, overlay sur wizard, paiement, attente commande et écran confirmation.

## 17. P1 — Wizard et flux de commande non visibles

Le sous-système demandé inclut le wizard, mais le composant wizard n’est pas visible dans l’extrait, seulement référencé par `ROUTE_ORDER` avec `kiosk.wizard`. Il faut donc signaler une zone d’audit incomplète. Risques typiques : options obligatoires non validées, variations indisponibles, prix recalculé différemment entre wizard et serveur, retour panier incohérent, et accessibilité des étapes. Tests nécessaires : produit simple, produit avec modifiers, rupture pendant wizard, changement de catégorie, retour arrière, ajout panier, puis checkout caisse/cuisine avec détails exacts.

## 18. P1 — Transitions route et cohérence de navigation borne

`ROUTE_ORDER` pilote `slide-left` ou `slide-right`, avec exception `kioskStableShell` pour éviter de réanimer le catalogue à chaque `?cat=`. C’est une optimisation UX utile. Le risque est une navigation incohérente si une route non listée apparaît, ou si le wizard/cart/payment évoluent sans mise à jour de l’ordre canonique. Pour une borne, la clarté du parcours client réduit les abandons. Tests : navigation idle → catégories → produits → wizard → panier → paiement → waiting, retours, deep links, query catalogue, et reset.

## 19. P1 — Panier flottant et visibilité selon route

Le panier flottant est masqué sur `idle`, `categories`, `cart`, `payment`, `waiting`, `confirmation`, `upsell`. Il reste visible notamment en produits et wizard selon les routes. C’est cohérent, mais attention : en upsell il est masqué, ce qui peut empêcher une correction rapide si l’upsell agit comme pré-checkout. Le panier affiche total formaté et nombre d’articles. Tests : ajout produit, route wizard, changement disponibilité, total remis à zéro, panier caché pendant paiement. La caisse doit recalculer, mais l’UX doit rester cohérente.

## 20. P0 — Temps réel cuisine vers borne : contrat d’événements

`KioskWaitingComponent.vue` dépend de `services/eventContract` via `onEvents`, tandis que `KioskAppComponent.vue` utilise `onEvent` pour disponibilité. Cette centralisation est saine, mais le contrat événementiel devient critique : noms `OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`, payloads `order_id`, `queue_number`, `branch_id`, `is_available`. Risque P0 : changement backend non détecté, borne bloquée en préparation. Tests contractuels à ajouter : schémas payload, événement malformé, branche absente, ordre inconnu, duplication, retard. La cuisine doit publier exactement le statut attendu.

## 21. P1 — Gestion réseau et bannière de connexion

`ConnectionStatusBanner` est monté globalement dans `KioskAppComponent.vue`, et `KioskWaitingComponent.vue` affiche aussi une bannière locale après trois échecs de polling. Cette double information peut être utile, mais il faut éviter la confusion client. Risque : la borne indique offline alors que la commande est bien en cuisine, ou inversement. Tests : perte réseau pendant paiement, pendant attente, pendant menu, puis reconnexion. La file offline doit afficher clairement ce qui est sauvegardé localement versus envoyé à la caisse/cuisine.

## 22. P1 — Toasts, messages et internationalisation opérationnelle

Les composants utilisent `$t` et un `provide` global `showToast`. C’est propre pour harmoniser les retours utilisateur. Cependant, quelques textes restent en dur dans l’extrait, par exemple le bouton `Voir` de la CTA conflit offline et le toast quota “File saturée. Veuillez relancer la borne.” Risque : incohérence langue, accessibilité et exploitation internationale. Tests : locale française, autre locale, RTL via `dir`, messages offline, erreurs paiement, annulation. Les messages caisse/cuisine ne sont pas visibles dans l’extrait et doivent être audités séparément.

## 23. P1 — Nettoyage des listeners et prévention des fuites

`beforeUnmount` de `KioskAppComponent.vue` nettoie timers, auto sync, listeners touch, Echo, watchers a11y, WebSocket reconnect, quota listener et debounce stale. `KioskWaitingComponent.vue` nettoie polling, countdown, elapsed et Echo. C’est solide. Risque résiduel : route transitions rapides, double mount, ou composant payment quittant pendant attente TPE. Tests : navigation répétée, reset kiosk, ouverture admin, changement route pendant polling, paiement annulé. Les fuites peuvent créer doubles commandes, doubles polls, ou événements cuisine traités plusieurs fois.

## 24. P0 — Priorités de tests bout-en-bout caisse/borne/cuisine

Les tests E2E prioritaires doivent couvrir le triangle complet : borne crée commande, caisse reçoit paiement/statut, cuisine prépare, file d’attente affiche prêt. Scénarios P0 : paiement carte succès, refus TPE, cash, TR, offline puis resync, rupture produit pendant panier, événement mauvaise branche, annulation avant cuisine, statut prêt via Echo, fallback polling, timeout 15 minutes. Chaque scénario doit vérifier absence de doublon, total identique, queue number stable, panier reset, et état final caisse/cuisine. Les parties backend et wizard détaillé sont non visibles dans l’extrait.

THEME_DONE: mots=1904|chars=13241

---

## Méthodologie & compteurs

- **Cible** : **100000** tokens (demande utilisateur).
- **Cumul `usage` API** (quand fourni) : **total = 0** (prompt 0 ; completion 0).
- **Cumul estimé** (si stream sans usage : ~1 token / 3,2 car, conservateur) : **total ≈ 100838**.
- **Recommandation exploitation** : utiliser le **tableau le plus fiable côté facturation** : si le fournisseur affiche l’`usage` complet sur le **dashboard** pour chaque requête, c’est la ref ; sinon, l’estimation sert d’**ordre de grandeur** (les streams ne renvoient souvent pas `usage` à chaque chunk).
- Outil : `scripts/codex-mega-intelligence-audit.mjs` (stream) — **évite les HTTP 504** des requêtes `stream:false` massives (≈ 60s timeout Cloudflare).

> **Cible d’envergure** : atteinte au sens **estimé** ou **API** (cf. nombres ci-dessus).
