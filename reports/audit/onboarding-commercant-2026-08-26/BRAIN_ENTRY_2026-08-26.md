# Bloc à coller dans `PROJECT_BRAIN.md §4 NEXT TO DO` (en tête) — rédigé le 2026-08-26

> ⚠️ Le `PROJECT_BRAIN.md` de l'arbre principal porte des modifications non commitées d'autres sessions : ce bloc n'a PAS été
> écrit dans le worktree pour éviter un conflit de fusion. Le coller à la main (ou par la session qui fusionne la branche).

### 🎯 PROGRAMME ÉCRIT, NON LANCÉ — `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` (14 GOAL)
Rédigé le **2026-08-26** (HEAD `43b120c7d`, branche de livraison `goal/onboarding-commercant-2026-08-26`, worktree
`.claude/worktrees/goal-onboarding-commercant-2026-08-26`). Mandat propriétaire : rendre le logiciel livrable à un **nouvel
établissement** qui règle tout depuis le Dashboard sans développeur (identité, catalogue, wizard à règles gratuit/inclus/payant,
extraction de menu par IA + assistant, réglages, équipe, rapports, stock, promos, équipement, UX, installation vierge,
sécurité, convergence). **Contradiction constitutionnelle tranchée par gate G0** (paramétrer ≠ multi-tenant).

- **14 paires** `plans/GOAL_ONB01..14_*_2026-08-26.md` + `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB01..14_*.md`
  (prompt de lancement en §0 de chaque rapport de mission) · ports de session **8801-8814** · vagues A (01,02,05,06,07,08,09,10) →
  B (03,04,11,13) → C (12) → D (14).
- **Reconnaissance web réelle** sur `:8766` : `recon/Z1_catalogue_wizard.md`, `Z2_profil_reglages.md`, `Z3_utilisateurs_rbac.md`,
  `Z7_equipement_ops.md` (+ cartes `Z0_*`) ; Z4/Z5/Z6/Z8 non exécutées (limites de session) → vague W1 des GOAL concernés.
- **P1 mesurés (extraits)** : champs SIRET/TVA/mentions/barème de la filiale jamais enregistrés · page Site inenregistrable sans clé
  Google Maps · zone de livraison cassée (DrawingManager retiré) · Licence = clé d'API en clair · jetons de borne non révoqués ·
  borne du Dashboard ≠ borne qui se connecte · imprimantes LAN refusées (message SMTP) · statut imprimante 1 vs 5 · TPE simulé
  non éditable · `kds_station` = erreur SQL brute · canal inconnu accepté · TVA 0 % par défaut (config dit 10 %) · 47 taxes
  parasites · `users.phone` erreur SQL · 80 permissions en anglais · 9 mutateurs sans FormRequest · aucune sémantique de prix
  dans le wizard · installation neuve = Le Cayenne (147 + 11 fichiers « cayenne »).
- **Erreur d'instrument corrigée** : « page Rôles introuvable » = mauvaise URL (`role`, singulier) — leçon §3ter.
- **Nettoyage** : toutes les entités de test des auditeurs supprimées définitivement (`AUDIT-ONB-*` = 0), réglages restaurés.
- **Gates transverses** : G0 (constitution), G-PRIX (LOCK PricingService, ONB-03), G-IA (clé/plafond, ONB-04), G-CACHE (22 pages,
  ONB-05), G-DATA (migrations/bases dédiées), G-PUSH.
- **Pour lancer** : ouvrir une session sur l'arbre principal, fusionner/checkout la branche, coller le prompt §0 du rapport de
  mission du GOAL choisi. Aucun code produit modifié par la session de cadrage ; rien poussé.
