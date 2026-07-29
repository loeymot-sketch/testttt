# GOAL S2 — CAISSE + STOCK — CONVERGENCE (2026-07-29)

Base `fa172d5f4` · branche `worktree-s2-caisse-stock-2026-07-29` (poussée) · 17 commits `[S2]`.

## 1. Boucle adversariale (DISCIPLINE §6)
| Cycle | P0 | P1 | P2 | P3 | Issue |
|---|---|---|---|---|---|
| Auto-RED (moi, sur mon propre diff) | 0 | 1 | 0 | 0 | carte fantôme statut terminal — corrigé avant le cycle 1 |
| **Cycle 1** | 0 | **2** | 1 | 3 | fusion all-time du tracker + coût SQL ; boot-guard rendu inerte ; DST ; i18n — **tous corrigés** |
| **Cycle 2** | **0** | **0** | 2 | 4 | sessions PIN survivantes ; chip illisible ; bouton hors écran ; back-off ; libellé ; reset — **tous corrigés** |
| **Cycle 3** | *(en cours à la rédaction)* | | | | balayage de clôture + attaque des correctifs du cycle 2 |

Auto-rétractation notable : le PASS « pagination FR » a été **retiré** après sonde DOM
(cf. §4) — un `trans()` vert ne prouve pas ce que l'utilisateur voit.

## 2. Ce que la session a corrigé (tout prouvé)
**Argent / fiabilité**
- Tracker « À encaisser » affichait **0** alors que la file en contenait 17 (double cause :
  prédicat évalué sous un seul statut + fetch borné au jour). Corrigé, puis **re-corrigé**
  quand le cycle 1 a montré que ma première approche polluait le board.
- **Réception de marchandise ne levait pas la rupture** → produit invendable après
  réavitaillement (TDD, 2 tests ; le 86 manuel du gérant reste posé).
- Verrou `RefundCashNoWalletCreditTest` **sauvé** (non commité depuis le 22/07, 2 tests verts).

**Sécurité**
- Carnet : PIN par défaut **commité `2468`** supprimé → fail-closed, **et** sessions déjà
  ouvertes coupées (sinon quiconque avait déverrouillé avec le PIN public gardait le
  registre à vie). Même garde posée sur `/m`. Boot-guard prod élargi au PIN vide.

**Ergonomie caissier**
- Réimpression d'un ticket **clôturé** : d'IMPOSSIBLE → 2 clics, colonne collée à droite
  pour rester atteignable à 1280 px (mesuré x=1215).
- Commandes annulées / filtre date : **5 clics → 1**.
- Header POS superposé à 1280 ; carte tracker illisible ; chip active en blanc sur pêche
  (contraste 1,18:1, critique sur écran tactile où le survol reste collé).
- FR : « Oui / N° » (13 écrans), « Article Description » → « Désignation » (7 tickets),
  « 1 Articles », libellés « borne » mensongers sur une file à 4 origines, message /m trompeur.

## 3. Preuves
- **E2E réel money-path** : ticket 7,40 € payé 10,00 € → rendu **2,60 € exact** ; en base
  `payment_status` PAID, `pos_received_amount` 10,00, séquence fiscale **2690** allouée à
  l'encaissement, `CashMovement` = **7,40 €** (le total, pas les 10 reçus) ; chaîne NF525 OK.
- **Cycle stock au gramme** : 8 compteurs, écart 0 à la vente ET au retour ; propagation 86 < 1 s.
- **Suites** : PHPUnit périmètre S2 **969 tests / 4 465 assertions / 0 échec** ·
  vitest **2 646 / 0** · `fiscal:verify-chain --all` **CHAIN OK** (4 branches) ·
  **frozen-diff = 0 ligne**.
- Captures LUES à chaque vague (`tests/captures/goal-s2-*`), y compris pages cachées.

## 4. Ce qui N'EST PAS corrigé (honnêteté)
| Sujet | Pourquoi pas moi | Où |
|---|---|---|
| Pagination « Previous/Next » anglaise (~50 écrans) | racine = libellés codés en dur de la lib ; le fix touche `admin/components/**` = zone partagée §6 | handoff CENTRAL |
| « VAT (10.00 %) » sur tous les tickets clients | racine DATA (`taxes.name`), fiscal-adjacent, voie CENTRAL | handoff CENTRAL |
| Pas de filet 86 sur borne / KDS si la queue tombe | voies S1 / S5 | handoff S1+S5 |
| Pas de reprise BOM au remboursement **partiel** | `EventServiceProvider` = registre partagé | handoff |
| `ItemService` laisse fuiter les items de catégorie inactive dans la grille POS | voie CENTRAL | handoff S6 |
| Sidebar : les menus codés en dur court-circuitent le masquage V1 | `BackendMenuComponent` = voie CENTRAL | handoff S6 |
| Écran client OSS (état vide muet, bandeau magenta hors palette) | voie KDS/OSS | noté |
| Fiche commande incohérente (bloc livraison sur une commande à emporter, `Extras: ,`) | ma voie — **backlog S2**, non traité faute de temps | noté |

## 5. Gates owner (aucune ne bloque le reste)
1. **Poser `DAILY_BOOK_PIN` dans le `.env`** du poste : le Carnet est désormais fail-closed,
   il reste **inaccessible tant que l'owner n'a pas choisi un PIN** (c'était le prix à payer
   pour supprimer le PIN public commité). Le boot prod refuse aussi de démarrer sans lui.
2. Wizard caisse : format « €6.90 » anglais — `pos-wizard.js` est **frozen strict**, un LOCK
   est requis si l'owner veut le format FR.
3. Purge des items/catégories de test `E2E_PLAYWRIGHT_*` (re-semés à chaque run Playwright ;
   le vrai correctif est un teardown, pas un nettoyage DB).
4. Décider du renommage `taxes.name` « VAT » → « TVA » (document client).

## 6. Leçons durables
- **Un tableau de bord « du jour » ne doit jamais absorber une file all-time** : on y perd les
  autres colonnes et le compteur ment. Un compteur + un lien suffisent (une vérité par écran).
- **`trans()` vert ≠ écran corrigé.** La seule preuve recevable pour un libellé est le DOM
  après rebuild.
- `php artisan serve` **mono-processus** met toute la caisse sous voile de chargement (les
  polls concurrents se bloquent) → `PHP_CLI_SERVER_WORKERS=10`.
- Sur les écrans à poll continu, les clics Playwright doivent passer par le DOM :
  l'attente d'actionabilité ne converge jamais.
- **Fail-closed sur une porte d'entrée ne ferme pas les sessions déjà ouvertes** — surtout
  quand le secret remplacé était public.
