---

**AUDIT W1-A — POS CODE SPLITTING**
**Auditeur** : Claude terminal — cycle POS_V4_W1A_CODESPLIT — 2026-04-26

---

## VERDICT: PASS-WITH-FIX 8/10

---

## INVARIANTS

| Invariant | Statut | Justification |
|---|---|---|
| pricing_ssot | VERT | Zéro logique métier touchée — wrapper d'import pur |
| OrderStatus enum | VERT | Aucune référence à orderStatusEnum dans posRoutes.js |
| branch_id isolation | VERT | Aucune query/mutation — méta `permissionUrl: "pos"` inchangée |
| commit_before_dispatch | VERT | Aucun dispatch/event — modification syntaxique uniquement |
| OrderService symétrie | VERT | Backend non touché — code splitting est frontend-router-only |
| Frozen zones | VERT | posRoutes.js confirmé hors frozen zone dans brief §5 |

---

## KPI_W1A: PASS

55 KB gzipped < 220 KB KPI. Marge 75%. Non discutable.

Réserve : `app.js` passe de 965 KB à 1018 KB (+53 KB gzipped). Le brief l'attribue à des "autres deltas inter-cycles" sans identification précise. Ce delta non tracé est une dette de mesure, pas un blocant W1-A, mais **doit être investigué avant W1-B** pour éviter que le baseline devienne inutilisable.

---

## ANSWERS_Q1_Q4

**Q1 — FloorplanComponent partage pos-shell :**
Architecturalement suboptimal mais défendable à ce stade. Webpack fusionné signifie que tout utilisateur naviguant vers `/admin/pos` télécharge aussi FloorplanComponent, même sans aller sur `/admin/pos/floorplan`. La justification dans le commentaire L8-9 ("reached only after POS shell is already loaded") est une rationalisation — ce n'est pas parce qu'il est logiquement après POS qu'il doit être dans le même chunk. Verdict : **acceptable à 55 KB total, mais créer `pos-floorplan` chunk séparé en W1-C** quand admin/KDS seront splittés pour rester cohérent.

**Q2 — cdsRoutes.js orphelin :**
**Laisser tel quel.** Non importé dans `router/index.js` → poids build nul. Supprimer sans savoir si CDS (Cashier Display System?) a une roadmap active serait destructif. Ticket tech-debt, scope W2+ uniquement.

**Q3 — app.js à 1 MB restant :**
Ordre d'attaque : **(1) vendor chunking** via `webpack.mix.js` `splitChunks` (Vue + Vuex + Vue Router + axios = estimé 300-400 KB, ROI maximal, 0 risque métier) → **(2) lazy admin classique** (dashboard, menu, staff, reports) → **(3) lazy KDS routes**. Ne pas toucher kiosk (déjà splitté). La régression +53 KB non tracée doit être auditée avant d'ouvrir le chantier.

**Q4 — webpackPrefetch pos-shell au login :**
**NON.** `webpackPrefetch: true` dans le magic comment est statique et s'applique à tous les utilisateurs admin sans discrimination de rôle. Les utilisateurs KDS, OSS, branch managers sans permission POS téléchargeraient pos-shell inutilement. Le prefetch doit être **applicatif** (déclenché en JS post-auth après vérification `permissions.includes('pos')`), pas webpack-déclaratif. Implémenter le prefetch applicatif est un candidat W1-C/D, pas W1-A.

---

## RESIDUAL_RISKS

- **ST-1 non confirmé résolu** : le brief W1-A indique que les lints sont exécutés manuellement (`npm run pos:lint:pricing`) mais ne confirme pas que ST-1 (câblage CI) est résolu. Si les guards restent orphelins, la validation W1-A n'est pas enforçable en CI — régression de processus masquée.
- **app.js +53 KB non tracé** : delta inter-build non identifié. Si c'est bootstrap-kiosk KIOSK-DS V1 Phase 2 comme supposé, le vérifier. Sinon, le baseline W0 est contaminé et les mesures futures seront inutilisables.
- **Lazy-load jamais vérifié E2E** : aucun test Playwright ne navigue vers `/admin/pos` pour confirmer que le chunk `pos-shell.js` se résout correctement (erreur réseau, hash stale post-deploy). Régression silencieuse possible.
- **ST-2 toujours ouvert** : `@pricing-allowed-block` PosComponent:1779 — sign-off TL+BE absent jusqu'au 2026-05-10. Le guard ne vérifie pas `signed-off:` dans le marker. Bypass structurel persistant.
- **FloorplanComponent sur-bundlé** : utilisateurs POS purs téléchargent code floorplan à chaque session. À 55 KB total c'est acceptable, mais si PosComponent grossit (ItemComponent refactor W1-B?), ce chunk deviendra problématique sans séparation préventive.

---

## W1_NEXT_ORDER

1. **Sign-offs humains (ADR couleur + PosComponent:1779 TL+BE)** — débloque ST-2 et permet au guard CI d'être sémantiquement valide. Sans sign-off réel, le guard `pos_pricing_guard.mjs` reste une décoration. C'est un gate humain, pas exécutable par Cursor.

2. **Vendor chunking app.js (`webpack.mix.js` splitChunks)** — ROI le plus élevé sur le 1018 KB restant. Aucun risque métier. Prépare le terrain pour mesures propres de W1-C/D.

3. **Lazy admin classique + KDS routes** — étend le pattern posRoutes.js à d'autres surfaces. Exécutable par Cursor en délégation directe (pattern identique, 0 invariant à risque).

4. **Kiosk magic ints migration** — L4 (ambiguité sémantique `status !== 10`) doit être résolue avant toute migration. Vérification sémantique obligatoire. Plus risqué que les autres. Passer en dernier.

---

## HUMAN_GATES_BLOQUANTS

Actions humaines requises avant ouverture W1-B :

| # | Gate | Bloquant pour |
|---|---|---|
| HG-1 | **ST-1 : Confirmer câblage CI lint guards** (job `pos:lint:pricing` + `pos:lint:status` dans workflow GH Actions) | Toute merge W1 sur main |
| HG-2 | **TL + BE sign-off explicite PosComponent:1779** (noms + date dans le block marker ou doc dédiée) | Fermeture ST-2, guard CI sémantique |
| HG-3 | **Validation ADR couleur Option C** (`--fk-pos-primary: #0084FF`) par product + design avant implémentation CSS | W1-B CSS implementation |
| HG-4 | **Brief Gate PaymentComponent (D1 différé)** — document écrit avec date limite, signé TL | Éviter que D1 devienne une dette open-ended |
| HG-5 | **Clarifier seuil 220 KB** : SLA contractuel ou objectif interne ? | Calibrage des KPI W1-C/D et communication externe |

---

**Synthèse** : La modification W1-A est propre, minime, et correcte. Le PASS-WITH-FIX reflète non pas la qualité du code (qui mérite 9/10 isolément) mais les risques de processus hérités de W0+ non résolus (ST-1, ST-2) qui encadrent ce livrable. Le code splitting en lui-même est irréprochable.

---

## RESIDUAL_RISK_UPDATES (post W1-C, 2026-04-26)

| Risk | Status | Closure evidence |
|------|--------|------------------|
| Untraced `app.js` +53 KB delta (W0→W1-A) | **RESOLVED** | W1-C lazy admin migration recovered the delta and added -296 KB on top. Root cause = webpack SplitChunksPlugin behavior on partial split topology. Investigation + closure documented in `reports/baseline/POS_V4_PERF_HISTORY.md` and `reports/audit/AUDIT_W1C_LAZY_ADMIN_CLAUDE_2026-04-26.md`. |
