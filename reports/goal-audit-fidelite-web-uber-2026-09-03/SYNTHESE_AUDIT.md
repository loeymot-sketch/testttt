# Audit global — structure, sessions parallèles, et les 3 défauts signalés
### 2026-09-03 · branche `pos/category-first-caisse-2026-06-23` · HEAD `a91f95e2e`

> Méthode : 6 agents d'audit en lecture seule, puis 3 agents **adversaires** chargés de démolir
> leurs conclusions. **Neuf affirmations de mes propres agents ont été réfutées ou corrigées par
> les adversaires.** C'est le résultat le plus important de la méthode : un audit qui se confirme
> lui-même ne vaut rien. Rapports détaillés dans `wave-a/`.

> ⚠️ **Portée des chiffres.** Toutes les requêtes SQL portent sur la base locale `foodking_e2e`,
> qui est une base de **développement**, pas la production. Les ordres de grandeur sont
> indicatifs ; aucune décision d'argent ne doit s'appuyer dessus sans re-mesure sur le serveur.

---

## 1. Tes trois signalements — verdicts

### ① « Le premier choix de viande » → **NON REPRODUIT**
Aucune présélection automatique n'existe, ni à la borne ni à la caisse : l'état initial est vide
(`KioskStepViandeComponent.vue:152`, `pos-wizard.js:935,941,961`). Le message
« Sélectionnez au moins 1 Viande 1 » vient du serveur (`MultiVariationConstraint.php:225`) et
son périmètre est correct. La sentinelle du correctif du 2026-06-30 a été rejouée : **14/14 verte**.

*Défaut latent trouvé au passage (P2)* : `pos-wizard.js:363-380` déduit le quota de viande incluse
**à partir du NOM du produit**. Renomme un produit et le quota change en silence. Fichier en zone
gelée → correctif sous LOCK, pas maintenant.

### ② « Le cornichon mis en gratuit, c'était pas la peine » → **la plainte est juste, la cause n'est pas le prix**
Le cornichon est une crudité **gratuite depuis le 2026-05-13**, par mandat écrit
(`config/menu.php:127`, `MenuResetLeCayenneCommand.php:72,872`). Preuve qu'aucune session ne l'a
jamais reprixé : `created_at == updated_at` sur **100 %** des lignes crudité — aucun `UPDATE`
n'a jamais tourné.

**Ce qui s'est réellement passé, et c'est bien récent** : **11 lignes « Cornichon » ont été AJOUTÉES
hier, le 2026-09-02 à 18:32:43** (ids 616-620, 625-630), par une exécution de `menu:heal-light-v2`,
sur le Cayenne, le Suprême, le Méga, le Terminator, le Sandwich Classique et 6 burgers.

**Conséquence vérifiée** : le cornichon n'a de symbole dans aucune des deux tables de symboles.
Il s'imprime donc **comme une ligne de supplément** sur le ticket de cuisine
(`KitchenTicketSymbolicFormatter.php:54-63,355`) et s'affiche « STO Cornichon » à la caisse.
C'est ça que tu vois. → **Décision à prendre (D1).**

### ③ « On modifie un produit dans le panier, le prix ne change pas » → **le défaut existe, et il est trouvé**
Contrairement à ta formulation, les deux surfaces **recalculent bien** (`pos-wizard.js:4651` →
`posCart.js:318-321` → `posCartLineMath.js:44-54` ; borne `KioskWizardComponent.vue:2207,2353`),
40 tests verts, et **aucune exposition NF525** — `PricingService.php:190` facture depuis la base
et les totaux du client sont retirés de la charge utile.

**Mais il y a un vrai défaut de prix, juste à côté (P1)** :
`ItemComponent.vue:1490` teste `extraLower.includes('cheddar')` **sans limiter le périmètre**, et
court-circuite le cas général des suppléments payants (`:1518`). Résultat : quand tu rouvres une
ligne qui contient le VRAI supplément « Cheddar » (30 lignes actives à **0,90 €**), la tuile
s'affiche **non sélectionnée** et la caisse ajoute **1,00 €** au lieu de **0,90 €**
(`pos-wizard.js:1564`, réglage NULL en base → repli en dur).

