# REFUTER-1 — F3-05 (P3, validation barème semi-FR loyalty-setup)
Date: 2026-06-12 — harnais :8767 foodking_e2e — verdict: NON RÉFUTÉ (confirmé à 100%)

## file:line — TOUS EXACTS
- app/Http/Requests/LoyaltySetupRequest.php:17-19 = rules() ['required','integer','min:0','max:1000'] ; AUCUNE méthode attributes() ni messages() (fichier entier lu, 23 lignes).
- grep -rn loyalty_points_per_euro lang/ → 0 hit (exit 1) ; lang/fr/validation.php a bien un bloc 'attributes' (l.180) mais sans entrée loyalty.
- LoyaltySetupComponent.vue:24 = min="0" (input #loyalty_points_per_euro, type=number, max="1000" l.25) ; form @submit.prevent="save" SANS novalidate (grep exit 1).
- Annexe : Vue data() l.96 default per_euro=10 vs LoyaltySetupResource.php:20 default `?? 1` — divergence de fallback confirmée (cosmétique, mounted() écrase via API).

## Repro API (PUT /api/admin/setting/loyalty-setup, x-api-key + Bearer, locale fr)
- -1   → 422 « La valeur de loyalty points per euro doit être supérieure ou égale à 0. »
- "abc" → 422 « Le champ loyalty points per euro doit être un entier. »
- 1001 → 422 « La valeur de loyalty points per euro ne doit pas être supérieure à 1000. »
- absent → 422 « Le champ loyalty points per euro est obligatoire. »
→ phrases FR, attribut brut EN non traduit. Verbatim identique au finding.

## Repro UI (Playwright, login admin@lecayenne.fr, /admin/settings/loyalty-setup)
- Saisir -1 + Enregistrer → xhrSeen=false (sniffer PUT/PATCH/POST loyalty-setup), checkValidity=false, validationMessage="Value must be greater than or equal to 0." (EN, locale navigateur). Capture refuter1-F3-05-minus1.png (bulle native EN visible sous le champ FR « POINTS PAR € »).
- Champ vide + Enregistrer → XHR part, 422, inline FR « Le champ loyalty points per euro est obligatoire. ». Capture refuter1-F3-05-empty.png.
Script: refuter1-f3-05.cjs.

## Dedup / sévérité
- PAS un dedup : commit 9d415b8db (PS-01) n'a touché que CouponRequest.php + OfferRequest.php ; LoyaltySetupRequest jamais healé (dernier commit fichier = edc8680e5 "up"). Même CLASSE de défaut, fichier distinct.
- Sévérité P3 JUSTE : page admin-only (permission:settings), rejet serveur correct, zéro impact fonctionnel/NF525 ; pur i18n/UX FR (mandat ADR-007 FR rend le point légitime mais mineur). Pas de sur-cotation cloud/SaaS.
