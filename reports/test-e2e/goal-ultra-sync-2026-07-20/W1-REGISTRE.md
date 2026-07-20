# W1 — Audit total site web · registre (binôme adversaire + captureur) · 2026-07-20

## Volet ADVERSAIRE (fiches vs backend) — ✅ RENDU
**Verdict : parité CONFIRMÉE avec preuves.** Générateur --check exécuté (exit 0) : 38/38 prix, 38/38 flags,
pools alignés. Décomposition 55 items API = 38 produits + 10 pool-options (cat 8) + 3 formule (cat 27) +
4 SKU frites pré-composés (107-110, prix composés == SKU vérifiés au centime, résolution par nom fail-loud
api.js:340-357). Matrice 9 catégories : règles uniformes, divergences intra-catégorie JUSTIFIÉES par les
details backend (Méga/Terminator v=2 ; menu enfant Nuggets=sauce seule / kid-burger=crudités3+suppl ;
formule 2,50/1,50/1,00 = addon 2,50 × ratios config/kiosk.php:181-183 via PricingService:802-804).
0 doublon (ids/slugs/noms/cats/pools). Échantillon profond 7 produits : 0 fantôme, 0 manquante.
**Réfuté** : items test exposés (83-86 → 404, 0 nom suspect / 55) ; « 10 vs 9 suppl bols » (10ᵉ=gratiné réel).
Collision ids web↔backend neutralisée (résolution par slug/nom, jamais id web — api.js:242-243).

### Findings (design, pas facturation)
- **P3-1 (gate owner)** : 2ᵉ sauce BOL inaccessible web (wizard force min1/max1, wizard-v2.jsx:211) alors que
  le backend vend `Sauce supplémentaire @0,50` sur les bols (det-45) — borne possiblement aussi. Trancher :
  ouvrir la 2ᵉ sauce bol côté web (parité assortiment) ou verrouiller partout.
- **P3-2 (note gestion)** : styles frites → SKU par NOM (« Grande Frites Cheddar fondu »). Renommer ces items
  backend casserait le checkout web en fail-loud propre (jamais de sous-facturation). À savoir en gestion.

## Volet CAPTURES (toutes pages) — EN COURS
(sera annexé au retour du captureur)

## Volet W4 design (architecte commandes programmées) — EN COURS

## Volet W4 design (architecte) — ✅ RENDU (plan complet dans le transcript agent ; résumé)
Colonne NEUVE `orders.scheduled_at` DATETIME NULL indexée (NULL=ASAP) — is_advance_order (Ask::YES=5 piège
vendor) + delivery_time (string sans date, minuit ambigu) INTOUCHÉS. Gate serveur évalué à chaque poll KDS
(5-60s) → pas de cron. Lanes : E0 data/config → E1 KitchenReleaseRule statics → {E2 gates 5 chemins jumeaux
(KDS list/orderItems/changeStatus-422 + KdsSync + OSS ×2), E3 meta scheduled_upcoming piggyback, E4 intake
web OrderRequest+resources, E5 intake POS (PosComponent.vue, PAS pos-wizard.js), E6 bandeau KdsScheduledBanner
.vue+store} → E7 6 fichiers tests (gate, meta, intake, parité OSS, minuit-straddle, e2e). NF525 : zéro impact
(SELECT-only + colonne additive). « Prête »→compte : chaîne customer.{id} existante, juste exposer scheduled_at
dans les resources. config/kds.php existe → + scheduled_lead_minutes (défaut 20, env).

## Volet CAPTURES — ✅ RENDU (~45 captures analysées) : GREEN, 0 P0/P1, 1 P2, 5 P3
Cœur commerce irréprochable sur le déployé : compteurs 38=38, prix cartes=fiches=wizard=panier (Bol complet
7,90→13,30 ✓ au centime), gating min/max, promo défer fail-closed, créneau unique, empty states, console 0 erreur.

