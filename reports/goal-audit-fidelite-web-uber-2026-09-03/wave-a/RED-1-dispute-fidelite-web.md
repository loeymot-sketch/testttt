# RED-1 — Contestation adversaire du rapport A2

Cible : `reports/goal-audit-fidelite-web-uber-2026-09-03/wave-a/A2-fidelite-existant.md`.
Dépôt `testttt`, HEAD `a91f95e2e`. Lecture seule. Chaque contestation est ancrée sur un
`fichier:ligne` que J'AI lu ou une requête SQL que J'AI jouée, avec sa sortie.

**Score : 2 réfutées, 1 réfutée sur le remède, 1 indéfendable (et mal attribuée), 1 nuancée.**

---

## 1. « La vitrine client web est éteinte » — **RÉFUTÉE**

A2 a lu les bonnes lignes et en a tiré la mauvaise conclusion. Les redirections existent :
`routes/web.php:43-46` et `frontendRoutes.js:21-25` renvoient bien `/`, `/menu`, `/offers`
vers `/login`. **Mais cette surface n'est pas le site client**, et le commentaire juste
au-dessus le dit : `routes/web.php:38` — « commande en ligne hors périmètre V1 ».
A2 a mesuré une surface volontairement condamnée en 2026-06-25 et l'a prise pour le produit.

Le vrai site client est le dépôt séparé, et il est **entièrement câblé à l'API de ce backend** :
- `/Users/1millnonstop/Downloads/web/index.html:11` — `<meta name="api-base-url" content="http://127.0.0.1:8766">`
- `index.html:58` charge `api.js`, puis `:78-84` chargent `loyalty-v2.jsx`, `orders.jsx`, `account-v2.jsx`
- `api.js:448` — `POST /api/frontend/order` ; `:453` `order/show` ; `:488` `loyalty/history` ;
  `:498` `loyalty/redeem` ; `:523` `loyalty/qr`
- commit `31a4d71` du dépôt vitrine : « WEB aligné borne — fidélité RÉELLE (QR signé lqr.
  mint-on-display + historique + redeem) »

Ce n'est pas une intention : c'est en base. Mesure indépendante (moyen différent d'A2 —
lui a lu des routes, moi j'ai compté des commandes) :

```
mysql> SELECT source_surface, order_type, COUNT(*) n, MIN(DATE(created_at)), MAX(DATE(created_at))
       FROM orders WHERE deleted_at IS NULL GROUP BY source_surface, order_type;
web  10  239   2026-07-08  2026-08-14
web   2    3   2026-07-15  2026-07-15
web  20    2   2026-08-05  2026-08-05
web   5    2   2026-06-13  2026-07-15
web  15    1   2026-08-05  2026-08-05
```

**249 commandes de surface `web`**, dont 40 en `payment_method=4` (carte) et 13 marquées
PAYÉES pour 166,05 €. Le journal projet (`PROJECT_BRAIN.md:3045`, entrée 2026-08-12) décrit
la commande **#440 du site, carte Mollie, 31,40 € encaissés**, puis **#457 (27,60 €) et #459
(27,90 €)** imprimées en service réel. Ces trois-là ne sont PAS dans `foodking_e2e`
(`SELECT ... WHERE id IN (440,457,459)` → 0 ligne) : elles sont en production. Ce qui
révèle au passage un défaut de méthode d'A2 — il a présenté des chiffres d'une base
**e2e de test** comme des faits produit.

**Conséquence sur le plan : la recommandation d'A2 (« réactiver `frontendRoutes`, `/menu`,
`/home` ») est à jeter.** Rallumer la vitrine Vue construirait un SECOND site client
concurrent de celui qui encaisse déjà. Le chantier réel est côté API et côté dépôt Vercel.

---

## 2. « Le paiement carte en ligne n'existe pas » — **RÉFUTÉE**

D'abord la sémantique, qu'A2 a supposée : elle est juste. `app/Enums/Status.php:7-8` —
`ACTIVE = 5`, `INACTIVE = 10`. Et la table dit bien ce qu'il dit :

```
mysql> SELECT id,name,slug,status FROM payment_gateways ORDER BY id;
1  Cash On Delivery  cash-on-delivery   5
2  Credit            credit             5
3  Paypal            paypal            10
4  Stripe            stripe            10
```

