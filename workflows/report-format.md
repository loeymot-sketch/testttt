---
description: Format imposé pour les rapports QA Playwright / E2E (répertoire reports/antigravity/)
alwaysApply: true
---

# Format de rapport Playwright / E2E requis

Pour chaque test exécuté de bout en bout (E2E), l’exécuteur Playwright / E2E DOIT documenter ses résultats en suivant EXACTEMENT la structure ci-dessous.

L’objectif est d’avoir une uniformité parfaite, test par test, dans les rapports générés sous `reports/antigravity/` (nom de dossier hérité ; sémantique = vérification Playwright / E2E).

---

### Test {ID}: {Nom du test}

**Status:** ✅ PASS / ❌ FAIL  
**Date:** {timestamp}  
**Agent:** Playwright / E2E verification

**Prérequis:**

- {liste des conditions initiales : data, auth, écran ouvert...}

**Étapes exécutées:**

1. {étape détaillée 1}
2. {étape détaillée 2}
3. {étape détaillée 3}

**Résultat attendu:**

- {ce que le système est censé faire selon les BUSINESS_RULES et l'ARCHITECTURE}

**Résultat observé:**

- {ce qui s'est réellement passé lors de l'exécution du test}

**Différences:**

- {S'il y a un écart entre attendu et observé, le détailler ici. Sinon, écrire "Aucune"}

**Captures/Logs:**

- {Chemin absolu vers les screenshots (.png/.webp), fichiers vidéos, ou logs console interceptés}

**Verdict:**

- PASS: {Si tous les critères sont OK}
- FAIL: {Si échec, description de l'échec et potentiellement la cause technique devinée}

---
