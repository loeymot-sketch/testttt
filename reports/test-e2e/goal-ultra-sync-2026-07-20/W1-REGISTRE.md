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
