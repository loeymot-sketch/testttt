# Claude Scoring Rubric — FoodKing

**Statut :** Référence opérationnelle active
**Usage :** Standardise le scoring et la décision de `00_ORCHESTRATOR`
**Complète :** `CLAUDE.md` §7-8-11, `docs/roles/00_ORCHESTRATOR_ROLE.md`
**Portée :** review post-exécution, post-fix, post-Anti-Gravity, bugbot-review si impact cycle

---

## 1. But

Ce document définit comment Claude score un cycle FoodKing et comment
ce score se traduit en décision réelle.

Le scoring n'est pas cosmétique.
Il sert à empêcher :
- l'approbation facile
- les verdicts flous
- la complaisance face à des preuves faibles
- la confusion entre "tests passants" et "travail acceptable"

---

## 2. Les 5 axes obligatoires

Chaque review Claude doit scorer ces 5 axes de 0 à 100.

| Axe | Question principale |
|------|---------------------|
| Architecture integrity | Le changement respecte-t-il les couches, frontières, zones gelées, dépendances critiques ? |
| UX / flow quality | Le flow utilisateur est-il cohérent, lisible, sans dead-end, et cohérent inter-surfaces ? |
| Business logic completeness | Prix, statuts, coupons, branch isolation, queue number, règles métier : tout est-il complet et correct ? |
| Security / validation quality | Auth, authz, validation d'entrée, transitions, protections serveur : rien d'affaibli ? |
| Evidence strength | Les preuves disponibles couvrent-elles réellement le risque et le scope du changement ? |

---

## 3. Échelle d'interprétation par axe

### 95–100
Très fort.
- aucune faiblesse significative visible
- preuves solides et cohérentes
- pas de contradiction docs/code
- aucun risque résiduel critique

### 85–94
Solide mais pas parfait.
- changement acceptable
- quelques limites mineures possibles
- preuves suffisantes pour décider
- aucun invariant critique menacé

### 70–84
Fragile / partiel.
- quelque chose manque
- ou une zone critique est insuffisamment prouvée
- ou la complétude n'est pas démontrée
- ou le blast radius semble sous-évalué

### 50–69
Faible.
- risque réel non résolu
- ou preuve insuffisante sur une zone critique
- ou doute important sur la cohérence métier / architecture / sécurité

### 0–49
Inacceptable.
- invariant cassé ou potentiellement cassé
- régression probable
- preuves quasi nulles
- contradiction forte avec docs ou architecture
- cycle non jugeable sérieusement

---

## 4. Critères FoodKing par axe

## 4.1 Architecture integrity

### Score haut si
- couches respectées
- pas de dérive entre Controller / Service / Model / Event
- zones gelées intactes
- Sanctum / Spatie / Pusher non affaiblis
- blast radius correctement identifié

### Score bas si
- logique métier déplacée dans la mauvaise couche
- nouveau couplage non documenté
- changement dans zone gelée sans plan clair
- docs architecture incohérentes avec le changement

### Questions FoodKing
- les deux chemins `OrderService` et `FrontendOrderService` ont-ils été évalués si nécessaire ?
- `branch_id` est-il toujours respecté ?
- les jobs/events restent-ils dispatchés hors transaction ?

---

## 4.2 UX / flow quality

### Score haut si
- flow compréhensible et stable
- feedback clair
- pas de dead-end
- cohérence cross-surface
- niveau de friction adapté au rôle utilisateur

### Score bas si
- UX locale "améliorée" mais incohérente avec une autre surface
- perte de feedback utilisateur
- régression visible
- flow non testable ou non observé

### Questions FoodKing
- POS / Kiosk / KDS / OSS montrent-ils la même vérité métier ?
- l'utilisateur sait-il quoi faire à chaque étape ?
- le changement crée-t-il une divergence silencieuse entre surfaces ?

---

## 4.3 Business logic completeness

### Score haut si
- prix recalculé serveur
- transitions de statut intactes
- coupons bornés correctement
- queue number cohérent
- aucune étape métier oubliée

### Score bas si
- total client accepté implicitement
- saut de statut possible
- branch isolation incertaine
- cas limite non traité sur flux réel
- logique partiellement implémentée

### Questions FoodKing
- le backend reste-t-il la source de vérité ?
- un client ou un kiosk peut-il imposer un état ou un prix ?
- le flow complet est-il cohérent de la création à la livraison ?

---

## 4.4 Security / validation quality

### Score haut si
- auth et authz intactes
- validations serveur claires
- permissions alignées avec `docs/AUTHZ_MATRIX.md`
- inputs non fiables correctement rejetés
- aucun contournement de middleware

### Score bas si
- validation absente ou faible
- ability kiosk étendue sans justification
- scope branche douteux
- auth refresh ou role routing fragile
- régression probable sur sécurité documentée

### Questions FoodKing
- Sanctum et abilities sont-ils préservés ?
- un acteur peut-il agir hors de sa surface autorisée ?
- les transitions interdites sont-elles toujours interdites ?

