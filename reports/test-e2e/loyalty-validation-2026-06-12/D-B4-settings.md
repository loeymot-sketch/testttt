# Lane D-B4-settings — micro-audit /admin/settings (tous onglets)
Date: 2026-06-12 — harnais :8767 APP_ENV=e2e foodking_e2e — read-only repo, mutations data e2e OK
Source des onglets: resources/js/components/admin/settings/MenuComponent.vue + v1-hidden-modules.js (beaucoup d'onglets cachés V1 par design — accessibles par URL directe)

## Étape 1 — Recon menu (D-B4-settings-00-menu.png)
Onglets VISIBLES (7): Entreprise, Site, Filiales, Bornes, Configuration Des Commandes, Configuration Borne, Devises.
Onglets nommés par l'orchestrateur mais ABSENTS du menu (cachés V1 par design, `resources/js/config/v1-hidden-modules.js` V1_HIDDEN_MENU_MODULES): Fidélité (settings.loyalty-setup), Mail, Notification, Licence, Langues, Cookies, Analytics → audités par URL directe, statut "hiddenV1".

## Étape 2 — Sweep 16 onglets (db4-sweep-results.json)
- Console errors: 0 sur les 16 onglets. Réseau >=400: 0 sur les 16.
- Latence: toutes <1s réelle (loadMs mesuré inclut 1500ms d'attente fixe; max net ~900ms site). Aucun >2s.
- i18n trouvé sur onglet Site (VISIBLE):
  - `resources/js/languages/fr.json:1079` "ios_app_link": "Ios Application Lien" → rendu "IOS APPLICATION LIEN" (ordre des mots cassé; l'équivalent Android ligne 876 = "Lien application Android"). P3.
  - `resources/js/languages/fr.json:1098` "left": "Left" → radio position devise rendu "(€) Left" vs "Droite (€)". EN résiduel. P3.
- Bornes (kiosk-machines): colonne STATUT vide en innerText → vérif DOM en cours (étape 3).
- Pagination/tri/recherche: AUCUN contrôle sur Filiales (1 entrée), Bornes (10/10 une page), Devises (1 entrée) — N/A pas de no-op détectable.

## Étape 2b — Véracité data (tinker foodking_e2e)
- branches=1 (UI "1 à 1 sur 1") OK ; kiosk_machines=10 (UI "1 à 10 sur 10") OK ; currencies=1 (UI "1 à 1 sur 1") OK.

## Étape 3 — Renders complets onglets cachés (phases 2/4/5, db4-phase*-progress.log)
- company: formulaire complet FR (NOM/EMAIL/.../ADRESSE), save idempotent OK → toast FR « Entreprise : mise à jour réussie. », DB company intacte post-save (tinker: Le Cayenne/contact@lecayenne.fr/75000...). Capture D-B4-settings-company-after-save.png.
- loyalty-setup (hiddenV1): rendu FR ; MAIS capture phase2 sous charge concurrente = formulaire AFFICHÉ 10/100/50 (defaults Vue) alors que l'API retourne 1/100/50 → voir FINDING F3. Trace propre (db4-loyalty-trace.cjs): inputs 1/100/50, preview « 10 pts → 0.10€ » (point décimal → F-loyalty-2).
- mail (hiddenV1): FR OK. notification (hiddenV1): FR OK (libellés Firebase, « FIREBASE APPLICATION ID » ordre mots mineur).
- notification-alert (hiddenV1): contenu massivement EN — onglets « Mail Notification Messages », lignes « Order Pending Message... », valeurs DB « Your order is successfully placed. » → FINDING F6 (P3, page cachée V1).
- license: FR OK (« CODE DE LICENCE »). cookies: FR OK. analytics: empty-state propre FR « Aucune donnée disponible. ». payment-terminals: en-têtes FR + « Aucune donnée » (0 TPE en DB e2e = véridique).
- languages: rendu correct au 2e passage « Français(Par défaut)/Anglais, Affichage de 1 à 2 sur 2 entrées » (API language 200 = 2 rows = DB 2 rows). Au 1er passage (kill de session concurrent) : table vide + « Affichage de à sur entrées » sans message d'erreur → FINDING F5 (error-state).

## Étape 4 — Interactions
- Bornes > Modifier (1re ligne KM-STRESS-B1-...): modal s'ouvre, libellés FR, MAIS console TypeError `Cannot read properties of undefined (reading 'id')` (vendor.js/vue-select) + champ UTILISATEUR = « -- » non présélectionné → FINDING F4. Root-cause prouvée: users 53/47/27 SANS rôle (tinker model_has_roles=[]) créés par E2EStress/SoakCommand ; /admin/users?excepts=2 (SimpleUserService::list whereHas('roles')) les EXCLUT des options → vue-select ne résout pas v-model. Machine prod KIOSK-LC-001 (user 1 rôle Admin) non affectée.
- Devises > Ajouter Une Devise: modal FR propre (NOM/SYMBOLE/CODE/CRYPTOMONNAIE/TAUX DE CHANGE/Fermer/Enregistrer). Capture D-B4-settings-currencies-add-modal.png.
- Filiales > Voir (/admin/settings/branches/show/1): rendu FR complet (Hénin-Beaumont, 437 Rue Élie Gruyelle) MAIS pageerror Google Maps « DrawingManager... no longer available as of 3.65 » → DEDUP-suspect (dashboard-deep 2026-06-08 P1 delivery-zone map). Pas creusé.
- order-setup: 9 inputs présents et remplis (prep 30, slot 30, km gratuit 2, base 1, €/km 1). Aucun contrôle pagination/tri/recherche sur les listes de ce périmètre (1-10 entrées, page unique) → no-op N/A.

## FINDINGS (consolidés)
- F1 P3 i18n: fr.json:1079 "ios_app_link": "Ios Application Lien" (Site visible) — capture D-B4-settings-site.png.
- F2 P3 i18n: fr.json:1098 "left": "Left" → « (€) Left » radio position devise (Site visible).
- F3 P2 loyalty: LoyaltySetupComponent.vue:96 default 10 pts/€ + :109 fallback `?? 10` + .catch silencieux — UI peut afficher (et laisser enregistrer) le barème 10x cashback que le GOAL L1 a éradiqué ; resource backend fallback = 1 (LoyaltySetupResource.php:20). Vu rendu LIVE (D-B4-settings-loyalty-setup.png). Sentinel parité ne couvre pas ce default Vue.
- F4 P3: Bornes Modifier → TypeError console + UTILISATEUR « -- » pour machines à user sans rôle (artefacts E2E*Command) ; KioskMachineCreateComponent.vue:19-22 + SimpleUserService::list() whereHas roles. Capture D-B4-settings-kiosk-machines-edit-modal.png.
- F5 P3 error-state: échec silencieux de fetch liste → « Affichage de à sur entrées » (PaginationTextComponent.vue:6-8 interpole from/to/total undefined) + table vide sans erreur. Capture languages phase4 (remplacée ensuite — séquence documentée dans db4-phase4/5-progress.log).
- F6 P3 i18n (hiddenV1): notification-alert 100% EN (labels + data DB seed EN). Capture D-B4-settings-notification-alert.png. DEDUP possible avec i18n sweep dashboard-deep 06-08 (autre branche).
- F7 P3 i18n: timeFormatEnum.js:5-13 options « 12 Hour »/« 24 Hour » EN dans FORMAT HORAIRE (Site visible) ; aussi minutes non paddées (« 2:8 » possible).
- DEDUP-suspect (1 ligne): branches/show DrawingManager = connu dashboard-deep 06-08.
- HARNAIS (pas produit): tempêtes 401 récurrentes = relogin concurrent d'autres lanes (Sanctum revoke-on-relogin, compte admin partagé) — a tué 3 runs ; contourné par re-login + retries.

## Verdict batch: 0 P0/P1. 1 P2 (F3 loyalty default 10x UI). 6 P3. Console/réseau propres sur les 16 onglets hors interférence harnais. Latence réelle <1s partout (mesure sweep isolée).
