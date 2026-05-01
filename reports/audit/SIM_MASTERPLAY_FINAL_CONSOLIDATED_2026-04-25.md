# Master Play — **rapport final consolidé** (toutes sources, toutes passes)

**Date** : 2026-04-25  
**Périmètre** : simulation + preuves fichier — **aucune modification de code** dans ce lot.

---

## 1. SSOT de ce rapport (ordre de lecture)

| Fichier | Rôle |
|---------|------|
| `SIM_MASTERPLAY_BREAKDOWN_SYNTH_V0_2026-04-25.md` | Carte V0 |
| `SIM_MASTERPLAY_GPT_CHALLENGE_ROUND2_2026-04-25.md` | Adversaire GPT (Round 2) |
| `SIM_MASTERPLAY_SYNTH_BRIDGE_ROUND3_2026-04-25.md` | Pont V0 / GPT |
| `SIM_MASTERPLAY_BREAKDOWN_SYNTH_V1_2026-04-25.md` | Fusion V1 |
| `SIM_MASTERPLAY_CLAUDE_TERMINAL_ROUND4_2026-04-25.md` | **Arbitrage Claude Code terminal** (Opus 4.7 + high) |
| `SIM_MASTERPLAY_ORCHESTRATION_PLAN_EXECUTED_2026-04-25.md` | Plan numéroté exécuté |
| **Ce document** | Synthèse unique + grands tableaux comparatifs |

**Verdict global retenu** : `MERGE_V0_WITH_REVISIONS` (V1) + `AUDIT_VERDICT: REWORK` (Round4) = **prêt doc, pas prêt prod** sans cycles P0.

---

## 2. Conflit n°1 — `DEVICE_FLOW` · Firebase vs Echo

| Source | Position |
|--------|----------|
| `docs/DEVICE_FLOW.md` §2 L16 | « WebSockets **Firebase** pour les entrées Kiosk » |
| `PosComponent.vue` | `onEvents`, `_subscribeEcho` @ ~1172 — **Echo** |
| V0 (POS-DOC-01) | Candidat dérive |
| GPT R2 §B.1 | Confirme |
| **Round4 Claude** | **Dérive documentaire avérée** — **aligner la doc** |

**Décision** : le **code** fait foi ; `DEVICE_FLOW` à corriger en **cycle doc**.

---

## 3. Conflit n°2 — Idempotence : lock vs requête vs index DB

| Élément | Détail |
|---------|--------|
| Migration | `2026_04_18_140003_scope_idempotency_key_to_branch.php` — unique `(`branch_id`, `idempotency_key`)` |
| Cache lock | `sha1($lockBranchId . '|' . $idempotencyKey)` — **scopé** |
| Requête | `FrontendOrder::where('idempotency_key', $id)->first()` — **non scopée** `branch_id` |
| Risque | Même clé, deux branches → mauvaise commande retournée en hit idempotence |
| GPT R2 | P0 attaque |
| **Round4 Claude** | P0 **maintenu** ; lock mitige concurrence, pas sémantique multi-branche read |

**Décision** : **P0 code** (lookup + test) — inchangé dans ce lot.

---

## 4. Conflit n°3 — Montant TPE / carte vs backend

| Élément | Détail |
|---------|--------|
| Après `submitOrder` | `rawTotal` depuis `res.data.data.total` ou `order_amount` (L295) |
| Hors-ligne | `isOfflineId` → `cartTotal` (L297–298) |
| Erreur | Pas de `total` valide → `throw` (L300–303) |
| TPE | `amountEuros = this._lastOrder.total \|\| this.cartTotal` (L408) — `_lastOrder` rempli L324 **après** réponse API |
| GPT R2 §A.2 | UNVERIFIED (paiement non lu) |
| **Round4 Claude** | **SSOT backend en ligne** — §A.2 **nuancé/infirmé** pour le online |

**Décision** : conserver le **avertissement offline** ; piste doc / tests ciblés « offline TPE » si produit l’exige.

---

## 5. Table « Challenge double » — Toutes les attaques GPT R2 (SECTION_A) vs Round4