Mais A2 a conclu « aucun encaissement carte en ligne n'est ouvert » depuis une table où
**le fournisseur réellement utilisé n'a même pas de ligne**. Mollie n'y figure pas — parce
qu'il ne passe pas par ce registre hérité :

- `app/Http/PaymentGateways/Gateways/Mollie.php` existe (A2 n'a listé que Stripe et Paypal)
- Route vivante, vérifiée par `php artisan route:list` (moyen différent : le routeur, pas la base) :
  `POST api/frontend/order/{frontendOrder}/mollie-checkout` → `MolliePaymentController::checkout`
  (`routes/api.php:2019`), plus `POST api/frontend/order/applepay-session` (`:2016`) et
  `POST api/frontend/order/{frontendOrder}/payment-confirm` (`:1997`)
- `config/payment.php:114-125` — bloc `mollie`, avec Apple Pay/Google Pay
  (`MolliePaymentController.php:33-37`) et `apple_pay_domain` par défaut `www.lecayenne.fr`
- **`.env:104-105` — `MOLLIE_ENABLED=true` et une clé `MOLLIE_API_KEY=live_…`** (clé de
  PRODUCTION, non citée ici ; à traiter par ailleurs comme une hygiène de secret)

Le montant envoyé est le total scellé backend et seul le webhook marque PAID
(`MolliePaymentController.php:29-32`) — l'invariant NF525 tient.

Nuance que je concède à A2 : **Stripe** est bien verrouillé, et plus fortement qu'il ne le
dit — `config/payment.php:48-55`, garde d'activation `GATE_STRIPE_CENTS_ACTIVE_2026-04-25`,
`activation_gate_cleared => false`, code-owned. Donc sa recommandation « activer la ligne
`payment_gateways` de Stripe » serait **inopérante** (le garde code prime sur la base) **et
inutile** (le canal carte existe déjà par Mollie).

---

## 3. « Le QR ne porte pas de commande » — **fait CONFIRMÉ, remède RÉFUTÉ**

J'ai relu le signer moi-même. `LoyaltyQrSigner.php:57-64` :

```php
$payload = ['v' => self::VERSION, 'cust' => $customerId,
            'code' => strtoupper(trim($loyaltyCode)),
            'nonce' => $nonce, 'iat' => $iat, 'exp' => $exp];
```

Charge exacte `{v, cust, code, nonce, iat, exp}` — **A2 a raison, aucun `order_id`.**

Mais sa conclusion (« étendre la charge d'un champ `ord` ») est architecturalement fausse :
**un identifiant de commande scannable existe déjà**, et A2 ne l'a pas cherché.
`orders.tracking_token` (colonne présente, vérifiée par `SHOW COLUMNS FROM orders`), servi par
`GET api/frontend/order/track-qr/{trackingToken}` → `OrderController::trackQr()`
(`app/Http/Controllers/Frontend/OrderController.php:84-89`), qui **rend déjà une image QR**
encodant `/suivi/{token}`. Commentaire `:66` : « son `tracking_token` opaque (jamais
l'id/serial, séquentiels et devinables) » — le choix anti-énumération est déjà fait.

Le manque réel est donc plus étroit qu'annoncé : ce n'est pas « le QR ne porte pas de
commande », c'est **« rien ne lit le QR de commande au comptoir »** —
`grep -rn "tracking_token\|track-qr\|/suivi" resources/js/components/admin/pos/` → **0 résultat**.
Et le jeton est peu posé : `SUM(tracking_token IS NOT NULL) = 56` sur 3252 commandes,
contre `queue_number` renseigné 3129 fois. Par ailleurs `PosCounterCollectModal.vue`
(1130 l.) et `GET /counter-collect/pending` (`routes/api.php:1058`) existent déjà — l'écran
comptoir n'est pas à créer, il est à **doter d'un lecteur**. Surcharger le jeton FIDÉLITÉ
d'un `ord` fabriquerait un second jeton porteur de commande à côté du premier : c'est le
« jumeau oublié » qu'A2 dénonce lui-même en §Risques.

À noter au passage : A3 cite un `pickup_code` — `grep -rn "pickup_code" app/ routes/
database/migrations/` → **0 résultat**. Cette colonne n'existe pas.

---

## 4. « Complétude ~65 % » — **INDÉFENDABLE, et mal attribuée**

Contrôle d'abord la citation : `grep -n "65\|%"` sur `A2-fidelite-existant.md` ne rend
qu'une seule ligne, `:75`, sans rapport (un numéro de ligne de contrôleur). Et
`grep -rn "65\s*%\|complétude"` sur **tous** les rapports `wave-a/*.md` → **aucun résultat**.
**Ce chiffre n'apparaît nulle part dans la vague A.** Il ne peut donc être ni défendu ni
réfuté : il n'a pas été écrit. À la décharge d'A2, son rapport ne chiffre aucune complétude —
c'est une prudence, pas un manque.

Mon estimation, avec sa méthode : compter les briques du parcours client fidélité
livrées ET consommées par une surface vivante. Émission de points (oui, deux déclencheurs),
débit caisse (oui), reprise/annulation (oui), barème unique (oui), QR signé + anti-rejeu
(oui), lecture borne (oui), lecture caisse (oui), historique client web (oui, `screens.jsx:573-579`),
QR client web (oui, `components.jsx:33-38`), consentement RGPD systématique (non),
lecture du QR de commande au comptoir (non), purge/rétention (non). **9 sur 12 ≈ 75 %**,
et la part manquante n'est pas « la vitrine » mais la conformité et le retrait.

---

## 5. « 7 consentements pour 172 porteurs » — **NUANCÉE (chiffre pire, lecture fausse)**

Re-mesuré moi-même :

```
porteurs_code  users_avec_consent  users_consent_accepte  lignes_consent
172            7                   3                      9
```

Le dénominateur et le « 7 » d'A2 sont exacts. Mais **seuls 3** portent
`consent_accepted = 1` : A2 a sur-compté les consentants d'un facteur >2 en confondant
« a une ligne » et « a consenti ».

Sur l'interprétation, en revanche, il se trompe de sens. J'ai lu les 9 lignes
(`SELECT * FROM loyalty_consents`) : **toutes datent du 12–14 juin 2026**, plusieurs
partagent le même `ip_hash`, et elles vont par paires opt-in → opt-out à 90 secondes
d'intervalle (user 63 : 19:38:10 accepté, 19:39:43 `opt-out` ; user 87 : 14:23:02 puis
14:23:26). Ce n'est pas une population de clients : c'est **un jeu d'essai de QA** dans une
base e2e. En tirer un « déficit RGPD » de production est un artefact de mesure.

Sa question subsidiaire — le consentement est-il porté ailleurs ? — je l'ai instruite et la
réponse le conforte :
`SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='foodking_e2e'
AND (COLUMN_NAME LIKE '%consent%' OR '%opt_in%' OR '%rgpd%' OR '%gdpr%' OR '%marketing%')`
→ seulement `loyalty_consents.consent_accepted` et `kadora_participants.accepte_marketing`.
**Aucune colonne de consentement sur `users`.** A2 a raison : il n'y a pas d'ailleurs.

Enfin, le dénominateur 172 est le mauvais repère. `SELECT is_guest, COUNT(*) … GROUP BY is_guest`
→ **166 porteurs sur 172 sont `is_guest = 5`**, soit YES (`app/Enums/Ask.php:7-8`) : des
fiches créées **au comptoir** par `pos-loyalty/customers`, jamais par un parcours web.
Et `SELECT COUNT(DISTINCT u.id) FROM users u JOIN loyalty_transactions lt …` → **13** porteurs
ont une activité fidélité réelle. Opposer une table de consentement alimentée par la borne
à des fiches créées à la caisse est une erreur de catégorie. Le risque RGPD est réel mais
il porte sur le **chemin comptoir**, pas sur un ratio 7/172.

---

## Ce qui reste debout chez A2

Par honnêteté d'adversaire : le schéma, le barème `LoyaltyRules`, les points d'écriture et
de reprise, l'inventaire des tests, le constat que `pos-wizard.js` (zone gelée) ignore
totalement la fidélité, et l'avertissement NF525 « ne jamais recalculer les points côté
client » — tout cela a résisté à mes contrôles. Le travail de cartographie est bon.
C'est le **périmètre** qui est faux : A2 a audité le backend seul, en ignorant que le client
final est un dépôt séparé déjà branché dessus.
