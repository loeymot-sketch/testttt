# LOCK_COMPO_SAUCE_BORNE — Sauce EN PLUS facturée réellement sur la borne

> Frozen-zone override authorization. Contrat entre Owner (human gate), Claude
> (planner/implémenteur), et safety-check.sh (garde mécanique / hook pre-commit).

## §1. Identification

- **LOCK ID**: `LOCK_COMPO_SAUCE_BORNE`
- **Created**: 2026-07-15
- **Cycle**: composition borne+web (owner « catastrophe logique vente/site »)
- **Phase at creation**: VALIDATE (patch déjà écrit + prouvé live avant formalisation LOCK)
- **Status**: `APPROVED` (owner sign-off §10, autorisation chat explicite)

## §2. Frozen file(s) targeted

| Path | Why originally frozen | Lines targeted |
|---|---|---|
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | Design borne production-validated (CLAUDE.md §7) | buildLineItem : ~1905 (ajout bloc extra-sauce) + ~2032-2044 (retrait surcoûts display-only) |

Note : `resources/js/helpers/kioskPricing.js` N'EST PAS frozen — modifié librement, pas de LOCK requis.

## §3. Justification — why the override is necessary

**Problème** : Sur la borne, la sauce EN PLUS (2e+) était calculée en surcoût
**display-only** (`sauceVariationSurcharge`) ajouté au `lineTotal` affiché, mais
JAMAIS poussée dans `normalizedExtras` envoyé au backend. Résultat prouvé : écran
7,40 € mais order **scellé 6,90 €** → fuite revenu à chaque commande + `composition_snapshot`
sous-facturé = **risque NF525** (display ≠ sealed). Le web venait d'être aligné (1ère
gratuite / +0,50 chacune) → divergence borne↔web.

**Pourquoi pas d'alternative non-frozen** : la construction de la ligne borne
(`buildLineItem`, `normalizedExtras`) vit ENTIÈREMENT dans `KioskWizardComponent.vue`.
Aucun fichier adjacent non-frozen ne produit le payload envoyé au backend. Le helper
`kioskPricing.js` (non-frozen, modifié) gère l'AFFICHAGE mais pas le payload scellé. Le
câblage de la vraie ligne extra doit donc se faire dans le composant frozen. La couche
DATA (ItemExtra 'Sauce supplémentaire' @0,50) était déjà prête (migration 2026_07_15_180000)
et PricingService (SSOT frozen) la price génériquement — aucun changement PricingService.

## §4. Scope — exactly what changes

**Surgical** — ~20 lignes, aucun refactor architectural.

**Tasks** :
1. Ajouter dans `buildLineItem` un bloc poussant la sauce au-delà de la 1ère comme
   ItemExtra 'Sauce supplémentaire' (group_label='sauce', @0,50) dans `normalizedExtras` + `itemExtraTotal`.
2. Retirer `sauceVariationSurcharge` + `fritesSauceSurcharge` de `itemVariationTotal`
   (double-compte évité ; sauce frites en plus = gratuite, aucun mécanisme backend).

## §5. Files to modify

| File | Lines | Type of change |
|---|---|---|
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | ~1905, ~2032-2044 | add extra-sauce billing block + drop display-only surcharges |
| `resources/js/helpers/kioskPricing.js` (NON-frozen) | 29-44, 87-95 | getKioskExtraSauceUnitPrice lit l'ItemExtra réel ; retrait branche frites-sauce |
| `tests/js/KioskWizard.spec.js` (NON-frozen) | fixture extras | ajout ItemExtra 'Sauce supplémentaire' |

**Files NOT touched** : `app/Services/Pricing/PricingService.php` (SSOT frozen, aucun
changement — price l'extra génériquement) ; `PaymentComponent.vue` ; wizard web (repo séparé).

## §6. Acceptance criteria (verifiable, binary)

- [x] `npx vitest run tests/js/KioskWizard.spec.js` → 97 passed
- [x] Suite kiosk (posKioskVariationParity, kioskPricingPreview, edit-restore/roundtrip, upsell) → 46 passed / 1 skipped
- [x] `npm run production` → webpack compiled successfully (composant frozen compile)
- [x] Backend seal (PricingService) : Tacos M + 1× #431 → subtotal 7,40 ; + 2× → 7,90
- [x] Live borne (bundle reconstruit) : Tacos M + 2 sauces → request item_extras contient #431,
      preview 200 `{extras_total:0.5, total:7.4}`, écran Total €7,40 (display == sealed)
- [x] Pas de régression : 143 vitest kiosk verts

## §7. Rollback plan (data + code)

1. **Code** : `git revert <patch-sha>` (KioskWizardComponent.vue + kioskPricing.js + spec).
2. **Data** : N/A pour ce patch code. (L'ItemExtra 'Sauce supplémentaire' vient d'une
   migration séparée réversible `2026_07_15_180000` → `migrate:rollback` si besoin de la retirer.)
3. **Bundle** : `npm run production` rebuild depuis les sources revertées (deploy-lecayenne.sh le fait).
4. **User notification** : N/A — dev, non déployé. Au déploiement, la borne facturera
   simplement à nouveau 6,90 (comportement d'avant) si reverté.

## §8. Sub-agent + execution path

- **Implémenteur** : Claude orchestrateur (patch surgical direct, déjà appliqué + vérifié).
- **Vérification post-patch** : Claude a exécuté §6 (vitest + build + PricingService + live borne).

## §9. Safety-check override config

- **LOCK file path** : `tasks/2026-07-15/LOCK_COMPO_SAUCE_BORNE.md` (dans la racine de recherche).
- **Hook recognition** : le pre-commit débloque si le message du commit patch cite
  `LOCK_COMPO_SAUCE_BORNE.md`. Le commit patch inclura cette citation.
- **Scope marker** dans le fichier : bloc annoté `[COMPOSITION-SAUCE BORNE 2026-07-15 · FROZEN §7 — déblocage owner-autorisé + LOCK]` + `@pricing-allowed-block` signé.

## §10. Owner sign-off (human gate)

- **Owner**: Kossay (kossaybusiness@gmail.com)
- **Signed at**: 2026-07-15 (autorisation chat explicite)
- **Decision**: [x] APPROVED
- **Comments / conditions**: Autorisation verbatim owner :
  1. « la première sauce est gratuite partout la deuxième est payante pour le sandwich
     ou bien même pour les frites les boissons » (règle métier)
  2. « Oui, corrige la borne aussi (**j'autorise le déblocage frozen**) » (AskUserQuestion)
  3. « **Continue maintenant** » (borne frozen — AskUserQuestion 2026-07-15)

Après APPROVED : patch appliqué, §6 vérifié (tous verts + live), statut APPLIED.
Final sha du patch : (renseigné au commit — voir message citant ce LOCK).

---

**End of LOCK_COMPO_SAUCE_BORNE**
