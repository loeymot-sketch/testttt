# Inventaire « marque en dur » — Le Cayenne → autre marque

**Vague W1 · 2026-08-12 · lecture seule · préparation B5 (derrière la porte G3)**
Périmètre : `grep -ril "cayenne" app/ config/ resources/js/ database/ routes/` = **129 fichiers / 455 occurrences**.

## Décompte par catégorie

| Catégorie | Fichiers | Nature |
|---|---:|---|
| **MARQUE** | 35 | nom, palette, adresse, e-mails, domaine — devient donnée de profil |
| **RECETTE** | 34 | portions, symboles cuisine, composition — devient donnée métier |
| **RÈGLE MÉTIER** | 28 | horaires, TVA, livraison, mono-caissier, FR-only |
| **FAUX POSITIF** | 21 | commentaires, noms de classe, blocs obsolètes — rien à faire |
| **INFRA** | 11 | domaines, identifiants borne, CORS, chemins |
| **TOTAL** | **129** | |

## Le fait structurant

La **MARQUE** est majoritairement en *repli* (`?: 'LE CAYENNE'`) : silencieuse tant que la base est peuplée, mais elle réapparaît sur le ticket, l'afficheur client, le flyer et les mails **dès que la donnée manque**.

La **RECETTE** est le vrai nœud : elle ne vit pas en donnée mais en **code exécutable** — constantes PHP, identifiants numériques, et surtout des migrations de production qui rejouent une commande de recette à chaque `migrate --force`.

## Les 5 points les plus coûteux à dé-câbler

| # | Ancre | Pourquoi c'est cher |
|---|---|---|
| ① | `app/Console/Commands/EnsureCayenneMixteCommand.php:76` — `whereIn('name', ['Cayenne','Galette Cayenne'])` | **6 migrations de production** l'appellent au déploiement (`2026_07_31_180000:20`, `2026_07_31_181000:18`, `2026_08_01_120000:17`, `2026_08_01_130000:26`, `2026_08_01_140000:37`). Changer de marque **rejoue une recette Cayenne sur la carte de l'autre marque.** |
| ② | `app/Services/Kitchen/MeatPortionCalculator.php:22` + `:36-56` | La règle de portion (2 pièces ; 2 steaks 75 g, 200 g poulet, 4 nuggets, 3 tenders) est en constantes PHP et alimente **ticket cuisine + KDS + décrément de stock** via `MeatMaterialResolver.php:16`. |
| ③ | `app/Services/Hardware/KitchenTicketSymbolicFormatter.php:858` (+ `:881`, `:895`) et son jumeau `resources/js/helpers/kdsSymbolic.js:301` | Symboles du ticket cuisine dérivés des noms de la carte actuelle, en **double implémentation PHP/JS à parité stricte** — deux endroits à changer ensemble, toujours. |
| ④ | `MenuHealLightV2Round2PatchCommand.php:87` (`ATTR_SAUCE_CAYENNE = 331`, `ITEM_BIG_CAYENNE = 488`) | **Identifiants numériques en dur**, non résolvables par slug. Idem `MenuResetLeCayenneCommand.php:46`, `WizardCayenneAndBolsCorrectionsSeeder.php:43`, `EnsureFixedMeatSupplementCommand.php:36`. |
| ⑤ | `MenuHealLightV3Command.php:90` — `SOURCE_ROOT = '/Users/1millnonstop/Downloads/Le cayenne - compressé'` | **Chemin absolu d'un poste de développement** dans une commande artisan : injouable hors de cette machine. |

## MARQUE — points saillants (sortie visible client)

- `app/Mail/SignupOtpMail.php:40` — logo texte + `#F4501E` en dur dans le HTML du mail
- `app/Mail/WheelPrizeMail.php:113` — **adresse postale légale en dur** (« 437 rue Élie Gruyelle, 62110 Hénin-Beaumont »)
- `app/Http/PaymentGateways/Gateways/Mollie.php:188` — libellé **visible sur le relevé bancaire du client**
- `app/Services/Hardware/OrderReceiptEscPosRenderer.php:66` — repli en-tête ticket
- `app/Services/Hardware/CustomerDisplayService.php:31` — repli afficheur client
- `app/Services/Promo/PromoFlyerService.php:43,63` — `'LE CAYENNE'` + `www.lecayenne.fr`
- `config/printing.php:83,185` · `config/menu_images.php` (21 occurrences de mapping visuel)
- Palette `#F4501E` / `#FFB800` / `#1A1A1A` en dur dans : `UnifiedStockViewComponent.vue:324`, `KdsV2Grid.vue:651`, `KdsOrderCard.vue:580`, `KdsHistoryDrawer.vue:718`, `KitchenDisplaySystemComponent.vue:540-541`, `KioskIdleScreenComponent.vue:544,602`

## RÈGLE MÉTIER — points saillants

- **Horaires de service** en dur : `config/kds.php:95` (18h → 00h30, fenêtre enjambant minuit), `KitchenDisplaySystemOrderService.php:134`, `OrderStatusScreenOrderService.php:122` (23h-02h), `Console/Kernel.php:537` (Z auto 23:55/00:01)
- **TVA 10 % incluse** : `AssignMenuVatCommand.php:15`, `MenuSeeder.php:365`
- **Barème de livraison** en trois endroits : `helpers/deliveryCharge.js:11`, `DeliveryConfigSeeder.php:10`, migration `2026_06_01_100000:14`
- **Mono-caissier** : `config/cash.php:76` · **stock binaire** : `NotifyStockLowOnStockLevelChanged.php:18` · **FR-only** : `i18n.js:52`, `formatPrice.js:20`
- **Plan B paiement borne** : `DashboardService.php:741`, `KioskPaymentComponent.vue:4`

## INFRA

`MenuHealLightV3Command.php:90` (chemin de dev) · `EnsureKioskMachineCommand.php:141` (`@lecayenne.local`) · `KioskMachineTableSeeder.php:45` · `config/payment.php:124` (domaine Apple Pay) · `config/cors.php:19` · `config/uber.php:19` · `config/security.php:56` · `HealthzController.php:183` (`branch 1` en dur)

## Ce qui n'est PAS à faire

Les **21 faux positifs** sont des commentaires, noms de classe et blocs obsolètes (`config/uber_menu_map.php:19` entièrement commenté, `config/pos.php:95` bloc « HISTORY (superseded) »). Les toucher serait du bruit.

---

**Conclusion pour B5** : le swap n'est pas un travail de renommage. 35 fichiers MARQUE sont mécaniques ; les **34 RECETTE** demandent de sortir la règle du code vers la donnée, et **6 migrations de production** doivent d'abord cesser de rejouer une recette au déploiement. C'est là que le vrai coût se trouve, et c'est ce que la porte **G3** doit financer en connaissance de cause.
