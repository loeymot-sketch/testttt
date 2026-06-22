# Synthèse V8 — Composer batch (salve Y + X) — 2026-04-20

## Contexte

Salve **correction d'un faux audit V7 #1 + 3e durcissement check 4/6 + alignement doc gouvernance**.

Le déclencheur : avant de lancer Option T (cleanup events orphelins), l'orchestrateur a vérifié le ground truth — et découvert que les 5 events Item/Category sont **vivants**, dispatched via `event(new ...)` et écoutés par `InvalidateKioskMenuCacheOnCatalogChange`. **Annulation T** + correction immédiate du KI-001 + nouveau cycle Y pour traiter le 3e angle mort du grep.

| Salve | Cycle | Type | Verdict |
|---|---|---|---|
| ~~T~~ | ~~`P11_DEAD_EVENTS_CLEANUP`~~ | ANNULÉ pré-EXECUTE | events vivants, KI-001 corrigé |
| Y | V8 #1 — `P11_INVARIANT_4_OF_6_EVENT_HELPER_PATTERN` | Composer / no gate / script + services (commentaires) + doc | **CLOSED — PASSED Cas B** |
| X | V8 #2 — `P11_INVARIANT_DOC_REFRESH` | Composer / no gate / doc gouvernance | **CLOSED — PASSED** |

## Résultats

### Correction immédiate orchestrateur — KI-001 V7 #1 section
- **Ajouté** : section `⚠️ CORRECTIVE NOTE — Pre-V8 audit` corrigeant l'erreur factuelle
- **Documente** : les 5 events sont VIVANTS (bindés à `InvalidateKioskMenuCacheOnCatalogChange`, dispatched via `event(new ...)`, déjà wrappés `DB::afterCommit`)
- **Identifie** : le 3e angle mort du grep V5 #2 (pattern `event(new ...)` non capturé)

