# Méga-audit sécurité + synchro + logique + intersections (focus nouveau code) — 2026-07-07

Audit adversaire massif 12 lentilles + réfutation, verify-before-trust. HEAD au lancement.
60 findings → 8 REAL, 4 déjà-corrigés, 22 by-design, 26 FAUX, 0 V2.

## 8 REAL — surtout sur MON propre travail récent (C33 + commande téléphone)
| Sév | Finding | Fix |
|---|---|---|
| **P2** | Sécurité : `.env.bak-*`/`.env.dashe2e` (clés AWS + secrets HMAC NF525) NON gitignorés | ✅ **CORRIGÉ `61ece6440`** catch-all `.env.*` sauf examples |
| **P1** | Commande DIFFÉRÉE × fenêtre Z : créée avant un Z, encaissée après (fiscal alloué tard) → reçu dans AUCUN Z signé (gap-free NF525). Concerne TOUTES les différées (téléphone/borne/walk-in) | 🔄 fiscal (fiscal_dated_at + aggregate) |
| **P2** | Détecteur `verify-z-membership` MASQUE les orphelins HISTORIQUES (faux négatif — mon « 0 orphelin » était FAUX : les Z historiques signés en opened_at, le détecteur reconstruit en closed_at) | 🔄 fiscal (fenêtre pré/post-C33) |
| **P2** | Annulation commande téléphone ne rembourse PAS la fidélité (asymétrie type C36 sur counter-collect cancel) | 🔄 logique (refundPoints) |
| P3 | C33 sélection Z précédent `closed_at < ` strict — tie-break si deux clôtures au même instant | 🔄 fiscal |
| P3 | Commandes téléphone abandonnées jamais purgées (cron cible kiosk only) | 🔄 logique (étendre purge) |
| P3 | pos_customer_phone stocké mais jamais imprimé sur le ticket | 🔄 logique (affichage ticket) |
| P3 | Artefact transition cross-deploy (commande livraison pré-deploy remboursée) | documenté (transitoire) |

## Honnêteté
L'audit a attrapé une ERREUR de mon travail précédent : j'avais annoncé « branche 1 = 0 orphelin » après le détecteur autoritaire — c'était un FAUX NÉGATIF. Le vrai correctif distingue les Z pré-C33 (opened_at) des post-C33. Et le P1 (différée × Z) est une vraie faille NF525 que le flux téléphone + counter-collect expose. La boucle adversaire sur mon propre code paie.
