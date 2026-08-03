# GOAL 2026-08-03 — Viande nommée cuisine · Borne · Paiement web une-page

## Volet 1 — CAISSE→CUISINE (LIVRÉ, `f4c0538db`)
Racine : single-page actif soumettait `buildTicketInstruction()` SANS la ligne « Viandes en plus : <noms> » (seule ligne parsée par le ticket cuisine) ; le test historique validait le RÉCAP (autre builder) avec fallback mou = test vert encodant le bug.
Fix (LOCK_POS_WIZARD_TICKET_VIANDE_EN_PLUS_2026-08-03) : ligne dédiée émise (2 branches) + anti-doublon compoRe PHP/JS + fixture parité régénérée (220 rows).
Preuves : spec JS durci (assert `.ticket-content`, plus de fallback), KitchenTicketViandeSupplNameTest, capture Playwright : ticket = « TACOS L / Viandes : …, +Nuggets / Viandes en plus : Nuggets », badge +1 supp +2,50, total 10,40 exact.

## Volet 2 — BORNE (VÉRIFIÉ SAIN — 0 patch nécessaire)
La proposition viande suppl. vit DANS l'étape Viande (bon contexte) : quota inclus « 2/2 ✓ Complet », dépassement sur les MÊMES tuiles nommées, prix depuis l'ItemExtra DB, CTA « Envie de plus ? … (+2,50 chacune) » après quota (choix owner 07-28), instruction « Viandes en plus : <noms> » émise (KioskWizardComponent:2277-2285), upsell jamais pollué par la viande.
Preuve visuelle : capture réelle Tacos L — +2 suppléments (+5,00 €), total 12,90 € au centime.
P2 documentés (non patchés V1) : plafond quota+4 silencieux ; sandwichs viande FIXE utilisent l'extra distinct « Viande en plus » (type implicite = viande du produit).

## Volet 3 — WEB PAGE UNIQUE (LIVRÉ local, deploy en cours)
Avant : 2 pages (Étape 1 retrait / Étape 2 paiement), contact caché derrière le clic « Payer ».
Après (`lecayenne-web-deploy` commit « page UNIQUE ») : UNE page « Retrait & paiement » — retrait/heure/promo/note EN HAUT → coordonnées invité (prénom/nom/téléphone/email, OBLIGATOIRES + OTP email inline) VISIBLES D'EMBLÉE → paiement + CTA EN BAS. verifyOtp authentifie seulement ; « Payer » envoie. 0 nouvel onglet (3DS même-onglet consenti, inchangé).
Heals bonus : P1 confirmation « Tu paies sur place » après débit carte inline réussi (paidOnline jamais lu) ; P1 login modal Compte cassé depuis 08-02 (422 prénom/nom absents) ; repli comptoir annoncé (fin du catch muet) ; libellé « parcours de test » retiré ; state carte mort supprimé.
Preuves locales : 17/17 checks (page unique, ordre des sections, garde « Payer » sans OTP, 0 erreur JS), Babel transform OK ×4 fichiers.

## Gates
PHPUnit Hardware 33+117 · Auth 46 · vitest tests/js 2705/0 (sentinel bundle rebuilt) · frozen diff 0 hors LOCK · chaîne NF525 OK ×4.

## Restes / gates owner
- `mollie-profile-id` VIDE en prod (index.html:29) → la carte inline reste masquée (comptoir seul) tant que la clé Mollie n'est pas posée — gate owner connue.
- RED adversarial ×2 (backend + web) : rapports RED-backend.md / RED-web.md.
- Deploy VPS + Vercel + test réel www.lecayenne.fr : voir section DEPLOY ci-dessous (à compléter).
