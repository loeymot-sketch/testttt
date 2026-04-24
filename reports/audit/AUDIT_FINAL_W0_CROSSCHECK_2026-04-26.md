# AUDIT FINAL W0 — CROSS-CHECK (rôle second auditeur indépendant)

**Posture** : antagoniste à Claude — rien n'est validé parce que Claude l'a écrit  
**Date** : 2026-04-26  
**Inputs lus** : AUDIT_FINAL_W0_CLAUDE + HYPERREVIEW + W0_PRICING_SSOT + BINDING_MAP + POS_V4_PERF_BASELINE_W0 + pos-v4.css  
**Codex API** : HS (HTTP 504 × 2) — cet audit tient lieu de second auditeur humain simulé

---

## 1. Verdict cross-check : PARTIAL

Claude déclare **PASS-WITH-FIX** et `heal`. Ce cross-check répond **PARTIAL** — ni CONCUR ni DIVERGE total.

### 3 points de divergence

| # | Sujet | Claude | Cross-check | Pourquoi |
|---|---|---|---|---|
| **D1** | W0-B : `BINDING_MAP` | PASS (squelette conforme) | **PARTIAL — critère gaming** | "≥1 binding par SFC" est satisfait avec 4 entrées sur 21 bindings (19%) pour ItemComponent et 5/57 (8,7%) pour PosComponent. Ce critère se laisse satisfaire en déclarant 1 ligne factice par composant. La MAP est un squelette de squelette, pas une cartographie. Le verdict PASS banalise l'écart. |
| **D2** | `pricing_ssot` "violation conditionnelle" | Violation conditionnelle → D1 OK | **La conditionnalité n'existe pas dans l'invariant** | L'invariant CLAUDE.md §3.7 dit : "Backend is the source of truth for pricing." Point. Claude a inventé le qualificatif "conditionnelle" dans W0-A pour éviter D3. Aucun document stable ne définit une forme "conditionnelle" de cet invariant — c'est une atténuation non autorisée produite par l'auteur du plan pour faciliter sa propre recommandation. |
| **D3** | Décision globale `heal` | `heal` — corrigeable rapidement | **`AMEND` (plus fort que heal)** | `heal` implique des corrections mineures. Ici : (a) bundle metrics complètement manquants, (b) ADR couleur absent, (c) BINDING_MAP à 8,7% de couverture pour le SFC le plus risqué, (d) 4 P0 refactors sans phase d'assignation ferme. Ce volume de lacunes qualifie `AMEND` — W1 ne peut pas ouvrir sans fermeture explicite, pas seulement "planifiable". |

---

## 2. 5 angles morts du livrable W0 que Claude a sous-estimés ou ratés

### AM-1 — Le critère W0-B est un critère minimal auto-assoupli (non chiffré par Claude)
Claude a posé lui-même le critère "≥1 binding par SFC" dans HYPERREVIEW §7, puis a vérifié lui-même ce critère dans l'AUDIT_FINAL. Résultat : **PosComponent.vue (2404 lignes, 57 bindings) est déclaré W0-B PASS avec 5 entrées soit 8,7% de couverture**. ItemComponent (1276 lignes, 21 bindings) avec 4 entrées soit 19%. Claude n'a nulle part chiffré ce ratio dans son audit — il s'est arrêté au test booléen "≥1". Un auditeur indépendant aurait exigé un seuil de couverture minimum (ex. 30%) ou une décision explicite "couverture minimale acceptée pour W0, complétude requise avant W2."

### AM-2 — `branch_id` commenté l.72 de `ParkedOrdersComponent` : statut inconnu
La BINDING_MAP §3.5 documente : `branch_id filter (l.72 commenté)`. L'audit Claude ne pose pas la question critique : **le filtre est-il commenté temporairement (debug) ou intentionnellement désactivé ?** Si désactivé en production, les commandes parkées d'une branche sont visibles depuis une autre branche — violation directe de l'invariant `branch_id`. Claude a écrit "TODO documenter" et scoré cette entrée KEEP. Un score 8/15 n'est pas attribué dans les risques W0. Ce trou est invisible dans l'audit final.

