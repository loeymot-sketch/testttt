# Passation Cursor — Fichier 2/3 : historique & vision de la conversation

**Objectif :** ce que le nouveau compte / nouvelle session doit « savoir » sur **ce qui s’est fait** et **pourquoi**, sans relire tout le chat.

---

## 1. Arc narratif utilisateur

- Commandes répétées **« next P »** : enchaînement d’**épiques numérotés** P4 → P5 → … sur la branche **`feat/ton-sujet`**, avec **push** vers `origin`.
- Demande finale : **rapport global des P** + **mission d’audit POS 110 %** (read-only, multi-axes NF525 / caisse / KDS / OSS / sécurité / données / tests), livrables sous `reports/review/`.
- Demande actuelle : **pack de passation** en **3 fichiers** pour **nouveau compte Cursor** avec le **même dossier projet**.

---

## 2. Chronologie des livrables code (par message « next P »)

| Étape | Contenu livré |
|-------|----------------|
| **P4** | KDS `changeStatus` : transaction + `lockForUpdate`, comparaison statut attendu vs ligne → **409** si dérive ; `OrderStateMachine::allows` sur ligne verrouillée ; `recordTransition` **dans** la transaction ; notifications après commit ; controller propage `HttpException` ; Vuex recharge liste sur **409** ; test service avec modèle mémoire obsolète. Commit `e18344af4`. |
| **P5** | `OrderRequest` : `total` avec `min:0` ; test négatif. Commit `87491043c`. |
| **P6** | `TableOrderRequest` : `subtotal` / `total` `min:0` ; tests. Commit `952b840b1`. |
| **P7** | Alignement `min:0` sur `subtotal`, `discount`, `delivery_charge` (OrderRequest, Table, Pos) + `pos_received_amount` ; tests étendus. Commit `19476d56b`. |
| **P8** | `CouponCheckRequest` : `total` `min:0` ; test. Commit `4113423fb`. |
| **P9** | `CouponRequest` admin : champs monétaires / caps `min:0` ; tests. Commit `649d18d06`. |
| **P10** | `OrderSetupRequest` : tous les champs numériques `min:0` ; tests + chemin heureux 200. Commit `c00a8cd61`. |

**Contexte antérieur (résumé conversation / git)** : P1 stock/disponibilité, P2 TR, P3 RETURNED + audit — commits `b76506ae9` … `b007c6344`.

---

## 3. Vision technique globale

- **P4–P10** forment une **ligne continue d’hygiène validation** (montants / coupons / paramètres) + **cohérence KDS** sous concurrence. Ce n’est **pas** un refactor fiscal : **OrderService gelé** respecté sauf travaux antérieurs (P1–P3).
- **Principe récurrent :** rejeter les **entrées négatives absurdes** tôt (`FormRequest`) ; le **prix SSOT** reste côté services (cf. `BUSINESS_RULES.md`).
- **KDS :** le binding HTTP recharge toujours un `Order` **frais** ; le **409** protige surtout la fenêtre **post-binding, pré-lock** et les appels service avec instance périmée (tests le documentent).

---

## 4. Mission audit POS 110 % (après les P)

- **Mode strict :** aucun commit code ; uniquement **rapports** sous `reports/review/`.
- **Méthode :** grep/read + **sous-agents** exploration (fiscal, isolation, Vuex, routes).
- **Livrables :** famille `AUDIT_POS_110_*_2026-04-19.md` + `REPORT_GLOBAL_P_IMPLÉMENTATIONS_2026-04-19.md`.
- **Verdict synthétique :** socle **audit_logs + Z + séquence** solide pour audit interne ; tensions notables **Z.open** sans `lockForUpdate` sur MAX, **pas de `order_payments`**, **BUSINESS_RULES** vs dispo, **double parcours paiement** / idempotence.

---

## 5. Vision produit / risque (ce que la conversation a « appris »)

- **NF525 « certification »** ≠ **readiness technique** : le dépôt peut être **READY_TECHNIQUE** sans être **READY_ORGANISME**.
- **Tests verts** ne remplacent pas scénarios rush / double-clic / infra Redis.
- **Documentation** (`BUSINESS_RULES`) doit suivre le code sous peine de mauvaise exploitation terrain.

---

## 6. État émotionnel / consigne utilisateur implicite

- Préférence pour **prose claire**, **chemins complets**, **pas de commits** sur l’audit.
- **Nouveau compte Cursor** : besoin de **reconstruire le fil** via ces trois fichiers + règles workspace.

---

## 7. Rencontre avec fichier 1 et 3

- **Fichier 1** : index des **chemins et alimentations** (preuve, rapports, commits).  
- **Fichier 3** : **ordre de lecture** et **prochaines actions** pour la prochaine session.

---

*Historique reconstruit à partir du fil de conversation et de `git log` — 2026-04-19.*
