# Prompt — audit complet FoodKing (lecture seule, sans modifier le code)

Colle ce bloc dans ton client (OpenCode / Claude Code / etc.) avec **`max_tokens` au maximum autorisé par ton fournisseur** pour un modèle type **Opus**.  
Paramètres suggérés côté API : **température basse**, **pas d’outils d’écriture** si tu veux garantir zéro modification automatique.

---

**Instructions pour le modèle**

Tu es un auditeur technique **senior**. Tu **ne modifies aucun fichier** ; tu **produis uniquement un rapport** structuré.

**Périmètre** : dépôt Laravel + Vue FoodKing (POS, kiosk, KDS, commandes, paiement, fiscalité si visible dans les docs).

**Contraintes**

1. Ne propose **pas** de patch ni de diff ; si tu identifies des problèmes, décris-les et classe-les (sévérité, fichier ou zone logique si tu peux l’inférer sans inventer de chemains faux).
2. Respecte les invariants connus : **prix côté backend**, **`branch_id`**, **`OrderStatus` enum**, **dispatch après commit**, symétrie **OrderService / FrontendOrderService**, **zones gelées** sans gate.
3. Si une information manque, indique **« non vérifiable sans accès runtime »** au lieu de spéculer.

**Structure du rapport (obligatoire)**

1. **Résumé exécutif** (10–15 lignes).
2. **Architecture & frontières** (backend vs frontend, événements, files).
3. **Risques sécurité / auth / isolation tenant** (analyse conceptuelle).
4. **Cohérence flows commande & statuts** (risques de régression).
5. **Synchronisation temps réel / outbox** (si documenté dans le dépôt).
6. **Dette technique & tests** (couverture perçue depuis la structure du repo).
7. **Liste priorisée** : P0 / P1 / P2 avec justification courte.
8. **Annexes** : hypothèses, limites de l’audit, ce qu’il faudrait pour valider en prod.

**Longueur** : développe chaque section **au maximum** dans la limite du **`max_tokens` autorisé par l’API** ; si la limite coupe la réponse, termine par une phrase **« Suite dans un message suivant — demander poursuite section X »**.

---

**Contexte minimal à fournir au modèle** (optionnel, réduit les hallucinations)

- Pointer vers `AGENTS.md`, `docs/ORDER_FLOW.md`, `docs/DEVICE_FLOW.md`, `.cursor/rules/project-invariants.mdc` comme références de vérité.
