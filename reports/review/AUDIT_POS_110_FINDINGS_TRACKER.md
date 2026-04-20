# AUDIT POS 110 % — Registre consolidé des findings

_Review read-only — 2026-04-19. Méthode : grep, lecture ciblée, agents d’exploration, contradictions avec `docs/BUSINESS_RULES.md`._  
_Chaque ID : titre, axe (1–19), criticité, fichier(s), preuve courte, remédiation indicative._

| ID | Axe | Sev | Titre | Fichier:ligne (indicatif) | Preuve / note | Remédiation |
|----|-----|-----|-------|---------------------------|---------------|-------------|
| F-ARCH-001 | 1 | P2 | Liste POS : `branch_id` est filtre requête, pas scope auth implicite | `OrderService.php` ~59–62 | Admin peut lister toutes branches si UI envoie pas de filtre — aligné contrôleur `abort_unless` sur index pour user non-admin | Documenter + option scope serveur par défaut |
| F-ARCH-002 | 1 | P2 | `PricingService` / `PaymentService` : point d’entrée fragmenté (plusieurs chemins historiques) | Services | Screenshot mental : P1 a ajouté garde dispo — dette “SSOT” reste dispersée | Roadmap consolidation |
| F-STATE-001 | 2 | P2 | Compteur token panier POS en `localStorage` non atomique | `PosComponent.vue` (pattern noté en exploration) | Course inter-onglets possible | Verrouillage ou UUID session |
| F-STATE-002 | 2 | P1 | Deux `orderSubmit` rapides → nouvelles clés idempotence → **deux commandes** | `posOrder.js` + `PosComponent.vue` | Idempotence par tentative, pas par session panier | Réutiliser clé jusqu’à succès / annulation |
| F-FISC-001 | 3 | P1 | **Z `open()`** : `MAX(sequence_no)` sans `lockForUpdate` (concurrent avec autre writer théorique) | `ZReportService.php` 71–73 | Cache lock + transaction + UNIQUE DB atténuent ; pas de preuve SQL line-lock sur agrégat | `SELECT … FOR UPDATE` sur dernier row ou séquence dédiée |
| F-FISC-002 | 3 | P2 | Pas de table `fiscal_sequences` dédiée — séquence sur `orders.fiscal_sequence_no` | `FiscalSequenceService.php` | Nom usuel NF525 “table séquence” — OK si spec accepte | Doc + mapping contrôle |
| F-FISC-003 | 3 | P0 | Chaîne `audit_logs` : **HMAC + immutabilité Eloquent** démontrables | `AuditLogService.php`, `AuditLog.php`, tests `AuditLogHashChainTest` etc. | Conforme intentionnellement | Maintenir triggers/tests |
| F-FISC-004 | 3 | P2 | Pas de benchmark 10k lignes **dans le repo** pour monotonie Z — contrainte utilisateur “cryptographiquement” sur 10k+ | Tests | **Non satisfait littéralement** — reposer sur tests d’intégration + contraintes DB | Job perf / test stress hors CI |
| F-PAY-001 | 4 | P2 | Split-payment / gift / pourboire : pas de `order_payments` — modèle **monoligne** `orders` | grep `order_payments` vide | Spec POS-9.3 “multi-ligne paiement” **non présente** en schéma | Migration future ou clôture scope |
| F-PAY-002 | 4 | P2 | TR : P2 ajoute champ note ; pas audit séparé ligne tender | `PosOrderRequest`, services | Traçabilité dans payload order | Renforcer audit champ |
| F-ISO-001 | 5 | P1 | **KDS `list()`** : admin `branch_id=0` voit toutes branches — risque opérationnel, pas fuite API si auth OK | `KitchenDisplaySystemOrderService.php` 54–57 | Comportement voulu pour admin | Policy produit |
| F-ISO-002 | 5 | P2 | Events Pusher `private-branch.{id}` — cohérent ; vérif listener channel obligatoire | `OrderStatusChanged` etc. | Audit statique suffisant | Revue front subscribe |
| F-PERM-001 | 6 | P2 | Fiscal : **pas** de `middleware('permission:')` sur routes ; `can('pos-manage-fiscal')` dans contrôleur | `routes/api.php` 794–805, agents | Double mécanisme (route vs `can`) | Uniformiser middleware |
| F-PERM-002 | 6 | P3 | Matrice route×rôle **non générée automatiquement** — audit manuel partiel cette revue | — | Exigence “100% matrice” = livrable outillage | Parser routes + tests |
| F-SM-001 | 7 | P2 | `OrderService` assigne encore `$order->status =` après garde (pattern historique) | `OrderService.php` 1402, 1464, 1519 | Contraste avec `OrderStateMachine::apply()` “nouveau” | Migrer call sites progressif |
| F-SM-002 | 7 | P0 | `OrderStateMachine::allows` utilisé validations + KDS P4 | Multiple | Cohérence **renforcée** P4 KDS | — |
| F-KDS-001 | 8 | P1 | P4 : 409 si dérive — **HTTP ne simule pas** stale model (binding frais) | `KitchenDisplaySystemOrderService` | Service testé ; route toujours fresh | OK + doc |
| F-KDS-002 | 8 | P2 | Pas de `kds_group_id` trouvé en grep rapide — soit absent soit autre nom | grep | Trouver renommé ou gap | Vérifier spec OSS/KDS |
| F-DRW-001 | 9 | P2 | Tiroir-caisses : `openDrawer` kioskHardware — pas de chaîne hash **drawer events** vue dans ce grep | `PaymentComponent.vue` import | Lien ZReport non tracé ici | Audit fichier `kioskHardware.js` + audit_logs actions |
| F-REF-001 | 10 | P2 | Remboursement partiel structuré : **absent** (cf. handoff P3) | Docs P3 | Attendu backlog | — |
| F-SEC-001 | 11 | P2 | API admin : `apiKey` + Sanctum — CSRF scope API JSON typique | — | Standard SPA | Vérifier cookies si mode mixte |
| F-SEC-002 | 11 | P3 | `assertProductionSafe` / garde secrets : tests `FiscalSecretProductionGuardTest` | `tests/Feature/Fiscal/FiscalSecretProductionGuardTest.php` | Partiellement couvert | Étendre autres secrets |
| F-DATA-001 | 12 | P1 | `Order::restore()` **bloqué** explicitement (intégrité agrégat / NF525) | `Order.php` 84–106 | **Favorable audit** | — |
| F-DATA-002 | 12 | P2 | SoftDeletes Order + hard delete enfants — cohérent avec message runtime | `Order.php` | Documenté en commentaire | — |
| F-SYNC-001 | 13 | P1 | `docs/BUSINESS_RULES.md` L57–59 dit “pas de stock” — **contradiction** avec P1 disponibilité | `docs/BUSINESS_RULES.md` vs P1 code | **Dette doc** | Mettre à jour BUSINESS_RULES |
| F-OBS-001 | 14 | P2 | Canal `Log::channel('fiscal')` utilisé Z open | `ZReportService.php` 84–89 | Bonne pratique | Centraliser corrélations |
| F-PERF-001 | 15 | P3 | Pas de test charge 500 ord/h **dans** dépôt (CI) | — | Non démontré | k6 / prod observabilité |
| F-TEST-001 | 16 | P1 | Tests fiscaux nombreux mais **couverture %** non mesurée ce run | `tests/Feature/Fiscal/*` | `phpunit` vert ≠ toutes branches métier | `pcov` / CI |
| F-TEST-002 | 16 | P2 | Tests P5–P10 validation : utiles mais **masquent** pas bugs logique pricing | Requests | Risque “formulaire vert, prix faux” — mitigé SSOT | Tests intégration prix |
| F-REG-001 | 17 | P2 | Zones gelées `OrderService` / `FrontendOrderService` — tout changement risque régression | AGENTS / plans | Process gate humain | Respect frozen zones |
| F-I18N-001 | 18 | P3 | Messages erreur validation mélangés FR/EN selon couche | Divers | Cosmétique produit | Pass i18n unifiée |
| F-DEP-001 | 19 | P2 | Multi-branche fiscal : `branch_id` sur Z et séquences — cohérent ; secrets `.env` par env | Config `fiscal.*` | Vérifier staging=prod parité algos | Checklist déploiement |

**Légende criticité :** P0 = conformité / sécurité bloquante ou hypothèse falsifiée ; P1 = risque élevé ou dette majeure ; P2 = risque moyen / dette ; P3 = cosmétique ou confort.
