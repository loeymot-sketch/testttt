# LOCK_CAISSE_SAUCE_SEAL — Caisse : 2e sauce +0,50 affichée mais NON scellée (display≠sealed)

> Frozen-zone override. Contrat Owner (délégation « c'est toi qui gères ça » 2026-07-16) / Claude / safety-check.

## §1. Identification
- **LOCK ID**: `LOCK_CAISSE_SAUCE_SEAL`
- **Created**: 2026-07-16
- **Cycle**: validation terrain (owner « tout validé, tous systèmes »)
- **Phase**: EXECUTE
- **Status**: `APPROVED` (owner a délégué la gestion des corrections terrain)

## §2. Frozen file(s) targeted
| Path | Why frozen | Lines |
|---|---|---|
| `public/js/pos-wizard.js` | POS Vanilla wizard « design parfait » owner (§7) | ~4010-4048 (pont sauce), ~4147-4165 (loop data-wizard-qty), ~1368-1371 (display frites-sauce) |

## §3. Justification
**Problème** (audit cycle 2, CAISSE-2E-SAUCE) : le pont caisse matche la 2e sauce sur un label
`'sauce suppl' + {nom de la sauce}` (l.4039), mais l'extra générique DB = « Sauce supplémentaire »
SANS suffixe de nom → jamais coché → le backend facture **0 €** alors que l'écran affiche **+0,50 €**
(l.1357) = display≠sealed + **sous-facturation** + divergence borne (qui, elle, scelle correctement).
Frites-sauce (l.1370) : +0,50 affiché mais transmis en note texte, jamais facturé, et la borne l'a
rendu GRATUIT → caisse désalignée.

**Pourquoi frozen** : la sérialisation caisse→backend vit dans `pos-wizard.js` (frozen §7). Le pattern
CORRECT existe déjà dans ce même fichier pour la viande supplémentaire (l.4131-4165, décision owner
2026-07-01 : data-wizard-qty → onWizardBridgeExtra → setExtraQuantity). On le réplique pour la sauce.

## §4. Scope (surgical)
1. Remplacer le matching sauce par nom (4010-4048) : trouver l'extra générique « Sauce supplémentaire »
   (`/sauce\s*suppl/i`) + calculer la quantité (sauceOrder.length-1), stocker sauceSupplExtraId/Qty.
2. Dans la loop des checkboxes (4147-4165), poser `data-wizard-qty` sur l'extra sauce (comme la viande).
3. Retirer le surcoût display frites-sauce (l.1368-1371) → gratuit, aligné borne (display==sealed).

## §5. Files to modify
| File | Lines | Change |
|---|---|---|
| `public/js/pos-wizard.js` | ~4010-4048, ~4147-4165, ~1368-1371 | pont sauce→extra générique + qty ; retrait display frites-sauce |

Non touché : PricingService (SSOT), le reste du wizard.

## §6. Acceptance (binaire)
- [ ] `node --check public/js/pos-wizard.js` OK
- [ ] Caisse réelle (Playwright) : Tacos M + 2 sauces → preview/encaissement scelle 7,40 € (extras_total 0,50)
- [ ] Pas de régression viande-supplément (le pattern partagé)

## §7. Rollback
`git revert <sha>` ; pos-wizard.js sert directement (pas de build) → effet immédiat. Data : N/A (code seul).

## §8. Sub-agent
Claude orchestrateur (patch surgical direct + vérif caisse réelle).

## §9. Safety-check override
Hook pre-commit débloque via citation `LOCK_CAISSE_SAUCE_SEAL.md` dans le message du commit PRÉCÉDENT.

## §10. Owner sign-off
- **Owner**: Kossay — délégation explicite « c'est toi qui gère ça c'est toi qui va créer des plans...
  refaire la boucle jusqu'à tout soit validé » (2026-07-16) + « tous les systèmes ».
- **Decision**: [x] APPROVED (délégation terrain)
- Patch sha : renseigné au commit citant ce LOCK.

---
**End of LOCK_CAISSE_SAUCE_SEAL**