## HEALS W1 — ✅ DÉPLOYÉS (`793df3a`, ?v=20260720h, vérifié servi)
- **P2-1 fidélité ARBITRÉ par le backend** (/loyalty/config : points_per_euro=10, min_redeem=50) : le panier
  (+133 pts) était JUSTE — corrigé les TEXTES menteurs : hero stat, promesse 4-points, badge signature (10 pts
  par euro), Compare li, FAQ (1€=10 pts · dès 50 pts), fallback login. 
- **P3** : recherche désormais GLOBALE (« mega » trouvable depuis toute catégorie) ; chips régime dynamiques
  (Épicé sans produit tagué → masqué) ; « Prêt en 0 min » → « Prêt immédiatement ».
- Non traités (assumés) : animation reveal lente (design), badge nav « 70 » = AVATAR téléphone pas points
  (faux positif partiel — QR affiche déjà « session expirée » honnête), panier non persisté au reload (design V1).

## W4 lanes : E0+E1 ✅ commité (1cde5bad7) · E4 ✅ (8/8+50) · E5 ✅ (4/4+19, frozen 0) · E6 ✅ (14/14 vitest)
## E2+E3 (gates KDS/OSS + meta) : EN VOL

## W4 régression hunt (double-vérif) — 1 P1 RÉEL attrapé + fix en cours
**P1** : programmée créée >8h avant sa cible (cas phare « commandée le matin pour ce soir ») = AND avec la
fenêtre glissante 8h (order_datetime>=staleFloor, branche advance exige YES) → à T-lead : éjectée du bandeau
ET rejetée du board = invisible partout cuisine, jamais cuisinée. Les 25 tests verts créaient tous <8h avant
(vert vacueux sur ce cas). Fix : OR `scheduled_at NOT NULL` dans la fenêtre datetime des 5 chemins jumeaux +
tests J-1/10h→20h + date sur bandeau (P3 multi-jours). RAS explicite sur : sur-gating caisse (non gatée ✓),
timezone, bump-guard, meta piggyback, spread quote, ASAP inchangé.

## W4 P1 fixé+committé eafee1bbe (28/28+320+19, TDD red-first). Migration locale posée (COL OK).

## W6 fidélité — ✅ RENDU : VPS (base réelle) PROPRE 0/8 anomalies
Guest 0612345678 solde 0 == ledger (les « 70 » nav = avatar tél, 0 contrepartie DB). Config VPS 10pt/€,
100=1€, min 50 (défauts, settings vides). Local : DB opérationnelle = foodking_e2e (foodking = legacy morte) ;
anomalies = fixtures e2e (drift 10 users, redeems non-multiples PRÉ-guard, 13 sans consent), pas bugs actifs.
**Heals à faire** : P2 users.phone SANS UNIQUE (exploitable — app-level check à l'inscription + dédup avant
contrainte au go-live) ; P2 config locale 1pt/€≠VPS 10pt/€ (aligner local sur prod pour valider la vraie
config) ; P3 earns order_id=NULL hors UNIQUE (défense=idempotency seule). Note : order #171 = REJECTED
(annulé côté caisse), #172 PENDING.

## W5 Mollie — ✅ STRUCTURE LIVRÉE fail-closed (8/8 + 8 régression, frozen 0)
Gateway Http:: (0 package) · webhook /api/webhook/mollie idempotent statut-scopé, JAMAIS confiance au POST
(re-fetch GET = vérité) · checkout endpoint 503 si non configuré · montant = total scellé · paid→
finalizePaidKioskOrder EXISTANT (0 nouveau chemin fiscal) · failed→UNPAID encaissable caisse.
GATE G-W5 owner à l'activation : MOLLIE_API_KEY test_xxx + MOLLIE_ENABLED + redirect URL + flag web ; et
DÉCISION : web pur sans KioskMachine → finalize no-op → PAID sans fiscal_seq (warning
mollie_webhook_fiscal_finalize_noop, documenté par test) — élargir gate F-21 = owner.