Le fichier **n'est pas en zone gelée** : correctif possible sans LOCK.
Cause de fond : **zéro test Vitest** ne couvre `posCart/replaceCartLine` — la seule assertion
existante vérifie que le prix **ne bouge pas**. Rien ne vérifie qu'il bouge quand il le doit.

---

## 2. Ce que les sessions parallèles ont fait — et le vrai risque

- **Le cluster « sauces / pain / galette » n'est PAS un risque.** Trois branches ont **0 fichier**
  de différence avec HEAD ; la quatrième est le même travail sous un autre SHA. `git cherry` le
  prouve là où `git log` faisait peur. **Rien à récupérer, rien à arbitrer.**
- **Le vrai piège : `fix/rattrapage-fusion-2026-09-03`.** Les 5 fichiers qu'elle dit « rattraper »
  sont déjà dans HEAD, **et HEAD est plus avancé** (il importe les enums canoniques, la branche
  recopie encore 7 constantes en dur). **La fusionner telle quelle ferait régresser HEAD**, sans
  qu'aucun test ne vire au rouge. → à résoudre morceau par morceau, ou à abandonner.
- **Le gisement orphelin : `fix/uber-order-fetch-v2`** — 10 commits, 22 fichiers, 1 008 lignes,
  **871 commits de retard**, rien dans HEAD. Elle corrige des défauts **payés en bac à sable**
  (lecture de commande v1→v2, corps `[]` rejeté en 400, échecs silencieux non journalisés) et
  apporte la visibilité Uber en caisse / KDS / historique.
  **Arbitrage rendu** : elle se fusionne **pour elle-même**, pas pour préparer Uber Direct —
  elle n'en contient pas une ligne.
- **Zones gelées : 0 ligne modifiée hors HEAD.** Aucune fusion en cours. Arbre sain.
- `origin/pos/category-first-caisse-2026-06-23` est **1 commit en avance** sur le local
  (tableau de bord) — intégrable en avance rapide, coût nul.

---

## 3. Les 4 chantiers demandés — état réel

### A. Fidélité, compte client, points au paiement — **~75 % déjà construit**
Le moteur est mature et éprouvé : grand-livre `loyalty_transactions`, barème à définition unique
(`LoyaltyRules.php`, 10 pts/€, 100 pts = 1 €), **crédit idempotent au PAIEMENT**
(`OrderService.php:1516-1518`), reprise symétrique à l'annulation et au remboursement, QR signé
HMAC-SHA256 avec anti-rejeu. La caisse est la surface la plus aboutie : elle voit le solde, cherche
par téléphone / code / QR, **scanne déjà à la caméra de la tablette**
(`PosLoyaltyIdentifyModal.vue:815`), crée un client, crédite et débite. **241 tests verts** (rejoué).

**Réfutations importantes de mes propres agents :**
- « La vitrine client est éteinte, il faut la rallumer » → **FAUX et dangereux.** La vitrine Vue de
  ce backend est condamnée **exprès** (`routes/web.php:38`, `STAFF_ONLY_MODE=true`). Le vrai site
  client est le **dépôt Vercel séparé**, déjà câblé (`api.js:448` → `POST /api/frontend/order`) et
  qui encaisse réellement : **249 commandes `source_surface='web'`**, dont 13 payées par carte.
  La rallumer construirait un **second site concurrent de celui qui encaisse déjà**.
- « Le paiement carte en ligne n'existe pas » → **FAUX.** **Mollie est actif**
  (`routes/api.php:2019`, `MOLLIE_ENABLED=true`), Apple Pay compris. L'agent n'avait regardé que
  Stripe et PayPal.

**Ce qui manque vraiment** : le consentement RGPD sur le chemin **caisse**, et le lecteur au comptoir (ci-dessous).

### B. Retrait par QR + commandes web séparées — **la séparation existe déjà**
La colonne `source_surface` est réelle et peuplée : `pos` 1823, `kiosk` 1277, **`web` 253**,
`uber_eats` 23. La caisse affiche déjà une pastille « 🌐 N web à traiter » et des files dédiées.

**Le QR de commande existe déjà aussi** : `orders.tracking_token` + `GET api/frontend/order/track-qr/{token}`.
L'alerte « jeton NULL sur 253/253 » a été **réfutée** : depuis le correctif du 2026-08-16,
**56/56 = 100 %** sont renseignés ; le « 0 % » mesurait le calendrier, pas le canal.

