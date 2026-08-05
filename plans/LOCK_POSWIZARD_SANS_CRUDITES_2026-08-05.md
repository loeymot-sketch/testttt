# LOCK_POSWIZARD_SANS_CRUDITES — Bouton « Sans crudités » un-geste (wizard caisse)

> Frozen-zone override authorization. Contrat entre Owner (gate humaine),
> Claude (planificateur/implémenteur) et la discipline safety-check.

## §1. Identification
- **LOCK ID** : `LOCK_POSWIZARD_SANS_CRUDITES`
- **Créé** : 2026-08-05
- **Cycle** : GOAL_OWNER_8AXES_CUISINE_CAISSE_WEB (Vague 3, T-8.3)
- **Phase** : EXECUTE
- **Statut** : `APPROVED-BY-DIRECTIVE` (cf. §10 — demande owner verbatim du /goal ; contresign formel demandé au rapport final)

## §2. Fichier frozen ciblé
| Path | Pourquoi frozen | Lignes ciblées |
|---|---|---|
| `public/js/pos-wizard.js` | CLAUDE.md §7 — « design parfait selon owner », Vanilla JS non-Mix | ~3160-3178 (rendu section crudités single-page) + ~6055-6070 (handler clic) |

## §3. Justification
**Problème** : l'owner demande (axe 8, /goal 2026-08-05) : « mettre le choix,
aucun crudité comme ça le client ou bien moi-même sur la caisse lorsqu'il me
dit pas de crudités je fais pas toucher toutes les crudités ». Aujourd'hui les
crudités classiques sont incluses par défaut (`cruditeDefaultIncluded`,
pos-wizard.js:3368) — exclure = un clic PAR crudité.

**Pourquoi aucune alternative** : la voie data (extra gratuit « Aucune
crudité ») est PROUVÉE impossible — pos-wizard.js:821 ET
KioskWizardComponent.vue:1724 pré-cochent tout extra gratuit → le marqueur
sortirait coché sur CHAQUE ticket. La borne a son bouton dans le step NON-frozen
(KioskStepGarnituresComponent) ; la caisse n'a aucun fichier non-frozen portant
cette section. Précédent identique : LOCK_POSWIZARD_KIOSKWIZARD_OWNER8
(2026-07-06, oignons cuits opt-in, même zone, même mécanique).

## §4. Scope — chirurgical
1. Rendu : 1 bouton « 🚫 Sans crudités » en tête du bloc `.garniture-toggle` single-page (data-garniture-none).
2. Handler : 1 listener qui met `selections.garnitures['c_<id>'] = false` pour toutes les crudités du bloc puis `updateSinglePageUI()`.
3. AUCUN changement pricing / payload / compo (les crudités décochées suivent le chemin existant « ✕ Sans X »).

## §5. Files
- Modifié : `public/js/pos-wizard.js` (2 blocs ci-dessus, ~20 lignes ajoutées).
- Non touchés (explicite) : `resources/views/admin-pos-v4.blade.php` (frozen), `public/css/pos-wizard.css` (frozen — styles inline sur le bouton pour ne pas y toucher), chemins multi-step legacy (:1772/:1872 — le rendu ACTIF est le single-page).

## §6. Acceptance (binaire)
- [ ] `node --check public/js/pos-wizard.js` → exit 0
- [ ] Playwright : ouvrir wizard caisse d'un item à crudités → cliquer « Sans crudités » → toutes les tuiles passent « ✕ Sans X » ; recap sans crudités ; capture lue.
- [ ] Ticket cuisine de la commande : AUCUN symbole crudité en L1.
- [ ] Non-régression : exclusivité oignon cru↔cuit intacte (re-cocher un oignon après « Sans crudités » fonctionne).

## §7. Rollback
1. Code : `git revert <sha-du-patch>` (commit isolé, promis §9).
2. Data : N/A — aucun état persistant modifié.
3. Bundle : N/A — fichier servi tel quel (`asset()` + cache-bust filemtime, master.blade.php:344).

## §8. Exécution
- Implémenteur : Claude principal (session GOAL), pas de sub-agent routine (interdit frozen).
- Vérification post-patch : Claude (acceptance §6) + RED-team vague 8.

## §9. Marqueur scope
Commentaire dans le code au point de patch :
`// [LOCK_POSWIZARD_SANS_CRUDITES] owner /goal 2026-08-05 — un geste « sans crudités »`
Patch commité SEUL (aucun autre fichier dans le commit).

## §10. Owner sign-off (gate humaine)
- **Preuve de directive** : message owner /goal 2026-08-05 (verbatim, axe 8) :
  « je veux une dernière chose lors de de la caisse et la borne et de même le
  site Web, mettre le choix, aucun cruauté [crudité] comme ça le client ou bien
  moi-même sur la caisse lorsqu'il me dit pas de Recruiter [crudités] je, je
  fais pas toucher toutes les Recruiter pour les sélectionner comme ça y a pas
  de crudités » + directive d'exécution « do the goal … max and best result ».
- **Contresign formel** : ☐ demandé au rapport final de convergence (Vague 8).
  Si l'owner refuse : rollback §7 en un revert.