## W2 calculs double-vérif — ✅ VERT 10/10 (0 centime perdu)
Borne LOCALE (login kiosk réel→quote→order→snapshot) : sandwich 11,30 · galette 10,40 · burger ×2 19,80 ·
tacos multi-sauce ×2 11,30 · bol+gratiné 13,30 · menu enfant 4,90/6,70. WEB VPS : #175-177 identiques.
Chaque supplément payant présent+chiffré au snapshot. Fail-closed prouvé live (hors-profil→422, cross-item
VPS rejette IDs locaux). Nettoyage : 5829-5835 local + 175-177 VPS annulés (status 16, sceaux intacts).
**Findings** : F2 dérive d'IDs extras local↔VPS (backfill 16/07, même prix — casse tout futur mirror par ID,
note gestion) ; F3 menu enfant 40 + bol 45 : « Sauce supplémentaire » DB hors profil publié = pas commandable
borne (cohérent avec P3-1 W1 — MÊME décision owner : ouvrir ou verrouiller partout).

## W4 PREUVE VISUELLE — ✅ PASS 1/1 (spec Playwright réel, 2 captures analysées)
Capture 1 : programmée T+2h → bandeau « ⏰ Programmées (1) : 04:38 — #2007265844 » lisible, board VIDE (0
carte) — corroboré API (meta oui, data non). Capture 2 : T+10min → carte V2 normale (slot A, badges, bouton
Prêt), la T+2h reste bandeau-seul. Spec tests/e2e/scheduled-order-kds-banner-2026-07-20.spec.js (login specs
existants, cleanup fait 5844/5845). Piège réel résolu : bundles locaux STALE (code committé jamais compilé)
→ npm run dev + gate string-servie. Note à vérifier (contradiction apparente) : le captureur dit « kiosk/web
ne persiste pas scheduled_at » MAIS le test E4 ScheduledOrderIntakeValidationTest prouve la valeur en DB
(8/8 vert ×3 runs) — la lecture statique du captureur est probablement fausse ; re-trancher à la W7.

## BILAN GOAL au 2026-07-20 : W1 ✅ · W2 ✅ 10/10 centime · W4 ✅ (code+hunt-P1-fixé+captures) ·
## W5 Mollie ✅ fail-closed · W6 audité ✅ (VPS propre ; heals phone-unique+config à faire) ·
## Reste : W3-propagation gestion→systèmes · W6-heals · W7 boucle finale + deploy VPS du lot.

## W6-HEALS — ✅ H1 phone-unique : code DÉJÀ correct (GuestSignupController:98 where-first + LoyaltyController:143)
## prouvé+verrouillé PhoneUniqueGuestTest 2/2 (14 assert). H2 config locale alignée PROD 10/100/50 (endpoint ✓).
## (La contrainte DB UNIQUE reste un item go-live après dédup — registre.)

## W3 — ✅ CLOS (les 3 volets)
T3.1 catalogue SSOT/doublons = prouvé W1-adversaire (38/38, 0 doublon). T3.2 propagation MESURÉE : 86 posé
tinker → borne/détails/caisse APIs +458ms (granularité poll 2s, lecture DB live 0 cache), revert +35ms ; nom
modifié→propagé→reverté exact ; 0 résidu (avail_rows_98 baseline, tokens purgés, fiscal intouché). T3.3
matrice logique par catégorie = W1-adversaire (divergences justifiées backend). M2 STATUT CLIENT prouvé :
programmée POS réelle 5846 → bump chef 7→8 (202) → GET client /api/frontend/order/show/5846 = status 8
« Prête » + scheduled_at ISO (garde 403 user_id stricte). Nuance : POS différé collapse ACCEPT
(auto_prepare_on_paid Wave S-1, preuve transitions). Reste en vol : W8-web (états dégradés) + W8-borne
(7 catégories captures). Puis W9-W12 (plan §extension).
