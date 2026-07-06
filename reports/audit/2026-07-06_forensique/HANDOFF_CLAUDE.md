# HANDOFF — Passation à la session Claude de correction

> **Pour la prochaine session Claude / l'équipe de développement.**
> Ce dossier (`reports/audit/2026-07-06_forensique/`) contient l'audit forensique complet du monorepo FoodKing (POS, borne kiosk, KDS, OSS, commande en ligne) et le **paquet de correctifs P0 prêt à appliquer**. Branche de travail : `claude/voice-feature-bzigph`.

## 1. Ce qui a été fait
Audit multi-agents adversarial **100 % statique** (dépôt cloné sans `vendor/` ni `node_modules/` → aucun build, aucun test exécuté). Chaque finding est prouvé par `fichier:ligne` ; les plus graves ont été relus à la source ; les correctifs P0 ont été **vérifiés adversarialement** (exactitude/applicabilité + invariant sécurité + régression + juge).

**Résultat global : verdict `block`, score ≈ 3,5/10.** 7 invariants sur 8 violés, 31 findings critiques, 76 élevés, 5 chaînes d'attaque faisables sur 6. Le produit n'est **pas déployable en l'état** mais **réparable** (les bons patterns existent, ils sont *opt-in* au lieu d'imposés).

## 2. Par où commencer (toi, session de correction)
1. Lis **`00_RESUME_EXECUTIF.md`** (5 min) puis **`03_INVARIANTS_FULLSTACK.md`** (la cause de tout).
2. Ouvre **`11_PAQUET_P0_CORRECTIFS.md`** — c'est ton plan d'action : 8 correctifs ancrés (`AVANT`/`APRÈS`), tests, rollback, **ordre d'application**, et 2 correctifs **rejetés à NE PAS appliquer**.
3. **Applique dans un environnement de build** (`composer install`, `npm install`) : tu dois pouvoir lancer PHPUnit/Vitest et builder. L'audit n'a pas pu le faire — toi tu le dois avant tout merge.

## 3. Règles d'or pour corriger (ne pas casser en réparant)
- **Respecter l'ordre d'application du §1 de `11_PAQUET_P0_CORRECTIFS.md`.** En particulier : `sec-branchscope` (isolation) **AVANT** toute relâche de cache, sinon fuite cross-branche via CDN.
- **Ne jamais** appliquer `sec-admin-guard` tel quel : le préfixe `/api/admin` **n'est pas** admin-only (il contient POS, table-order, KDS, OSS, dashboard) → un `role:admin` global renverrait 403 en prod. À redesigner par sous-groupe.
- **Ne jamais** appliquer `perf-htaccess` tel quel : sans fingerprinting (`mix.version()` absent), un `Cache-Control immutable 1 an` figerait les bundles → clients bloqués sur l'ancienne version après déploiement.
- **Invariants intouchables** : backend = seule source de vérité du pricing ; isolation par branche (aucun cache partagé entre branches) ; transitions de statut contrôlées ; intégrité fiscale ; atomicité outbox (`afterCommit` dans la transaction).
- **Piège perf-persist** : le throttle de `vuex-persistedstate` doit **flusher synchronement AVANT `axios.post`** de la commande, sinon un crash <400 ms perd l'`idempotencyKey` → **double commande**.
- Chaque correctif appliqué doit être **prouvé par le test fourni** (ex. Stripe : total 12,99 € → 1299 centimes).

## 4. Actions ops (hors code, à faire par un humain)
- 🔥 **Roter/révoquer la clé de service GCP** `foodking-inilabs` (elle est dans l'historique git ET a été servie publiquement — considérée brûlée). Puis `git rm --cached public/file/service-account-file.json`, la déplacer hors docroot (`storage/app/private/`), et purger l'historique (BFG/git-filter-repo).
- Roter les identifiants committés : borne `kiosk123`, admin seed `123456`, `payload_*.json`.
- Configurer `QUEUE_CONNECTION=redis` + un worker en prod (le défaut committé `sync` bloque l'API au submit).

## 5. Inventaire des rapports
| Fichier | Contenu |
|---|---|
| `00_RESUME_EXECUTIF.md` | Verdict, Top 6, 5 causes racines, gouvernance |
| `01_VERSION_MAP_ET_DETTE.md` | Laravel 9 / PHP 8.1 EOL, Stripe SDK, build legacy |
| `02_STRUCTURE_ET_HYGIENE.md` | Structure du dépôt + arborescence cible + rangement |
| `03_INVARIANTS_FULLSTACK.md` | Les 8 traçages full-stack (7/8 violés) — **à lire** |
| `04_REGISTRE_FINDINGS.md` | 31 critiques + 76 élevés, ancrés `fichier:ligne` |
| `05_SECURITE_RED_TEAM.md` | 6 chaînes d'attaque + inventaire des secrets |
| `06_SCORECARD_ET_CARTE.md` | Scores/verdicts des 13 systèmes + dépendances |
| `07_FEUILLE_DE_ROUTE.md` | Remédiation P0→P3 séquencée |
| `08_DEEP_DIVE_TECHNIQUE_SECURITE.md` | Code réel + PoC + patchs sécurité (diffs) |
| `09_PERFORMANCE_POS_BORNE.md` | Causes de lenteur POS/borne + plan 5-10× + garde-fous |
| `10_AUDIT_CAISSE_KIOSK.md` | Audit fonctionnel dédié caisse + borne (32 findings confirmés, verdict caisse HEAL→BLOCK fiscal / borne BLOCK) |
| **`11_PAQUET_P0_CORRECTIFS.md`** | **Correctifs P0 prêts à appliquer (ton plan d'action)** |
| `dashboard.html` | Tableau de bord visuel de l'audit |
| `README.md` | Index de navigation |

## 6. État git
- Branche : `claude/voice-feature-bzigph` (poussée sur `origin`).
- Tous les rapports sont committés. **Aucun code produit n'a été modifié** par l'audit — le paquet P0 est un plan, pas des commits de code.
- Quand tu appliques les correctifs : commits atomiques, un correctif = un commit avec son test, dans l'ordre du §1 de `11_PAQUET_P0_CORRECTIFS.md`.

---
*Audit généré par orchestration multi-agents adversariale (reconnaissance → découverte multi-lentilles → traçage d'invariants → red team → vérification adversariale → synthèse). Handoff destiné à une session de correction outillée (build + tests disponibles).*
