# Z2 — PROFIL ÉTABLISSEMENT & RÉGLAGES MÉTIER — reconnaissance web réelle (2026-08-26)

> Cible `http://127.0.0.1:8766` (arbre principal, HEAD `43b120c7d` + non commité), base locale `foodking_e2e`.
> Deux passages (03:47-03:58, 11:57-12:01), coupés par la limite de session ; consolidé par le chef de projet depuis
> `tmp/recon/Z2/01-tour.json` (25 pages), `02-api.json` (57 appels API), `03-captures.json`, `04-pass2.json` et 6 captures.

## 1. Périmètre parcouru
25 URL ouvertes en Admin (1366×768, console + réseau ≥ 400) : `company`, `site`, `branches/list`, `branches/show/1`, `order-setup`,
`kiosk-setup`, `loyalty-setup`, `currencies/list`, `theme`, `languages/list`, `languages/show/1`, `pages/list`, `time-slots`,
`sliders/list`, `mail`, `otp`, `notification`, `notification-alert`, `social-media`, `cookies`, `analytics/list`, `sms-gateway`,
`payment-gateway`, `license`, `/admin/observability/system` → **25/25 en HTTP 200, 0 réponse ≥ 400, 0 libellé brut**.
Sous-menu Réglages visible : 11 entrées (Entreprise, Site, Filiales, Bornes, Rapports Z, Imprimantes, TPE, Configuration des commandes,
Configuration borne, Fidélité, Devises) ; les 14 autres n'existent que par URL. À 1024 px : 11 onglets, pas de défilement horizontal.

## 2. CE QUI MARCHE (preuves)
- **Propagation immédiate** : temps de préparation 30 → 31 (PUT 200) visible dans `GET /api/frontend/setting` sans cache (`no-cache, private`), restauré 30 (base vérifiée) ; titre borne modifié → visible sur `/kiosk/idle` en 706 ms, restauré « Bienvenue ! ».
- **Validations** : PIN 3 chiffres → 422 ; titre 101 car. → 422 ; temps de préparation négatif → 422 ; devise taux négatif → 422 ; filiale nom dupliqué / ville vide → 422 ; filiale par défaut non supprimable (« Default branch not deletable », `BranchService.php:89-92`) ; créneaux : fin < début, vide, durée nulle, chevauchement → 422 ; adjacent → 201.
- **Anti-injection `.env`** : Entreprise nom avec `\n` ou `"` → 422 (`CompanyRequest.php:33`) ; Site fuseau avec `\n`, format de date avec `"` → 422 (`SiteRequest.php:36-41`).
- **RBAC** `pos@` : 403 sur `PUT company`, `payment-gateway`, `license`, `mail`, `sms-gateway`, `notification`, `kiosk-setup`, `branch update/delete`, `time-slot store`.
- Filiale : création UI 201 (toast), suppression UI = soft delete (`BranchService::destroy`), aucun orphelin.
- Interrupteurs : 6 booléens lisibles, description FR, état = défaut fichier.