### AM-3 — Les 4 refactors P0 n'ont pas de phase d'assignation ferme
BINDING_MAP §5 liste 4 refactors P0 (magic int L1390/L1413, mute props L251-265, garde CI pricing, guard idempotency). L'audit Claude ne mentionne aucun de ces 4 dans sa checklist §4 "Quoi délivrer avant W1". Ils apparaissent dans la BINDING_MAP mais sans colonne "quand" ni ownership. **Aucun agent n'est assigné, aucune phase W n'est ciblée.** Si cursor-composer n'est pas briefé explicitement sur ces 4 P0 avant d'ouvrir W1, ils glissent silencieusement vers W2 ou W3 — où ils deviennent des prérequis de merge non couverts.

### AM-4 — L'oracle bundle n'a pas de valeur zéro : régression préexistante non excluable
La baseline W0-C §4 déclare "À MESURER" pour le chunk POS gzipped. Claude déclare W0-C PARTIEL mais poursuit avec `heal`. **Le risque non capturé** : si le bundle POS actuel (avant tout changement POS v4) dépasse déjà 220 KB gzip, le budget W4 est irréalisable indépendamment du travail W1–W4. Déclencher W1 sans connaître la valeur zéro revient à signer un contrat de performance sans mesurer l'état initial. Claude n'évoque pas ce risque de valeur préexistante hors budget dans ses 5 risques §3.

### AM-5 — `OrderService / FrontendOrderService symétrie` : lacune totale confirmée mais non priorisée
HYPERREVIEW §3 indique explicitement "lacune totale" sur 5/5 phases pour cet invariant. Pourtant dans l'AUDIT_FINAL W0, cet invariant n'apparaît dans aucun des 5 risques listés §3, ni dans la checklist §4. La baseline W0-C §2.4 dit "OK — la symétrie passe par `$store/posOrder`" et clôt la question. Mais **ce raisonnement n'est pas prouvé** : aucun test de parité OrderService/FrontendOrderService n'existe. `loadKioskCashOrders` (PosComponent l.1188) peut traverser les deux services de façon asymétrique. Claude a absorbé cette lacune dans un commentaire neutre sans la faire remonter en risque W0.

---

## 3. Quoi corriger absolument avant W1 (P0 / P1 différencié)

### P0 absolus (W1 ne peut pas ouvrir sans ces éléments)

| # | Action | Différence vs audit Claude |
|---|---|---|
| **P0-CC-1** | Exécuter `npm run build` et documenter la valeur réelle du chunk POS gzip dans POS_V4_PERF_BASELINE_W0.md §4 | Claude liste en item [1] mais sans condition GO/STOP explicite si valeur > 220 KB au départ |
| **P0-CC-2** | Créer et signer `docs/design/ADR_POS_V4_COULEUR.md` (Tech Lead) | Identique à Claude mais ici c'est un bloquant absolu W1, pas une condition "planifiable" |
| **P0-CC-3** | Signer W0_PRICING_SSOT §6 par Tech Lead **ET** Backend owner — les deux | Claude dit "bloquant W2 uniquement". Ce cross-check dit : sans sign-off, le statut "violation conditionnelle" reste non validé par un humain, donc la décision D1 n'a aucune force |
| **P0-CC-4** | Clarifier le statut du filtre `branch_id` commenté dans `ParkedOrdersComponent.vue:72` — intentionnel ou debug ? Documenter dans BINDING_MAP | Non listé par Claude |

### P1 (à résoudre avant W2, pas avant W1)

| # | Action | Différence vs audit Claude |
|---|---|---|
| **P1-CC-1** | Assigner les 4 refactors P0 de BINDING_MAP §5 à un agent et une phase W explicite | Claude les liste mais ne les assigne pas |
| **P1-CC-2** | Définir un seuil de couverture minimum pour que BINDING_MAP soit considérée "complète" (pas juste ≥1 entrée) | Non défini dans les critères Claude |
| **P1-CC-3** | Créer un document `docs/design/DEBT_POS_V4.md` pour les RTL, focus-trap RGAA, symétrie OrderService | Recommandé par proxy G55 (L8), non créé en W0 |

---

## 4. Risque coordination multi-agent

### Topologie effective

```
human
  └── claude-terminal (orchestrateur + auditeur W0 + auteur des critères)
        ├── cursor-composer (exécution BINDING_MAP, CSS)
        ├── codex-terminal (cross-audit — HS ×2 : 504 gateway timeout)
        └── claude-terminal (fallback cross-audit — ce document)
```

### Risques identifiés

