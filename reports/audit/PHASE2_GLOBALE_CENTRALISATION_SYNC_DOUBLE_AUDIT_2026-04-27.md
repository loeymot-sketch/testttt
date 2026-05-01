# Double Audit — Phase 2 Globale Centralisation Sync

Rapport audité : `reports/audit/PHASE2_GLOBALE_CENTRALISATION_SYNC_ULTRA_PLAN_2026-04-27.md`  
Mode : auto-critique contradictoire avant handoff Claude  
Verdict : `DOUBLE_AUDIT_VERDICT: PASS_WITH_GATES`

## 1. Vérification du Mandat

| Exigence du prompt Claude | Résultat |
| --- | --- |
| Graphiti avant analyse ou secours mémoire | PASS : Graphiti `foodking` consulté, `memory/INDEX.md` lu. |
| Aucun patch produit | PASS : seuls rapports d'audit prévus. |
| Matrice ≥12 lignes | PASS : 18 lignes. |
| Audit duplicate/legacy sans suppression | PASS : archive proposée avec manifeste, aucun `rm`. |
| Ultra plan avec phases/gates | PASS : Phases 0 à 7. |
| Tâches Codex avec allowlists | PASS : 10 tâches avec allowlists/interdictions. |
| Double audit sur intersections P0 | PASS : section contradictions + risques ci-dessous. |
| Verdict final `PHASE2_STRATEGIE = ...` | PASS. |

## 2. Dispute Interne sur les P0

### Sujet A — Le catalogue dual-path est-il vraiment P0 ?

Position forte initiale : P0, car Dashboard centralisé peut écrire prix/structure que POS/Kiosk lisent différemment.

Contre-argument : tant que Dashboard Phase 2 n'est pas livré en write global, le système V1 peut fonctionner avec chemins séparés si les sentinels POS/Kiosk restent verts.

Décision finale : ce n'est pas un P0 release V1 isolé, mais c'est un P0 précondition Phase 2. Le rapport le formule ainsi : `P0 avant Phase 2`, pas `bug runtime immédiat`.

### Sujet B — `MenuSnapshot` category non bumpé : bug ou dette ?

Preuve : `CategoryCreated/Updated/Deleted` déclenchent `InvalidateKioskMenuCacheOnCatalogChange`, pas `BumpMenuSnapshotOnItemAvailabilityChanged`.

Contre-argument : si les consommateurs actuels ne pollent pas `MenuSnapshot`, le cache flush peut suffire.

Décision finale : dette P0 pour centralisation/polling Phase 2, pas preuve de panne borne actuelle. La mission proposée commence par tests de couverture avant patch.

### Sujet C — Variation/extra/addon sync : peut-on l'affirmer sans lire tous les tests ?

Preuve inspectée : les services CRUD dédiés ne montrent pas d'event/snapshot/cache dans la recherche ciblée ; ils touchent composition/prix.

Contre-argument : `ItemService::update` peut créer/update des extras et émettre un event global, donc certains chemins sont couverts.

Décision finale : finding maintenu mais formulé comme "services dédiés sans preuve d'event clair". La mission PH2-P0-05 doit d'abord cartographier routes exactes avant patch.

### Sujet D — Catégories branch-scopées : faut-il imposer une migration ?

Position forte initiale : ajouter un pivot branch/category.

Contre-argument : produit peut choisir catégories globales, disponibilité branchée au niveau item seulement. Une migration serait une décision métier.

Décision finale : gate ADR, pas code. Le plan ne prescrit pas de migration.

### Sujet E — Docs outbox drift : P0 ?

Preuve : code `EventContract.php` plus strict que docs.

Contre-argument : tests code sont verts ; docs ne cassent pas runtime.

Décision finale : P1, mais à faire tôt parce que les prompts Claude/Codex consomment les docs.

### Sujet F — Legacy archive : peut-on déplacer maintenant ?

Position forte initiale : archives déjà bannered donc déplaçables.

Contre-argument : chemins avec espaces et anciens bundles peuvent rester référencés dans scripts/lints/docs. Déplacer sans gate peut casser tooling.

Décision finale : aucun déplacement dans ce cycle. Seulement proposition `archive/phase2-dedup-YYYY-MM-DD/` + manifest.

### Sujet G — D-M13 : Codex peut-il décider ?

Position forte initiale : l'utilisateur a demandé "décide smartly responsible".

Contre-argument : AGENTS et prompt Claude interdisent gate migration sans gate humain. La demande courante dit audit/plan only.

Décision finale : D-M13 reste bloqué humain. Le plan prépare la mission future mais n'attaque pas la migration.

## 3. Risques Restants dans le Plan

| Risque | Gravité | Mitigation prévue |
| --- | --- | --- |
| Le rapport peut sous-estimer un chemin POS menu non lu en profondeur | P1 | PH2-P0-03 impose exploration POS exacte avant migration. |
| Les tests existants peuvent déjà couvrir certains points catalog sync | P2 | Missions demandent reproduction et citation avant patch. |
| Dirty worktree peut masquer des changements non persistés | P0 process | Phase 0 impose bucket/gouvernance avant exécution large. |
| Dashboard centralisation peut nécessiter UX/permissions non couvertes ici | P1 | PH2-P0-07 AUTHZ et phase dashboard incrémentale. |
| Event catalogue dédié peut devenir trop générique | P1 | Gate event contract si nouveau type public ajouté. |

## 4. Vérification Invariants FoodKing

| Invariant | État |
| --- | --- |
| Backend pricing SSOT | Respecté : aucune tâche ne déplace le calcul prix côté frontend. |
| `OrderStatus` enum | Respecté : Dashboard actions doivent passer par services/state machine. |
| `branch_id` isolation | Respecté : branch channels, KDS, OSS et queue sont traités comme P0. |
| Dispatch after commit | Respecté : outbox/DomainEvent restent la voie recommandée. |
| OrderService / FrontendOrderService symmetry | Respecté : D-M13 et queue sont explicitement symétriques. |
| Frozen zones | Respecté : aucune modification payment/push/analytics/delivery demandée. |

## 5. Verdict

Le plan est exécutable, mais uniquement comme chaîne séquentielle avec gates. Il ne doit pas être transformé en refonte massive simultanée.

`DOUBLE_AUDIT_VERDICT: PASS_WITH_GATES`

`NEXT_ACTION: faire auditer ce rapport par Claude, puis lancer PH2-P0-01 et PH2-P0-03 avant tout code runtime.`
