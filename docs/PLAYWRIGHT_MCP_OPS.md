# Playwright MCP — Note operationnelle FoodKing

**Statut :** Reference active. S'applique immediatement.
**Perimetre :** Utilisation de Playwright via MCP dans la boucle
Claude -> Cursor -> Playwright definie par `AGENTS.md`.

---

## 1. Position dans la boucle

```text
Step 1:  Parse plan JSON
Step 2:  vision-keeper pre-flight (fixe playwright_level_recommendation)
Step 3:  Implementation
Step 4:  Validations (lint, unit tests)
Step 5:  playwright-smoke-gate (decide le niveau final)
         |
         +---> Playwright MCP execute ici <---
         |
         verdict: pass / fail / waived
Step 6:  output_from_cursor.json (propage playwright_result)
Step 7:  status-sync
```

Playwright intervient a **Step 5** et nulle part ailleurs.
Cursor ne touche pas le navigateur avant Step 5.
Cursor ne fabrique jamais de resultat de test.

---

## 2. Flows qui declenchent TOUJOURS Playwright

Niveau `critical-flow` — pas de waiver possible sans approbation
humaine ecrite :

| Flow | Surface | Preuve requise |
|------|---------|----------------|
| Login -> redirection par role | Auth | Page cible atteinte, pas `frontend.home` |
| Ajout item -> wizard -> paiement cash -> commande creee | POS | Commande visible dans KDS |
| Ajout item -> paiement carte (4 derniers chiffres) | POS | Commande creee, `pos_payment_note` rempli |
| Changement statut PREPARING -> PREPARED | KDS | OSS reflete le nouveau statut |
| Idle -> type commande -> menu -> panier -> paiement -> ticket | Kiosk | Numero `queue_number` affiche |
| Total recalcule apres ajout/suppression item | POS/Kiosk | Montant affiche = montant attendu |
| F5 sur POS -> authcheck -> retour bonne surface | Auth | Pas de regression vers dashboard |

Si un changement touche **un fichier implique dans ces flows**,
le test est obligatoire. Pas de negociation.

---

## 3. Flows qui peuvent rester sans Playwright

Niveau `none` — aucun test navigateur requis :

- Modification de documentation (`docs/`, `reports/`, `workflows/`)
- Modification de fichiers de test uniquement (`tests/`)
- Configuration backend pure (`config/`, `.env.example`)
- Changement de commentaires ou formatage
- Modification de seeders sans impact sur les flows actifs
- Ajout/modification de regles Cursor (`.cursor/rules/`)

Niveau `smoke` — snapshot + absence d'erreurs console suffit :

- Changement CSS sans impact sur la logique
- Modification d'un composant Vue sans interaction stateful
- Ajout d'un champ affichage dans un listing admin
- Modification de traduction / libelle

**Regle :** En cas de doute entre `none` et `smoke`, choisir `smoke`.
En cas de doute entre `smoke` et `critical-flow`, choisir `critical-flow`.

---

## 4. Preuve minimale avant `status: done` sur une tache UI

### Niveau `smoke`

1. `browser_navigate` vers la page touchee
2. `browser_snapshot` — structure ARIA lisible, pas d'element casse
3. `browser_console_messages` — zero erreur JS
4. `browser_take_screenshot` — capture archivee dans `screenshots_paths`

Quatre actions. Pas moins.

### Niveau `critical-flow`

Tout ce qui precede, plus :

5. Execution complete du flow (navigate -> interact -> assert)
6. Verification de l'etat final (commande en base, statut change,
   redirection correcte, montant affiche)
7. `browser_network_requests` — aucun 4xx/5xx sur les appels API du flow

Sept actions. Pas moins.

### Niveau `full-regression`

Tout ce qui precede, sur **chaque surface affectee** + smoke sur
les surfaces adjacentes.

### Schema de sortie obligatoire

```json
{
  "playwright_needed": true,
  "playwright_level": "smoke | critical-flow | full-regression",
  "playwright_result": "pass | fail | waived"
}
```

`status: done` est **interdit** si `playwright_result` est `fail`
ou `waived`. Seul `pass` autorise `done`.

---

## 5. Fallback si MCP est indisponible

Si Playwright MCP (`cursor-ide-browser` ou `user-playwright`) ne peut
pas etre invoque :

1. Emettre `playwright_report.json` avec `status: "waived"`.
2. Renseigner `waiver_reason` avec la raison exacte
   (ex: `"MCP not available in this execution environment"`).
3. Fixer `output_from_cursor.json.status: "partial"`.
   Jamais `"done"`. Jamais.
4. Ajouter dans `needs_from_orchestrator` :
   `"playwright [level] test pending — MCP unavailable"`.
5. Le test doit etre execute au prochain cycle ou le Human
   doit valider manuellement avec la checklist correspondante.

### Checklist manuelle de substitution (dernier recours)

Si MCP est absent ET que le Human accepte de valider manuellement :

**POS :**
- [ ] Login caissier -> arrive sur `/admin/pos`
- [ ] Ajout item -> wizard affiche etape X/Y
- [ ] Total se met a jour en temps reel
- [ ] Paiement cash -> commande creee
- [ ] KDS voit la commande

**KDS :**
- [ ] Login chef -> voit les commandes de sa branche
- [ ] Changement PREPARING -> PREPARED fonctionne
- [ ] OSS reflete le changement

**Kiosk :**
- [ ] Type commande en premier
- [ ] Wizard avec barre de progression
- [ ] Paiement -> numero ticket affiche

Le Human coche et signe. Le resultat est note dans
`reports/execution/latest.md` avec la mention
`"validation: manual — MCP unavailable"`.

---

## 6. MCP interactif local vs runner headless futur

### MCP interactif local (maintenant)

- **Serveurs :** `cursor-ide-browser` (26 outils), `user-playwright` (21 outils)
- **Execution :** dans l'IDE Cursor, navigateur reel visible
- **Declenchement :** Cursor appelle les outils MCP a Step 5
- **Limites :** un seul navigateur a la fois, pas de parallelisme,
  depend de la session IDE active, pas de CI
- **Usage :** validation immediate apres implementation, smoke tests,
  exploration de bugs UI

### Runner headless futur (a implementer)

- **Execution :** pipeline CI/CD ou bot autonome, sans IDE
- **Navigateur :** Chromium headless via Playwright CLI
- **Declenchement :** hook post-push ou appel bot
- **Capacites :** parallelisme, screenshots systematiques,
  rapports HTML, integration GitHub Actions
- **Usage :** regression complete, tests nightly, validation pre-merge

### Regles de coexistence

1. Le MCP local est la source de verite **pendant le cycle de
   developpement**. Ses resultats sont valides.
2. Le runner headless est la source de verite **pour le merge**.
   Un test MCP local qui passe ne dispense pas du test headless
   quand celui-ci sera operationnel.
3. Les deux modes utilisent le meme `playwright_report.json`.
   Le champ `level_source` indique l'origine.
4. Quand le runner headless sera actif, le MCP local reste
   utilisable pour le debug et l'exploration rapide.
5. La migration vers headless ne change pas les niveaux de test
   ni les regles de preuve. Seul le mode d'execution change.

---

## References

- `AGENTS.md` — workflow et roles
- `.cursor/rules/playwright.mdc` — regle Cursor
- `.cursor/skills/playwright-smoke-gate/SKILL.md` — skill gate
- `docs/GATES_DOCTRINE.md` — gates futurs (ux-heuristic-gate, sync-consistency-gate)
- `reports/antigravity/` — rapports E2E existants

---

*Note operationnelle. Pas de prose. Pas d'exceptions non documentees.*