**Le manque réel est étroit, et c'est une bonne nouvelle** : il n'existe **aucun lecteur au comptoir**
(0 occurrence dans `components/admin/pos/`), alors que `PosCounterCollectModal.vue` existe déjà et
que le patron « scanner OU taper au clavier » est **déjà en production**. Donc : la validation
manuelle marche dès le premier jour, et **ton lecteur physique se branchera plus tard sans une
ligne de code de plus** (les lecteurs QR s'émulent en clavier).

### C. Temps de préparation 15 min — **le réglage existe, mais il ne touche aucun client**
- La valeur **réelle en base est 30**, pas 15 (`settings` id=40) — le « 15 » n'est qu'un repli de
  code jamais atteint. Chaîne de lecture vérifiée de bout en bout.
- **Le défaut que personne n'avait vu** : avec `STAFF_ONLY_MODE=true`, `router/index.js:287-290`
  renvoie vers `/login` **toutes** les routes client. Cela tue `/checkout` et `/my-orders/:id`,
  c'est-à-dire **les deux seuls écrans qui lisent ce réglage**. Un correctif côté backend serait
  donc **invisible pour 100 % des clients**.
- **Et ton site promet aujourd'hui trois délais différents**, tous en dur, tous faux :
  « Prêt en 8 min » (`screens.jsx:144`), « 8 à 20 minutes » (`:385`), « ~12 min »
  (`funnel.jsx:143,273,683`). Aucun n'appelle l'API.

→ **Conclusion : ce chantier se fait dans le dépôt vitrine Vercel**, pas dans le backend.

### D. Uber Eats livraison — **Uber Direct est absent, mais le socle tarifaire existe**
- L'existant est **Uber Eats Marketplace** (recevoir des commandes d'Uber). **Uber Direct**
  (payer Uber pour livrer TES commandes) : **0 ligne**, confirmé par deux agents indépendants.
- **Les frais par adresse existent et sont réellement atteints** (`OrderRequest.php:108` ←
  `CheckoutComponent.vue:783-793`), branche 1 peuplée : `base=3 €, +2 €/km, minimum=4 €, 3 km offerts`.
  Barème réel : **4/4/4/5/7/9/13/17 €** de 1 à 10 km.
- **MAIS la livraison est éteinte au serveur** (`order_setup_delivery = DISABLE`, migration
  `2026_07_27_093000_disable_delivery_until_launch`). Rien de ce chantier n'a d'effet tant qu'elle
  l'est. → **Décision à prendre (D4).**
- **La caisse contourne le garde de zone** : elle appelle `DeliveryFeeService` directement, sans
  vérifier le polygone ni re-dériver la distance (`PosOrderRequest.php:47-58`, `PosController.php:247-255`).
- **Un défaut NF525 trouvé par l'adversaire** : `OrderService.php:2154` pose
  `driver_id = Auth::id() ?: null`, puis `:2255` **saute l'enregistrement du mouvement de caisse**
  quand il est vide → `ZReportCashEnrichmentService:489` détecte une dérive
  `movement_missing_audit_row` à **chaque encaissement à la porte**. Indépendant d'Uber, à corriger.
- **Risque n°1, vu par personne d'autre** : `orders` **n'a aucune colonne de distance**. La distance
  est calculée puis jetée. Après une bascule Uber, **aucune réconciliation « facturé au client » vs
  « facturé par Uber » n'est possible**, même après coup.
- La démarche à mener chez Uber est rédigée dans `docs/uber/MISSION_OWNER_UBER_DIRECT_2026-09-03.md`.

---

## 4. Deux constats qui dépassent la demande

- 🔐 **Une clé Mollie de PRODUCTION (`live_`) est dans le `.env` local.** Le fichier est bien
  ignoré par git et **n'a jamais été commité** (vérifié). Mais une clé de paiement réelle sur un
  poste de développement encaisse pour de vrai. → **Décision (D5).**
- 🔴 **La machine qui encaisse tourne en `APP_ENV=staging`** (constat antérieur, toujours ouvert,
  `FINDING-APP-ENV-STAGING.md`). Tous les gardes de démarrage NF525 sont donc **inertes** en
  production. Ce n'est pas un correctif de code : c'est un arbitrage d'exploitation.
