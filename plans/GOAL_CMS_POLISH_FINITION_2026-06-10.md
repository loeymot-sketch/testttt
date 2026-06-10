# GOAL — CMS POLISH & FINITION : résiduels e2e + intégration spine + gates owner

**Date:** 2026-06-10 · **Statut:** ✅ EXÉCUTÉ ET CONVERGÉ (waves P1+P2 + 4 P3 round-4 ; adversarial 11/11 CLOSED + 4 nouveaux P3 fixés ; P3.1 ff différé — spine occupée, merge-into-mine maintenu superset ; P3.2-P3.5 = gates owner PENDING)
**Base:** branche `goal/cms-gestion-2026-06-10-spine` (post-convergence test-e2e 3 rounds, P0+P1=0)
**Fondation:** `reports/test-e2e/cms-e2e-2026-06-10/CONVERGENCE_FINAL.md` (11 résiduels P2/P3 stables 2 cycles, chacun avec preuve PNG/DOM) + gates du `GOAL_CMS_GESTION §S`

---

## §0 — PRÉAMBULE
- **Tout finding ci-dessous est DÉJÀ prouvé** (artefacts round-2/3) — pas de re-discovery, exécution directe par le pipeline `ultra-audit-profond` (audit→fix→test→visual). Worktree : `cms-gestion-2026-06-10`, harness :8767/`foodking_e2e`, mêmes contrats que le GOAL parent (§0.2 : prix SSOT, sync outbox, frozen §7, SSOT menu, no push).
- Convergence : mêmes critères (§0.4 parent) + re-run du script de capture `reports/test-e2e/cms-e2e-2026-06-10/round-1/cms-capture.mjs` comme test de non-régression visuelle (set-equality vs round-3).

## §1 — WAVE P1 : les 4 P2 (qualité UX, owner-grade)
- T-P1.1 **Sources du picker incomplètes (R2-NEW-01)** — le step « Choisis la taille » n'est pas scopable : `buildAvailableSources` (`ComposerProfileController.php:521`) dérive les groupes du SEUL premier item représentatif → les attributs absents de cet item (Taille, Viande 2, garnitures…) n'apparaissent jamais pour un wizard CATÉGORIE. Fix : pour `availableSourcesForCategory`, agréger les sources sur TOUS les items actifs de la catégorie (union dédupliquée par attribut/groupe), pas `items()->first()`.
  • acceptance: étendre `tests/Feature/Composer/ComposerAvailableSourcesTest.php` — catégorie 2 items aux attributs disjoints → union complète ; e2e iframe : le select source du step taille offre le groupe Taille
- T-P1.2 **Liste settings : arbre interleavé (A-003)** — appliquer le même tri parent→enfants que Studio/rail stock dans `ItemCateogryListComponent.vue` (la table drag-sort : n'autoriser le drag QUE intra-niveau, ou désactiver le drag des sous-lignes — décision à l'audit).
  • acceptance: `(test À CRÉER tests/js/categoryListTreeOrder.spec.js)` mount-level + capture A3 : ↳ sous le parent
- T-P1.3 **Palette CTAs Studio (A-004)** — `bg-rose-700`/`bg-green-700` → tokens brand (`#F4501E` primaire, accent cohérent avec Settings) sur les 4 CTAs Studio + bouton wizard.
  • anchor: `CatalogStudioComponent.vue:11,17,45,88` · acceptance: capture A5/A6 conforme palette §3bis, 0 régression specs Studio
- T-P1.4 **A11y header/close (A-005)** — `aria-label` sur `.dropdown-btn` (header) et `.fa-xmark` (close modal) + tout bouton icône repéré au sweep.
  • acceptance: axe-core pass sur /admin/items/studio + liste settings (0 button-name)

## §2 — WAVE P2 : les 7 P3 (cosmétique, batch unique)
- T-P2.1 Pluriels FR « 1 articles/1 PRODUITS » → pluralisation vue-i18n (`{n} article|{n} articles`) sur les 3 occurrences relevées (A-006)
- T-P2.2 File input natif anglais (A-007) → input file masqué + bouton FR (pattern existant ItemUpload) sur le modal catégorie
- T-P2.3 Titre modal « Catégories » générique (A-008) → « Ajouter une catégorie » / « Modifier {nom} » (temp.isEditing)
- T-P2.4 « INDISPONIBLES » tronqué (A-009) + selects tronqués builder (B-005) → min-width/ellipsis propre
- T-P2.5 Hint verrou parent (R2-NEW-02) → sous le select vide : « Cette catégorie a des sous-catégories : elle ne peut pas devenir une sous-catégorie. »
- T-P2.6 Glyphe Dupliquer (R2-NEW-03) → choisir un glyphe existant plus évocateur dans les 172 (`lab-line-sample-file` ?) ou SVG inline ; tooltip conservé
  • acceptance wave : re-run capture script → set résiduel vide hors carried-over assumés ; specs CMS verts

## §3 — WAVE P3 : intégration + gates (owner dans la boucle)
- T-P3.1 **FF spine** : quand la session GOAL CLIENTS est au repos (`git -C .claude/worktrees/pre-cloud-exec status` propre), fast-forward `heal/pre-cloud-exec-2026-06-05` sur cette branche (superset déjà vérifié) + re-run suites sur la spine.
- T-P3.2 **G-5** (owner) : flip `FEATURE_WIZARD_PER_ITEM_DEMO` → débloque delete/unpublish des 10 wizards item-level (test 404 à flipper en 200).
- T-P3.3 **G-4/GATE-W6** (owner, LOCK) : renderer `generic_choices` dans `pos-wizard.js` frozen + flip `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` — via skill `lock-plan`, séquencé APRÈS P1/P2.
- T-P3.4 **G-1 GATE-G** (owner, LOCK) : enforcement PricingService catégorie-hérité — LOCK à régénérer.
- T-P3.5 **PUSH** (owner) : push de la branche intégrée vers origin.

## §X — ORDRE & CONVERGENCE
P1 (séquentiel, ~1 session) → P2 (batch, même session) → re-capture set-equality → P3.1 ff → gates owner. Checkpoint 6 pts par wave (frozen diff 0, NF525 intact, visual gate, RED dispute, BRAIN, commit). Done = capture script vert avec set résiduel ∅ + suites CMS vertes + BRAIN à jour.

## §F — DONE
Les 11 résiduels e2e fermés (ou explicitement acceptés owner), palette/a11y/i18n owner-grade sur les 4 surfaces CMS, branche intégrée à la spine, gates G-1/G-4/G-5 décidés par l'owner, push effectué. Production-perfect ou rien.

**⏸️ PLAN-ONLY — attend `lance le GOAL`.**
