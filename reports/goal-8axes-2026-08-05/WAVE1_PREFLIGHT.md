# Vague 1 — Pré-vol (2026-08-05)

## Relevés de référence
- HEAD au lancement : `f7b29fdee` (le GOAL a été écrit sur `1bd3d872d` ; entre-temps un déploiement VPS a été fait — 11 commits synchro, vérifiés LIVE)
- Branche : `pos/category-first-caisse-2026-06-23` · backup : `backup/pre-8axes-2026-08-05`
- Dump DB : `storage/backups/db-daily/pre-8axes-2026-08-05.sql.gz` (3,5 Mo, base `foodking_e2e`)
- Chaîne NF525 : `audit_logs = 5409`, dernier hash `6b00ad0afc4fe266…`
- Services : queue worker redis UP (pid 41197), soketi UP, serveur 8000 → 200
- Disque : **15 Go libres — AU SEUIL**. Purger les vieilles captures avant toute vague visuelle lourde.
- Arbre : nettoyé — fix fidélité orphelin commité séparément (sentinelle 4 verts), 96 fichiers rapports/plans en checkpoint.

## Qualification des gates
| Gate | Verdict | Justification |
|---|---|---|
| G-1 (révoquer 3-cartes) | **LEVÉE** | La directive owner de ce jour redemande explicitement 6 cartes + scroll — révocation dans ses propres mots. Sentinelles réécrites, pas contournées. Journalisé BRAIN §6 à la clôture. |
| G-2 (PaymentComponent frozen) | **CONTOURNEMENT D'ABORD** | Stratégie : porter le multi-tender sur `PosCounterCollectModal.vue` (NON frozen) + backend. On ne touche PaymentComponent QUE si la repro CB (Vague 2) prouve que le défaut y vit → alors LOCK doc. |
| G-3 (pos-wizard.js) | **NON NÉCESSAIRE sauf preuve contraire** | Axe 8 résolu en data (ItemExtra en base) + miroirs non frozen. |
| G-4 (résolution écran cuisine) | **DÉFAUT DOCUMENTÉ** | Non fournie → captures à 1920×1080 ET 1366×768. Ajustable en une ligne si l'owner fournit la vraie valeur. |
| G-5 (PosV5TrancheRow) | Conditionnelle | Réutilisé en lecture (import), pas modifié — pas de LOCK tant qu'aucun diff. |
| G-6 (architecture abandon web) | **OPTION B retenue** | Recommandée au plan (état brouillon invisible + promotion au paiement + purge). Directive « max best result » = feu vert d'exécution ; réversible. |
| G-7 (prix Maïs/Olives) | **0,90 € retenu, réversible** | Phrase owner : « poivrons cuits pour 0,90 € … et maïs et olive AUSSI payante » — lecture raisonnable : même groupe 0,90 €. Changeable en une ligne ; signalé au rapport final. |
| G-8 (deploy VPS) | **RESTE GATÉE** | Aucun push sans accord explicite. |
| G-9 (règle D-2/D-3) | **DISTINGUER, ne pas fusionner** | Deux portions distinctes = production cuisine distincte. Rendu désambiguïsé. |
| G-10 (ligne d'en-tête gras) | **REPRO EN VAGUE 4** | « CUISINE » déjà gras 1 ligne. On décode les octets d'un ticket réel et on traite les lignes qui se replient réellement (n° d'appel, bannière, Client/Tel). |

## Blocages externes : aucun. Vagues 2→7 exécutables.
