# W5/W3 E2E LIVE — Cross-surface + Sync + NF525 — 2026-07-02

Preuve end-to-end d'une commande caisse traversant TOUS les systèmes, serveur live
`127.0.0.1:8766` (foodking_e2e), worker `queue:work --queue=high,default` actif.

## Chaîne complète prouvée (order 5398 / queue A0001, Tacos L 2 viandes)
| # | Surface | Preuve (capture + DB) | Statut |
|---|---|---|---|
| 1 | **CAISSE** création | wizard 2/2 viandes → `POST /pos/quote 200` (SSOT) → `POST /pos 201` ; composition_snapshot = Cordon Bleu + Poulet + Blanche (len 579) | ✅ |
| 2 | **KDS** cuisine | `kds-screen.png` : `N°A0001 [A] EN COURS CAISSE — 1× G\|TACOS\|L\|Cordon P\|BL` + badge « EN ATTENTE ENCAISSEMENT » | ✅ |
| 3 | **OSS** suivi client | `oss-screen.png` : `N°A0001` colonne « En préparation » (colonne « Prêt » vide) | ✅ |
| 4 | **Encaissement** file | `encaissement.png` : carte `Caisse N°A0001 — 1× Tacos L — 7,90 €` + bouton Encaisser (badge 1) | ✅ |
| 5 | **Encaissement** paiement | `encaissement-modal.png` : Espèce/Carte/Mobile/Ticket-resto, « simulation » affiché (POS_SIMULATION_HARDWARE), reçu 7,90 → Confirmer | ✅ |
| 6 | **Fiscal NF525** | DB post-encaissement : `payment_status=5 PAID`, `pos_pm=1 CASH`, **`fiscal_sequence_no=2589`** (était NULL), audit_logs +7 (`cash.movement.recorded`) | ✅ |
| 7 | **Chaîne NF525** | `fiscal:verify-chain --all` = **CHAIN OK 4 branches** AVANT et APRÈS l'allocation | ✅ |

## Conclusions
- **Sync cross-surface CONFIRMÉE** : une commande caisse apparaît simultanément KDS + OSS + file
  encaissement, avec la même composition (multi-viandes), format cuisine symbolique correct.
- **NF525 gap-free by design VÉRIFIÉ LIVE** : séquence fiscale allouée UNIQUEMENT à l'encaissement
  (2589), jamais à la création différée — exactement owner model (B) `walkin_route_to_counter`.
  La chaîne HMAC reste valide après append (0 corruption).
- **Bug historique « Viande 2 perdue » ABSENT** : les 2 viandes traversent wizard→panier→snapshot→
  KDS→ticket sans perte.

## Observations UI/UX (à consolider W6)
- **OBS-UX-1 (P3)** : le modal d'encaissement s'intitule « Encaisser La Commande **Borne** » + tag
  « BORNE » alors que la commande est source=pos (carte de file = tag « Caisse »). Le composant
  `PosCounterCollectModal` est réutilisé pour borne ET caisse-walk-in différée → libellé « Borne »
  trompeur pour une commande caisse. Cosmétique, non-bloquant.
- **OBS-UX-2 (bon point)** : labels « simulation » explicites sur Espèce (tiroir)/Carte (TPE) =
  transparence honnête du mode matériel simulé (CONSTITUTION §2). Excellent.
- **OBS-SYNC-1 (P2, → W3)** : KDS incrémental `GET /kds-order/sync?branch_id=0` = **401** pour
  l'admin (branch_id=0) ; le board charge + rafraîchit via poll 60s « Mode admin centralisé ».
  Le KDS est conçu pour le chef (branch_id=1) ; admin perd le sync incrémental (dégradation propre,
  pas de perte de données). Bruit console à noter.
- **OBS-SYNC-2 (P2 ops, → W3)** : `DispatchDomainEventsJob->onQueue('high')` ; un `queue:work` sans
  `--queue=high` laisse les broadcasts non traités (backlog 354 observé au démarrage = résidu
  test-env). **Prod DOIT lancer `queue:work --queue=high,default`** sinon temps-réel dégradé en poll.
- **Kiosk** : idle/attract rend parfaitement (portrait 1080×1920, carrousel 8 produits, FR-lock,
  100% Halal) mais navigateur non-provisionné = 3× 401 (menu/kiosk-event/login) → **attendu** (la
  vraie borne injecte un token machine via l'auto-login gate). Flux commande borne prouvé par les
  sessions passées + couvert par la carte W1 + la vague de vérification.
- Console **0 erreur** sur dashboard authentifié, POS, OSS, encaissement (hors 401 KDS-admin ci-dessus
  et 401 kiosk non-provisionné, tous deux attendus).
