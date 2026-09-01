# Convergence — surfaces admin Grok (dashboard + catalogue + caissier cockpit)

Deux rounds consécutifs **opt-2** et **opt-3**, P0+P1 = 0, même set (vide).
Parent a lu les PNG des deux rounds.

## Wave C studio

| Round | Compteur | Aliquam/Rerum/E2E/AUDIT | Sandwichs/Tacos |
|---|---|---|---|
| opt-2 | 14 | absents | présents |
| opt-3 | 14 | absents | présents |

## Wave A dashboard

| Round | Total articles | Palette | Suivi |
|---|---|---|---|
| opt-2 | 55 | Cayenne | Ticket moyen seul |
| opt-3 | 55 | Cayenne | Ticket moyen seul |

## Wave E caissier

Deeplink `/admin/observability/system` → `/admin/dashboard`. Pas de Vue cockpit. Barre sans « État du système ». (Déjà vert rounds 2+3, reconfirmé opt-3.)

## Pas CONVERGENCE_FINAL produit

- 64 junk **en base** (masque, pas wipe)
- Studio « Toutes les catégories » **59** vs KPI **55**
- Item E2E encore dans « Articles mis en avant »
- Borne/POS n’utilisent pas `list()`
- Frozen kiosk/POS wizard hors vague
- P2 : debugbar, `english.png` 404, `capitalize` « Tableau De Bord »
