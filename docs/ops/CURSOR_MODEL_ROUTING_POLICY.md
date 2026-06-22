# Cursor model routing policy — FoodKing

**Statut :** Référence opérationnelle — exécution autonome déterministe  
**Complète :** blocs de planification Claude (`Execution class`, `Preferred model`, `Fallback model`, `Max mode`) et `docs/ops/BOT_TO_CLAUDE_RUNTIME_CONTRACT.md`

---

## 1. Règles non négociables (orchestration production)

1. **Auto** et **Premium routing** sont **interdits** pour les cycles d’orchestration production FoodKing. Le routage implicite ou payant ne constitue pas une couche de confiance pour le pipeline bot / plan / exécution.
2. **Chaque cycle** doit s’exécuter avec **un seul modèle explicitement épinglé** (« pinned »), annoncé dans le plan et respecté par l’exécuteur.
3. **Changer de modèle en cours d’exécution** d’un même cycle **ne fait pas partie** du design runtime de confiance. Un run = un modèle, sauf arrêt explicite (voir §5).

---

## 2. Classes d’exécution et modèles préférés


| Execution class            | Rôle                                                                  | Preferred model                         |
| -------------------------- | --------------------------------------------------------------------- | --------------------------------------- |
| `inspection_readonly_fast` | Lecture statique, classification, rapports sans écriture code         | **Composer 2 standard**                 |
| `implementation_bounded`   | Implémentation cadrée, petit blast radius, fichiers autorisés stricts | **Composer 2 standard**                 |
| `implementation_complex`   | Refactor ou logique transverse, forte dépendance au contexte          | **GPT-5.5** (proxy)                     |
| `critical_review_judge`    | Revue finale, arbitrage risque, verdict sur invariants métier / authz | **Claude 4.6 Opus**                     |
| `e2e_behavioral_tooluse`   | E2E, navigateur, preuves comportementales, enchaînement d’outils      | **GPT-5.5** (ou procédure + Playwright) |


Le plan Claude doit toujours déclarer **une** de ces classes et le **Preferred model** associé (et optionnellement `Fallback model`, `Max mode`), sans ambiguïté.

---

## 3. Règles de secours (fallback)

- **Implémentation à faible risque** (typiquement `implementation_bounded` ou correctifs triviaux) : secours autorisé vers **Composer 2 standard** si le préféré indisponible, **uniquement** si le plan l’autorise explicitement et que le périmètre reste inchangé.
- Si **GPT-5.5** (ou complexe proxy) ou **Claude 4.6 Opus** est indisponible : secours par défaut documenté → **Claude 4.6 Sonnet**, **sous réserve** que le plan ou l’orchestrateur valide ce downgrade pour ce cycle (pas de bascule silencieuse — voir §5).

Les fallbacks ne **prolongent** pas un run déjà commencé avec un autre modèle sans nouvelle décision humaine / plan.

---

## 4. Insuffisance du modèle épinglé

Si le modèle épinglé est **insuffisant** pour terminer le cycle (complexité, limites de contexte, échecs répétés) :

- **Cursor s’arrête** et **signale qu’une escalade est nécessaire** (vers un plan révisé, une autre classe d’exécution, ou un autre acteur).
- **Interdit :** changer de modèle **sans signaler** l’escalade ou **prétendre** que le cycle s’est déroulé sur le modèle d’origine.

---

## 5. Runtime design implication

L’**escalade** (modèle plus capable, classe `implementation_complex` ou `critical_review_judge`, découpage en sous-cycles) est une **frontière de cycle**, pas un détail interne d’un même run.

- **Dans un cycle :** un modèle, une intention de plan, une traçabilité lisible.
- **Entre cycles :** nouveau plan (ou addendum validé), nouvelle épingle modèle, éventuellement nouveau `files_allowed` ou nouvelle preuve.

Cela préserve l’auditabilité des rapports (`reports/planning/latest.md`, `reports/execution/latest.md`, `reports/review/latest.md`) et évite les verdicts fondés sur un mélange de modèles non déclaré.

---

## 6. Alignement avec le bloc de planification Claude

Les champs `**Execution class`**, `**Preferred model**`, `**Fallback model**`, `**Reason**`, `**Max mode**` du plan doivent **refléter** ce tableau et ces règles. Toute exception (modèle unique autorisé pour un prototype, hors production) doit être **explicitement** marquée dans le plan et ne constitue pas une dérogation implicite à §1.