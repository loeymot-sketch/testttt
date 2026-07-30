# CONVERGENCE FINALE — test-e2e pré-deploy 2026-07-30

Mission : vérification totale → test-e2e → gate → deploy (VPS + Vercel) → test réel.
Périmètre : delta backend (3 commits heals/fix) + web 27 commits (S4+S7+S8+CGV).

## Verdict : **CONVERGÉ — P0+P1 = 0 sur 2 cycles**

- **Round 1** : 19 captures (10 backend + 9 web) par 2 agents GStack, 2 superviseurs
  adversariaux indépendants (vision multimodale, zooms 2x).
  Brut : web P0=0/P1=0 · backend P0=0/P1=1.
  Adjugé avec preuves (`round-1/ADJUDICATION.md`) : **P0=0 · P1=0** —
  le P1 KDS réfuté par query DB (commande E4MASS 0-item en base, rendu honnête),
  et un P1 RÉEL découvert par contre-vérification (CGV 1€=1pt faux ×10)
  **corrigé (`e745509`) dans le cycle**.
- **Round 2** : unique surface modifiée (legal/cgv.html) re-capturée + lue —
  barème 1€=10pts rendu, conversion propre, 0 erreur console. Aucune autre
  surface touchée depuis ses captures round-1 → ensembles de findings stables.

## Évidence totale du cycle
| Gate | Résultat |
|---|---|
| PHPUnit full | 3839 tests / 16273 assertions / **0 échec** (2 runs, heals entre) |
| Vitest full | 2653 pass / 0 fail (371 fichiers) |
| Specs web locales | 7/7 EXIT:0 (ghost 10/10, suivi 15/15, parité S8 37/0, bol-boisson 9/0, TTL 4/0, email-OTP one-time, nav-smoke 0 err JS) |
| Smoke Playwright backend | 15 pass / 3 fail = dette specs (category-first / animation / donnée), adjugé non-régression |
| Frozen zones | diff 0 + baseline SHA256 rattrapée sous 3 LOCKs owner cités |
| NF525 local | CHAIN OK ×4 branches (×2 runs) |
| NF525 VPS | TAMPER connu/gaté owner (Workstream A fin-de-projet) — hors delta |
| Captures analysées | 20 PNG lus en vision (19 + CGV round 2) |

## Registre divulgué (non-bloquant)
- **P2 ×11** dont : chevauchement header caisse (02a, 4/4 runs) · empty-state
  défensif carte KDS 0-item + purge zombie · tuiles stock sans nom (4 vignettes
  indistinguables) · triple promesse de temps (10 / 10-15 / ~12 min) ·
  « À emporter sur place » (français bancal) · module panier vide 236px ·
  divergence tracker jour vs file all-time (famille S2 non fusionnée) ·
  « Guest User » anglais dans l'historique.
- **P3 ×13** (cosmétique, listés dans les findings JSON).
- **Dette specs** : 3 specs adversarial smoke à réécrire (category-first, stabilité
  animation borne, seed données KDS).
- Réserves harnais : borne capturée en paysage letterboxé (pas le portrait réel) ;
  capture 08-confirm-direct dupliquée binairement de 02 (couverte par spec DOM 10/10).

## Incident d'environnement (leçon)
Relance :8000 multi-worker avec mauvais docroot → toutes les réponses = Fatal
error PHP **avec HTTP 200**. Un curl code-only était vert. Règle : valider le
CONTENU (taille + marqueur), jamais le code seul. Specs web non affectées
(elles visaient :8766, serveur sain).
