# AUDIT POS 110 % — Synthèse exécutive (`≤ 300` lignes cible)

**Date :** 2026-04-19  
**Périmètre :** caisse web POS, surfaces admin POS / KDS / OSS, backend fiscal NF525, sync multi-surface, sécurité, données, tests.  
**Méthode :** lecture seule ; `grep` / `Read` ciblés ; agents d’exploration (fiscal, isolation, Vuex, routes) ; **aucun commit code.**  
**Contradiction doc :** `docs/BUSINESS_RULES.md` §Stock vs implémentation de disponibilité branch (P1) — **dette documentaire** (`F-SYNC-001`).

---

## Verdict global

Le socle **fiscal** (chaîne `audit_logs` HMAC, immutabilité modèle, séquence commandes avec `lockForUpdate`, Z signé avec `lockForUpdate` sur clôture, contraintes DB, tests `tests/Feature/Fiscal/*`) est **mûr pour un audit technique interne** et cohérent avec une stratégie NF525 « chaîne + séquence + Z ».  
Les risques **majeurs** restants sont plutôt **produit / dette** : absence de **`order_payments` multi-tender**, **liste POS** sans scope serveur par défaut, et **Z.open** sans `lockForUpdate` sur le calcul de `nextSeq` (atténué par **cache lock** + **UNIQUE**).  
Les durcissements **P5–P10** (validation des montants) **ne remplacent pas** le SSOT prix ; ils **réduisent les entrées aberrantes**.

**NF525 readiness (binaire par intention, pas certification légale) :** **READY_TECHNIQUE** sur chaîne d’audit + Z fermés + garde production secrets **partiellement** testée — **NOT_READY_CERT_ORG** (hors code : procédure commissaire, archive juridique, pentest externe, fiche paramétrage par établissement).

---

## Scores par axe (1 = faible, 5 = fort) *— subjectifs, audit interne*

| Axe | Couverture revue | Score | Commentaire court |
|-----|------------------|-------|---------------------|
| 1 Architecture services | Services fiscaux lus + OrderService points chauds | 4 | OrdService volumineux, dette chemins historiques |
| 2 State Vuex POS | `posOrder.js`, patterns Pos | 3 | Idempotence solide par requête ; scission commandes si double parcours |
| 3 NF525 | Fiscal* lus + agents | 4 | Très bon sur audit ; Z.open à surveiller |
| 4 Paiements multi-tender | Requests + grep | 3 | TR P2 OK ; pas table split |
| 5 Isolation branche | OrderService + KDS + tests existants | 4 | Liste POS = filtre ; admin KDS global volontaire |
| 6 Permissions | routes + controllers fiscaux | 3 | Fiscal via `can()`, pas middleware route |
| 7 State machine | OSM + OrderService grep | 4 | P4 KDS aligné ; OrderService pattern legacy |
| 8 KDS/OSS | services + P4 | 4 | 409 ajouté ; OSS read-only |
| 9 Tiroir / shift | surface (import drawer) | 2 | Peu de preuves chaîne événements dans ce run |
| 10 Refund | P3 + doc | 3 | Partiel backlog ; audit RETURNED présent |
| 11 Sécurité | throttle, apiKey, sanctum | 4 | Surface standard ; pas pentest réel |
| 12 Data | Order restore block | 5 | Blocage explicite documenté NF525 |
| 13 Sync cross-surface | Echo, dispo, BUSINESS_RULES | 3 | Doc stock obsolète |
| 14 Observabilité | channel fiscal | 3 | Bien pour Z ; corrélation bout-en-bout à outiller |
| 15 Performance | N/A profond | 2 | Pas preuve rush hour |
| 16 Tests | ~20+ fichiers Fiscal | 4 | Nombreux ; couverture % non mesurée |
| 17 Régressions | frozen zones | 3 | Process discipline requise |
| 18 i18n | spot checks | 3 | Mix FR/EN |
| 19 Déploiement | config fiscal | 3 | Secrets testés ; parité env à contrôler |

---

## Top 20 findings (priorité décisionnelle)

