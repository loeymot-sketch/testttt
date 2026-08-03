# CONVERGENCE FINALE — vérification déploiement c70b1e518 (KDS redesign + ticket + bridge)

Date : 2026-07-06 · Branche : pos/category-first-caisse-2026-06-23
Commits produits par l'audit : `056581cf9` (fix P1 hauteur KDS + tests réalignés) · `24e8a09c3` (parité translit + fixture durable) — **non poussés**.

## Verdict : CONVERGÉ — P0+P1 = 0, deux cycles propres consécutifs

## Round 1 (3 vagues parallèles)

### Wave A — visuel écran cuisine /kds
- VERTS : 3 cartes max, pastille « +N en attente » exacte, codes 3 lettres (CAY jamais CAYENNE), 1 ligne/produit, suppléments inline jaune gras, bande Historique fine 36px, 0 label brut.
- **P1 KDS-A-01** (corrigé `056581cf9`) : cartes pas plein écran hauteur — double root cause : `.db-main` display:block (app.css:320) + height inline 462px hardcodée (KdsOrderCard.vue:319). Fix route-scoped `db-main--kds` posée/retirée par le composant KDS.

### Wave B — ticket ESC/POS + bridge + parité VPS
- Queue = 8 lignes confirmée code (EscPosTicketBytesService:81, config/printing:125,134) + rendu réel #5474 : 0 ligne >32 col, prix atomiques, tailFeed 8, coupe partielle. Plus de marge 10 cm.
- bridge.js md5 `a70b4233fb34b775520f37f0fe47eb0e` : local == repo == VPS (`vps-418872ac.vps.ovh.net/dl/bridge.js`). Titre gras 2×2, tél 03 65 67 82 91, adresse, coupe partielle, 12/12 assertions node.
- admin-kds.js VPS md5 `35ff5098d1d5ef579e8c3af751bae25a` == manifest (cache busté).
- P2 test stale queue-30 → réaligné (`056581cf9`).

### Wave C — flux borne→KDS→caisse (e2e UI réel)
- Commande #A0001 (order 5499, Tacos M 6,90 €) : borne UI complète → PENDING_COUNTER → visible KDS « EN ATTENTE ENCAISSEMENT » → file caisse → encaissée → **PAID, fiscal_sequence_no 2624, CHAIN OK**, disparue de la file.
- Intégrité numérique 6,90 € identique sur toutes surfaces + DB. 0 mismatch.

## Round 2 (superviseur adversaire) : CLEAN
- Mesures DOM : 3 cartes h=1968/2020, hauteurs identiques, height inline null, CTA en bas, paysage + portrait.
- Régression fix vérifiée : /admin/pos et /admin/items intacts, classe `db-main--kds` bien retirée en sortie de route.
- Candidat P1 (carte jaune pleine) réfuté par le code : `kds-card--has-supplements` = design voulu.
- 0 erreur Vue, 0 fuite i18n, 0 nouveau 4xx/5xx.

## Round 3 (confirmation) : CLEAN + bonus
- Le test de parité ressuscité (fixture éphémère → corpus durable 391 snapshots réels) a attrapé une **vraie dérive latente** : `norm()` PHP via iconv//TRANSLIT dépendait de la libc (macOS : Méga→« M » au lieu de MEG) → fix `Str::ascii` déterministe (`24e8a09c3`). Parité ticket↔écran 3/3 sur 391 lignes réelles.
- Test C3 geocode réaligné sur le contrat distance-manuelle.

## Gates finaux
- Vitest : **304/304 fichiers, 2116 passed, 0 failed** (3 skipped)
- PHPUnit ticket/symbolique/print : **160 passed** ; filter LongFeed+WidthSafe : 15 passed
- Frozen zones : **diff 0 ligne** (13 fichiers §7)
- NF525 : `fiscal:verify-chain --all` = **CHAIN OK × 4 branches**

## Restes non bloquants (P2/P3 disclosed)
- Sync incrémental KDS : 401/403 (URL relative :8000 vs baseURL :8766 en dev + 403 admin branch_id=0) — fallback polling 60s actif. Backlog.
- Pastille +N chevauche « SYNC · ADMIN » (~7px) ; avatar navbar ORB cross-origin (dev) ; jaune suppléments #CA8A04 (choix lisibilité vs #FFB800).
- Index git : un vieux staging massif incluait des `.env.bak*` avec secrets AWS — dé-stagé (hook pre-commit avait bloqué). Ces fichiers sont toujours dans le working tree : à purger/rotationner.

## Action machine (inchangée — hors de portée logicielle)
Écran cuisine : hard-reload (Ctrl+Maj+R). Borne : re-télécharger le pont + relancer caché (message cowork `reports/handoff/COWORK_VERIF_BORNE_KDS_2026-07-05.md`).
