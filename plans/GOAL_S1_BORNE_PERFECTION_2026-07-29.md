# GOAL S1 — BORNE : PERFECTION ABSOLUE (2026-07-29)

> Tu es le LEAD BORNE. Mission jusqu'à convergence prouvée (DISCIPLINE §6) —
> autonomie totale (§7). Lis `plans/DISCIPLINE_MULTI_SESSIONS_2026-07-29.md` D'ABORD.
> La borne = NOTRE IMAGE (mandat owner) : chaque pixel, chaque bouton, chaque
> page (visibles ET cachées) doit être parfait, logique, rapide.

## Ownership (tes chemins — rien d'autre)
- `resources/js/components/frontend/kiosk/**` (⚠️ Wizard/App/Upsell = FROZEN →
  data/config d'abord ; logique = procédure LOCK documentée, 2 commits, citation)
- `resources/js/helpers/kiosk*.js` + tests js kiosk* · `public/css` kiosk-only
- `app/Http/Controllers/Kiosk*`, requests kiosk, `config/kiosk.php`, `config/menu_images.php`
- Profils composer côté DATA (item_wizard_profiles) — l'ÉDITEUR admin appartient à S2
- `tests/e2e/borne-*`, `tests/Feature/Kiosk*` · rapports `reports/goal-s1-borne/`

## État connu (anchors vérifiés récents)
- Chunks contenthash OK (`webpack.mix.js`) + beacon « Mettre à jour » actif.
- Wizard 7 étapes sain (formule 3 pages, viandes tuiles unifiées supplément 2,50 €,
  10 viandes dont Viande Hachée restaurée, sauces 1ʳᵉ incluse).
- E2e existants : `tests/e2e/borne-formule-split-capture-2026-07-22.spec.js` (vert),
  `borne-blank-viande-repro-2026-07-25.spec.js` (repro 401 headless — à fiabiliser).

## Vagues
### V1 — Cartographie totale + baseline captures
Fan-out lecture : lister TOUTES les surfaces borne (idle, catégories, fiche,
wizard CHAQUE étape × 4 archétypes produit [tacos taille/viandes, sandwich
sauce/crudités, bol, menu enfant], upsell, panier, paiement, confirmation,
états 86/rupture, erreurs réseau, écran attente). Capture Playwright de CHAQUE
état + Read de chaque PNG. Registre `V1-SURFACES.md` : surface → état → verdict.
Acceptance : 100 % des surfaces capturées + 0 label brut + 0 bouton mort listés.

### V2 — Logique & data (agents raisonnement)
Panel d'agents « logique pure » : cohérence des règles par archétype (viandes
incluses/max/supplément ; sauces incluses/+0,50 ; crudités défaut ; gratiné
bols-only 2 € ; formule ratios `config/kiosk.php` 0.76) CONTRE la DB réelle
(items/variations/extras/profils). Tout écart data↔UI = finding. RED dispute.
Acceptance : matrice archétype×règle 100 % verte, tests régression ajoutés.

### V3 — UX excellence (référence : la borne INSPIRE caisse+web)
Micro-interactions, vitesse perçue (<100 ms feedback tactile), lisibilité
1080×1920, images (poids, détourage), parcours sans cul-de-sac, accessibilité.
Améliorations = data/CSS/config d'abord ; frozen logique → LOCK si indispensable.
Acceptance : captures avant/après lues + temps interaction mesurés.

### V4 — Résilience terrain
Réseau coupé mi-wizard, backend 500, double-tap rapide, veille/réveil, quota
429, session longue (beacon), imprimante indisponible. Chaque scénario JOUÉ en
e2e réel. Acceptance : aucun état bloquant sans issue UX + specs ajoutées.

### V5 — Convergence
Full : suite Kiosk PHPUnit + vitest kiosk + e2e borne complets + audit
adversarial final (2 cycles propres). Deploy (DISCIPLINE §3) + BRAIN + memory.

## Interdits spécifiques
Pas de wireup mobile/web (CONSTITUTION). Pas d'invention de produits (SSOT DB).
Palette kiosk light Cayenne (#F4501E/#FFB800), dark mode interdit.