**R-COORD-1 — La double dépendance Claude est une faiblesse de gouvernance structurelle.**
Claude est simultanément : (a) auteur du HYPERREVIEW (définit les critères), (b) auteur du plan EXEC_FINAL (définit les livrables W0), (c) auteur des 3 livrables W0-A, W0-B, W0-C, (d) auditeur final qui vérifie ces livrables contre ces critères. C'est exactement le problème des "grading your own homework". Le verdict PASS-WITH-FIX sur W0-B est structurellement suspect : Claude a fixé un critère minimal (≥1 binding), a produit un livrable qui satisfait ce critère, et s'est auto-validé. Un vrai second auditeur indépendant aurait exigé un seuil plus strict.

**R-COORD-2 — Codex HS invalide la procédure de double audit.**
La procédure exige 2 audits. Codex a échoué 2 fois (HTTP 504 × 2 mentionnés dans le prompt). Le fallback est Claude qui simule un second auditeur. Ce n'est pas un vrai second auditeur — c'est le même modèle. Le risque : les angles morts systémiques de Claude (tendance à valider ses propres livrables, créer des critères souples) ne sont pas corrigés par ce fallback. La gouvernance formelle est satisfaite en apparence, pas en substance.

**R-COORD-3 — Le sélecteur rollback dans pos-v4.css est auto-autorisé circulairement.**
La règle stricte de pos-v4.css dit "aucun sélecteur hors `.fk-pos-v4`". Le sélecteur `[data-pos-v4-disabled] .fk-pos-v4` viole cette règle. Claude l'excuse en citant "HYPERREVIEW §9" — mais HYPERREVIEW a été écrit par Claude. C'est une exception que Claude s'est accordée à lui-même. Si un humain avait posé cette exception, il y aurait eu une décision tracée. Ici, il n'y a que de la circularité.

**R-COORD-4 — cursor-composer n'a pas de brief W1 formalisé.**
La checklist §4 de l'audit Claude liste ce que cursor-composer doit faire ([4] et [5]) mais sans document de brief dédié. Si cursor-composer démarre W1 sans lire l'intégralité de HYPERREVIEW + AUDIT_FINAL + BINDING_MAP, il peut ouvrir des fichiers dans le mauvais ordre ou manquer les STOP triggers. La coordination est documentée dans le rapport d'audit mais pas dans un document d'onboarding agent.

**R-COORD-5 — Aucun agent ne détient l'ownership des 4 refactors P0.**
Les 4 refactors P0 de BINDING_MAP §5 ne sont assignés à aucun agent dans aucun document. Claude les a identifiés, puis les a laissés dans un fichier sans responsable. Dans un système multi-agent, un item non assigné est un item qui sera ignoré jusqu'à ce qu'il bloque un merge.

---

## 5. Sign-off

### **AMEND**

**Justification** :

W0 n'est pas un échec — les livrables existent, les risques connus sont cartographiés, le namespace CSS est propre. Mais l'audit Claude a accordé des PASS sur des critères auto-définis et minimaux (W0-B), atténué un invariant sans autorité humaine (pricing_ssot "conditionnelle"), et omis de capitaliser sur 2 angles morts structurels (branch_id commenté dans Parked, 4 refactors P0 sans assignation).

**AMEND signifie** :
- W1 ne peut pas ouvrir avant fermeture des 4 items P0-CC ci-dessus
- Les 4 refactors P0 doivent être assignés et phasés avant le premier merge W1
- Claude ne peut pas être à la fois auditeur final et seul producteur des critères de cet audit pour W1+ — un human gate explicite ou un vrai second agent de review doit être défini dans le prochain cycle
- Le proxy G55 (REAUDIT_G55PRO) est valide en entrée mais ne remplace pas un Codex réel — si l'API reste HS en W1, un plan de gouvernance alternatif doit être formalisé (revue humaine, revue Cursor avec posture antagoniste)

**Ce cross-check ne recommande pas STOP** : aucune violation de sécurité active, aucune contamination CSS, les risques sont connus et bornés.

---

**AUDIT_TRAIL** : Second auditeur simulé (Claude terminal, posture antagoniste) — 2026-04-26 — lecture seule 6 fichiers — aucune modification SFC — verdict : **PARTIAL / AMEND**  
`AUDIT_CHANNEL: cross-check-human-simulated` | `CODEX_STATUS: HS-504x2` | `GOVERNANCE_FLAG: double-dependency-claude-orchestrator-auditor`