1. **F-FISC-001** (P1) — Z `open()` : `max(sequence_no)` sans `lockForUpdate` (`ZReportService.php` ~71–73). Atténuation : `Cache::lock` + transaction + unique DB.
2. **F-PAY-001** (P2) — Pas de table `order_payments` : split-payment / historique tenders limités au modèle `orders`.
3. **F-STATE-002** (P1) — Nouvelle idempotence par tentative paiement : double commande si double ouverture parcours (UX).
4. **F-SYNC-001** (P1) — `BUSINESS_RULES.md` nie stock / dispo ; le code a évolué (availability) — **documentation faux**.
5. **F-ISO-001** (P1) — Admin KDS cross-branch : produit OK, risque opérateur.
6. **F-FISC-003** (P0 positif) — Chaîne audit HMAC testée + immutabilité — pilier sain.
7. **F-DATA-001** (P0 positif) — `Order::restore()` désactivé avec justification NF525 (`Order.php` ~84–106).
8. **F-PERM-001** (P2) — Routes fiscal sans `permission:` middleware ; contrôle dans contrôleur.
9. **F-SM-001** (P2) — Assignation directe status dans `OrderService` (legacy) vs `OrderStateMachine::apply`.
10. **F-KDS-001** (P1) — 409 P4 côté service bien testé ; HTTP rare car binding frais.
11. **F-KDS-002** (P2) — Pas de `kds_group_id` en codebase (grep) — spec axe 8 à clarifier.
12. **F-ARCH-001** (P2) — Liste POS : isolation par filtre client, pas scope implicite.
13. **F-TEST-002** (P2) — Tests validation verts ne prouvent pas cohérence prix extrême rush.
14. **F-FISC-004** (P2) — Pas de simulation 10k séquences Z dans repo (exigence « cryptographique 10k » **non** remplie comme test automatisé).
15. **F-SEC-002** (P3) — Gardes secrets prod focalisées fiscal — étendre autres clés.
16. **F-OBS-001** (P2) — Logs `fiscal` sur open Z — bon ; manque corrélation ID unique transverse documentée.
17. **F-REF-001** (P2) — Remboursement partiel structuré absent (backlog P3).
18. **F-DRW-001** (P2) — Lien drawer ↔ Z non établi dans cet audit (hors preuve complète).
19. **F-PERF-001** (P3) — Charge 500 ord/h non instrumentée ici.
20. **F-REG-001** (P2) — Zones gelées OrderService — discipline release requise.

---

## Statistiques audit (chiffrées, honnêtes)

| Métrique | Valeur |
|----------|--------|
| Axes 1–19 couverts (document texte) | 19 |
| Fichiers PHP/JS lus (estimation) | ~45–60 |
| Findings indexés tracker | 27 |
| P0 / P1 / P2 / P3 | 2 / 7 / 15 / 5 (*certains positifs*) |
| Tests reproducteurs **écrits ce run** | **0** (read-only) |
| Commits produits | **0** |

---

## Décisions humaines suggérées

1. Mettre **à jour** `docs/BUSINESS_RULES.md` (stock / disponibilité) — **bloquant cohérence organisationnelle**.
2. Trancher **produit** : faut-il **scope liste POS** par `branch_id` serveur par défaut ?
3. **Risque accepté** ou **hardening** sur **Z.open** (`lockForUpdate` ou séquence dédiée).
4. Feuille de route **split tender** (`order_payments`) si roadmap métier.
5. **Certification NF525** : engager revue **externe** ; ce dépôt fournit **éléments techniques**, pas **acte notarié**.

---

## Rapports détaillés (même dossier)

Voir fichiers `AUDIT_POS_110_*_2026-04-19.md` + `AUDIT_POS_110_FINDINGS_TRACKER.md` + `AUDIT_POS_110_HIDDEN_RISKS_2026-04-19.md` + `AUDIT_POS_110_NF525_READINESS_2026-04-19.md`.

---

## Scénarios limites « mentalement » testés (échantillon)

| Scénario | Résultat attendu code | Risque résiduel |
|----------|------------------------|-----------------|
| Minuit pendant Z ouvert | `closed_at` / `opened_at` Carbon locale vs UTC — **signer en UTC** (vérifier impl `sign()`) | Dérive timezone si mal configuré `.env` |
| Panne milieu paiement | Retry client → **idempotence** même clé = même ordre | Nouvelle clé si UI regénère → **double** (`F-STATE-002`) |
| 2 caissiers même ordre | `changeStatus` OrderService : guards branche | Mutex inter-branch OK ; **intra-branch** course statut : partiellement mitigé KDS P4, pas partout |
| Remboursement après Z | Statut RETURNED + audit ; pas recalcul Z historique sans correction manuelle | Business rule clarifié backlog |

---

*Fin synthèse exécutive — objectif ≤300 lignes respecté.*
