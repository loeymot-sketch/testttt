# Audit BORNE/KIOSK — read-only, HEAD 9fba7b8f6 (auditeur adverse "30 jours de service")

## Synthèse (5 lignes)
1. **1 P1 réel + verifié** : les extras **inactifs** (status != ACTIVE) fuient sur les étapes Suppléments/Garnitures de la borne (menu payload + `partitionKioskExtras` ne filtrent PAS `status`) → options désactivées ressuscitées, doublons visibles, et **dead-end au paiement** (commande rejetée 422).
2. Le money-path est **SAIN (P0=0)** : la commande n'envoie QUE des ids ; tout `total` vient d'un quote signé serveur et est recalculé par `PricingService::calculateOrder` — jamais fait confiance au client.
3. i18n **propre** : 102 clés `$t` du wizard résolues dans fr.json, 0 label brut.
4. Routes **propres** : toutes les routes kiosk pointent vers des composants existants, écrans d'erreur deep-linkables + gardés.
5. Logique archétypes **saine** : sauce 1ère incluse/+0,50 OK, taille tacos unifiée, ratios formule 0.76 OK, gratiné 2€ bols OK.

## Compte : **P0 = 0 · P1 = 1 · P2 = 1 (latent)**

---

### P1-1 — Extras INACTIFS rendus sélectionnables sur la borne (résurrection + doublon + dead-end paiement)
**Racine A** `app/Services/Kiosk/KioskMenuService.php:400-401` — `item.extras` exposé au SPA est filtré **uniquement** par `isVisibleOn('kiosk')`, **jamais** par `status === Status::ACTIVE` (=5). Le champ `status` est même inclus au payload (`:412`) mais rien ne le consomme.
**Racine B** `resources/js/helpers/kioskExtrasPartition.js:79-125` — `partitionKioskExtras` ne teste jamais `status`. Consommé **cru** par `KioskStepSupplementsComponent.vue:132` et `KioskStepGarnituresComponent.vue:122` (idem viandes payantes via `kioskViandeCatalog.js` → `kioskIsViandePaidExtra`, sans check status).

**Repro concret** — item 41 « Bol Frites » (actif, profil wizard publié id 8, `is_available=1`) :
- extra 180 « Boule gratinée » 2€ → `status=10` (INACTIF), `visible_on=["pos","kiosk"]`
- extra 181 « Option Gratiné » 2€ → `status=10` (INACTIF), `visible_on=["pos","kiosk"]`
- extra 462 « Option Gratiné » 2€ → `status=5` (ACTIF)

L'étape Suppléments rend les **trois** → « Option Gratiné » **en double** + « Boule gratinée » (désactivée en admin) + « Oignon frais » (177, inactif) tous **commandables**.
**Impact** : au clic sur une tuile inactive, `/pricing/preview` renvoie 422 mais `kioskPricingPreview.js:253` avale **tous** les 422 en silence → le running-total ajoute quand même +2€ sans alerte → au `POST /frontend/order` le serveur lève 422 « Supplément ID X inactif… Commande rejetée » (`app/Services/Pricing/PricingService.php:495`) → **toute la commande est rejetée au moment du paiement**. La désactivation admin d'un supplément est un **no-op** sur la borne.
**Portée** (tinker vérifié) : **45** extras inactifs mais kiosk-visibles sur le menu réel ; **8** items actifs kiosk en portent ≥1.
**NF525/argent** : SAIN — le backend scelle correctement et rejette l'id inactif ; ce n'est PAS une dérive de prix, mais un défaut UX + contournement du contrôle de gestion.
**Fix hors frozen-zone** : correction possible dans `KioskMenuService` (filtrer `status===ACTIVE`) et/ou `partitionKioskExtras` — aucun fichier frozen touché (`KioskWizardComponent.vue` non impliqué).

---

### P2-1 (LATENT — pas d'impact runtime aujourd'hui) — IDs catégories FRITES_INCLUDED périmés
`config/kiosk.php:125` défaut `'309,310,311,314'` et fallback codé en dur `KioskWizardComponent.vue:1105` `[309,310,311,314]` référencent une **numérotation menu défunte** ; les catégories réelles sont 1-11,21 (Menu enfant = **11**, pas 314). Le garde `:1107` censé masquer l'upgrade `frites_style` pour les catégories « frites incluses » ne matche donc jamais.
**Inerte à ce jour** (vérifié : **0** item réel ne porte d'extra `group_label='frites_style'`) → aucun repro utilisateur actuel. Deviendra un vrai bug dès qu'un item enfant/assiette recevra un extra `frites_style`. Signalé comme dérive de config, pas comme défaut vivant.

---

## Confirmations positives (preuves)
- **Prix 100% serveur (invariant §1.1 tenu, P0=0)** : `kioskCart.js:98-140` (`sanitizeKioskOrderItem` → item_id/quantity/variation-ids/extra-ids/addon-ids seulement) ; subtotal/total viennent du quote signé (`:170-177`) et sont recalculés par `PricingService::calculateOrder` (`FrontendOrderService.php:302`). Running-total local = affichage/fallback uniquement.
- **i18n** : 102 clés `$t` des steps + wizard toutes résolues dans `resources/js/languages/fr.json` (résolution imbriquée). Idle-screen `$t` bruts existent aussi.
- **Routes** : `router/modules/kioskRoutes.js` — tous composants présents, gardes `requireCart`/`requireOrderRef`/`requireConfirmationContext` OK, `/products/:id` → redirect legacy, 4 écrans erreur deep-linkables.
- **Archétypes** : sauce filtre inactifs + 1ère gratuite/+0,50 (`KioskStepSauceComponent.vue:178,188-191`) ; taille tacos unifiée (`kioskTacosSize.js`) ; ratios formule frites/boisson 0.76 (`kioskPricing.js:5-6,68-70`) ; gratiné 2€ groupe `supplement_bol`.