### V8 #1 — `P11_INVARIANT_4_OF_6_EVENT_HELPER_PATTERN`
- **Fichiers modifiés** :
  - `scripts/check-invariants.sh` (+19/-5, regex étendue avec union `(...)::dispatch\(` ∪ `(event\(new |Event::dispatch\(new )(...)`)
  - `app/Services/ItemService.php` (+5/-5 lignes, **uniquement ajout commentaires `// allow:` en fin de ligne**, ZÉRO modif logique)
  - `app/Services/ItemCategoryService.php` (idem)
  - `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` (+ section V8 #1)
- **Validation orchestrateur** :
  - Re-run check 4/6 = **8 hits** (= V5 #2 baseline, ni Cas C ni Cas D, le check fonctionne sans régression)
  - Wrap multi-ligne `DB::afterCommit(function () use ($id) { event(new X($id)); });` confirmé sur les 5 sites
  - 5 commentaires `// allow:` au bon endroit
- **Cas B** : la regex étendue trouve 13 hits sans allowlist (8 V5 #2 + 5 nouveaux Item/Category). Avec `// allow:` sur les 5 nouveaux → 8 hits préservé.

### V8 #2 — `P11_INVARIANT_DOC_REFRESH`
- **Fichier modifié** : `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md` (+7/-3)
- **Changements** :
  - Version → `2026-04-18 (rév. 2026-04-20 — V8 #2 alignement avec scripts/check-invariants.sh)`
  - Note pédagogique ajoutée avant §3
  - Bloc dispatch §3 (anciennes lignes 92-93) remplacé par référence à `scripts/check-invariants.sh -v` (SSOT)
- **Commande shell testée** sur BSD sed (macOS), 13 lignes de sortie

## Lessons learned V8 (très importantes)

### 1. **Vérifier le ground truth AVANT de lancer un cleanup**
Option T (cleanup events orphelins) aurait supprimé 5 fichiers Event activement utilisés. La vérification orchestrateur (lecture EventServiceProvider + grep `new XxxCreated`) a évité un désastre.
**Règle** : avant tout cycle de suppression, **lire les call-sites** ET les **bindings provider** ET **3 patterns de dispatch** (statique `::dispatch`, helper `event(new ...)`, façade `Event::dispatch(new ...)`).

### 2. **Les sentinelles statiques ont des angles morts**
V5 #2 a corrigé l'angle mort 1 (FQN vs short-name). V8 #1 corrige l'angle mort 2 (helper pattern). Il pourrait rester :
- Multi-ligne wrap detection (un grep mono-ligne ne voit pas si `event(new ...)` est dans un `DB::afterCommit(function () { ... })`) — résolu par allowlist `// allow:` mais fragile
- Annotations / réflexion / dispatchString → si quelqu'un fait `app('events')->dispatch(...)`, aucune sentinelle ne le verrait

### 3. **Un "audit faux" ne doit pas être effacé, il doit être corrigé in-place**
Le KI-001 V7 #1 section a été **conservée** + une `CORRECTIVE NOTE` ajoutée. Cette transparence permet à un futur dev/agent de comprendre l'évolution du raisonnement et d'éviter de répéter l'erreur.

### 4. **Doc gouvernance : SSOT ou divergence garantie**
Le bloc grep §3 `POS_INVARIANTS_AND_GATES.md` était devenu **incompatible avec le script** après V5 #2 (scope élargi). V8 #2 résout en pointant vers le script unique. **Règle** : tout invariant codifié à 2 endroits (script + doc) finit divergent. Préférer 1 SSOT + référence dans la doc.

## Statistiques cumulées Composer (V1-V8)

| Wave | Cycles | PASSED | NO_OP | PARTIAL | BUG_FOUND | Annulés | Régressions |
|---|---|---|---|---|---|---|---|
| V1 | 8 | 8 | 0 | 0 | 0 | 0 | 0 |
| V3 | 1 | 1 | 0 | 0 | 0 | 0 | 0 |
| V4 | 11 | 9 | 0 | 1 | 1 | 0 | 0 |
| V5 | 2 | 2 | 0 | 0 | 0 | 0 | 0 |
| V6 | 2 | 2 | 0 | 0 | 0 | 0 | 0 |
| V7 | 2 | 1 | 1* | 0 | 0 | 0 | 0 |
| V8 | 2 | 2 | 0 | 0 | 0 | 1 (T) | 0 |
| **TOTAL** | **28** | **25** | **1*** | **1** | **1** | **1** | **0** |

*V7 #1 NO_OP était techniquement faux mais le résultat (pas de modif script) reste valide. Corrigé in-place dans KI-001 par orchestrateur pré-V8.

## Prochaines étapes (handoff)

### Gates en attente d'approbation humaine
1. **C1-C8 consolidé** — `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md` (3 GPT-5.4 V1 cycles)
2. **C9 étendu** — `tasks/execute-2026-04-20/V5_01_P11_DISPATCH_AFTER_COMMIT_REMEDIATION.md` (3 events × 8 call-sites identifiés statiquement, KI-001 documente le contexte)

### Options Composer no-gate restantes (suite cohérente)

| Option | Cycle | Bénéfice | Effort | Risque |
|---|---|---|---|---|
| Z1 | `P11_INVARIANT_4_OF_6_MULTILINE_AFTERCOMMIT_AWK` | remplacer les `// allow:` par un check awk multi-ligne (5 lignes au-dessus du dispatch → cherche `DB::afterCommit`) — élimine la dette d'allowlist | ~45 min | bas |
| Z2 | `P11_DISPATCH_PATTERN_3_FACADE` | étendre check 4/6 à `Event::dispatch(...)` non-static (façade) si un grep révèle des hits | ~30 min | bas |
| U | `P11_POS_DINE_IN_FLAG_TEST_EXTEND` | étendre `posDineInFlag.spec.js` aux edge cases | ~30 min | bas |
| V | `P11_KIOSK_OFFLINE_QUEUE_TEST_EXTEND` | étendre `kioskOfflineQueue.spec.js` reconnexion partielle | ~45 min | bas |
| W | `P11_KI_002_*` | doc known-issue pour autre bug ouvert (à sélectionner) | ~30 min | bas |

### Recommandation orchestrateur

**Option Z1** est la plus cohérente avec V8 #1 : elle élimine la dette d'allowlist en passant à une détection multi-ligne. Améliore la robustesse de la sentinelle.

**Option U** ou **V** sont utiles couverture POS/Kiosk mais moins urgentes que la finalisation des sentinelles.

**Recommandation forte** : approuver le **gate C9** maintenant. Le KI-001 + V5 #2 + V8 #1 ont tout préparé pour que la remédiation V5 #1 soit straightforward (3 events × `implements ShouldDispatchAfterCommit`). Plus on attend, plus le risque ghost-orders en prod est exposé.