## 3. CONSTATS (P0 → P3)
```
[P1] app/Http/Requests/BranchRequest.php + app/Services/BranchService.php + settings/Branch/BranchCreateComponent.vue — SIRET, TVA intra, mentions légales et barème de livraison de la filiale ne sont JAMAIS enregistrés
  reproduction : PUT /api/admin/setting/branch/{id} avec siret/vat_intra/register_id/legal_footer/delivery_fee_* (valides ou invalides) → 200, réponse sans ces clés, base : tous NULL (04-pass2.json branch_fiscal_fields_*) ; formulaire filiale : 11 libellés (NOM, LAT/LONG, EMAIL, TÉLÉPHONE, VILLE, ÉTAT, CODE POSTAL, STATUT, ADRESSE), aucun champ fiscal (03-captures.json branch_create_modal) ; onglet Informations sans SIRET (branch_show_info_tab hasSiret:false)
  preuve       : colonnes existantes `app/Models/Branch.php:14-52` ; migration ajoutant siret/vat_intra/legal_footer (à citer) ; réponse API + SELECT
  impact commerçant : les seules colonnes prévues pour SIRET/TVA/mention légale/barème sont inaccessibles ; le ticket ne portera jamais son SIRET sans développeur
  recommandation : champs dans BranchRequest (validation SIRET 14 chiffres, TVA FR), BranchService::store/update, formulaire + onglet Informations ; test de bout en bout (ONB-01)

[P1] app/Http/Requests/SiteRequest.php (site_google_map_key, site_copyright `required`) — La page Site ne peut pas être enregistrée telle quelle
  reproduction : /admin/settings/site → Enregistrer sans rien changer → 422 « Le champ site google map key est obligatoire. », « … copyright est obligatoire. » (04-pass2.json site_ui_submit_as_is ; capture 02b-site-enregistrer-refuse-cle-google.png) ; en base ces deux valeurs sont vides
  impact commerçant : sans compte Google Cloud il ne peut changer ni fuseau, ni format de date, ni devise
  recommandation : `nullable` sur la clé Google (n'est utile qu'à la zone de livraison) et copyright facultatif ; ou pré-remplir

[P1] resources/js/components/admin/settings/Branch/BranchShowComponent.vue (onglet Zone) — Le dessin de zone de livraison est cassé
  reproduction : /admin/settings/branches/show/1 → onglet Zone → console « Uncaught (in promise) Error: The DrawingManager functionality in the Maps JavaScript API is no longer available … as of version 3.65 » (03-captures.json errors)
  impact commerçant : impossible de définir sa zone de livraison ; seule erreur JS des 25 pages
  recommandation : remplacer DrawingManager (polygone manuel / cercle rayon) ou retirer l'onglet en V1 sans livraison

[P1] resources/js/components/admin/settings/License/LicenseComponent.vue — La clé de licence affichée en clair EST la clé d'API (X-API-KEY)
  reproduction : /admin/settings/license → champ texte de 38 caractères identique à MIX_API_KEY (03-captures.json license_page equalsApiKey:true ; API `admin_get license keyEqualsApiKey:true`)
  impact commerçant : page « Licence » sans objet en V1 locale, et exposition de la clé technique à tout titulaire de `settings`
  recommandation : cacher/retirer la page (ONB-05 G-CACHE) et ne jamais renvoyer la clé d'API par cette route

[P2] app/Http/Controllers/Admin/{Company,Site,OrderSetup,Branch,Otp,Theme}Controller (index) — Lecture des réglages ouverte au POS Operator
  reproduction : jeton pos@ → GET company 200, site 200 (fuseau, debug, clé Maps vide), order-setup 200, branch 200 (6 filiales), interrupteurs 200, otp 200, theme 200 ; écriture 403 (02-api.json)
  impact commerçant : un caissier lit la configuration complète ; pas de secret exposé aujourd'hui (clés vides), mais la clé Google Maps le serait
  recommandation : gate `settings` sur les index de réglages non nécessaires à la caisse (ONB-13)

[P2] settings/PaymentGateway (inputs `paypal_client_secret`, `stripe_secret` en `type=text`) — Secrets saisis en clair, page sans objet en V1 (encaissement comptoir)
  recommandation : `type=password` + cacher la page (G-CACHE)

[P2] resources/js/components/frontend/kiosk/** (gelé, lecture) + réglage `kiosk_welcome_title` — Le titre d'accueil réglable n'est qu'un des textes affichés : « Composez votre tacos comme vous l'aimez », « Le Cayenne », « TACOS • BURGERS • SANDWICHS • BOWLS », « 100% HALAL » sont en dur (03-captures.json kiosk_idle_after_title_change : hasCayenne:true, h1 « Composez votre tacos… »)
  impact commerçant : sa borne parle de tacos et de Le Cayenne quoi qu'il règle
  recommandation : ONB-12 (dé-cayennisation) + ONB-01 (identité) — édition sous LOCK kiosk

[P2] i18n — Toasts « Filiales Created Successfully. », « Configuration des commandes Updated Successfully. » (mélange FR/EN)

[P3] Site : 16 champs obligatoires dont « Passerelle de paiement en ligne », « Vérification email/téléphone », « Connexion invité », liens Android/iOS — vocabulaire SaaS pour un restaurant local
```

## 4. ANGLES MORTS d'un nouveau commerçant
Aucun écran pour : horaires d'ouverture / jours fermés (les créneaux `time-slots` sont cachés et pensés pour la commande en ligne), SIRET/TVA/mention légale (colonnes sans formulaire), barème de livraison (idem), couleurs (Thème = 3 logos, caché), langue de l'interface (Langues caché, FR verrouillé ADR-007). Deux « identités » (Entreprise = nom/contact, Filiale = adresse/zone) sans explication de ce qui s'imprime où.

## 5. « CAYENNE » EN DUR
`company_name = Le Cayenne` (donnée, normal) ; borne : textes ci-dessus ; filiales de démonstration `Collier and Sons Branch`… (seeder) ; comptes `admin@lecayenne.fr`.

## 6. QUESTIONS PROPRIÉTAIRE
1. Un seul écran « Mon établissement » (Entreprise + Filiale) ? 2. Rendre facultatives clé Google Maps et copyright ? 3. Zone de livraison : réparer (nouvelle API) ou retirer en V1 ? 4. Lecture des réglages par le caissier : la restreindre ? 5. Pages Licence / Passerelle de paiement / SMS / OTP / Cookies / Analytique / Réseaux sociaux : retirer ?

## 7. NETTOYAGE (preuve DB)
Filiales 11 et 12 supprimées définitivement ; créneaux 13/14 supprimés (`time_slots = 0`) ; `order_setup_food_preparation_time = "30"` et `kiosk_welcome_title = "Bienvenue !"` vérifiés en base après nettoyage.

## 8. Captures (`recon/screens/Z2/`)
`01-entreprise.png` (formulaire) · `01b-entreprise-guillemet-refuse.png` (422 inline) · `02b-site-enregistrer-refuse-cle-google.png` (blocage Site) · `03-filiale-modale-creation.png` (11 champs, aucun fiscal) · `03b-filiale-test-creee-liste.png` · `03c-filiale-test-supprimee-liste.png`.
