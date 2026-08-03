# CONVERGENCE FINALE — GOAL owner-8-problemes (2026-07-06)

Branche `pos/category-first-caisse-2026-06-23` · base `24e8a09c3` → HEAD `16544e7fc` · **11 commits, NON poussés (gate owner §10)**.

## Verdict : CONVERGÉ — P0+P1 = 0, round adversaire 2 CLEAN

Méthode : audit profond 3 vagues (read-only) → implémentation parallèle (domaines disjoints) → LOCK owner-gaté → round adversaire 1 (3 superviseurs fresh-eyes) → heal → round adversaire 2 (confirmation ciblée) CLEAN.

## Les 9 problématiques owner → statut
| # | Problème | Statut | Preuve |
|---|---|---|---|
| 1 | Boisson non choisissable dans formule caisse | ✅ | 15 boissons « Incluse », #5534 total 9,90 € figé serveur, fiscal 2631, 0 ligne facturée |
| 2 | « Hawaï » (pas Fanta Hawai) + Fuze Tea | ✅ | items id124 = « Hawaï 33cl » slug migré en place, 0 « fanta-hawai » restant |
| 3 | Caisse lente « années 2000 » | ✅ | tuiles -97,8% (32,7→0,74 Mo), 0 refetch/ajout, localStorage 135→4,5 Ko ; +bug pos_cart_v3 jamais fonctionnel corrigé |
| 4 | Images réelles viandes | ✅ | 7/7 photos webp liste+suppléments, 0×404 (⚠ Cordon Bleu watermark PNGTREE = gate owner G2) |
| 5 | Remarques absentes écran cuisine | ✅ | note nettoyée sur carte KDS + canal `BOISSON:` affiché |
| 6 | Option oignon cuit + symbole | ✅ | exclusif cru↔cuit caisse+borne, défaut cru, **O̲** souligné bytes `1B 2D 01 4F 1B 2D 00` |
| 7 | Boissons visibles cuisine (pas que ticket caisse) | ✅ | nom complet 3 chemins + extraction boisson formule borne (#5533 « BOISSON: Hawaï 33cl ») |
| 8 | Ticket borne = design caisse | ✅ | renderer serveur, orderId passé à cash-instruction, bytes 1124 o « A REGLER EN CAISSE » gras |
| 9 | Impression 20s / écran gris / flash | ✅ | toast 358 ms, pont 202 immédiat + compile PS unique boot, window.print jamais auto ; flash = relanceur machine (gate cowork G3) |

## Round 1 adversaire (3 vagues) → findings healed
- **ADV-A caisse** : range propre ; 1 P1 latent (crash restore parké livraison, `addressText.trim()` sur null, blame C3 pré-range) → heal `16544e7fc`.
- **ADV-B cuisine** : 2 P2 → détection boisson ne couvrait que 7/15 (régression du renommage Hawaï, sortie du match `/fanta/`) + fixture parité sans nouveaux shapes → heal `3e9eef062` (15/15 data-driven + fixture 220 rows). +P3 note multi-lignes + P3 garde note Uber.
- **ADV-C borne+gates** : 1 P1 → boisson borne n'atteint pas la cuisine (ligne « Formule… » droppée par sanitizer) → heal `3e9eef062` (extraction, bonus rétroactif commandes historiques). Gates full 5/5 verts.

## Round 2 adversaire (confirmation ciblée) → CLEAN
Les 4 heals re-attaqués e2e réel : restore parké (livraison+null+emporter) 0 TypeError ; boisson borne→cuisine #5533/KDS A0012 OK ; 15/15 boissons nom complet + 0/3 faux positif dessert ; note pre-line 2 lignes. Chasse aux régressions : notes légitimes préservées, sauce frites intacte. **Verdict CLEAN.**

## Gates finaux (round 2, re-runnés)
- Vitest **2244/0** (319 fichiers, 3 skipped)
- PHPUnit filtré zones **838/0** ; range full **3165/0**
- Frozen diff `24e8a09c3..HEAD` : SEULS pos-wizard.js (+299) + KioskWizardComponent.vue (+34), LOCK APPLIED → **0 ligne frozen non autorisée**
- NF525 `fiscal:verify-chain --all` = **CHAIN OK ×4**
- audit_logs 4909 ≥ 4821 baseline (append-only)

## Note ops critique (récurrente) — cache machine
Le round 2 a reproduit le crash restore UNE fois sur `pos-shell.js` EN CACHE (serveur sert bien les bytes corrigés, prouvé curl). C'est LE même symptôme que la mission d'origine (« je corrige et rien ne change ») : `pos-shell.js`/`admin-kds.js` n'ont pas de cache-buster. **Au déploiement : hard-reload machines OBLIGATOIRE** (le fix filemtime W5 sur master.blade.php aide app.js ; le rebuild change les hashes admin). Aligné sur la discipline deploy documentée.

## Restes (owner)
- **Gate §10 push** : 11 commits prêts, non poussés. Puis `deploy-owner8.sh`.
- **G2** : vraie photo Cordon Bleu (watermark) + visuels dédiés fuze-tea/hawai/perrier (replis en place).
- **G3** : pont borne caché (flash) — runbook cowork `COWORK_VERIF_BORNE_KDS_2026-07-05.md`.
- **G4** : purge `.env.bak*` + rotation clés AWS (bloqué par pre-commit, jamais commité).
- **P3 divulgués** (non bloquants) : label « ✕ Sans Oignons cuits » sur opt-in (frozen, non touché) ; toast validation paiement anglais brut (pré-existant) ; tuile Oignons cuits sans photo.
