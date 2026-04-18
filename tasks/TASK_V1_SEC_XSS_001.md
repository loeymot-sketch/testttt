# TASK_V1_SEC_XSS_001 — Correction des 5 v-html XSS

## Meta
- **Priority** : P1 (sécurité base, non bloquant vague 4)
- **Vague** : 3 — Sécurité base
- **PRIMARY_MODEL** : Composer (routine cleanup)
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : (indépendant)
- **BLOCKS** : —
- **Estimation** : 1 j-h

## Contexte

L'audit 360 a identifié **5 usages de `v-html`** dans les composants Vue avec du contenu potentiellement contrôlable côté utilisateur :
- Nom de produit custom (admin peut saisir du HTML libre).
- Commentaire commande (client peut saisir).
- Nom de borne (admin).
- Descriptif long produit.
- Message d'accueil kiosk configurable.

Un administrateur compromis ou une faille de validation amont = injection `<script>` côté borne → exfiltration session caissier, keylogging POS, etc.

Correctif trivial mais strictement nécessaire pour V1.

## Acceptance Criteria
- [ ] Inventaire exhaustif `grep -rn "v-html" resources/js/` → liste auditée.
- [ ] Chaque usage catégorisé : **remplacé par `v-text`** (défaut) OU **sanitisé via DOMPurify** (si HTML légitime requis).
- [ ] Dépendance `dompurify` ajoutée + composant utilitaire `resources/js/utils/safeHtml.js`.
- [ ] Règle ESLint `vue/no-v-html` à `error` — CI casse si réintroduit.
- [ ] Aucune régression visuelle (diff rendu avant/après identique pour les champs non-HTML).
- [ ] Test e2e d'injection dans un champ admin → chaîne affichée échappée, pas exécutée.
- [ ] `docs/SECURITY_NOTES.md` mis à jour avec rationale et liste exceptions autorisées.

## Scope

### SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `resources/js/components/**/*.vue` | 5 fichiers identifiés par grep | Write | No | No |
| `resources/js/utils/safeHtml.js` | nouveau util | Write | No | No |
| `package.json` | ajout dompurify | Write | No | No |
| `.eslintrc.js` | règle vue/no-v-html | Write | No | No |
| `docs/SECURITY_NOTES.md` | section XSS | Write | No | No |

### SUBSYSTEMS_OFF_LIMITS
- Backend — aucun changement.
- Migrations — aucune.
- OrderService / FrontendOrderService / frozen zones.

## Invariants at Risk
- [x] None
- [ ] Backend pricing SSOT
- [ ] OrderStatus enum
- [ ] branch_id data isolation
- [ ] Dispatch after DB commit
- [ ] OrderService / FrontendOrderService symmetry
- [ ] Frozen zone

## Execution Steps

### E1 — Inventaire
```
grep -rn "v-html" resources/js/ > /tmp/v_html_audit.txt
```
Produire un tableau dans le report : fichier, ligne, variable source, décision (v-text / sanitize).

### E2 — safeHtml utility
```js
// resources/js/utils/safeHtml.js
import DOMPurify from 'dompurify';
export function safeHtml(raw) {
    if (raw == null) return '';
    return DOMPurify.sanitize(String(raw), {
        ALLOWED_TAGS: ['b', 'i', 'em', 'strong', 'br', 'p', 'ul', 'ol', 'li'],
        ALLOWED_ATTR: [],
    });
}
```

### E3 — Refactor 5 usages
Par défaut remplacer `v-html="x"` par `v-text="x"`. Seules exceptions autorisées V1 :
- Description produit longue admin → `v-html="safeHtml(description)"`.
- Message accueil kiosk → `v-html="safeHtml(message)"`.

Tout autre cas = `v-text`.

### E4 — ESLint
```js
// .eslintrc.js
rules: {
    'vue/no-v-html': 'error',
    // exceptions localisées si nécessaire :
    // /* eslint-disable-next-line vue/no-v-html -- safeHtml() sanitized */
}
```

### E5 — Test e2e
Ajouter au spec Playwright admin : créer produit avec `<script>alert(1)</script>` dans nom → vérifier que le texte s'affiche littéralement (et `alert` n'est pas déclenchée).

### E6 — Documentation
`docs/SECURITY_NOTES.md` section XSS :
- Règle : `v-html` interdit par défaut.
- Exceptions listées avec justification.
- Procédure pour ajouter une nouvelle exception (PR review explicite).

## SYMMETRY_NOTE
N/A.

## GATE_CONDITIONS
- **Gate requise** : NON.
- Stop-gate si : demande d'ajouter un WYSIWYG riche côté admin (introduit complexité HTML) → V2.

## Status
- [ ] Pending plan
- [ ] Plan approved
- [ ] In execution
- [ ] Validation
- [ ] Audit
- [ ] Gate open
- [ ] Closed