---

## 4.5 Evidence strength

### Score haut si
- preuves adaptées au risque
- tests pertinents exécutés
- Playwright exécuté quand requis
- logs / captures / rapports cohérents
- aucune zone critique laissée sans preuve

### Score bas si
- preuves absentes ou trop indirectes
- seulement des assertions verbales
- tests passants mais hors sujet
- flow critique non testé
- contradiction entre preuves disponibles

### Questions FoodKing
- le type de test choisi dans le plan a-t-il bien été exécuté ?
- la preuve couvre-t-elle réellement POS/Kiosk/KDS/OSS si le changement les touche ?
- le verdict repose-t-il sur des preuves ou sur de la confiance ?

---

## 5. Calcul du score global

### Étape 1
Faire la moyenne simple des 5 axes.

### Étape 2
Appliquer les correctifs obligatoires :

- si `Evidence strength < 70` → **-10** au score global
- si une contradiction docs/code reste non résolue sur une zone critique → **-10**
- si un flow critique n'a pas été prouvé alors qu'il était requis → **-15**
- si un invariant critique est explicitement menacé → ne pas calculer vers APPROVED, passer directement en BLOCK ou HUMAN

### Étape 3
Arrondir à l'entier inférieur pour décision.

---

## 6. Règles de décision

### APPROVED possible si et seulement si
- score global **>= 85**
- aucun axe individuel **< 70**
- aucune contradiction critique ouverte
- preuve pertinente présente sur les zones critiques
- pas de dette bloquante reportée

### NEEDS_FIX si
- score global **70–84**
- ou un axe clé est faible sans être catastrophique
- ou le changement semble bon mais incomplet
- ou les preuves sont partiellement insuffisantes

### BLOCK / HUMAN si
- score global **< 70**
- ou un axe **< 50**
- ou invariant critique menacé
- ou contradiction majeure avec `CLAUDE.md`, `MEMORY.md`, docs métier/archi
- ou preuve trop faible pour juger une zone business critique

### NEEDS_ANTIGRAVITY si
- la logique semble acceptable mais la preuve comportementale réelle manque
- ou un flow critique multi-surface reste non prouvé
- ou le risque principal est comportemental / E2E plutôt que purement code

---

## 7. Règles d'override

Le score ne remplace pas le jugement.  
Mais le jugement ne peut pas violer les garde-fous suivants :

1. pas de APPROVED si score < 85
2. pas de APPROVED si evidence strength < 70
3. pas de APPROVED si type de test requis non exécuté
4. pas de APPROVED si invariant FoodKing critique est menacé
5. pas de minimisation parce que "les tests passent"
6. pas d'inflation artificielle pour "encourager" un travail moyen
7. si une zone business-critique est touchée (`pricing`, `auth`, `status`, `branch isolation`, `queue_number`), l'approbation exige une preuve **directe et pertinente** pour cette zone, pas seulement une confiance indirecte ou une impression générale

---

## 8. Invariants FoodKing qui forcent la prudence maximale

Si le cycle touche un de ces invariants, le score doit être conservateur :

- prix recalculé côté serveur
- isolation stricte `branch_id`
- transitions de statut de commande
- permissions Sanctum / Spatie / abilities kiosk
- `queue_number` cohérent
- notifications / jobs hors transaction
- cohérence POS ↔ KDS ↔ OSS ↔ Kiosk

Sur ces sujets :
- une preuve partielle ne vaut pas une preuve forte
- un test passant local ne vaut pas une validation complète
- une incertitude doit dégrader le score

---

## 9. Format standard à rendre dans une review

```text
Scoring:
  Architecture integrity:      [0-100] — [why]
  UX / flow quality:           [0-100] — [why]
  Business logic completeness: [0-100] — [why]
  Security / validation:       [0-100] — [why]
  Evidence strength:           [0-100] — [why]
  ---
  Adjustments:
    - [rule applied or none]
  Global score:                [0-100]

Decision:
[APPROVED | NEEDS_FIX | NEEDS_ANTIGRAVITY | BLOCK | HUMAN]

Rationale:
- ...
- ...

Residual risks:
- ...
```

---

## 10. Exemples d'interprétation rapide

### Cas A
- Architecture 90
- UX 88
- Business 91
- Security 86
- Evidence 84
- Global 87

=> APPROVED possible

### Cas B
- Architecture 88
- UX 82
- Business 79
- Security 84
- Evidence 61
- Moyenne 78
- Ajustement -10
- Global 68

=> BLOCK ou HUMAN  
Raison : preuve trop faible

### Cas C
- Architecture 86
- UX 80
- Business 83
- Security 81
- Evidence 72
- Global 80

=> NEEDS_FIX  
Raison : acceptable mais pas assez solide pour approbation

---

## 11. Règle finale

Le scoring Claude pour FoodKing doit protéger le projet contre
l'optimisme, la fatigue décisionnelle, et les approbations molles.

Si le score ne permet pas une approbation claire,
Claude ne doit pas approuver.
