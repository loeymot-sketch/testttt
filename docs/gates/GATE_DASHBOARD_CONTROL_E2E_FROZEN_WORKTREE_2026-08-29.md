# GATE — Validation E2E dashboard/contrôle bloquée par zone frozen staged

**Statut : PENDING_HUMAN_GATE**  
**Date : 2026-08-29**  
**Cycle : `CAISSE-SUPERVISOR-CONTROL-20260823`**  
**Ce document n'est pas une approbation. Aucun agent ne peut cocher le gate à la place du propriétaire.**

## Décision humaine requise

Choisir et autoriser explicitement un chemin de validation :

1. approuver/contresigner le lock existant puis corriger le hook afin qu'il reconnaisse réellement un lock valide ; ou
2. fournir un worktree de validation propre où la zone frozen n'est pas staged ; ou
3. faire finaliser/isoler le changement frozen par son propriétaire avant de reprendre l'audit.

Aucune suppression, restauration, unstaging ou déplacement des changements actuels ne doit être effectué par l'auditeur sans cette décision.

## État autoritaire observé

- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` est staged et classé frozen.
- `docs/locks/LOCK_KIOSK_WIZARD_MODIFIER_DEPUIS_RECAP_2026-08-25.md` est également staged.
- La ligne `Contresignature propriétaire` du lock reste non cochée.
- `bash .cursor/hooks/safety-check.sh` retourne exit 1 et `BLOCKED`.

## Défaut du hook

`.cursor/hooks/safety-check.sh:38-51` lit uniquement la liste des fichiers staged. Pour chaque zone frozen rencontrée, il met inconditionnellement `HIT=1`, puis quitte avec code 1.

Le script affiche « LOCK doc required », mais ne cherche jamais :

- un fichier sous `docs/locks/` ;
- une correspondance entre lock et zone frozen ;
- un statut/une contresignature ;
- une preuve de gate approuvé.

Par conséquent, même un lock staged et valide ne pourrait pas faire passer ce hook dans son implémentation actuelle. Corriger ce mécanisme est un changement de gouvernance à planifier/revoir ; ce n'est pas autorisé implicitement par l'audit.

## Impact sur l'objectif

La garantie « tout le dashboard et le contrôle sont testés » reste impossible à établir :

- `reports/antigravity/playwright-latest.json` contient maintenant 0 test passé et 47 ignorés, car la collecte `--list` a remplacé le reporter JSON ; la version HEAD contenait 0 passé et 1 ignoré. Aucun de ces artefacts ne prouve une exécution ;
- le corpus supervisor A→E est seulement collecté (17 tests) et ne couvre pas le dashboard/cockpit ;
- le corpus dashboard/admin est seulement collecté (47 tests) et n'a pas été exécuté sur le snapshot courant ;
- des scénarios E2E négatifs restent à ajouter pour les rôles non-admin, la branche absente, la santé inconnue et les erreurs réseau.

## Reprise après décision

1. Obtenir `safety-check.sh: PASS` sans contournement.
2. Démarrer un serveur depuis ce worktree sur l'URL déclarée et prouver l'identité de l'arbre servi.
3. Utiliser uniquement la base dédiée `foodking_e2e` avec le double opt-in requis.
4. Exécuter la campagne dashboard/contrôle dédiée, puis supervisor A→E séparément.
5. Vérifier postconditions DB, artefacts, absence de skip et zéro erreur inattendue.
6. Repasser audit Claude puis audit final GPT/Codex.

## Gate humain

- [ ] Le propriétaire confirme que le lock KioskWizard staged est approuvé/contresigné, **ou** fournit un worktree propre.
- [ ] Le propriétaire autorise un cycle gouverné pour corriger la logique de reconnaissance des locks du hook si ce chemin est retenu.
- [ ] Le binaire Codex est réparé pour permettre l'audit final obligatoire.
