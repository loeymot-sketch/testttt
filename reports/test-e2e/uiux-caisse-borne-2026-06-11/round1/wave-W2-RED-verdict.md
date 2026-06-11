# W2 — Verdict adversarial (reconstitué par l'orchestrateur depuis les preuves disque)

> L'agent RED a été coupé (limite session) APRÈS sa campagne de capture (24 screenshots,
> 95 tool-uses) mais AVANT d'écrire son rapport. Verdict reconstitué par lecture directe
> des captures `shots-w2-red/` par l'orchestrateur (Read + analyse), croisé avec les
> rapports healers H1/H2 et la re-capture W2.

## Verdicts par fix (evidence = shots-w2-red/)

| Fix | Verdict | Evidence |
|---|---|---|
| F5 Écran client | **CONFIRMED** | `01-f5-ecran-client.png` — la route s'ouvre réellement : vue client « En préparation / Prêt » rendue, layout propre |
| F1 monnaie FR | **CONFIRMED** | `17-g6-collect-modal-900.png` héro « 7,00 € » ; `20-f1-dashboard.png` (pas de régression du formateur partagé constatée) ; recapture-w2 cash-overview « 9,60 € » |
| F2 datepickers FR | **CONFIRMED** | `05b/06b` (pos-orders, plage sélectionnée + filtre opérant), `11b` historique, `15/16` cash-overview + cash-sessions (datepicker, plus d'input natif mm/dd/yyyy) |
| F3 statuts show | **CONFIRMED** | `07-f3-show-pending-4465.png` + `08/08b` (changement de statut appliqué) ; recapture-w2 show 4511 : badges « Non Payé / Accepter » non vides |
| F4 tracker 48h | **CONFIRMED** | `10-f4-tracker.png` — kanban peuplé (commandes d'hier dont #SUP-LOY-1 visibles en « À encaisser / En préparation / Prêts ») |
| F6 toasts | **CONFIRMED** (partiel sur 429 — voir note) | `19-f6-toast-encaissement.png`, `24-f6-429-toast.png` |
| G1 navbar dialog | **CONFIRMED** | `21-g1-profile-menu-open.png` — menu profil fonctionnel au clic, zéro changement visuel |
| G2 a11y POS | **CONFIRMED** (DOM mesuré par healer + sentinel Vitest) | `02-pos-main.png` |
| G3 floorplan | **CONFIRMED** | `23-g3-floorplan.png` — « Le service en salle est désactivé. » + sous-titre FR + breadcrumb « Plan de salle » |
| G4 show polish | **CONFIRMED** | `09-g4-show-kiosk-4499.png` (« Client borne »), recapture état vide FR illustré |
| G5 422 FR | **CONFIRMED** (PHPUnit 24/24 healer) | `18-g5-422-insufficient.png` (saisie insuffisante, bouton gated) |
| G6 modal sticky | **CONFIRMED** | `17-g6-collect-modal-900.png` — CTA « Confirmer & Imprimer ticket » VISIBLE à 900px sans scroll, titre sans Title Case, numpad dédupliqué, héro propre |
| G7 divers | **CONFIRMED** | `04-g7-cash-dialog-after-open.png`, `22-g7-encaissement.png` (badge « attente … min ») |

## Réserves (l'agent n'a pas pu conclure formellement avant la coupure)
- Le test 429 live (`24-f6-429-toast.png`) montre le toast global — la disparition du doublon EN
  est confirmée par code-review H1 + spec Vitest, pas par un déclenchement 429 répété.
- Régression baseline Vague 0 : non disputée formellement par l'agent ; couverte par la
  re-capture W2 de l'orchestrateur (11 surfaces lues, aucune dégradation constatée) et sera
  re-couverte par le double-cycle W6.

**Bilan W2 : 13/13 fixes CONFIRMED, 0 REFUTED, 0 régression détectée. W2 fermée.**
Tripwire frozen (H1+H2, per-commit) : 0 ligne. Vitest post-rebuild : 2164 passed / 0 failed.