| §A | Thème | Round4 |
|----|--------|--------|
| A.1 | Idempotence | **P0 maintenu** |
| A.2 | Paiement / total | **Infirmé (online)** — mitigé offline |
| A.3 | `branch_id=0` KDS / Echo | **P1** — non re-vu fichier dans Round4, pas contredit |
| A.4 | `OrderTableChanged` + test after-commit | **P0** — `DispatchAfterCommitTest` : 3 events, pas de `OrderTableChanged` |
| A.5 | Version sync KDS / `status_changed_at` | **P1** — listé, non re-ouvert ici |
| A.6 | Bump KDS / `localStorage` | **P0 gouvernance** |
| A.7 | OSS + événements | **P2** |

---

## 6. Table — Validations GPT R2 (SECTION_B) vs Round4

| §B | Sujet | Stabilité post-Round4 |
|----|--------|------------------------|
| B.1 | `DEVICE_FLOW` / Firebase | **Toujours vrai** (doc fausse) |
| B.2 | KDS 4 événements | **Tenu** (non re-grep ici) |
| B.3 | Filtre KDS `branch_id` | **Tenu** |
| B.4 | After-commit **design** | **Partiel** — test incomplet (A.4) |
| B.5 | `OrderStatus` enum | **Tenu** |
| B.6 | Unset totaux côtier + preview | **Tenu** + renfort paiement online |
| B.7 | Header `X-Idempotency-Key` store | **Tenu** |

---

## 7. Table — Métriques §D GPT (pistes futures)

| Métrique | Statut |
|----------|--------|
| Latence p95 commit → surface | **À instrumenter** — hors simulation |
| Couverture event × surface × tests | **Gaps** : `OrderTableChanged` test, OSS |
| Anti-double listener | **À E2E / intégration** |

---

## 8. Cinq actions ordonna **(reprise Round4, inchangé)**

1. **P0** — Scope `branch_id` sur lookup idempotence + test deux branches.
2. **P0** — `DispatchAfterCommitTest` : ajouter `OrderTableChanged` (constructeur minimal).
3. **P0** — ADR bump KDS (serveur vs règle mono-poste).
4. **P1** — Corriger `docs/DEVICE_FLOW.md` (Echo, pas Firebase pour ce flux).
5. **P1** — `status_changed_at` + scénario 2 écrans KDS.

---

## 9. Synthèse arbitrage (GPT **vs** Claude **vs** agent)

| Axe | GPT R2 | Agent (lecture code) | Claude R4 |
|-----|--------|------------------------|-----------|
| Paiement online | Suspect (non lu) | Confirme SSOT dès réponse API (commentaires AUDIT-52) | **Infirme** la critique sur online |
| Idempotence | Critique lookup | Même : lock OK, requête non | **P0** aligné |
| Doc Firebase | Signalé | Même | **Idem** |
| Tests after-commit | Manque `OrderTableChanged` | Même 3 fournisseurs | **P0** aligné |

**Lecture d’équipe** : GPT a poussé la **suspicion** utile sur le TPE (agent + Claude l’ont **désamorcée** pour le online avec preuves) ; sur **idempotence** et **tests**, GPT et Claude sont **alignés**.

---

## 10. Ligne de clôture simulation

- **Boucle BX** (procédure repo) : inchangée ; ce dossier = **brique d’entrée** pour un futur `TASK_ID` ciblant P0.
- **Graphiti** : épisode « V1 fusion » + ce rapport = matière à `add_memory` / JSONL `12_decisions` après validation humaine.
- **Dernier mot technique** (Round4) : `AUDIT_VERDICT: **REWORK**` — normal pour une simulation qui liste encore des P0 actifs en code / tests / doc.

---

*Fin du lot Master Play 2026-04-25.*

---

## 11. Suite (continuation produit) — 2026-04-25

Trois items P0/P1 **implémentés** : voir `reports/audit/SIM_MASTERPLAY_P0_CONTINUATION_2026-04-25.md` (idempotence + test after-commit + doc `DEVICE_FLOW`). P0 gouvernance KDS bump et P1 `status_changed_at` restent ouverts.
