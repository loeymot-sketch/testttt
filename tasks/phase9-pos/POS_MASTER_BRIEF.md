# POS MASTER BRIEF — Phase POS-A (Audit Global Lecture Seule)

**Version.** 2026-04-18
**Track.** B (parallèle à Track A = Kiosk Phase 9)
**Mode.** Lecture seule exclusive. Aucune modification de code, aucun commit, aucune migration.
**Livrable.** `reports/review/AUDIT_POS_GLOBAL_<DATE>.md` (50+ findings priorisés P0/P1/P2/P3 avec `fichier:ligne`).

---

## 1. Pourquoi ce brief

Le POS est la **centrale opérationnelle** de FoodKing : il reçoit les commandes kiosk, web, delivery, et crée les commandes "sur place" / "à emporter" / "table". Il pilote la fin de journée (X/Z), le tiroir-caisse, les remboursements, les annulations, les modifications, les permissions staff.

Le kiosk a déjà été audité (`reports/review/AUDIT_KIOSK_GLOBAL_2026-04-18.md`, 50 findings, Phase 9 en cours). **L'audit POS doit atteindre le même niveau de profondeur** avec en plus l'angle **centralisation multi-surfaces** (kiosk + POS + web + delivery).

## 2. Matériel existant à exploiter (ne PAS refaire)

Ces briefs d'audit ciblés sont DÉJÀ écrits, lire avant de scanner :

- `tasks/audits/AUDIT_POS_ORDER_CREATION_001.md`
- `tasks/audits/AUDIT_POS_PAYMENT_CASH_CARD_002.md`
- `tasks/audits/AUDIT_POS_STATUS_TRANSITIONS_003.md`
- `tasks/audits/AUDIT_POS_BRANCH_ISOLATION_004.md`
- `tasks/audits/AUDIT_POS_COUPON_LOYALTY_005.md`
- `tasks/audits/AUDIT_POS_WIZARD_CART_006.md`
- `tasks/audits/AUDIT_POS_IDEMPOTENCY_RETRIES_007.md`
- `tasks/audits/AUDIT_POS_AUTH_SESSION_REFRESH_008.md`
- `tasks/audits/AUDIT_POS_RECEIPT_INSTRUCTIONS_009.md`
- `tasks/audits/AUDIT_POS_AMEND_ORDER_GAP_010.md`

Rapports POS antérieurs à recouper :

- `reports/execution/2026-03-10-pos-master-plan-execution.md` (et wave-v/w/x/y).
- `reports/execution/2026-03-25-pos-priority-qa-run.md`
- `reports/execution/RAPPORT_TEST_FLUX_PAIEMENT_POS.md`
- `reports/execution/POS-CART-DISPLAY-ORDER-AUDIT-2026-03-10.md`
- `reports/review/AUDIT_INTEGRATION_POS_KIOSK.md`
- `reports/review/AUDIT_FINAL_KIOSK_POS_2026-04-17.md`

Si un finding de ces rapports est encore d'actualité (non corrigé ou régressé) → remontée en P0/P1 dans le nouvel audit avec mention `"resurface from <file>"`.

## 3. Périmètre exhaustif (ne rien oublier)

### 3.1 Parcours prise de commande POS

- Écran accueil POS, search produit (full-text, fuzzy, accents), scan code-barres.
- Catégories + sous-catégories + produits, filtres, allergens (visibles caissier pour conseil client).
- Wizard produit (variations, extras, supplements, garnitures, menu bundle, notes cuisine).
- Panier : édition quantités, modification ligne, suppression, notes globales, tab par table.
- Order type : sur place / à emporter / table X / delivery / kiosk reçu en caisse.
- Split bill : par item, par personne, par montant. Conservation du panier original.
- Discount staff manuel (%, montant fixe), coupon code, loyalty burn (réduction points), happy hour automatique.
- Paiement : cash (rendu monnaie, tiroir auto), carte (TPE bridge), ticket restaurant, split payment multi-tender, acompte.
- Reçu : impression auto, re-print, email/SMS, QR suivi commande.

### 3.2 Centralisation commandes multi-surfaces (CRITIQUE)

C'est le cœur de la demande produit : **le POS doit être la vue centrale unifiée de toute l'activité branche**.

- Drawer "commandes en cours" : toutes les sources (POS, kiosk, web, delivery partners) visibles avec statut temps réel.
- Filtres : par source (kiosk / POS / web / delivery), par statut (PENDING / PAID / ACCEPT / PREPARING / PREPARED / DELIVERED / CANCELED / REJECTED), par table, par staff.
- Détail commande : items, variations, extras, instructions, allergens refusés client, prix ligne par ligne, TVA, total, payé, reste dû.
- Actions sur commande : accepter (ACCEPT), marquer en préparation (PREPARING, manuel ou via KDS), marquer prête (PREPARED), livrer (DELIVERED), annuler (CANCELED), refund partiel / total, amender (ajouter / retirer items post-création).
- Historique : toutes commandes closes, recherche, re-print, duplicate.
- Notifications : nouvelle commande kiosk / web, commande prête (OSS), problème paiement, rupture stock mid-prep.

### 3.3 Inventaire, stock, 86

- Admin toggle item 86 depuis POS (ou uniquement admin central ?).
- Rupture automatique au stock_qty = 0 (si stock tracking activé).
- Libération stock sur annulation (doit être testé).
- Rupture mid-prep : comment le POS notifie le kiosk / web ?

### 3.4 Fin de journée, X/Z, tiroir caisse

- Rapport X (en cours, lecture seule) : CA, nb transactions, par mode de paiement, par staff, par heure.
- Rapport Z (clôture fiscale, irréversible) : génération, signature, archivage, numérotation séquentielle, conformité DGCCRF / NF525 / loi anti-fraude TVA.
- Tiroir caisse : ouverture auto (cash), ouverture manuelle (avec motif loggé), comptage fin de journée, écarts.
- Remise en banque : bordereau, export, montants comptés vs théoriques.

### 3.5 Dashboard POS (tuiles temps réel)

- CA jour en cours, comparaison J-1 / J-7 / J-30.
- Ticket moyen, panier moyen, taux de conversion kiosk.
- Top 10 produits vendus.
- Alertes rupture, alertes hardware (imprimante, TPE, tiroir).
- Uptime borne(s) de la branche.

### 3.6 Taux, TVA, arrondis, multi-devise

- TVA par item (10% resto sur place, 5.5% emporter, 20% alcool), cascade variations/extras.
- Arrondi légal (banker's rounding vs round half up).
- Multi-devise si branches internationales (EUR / GBP / MAD...).
- Application automatique TVA selon order_type (décision métier critique France).

### 3.7 Permissions Spatie

- Rôles : super-admin, admin branche, manager, caissier, runner, kitchen-lead.
- Matrice : qui peut annuler après PAID ? qui peut discount ? qui peut amend ? qui peut re-open Z ? qui peut créer un staff ?
- Audit log : toute action sensible (cancel, refund, discount > seuil, Z ré-ouvert) → journal immuable.

### 3.8 Events broadcastés depuis POS

- `OrderCreated`, `OrderStatusChanged`, `OrderItemAdded`, `OrderCanceled`, `OrderRefunded`, `PaymentRecorded`.
- Tous doivent respecter **EventContract V1** strict (cf. kiosk audit).
- Dispatch après `DB::afterCommit()` uniquement.
- Scopé `private-branch.{id}` avec ability staff.

### 3.9 Tests existants

- PHPUnit Feature POS (cartographier : `tests/Feature/Pos/*`, `tests/Feature/Order/*`).
- Vitest POS Vue (`tests/js/pos/*` ou équivalent).
- Playwright POS E2E (si existants).

## 4. Méthodologie (modèle audit kiosk)

Lance **4 sous-agents parallèles** en lecture seule, chacun sur un pan :

**Sous-agent 1 : Parcours commande POS (wizard → panier → paiement).**
Focus : UX caissier, latence, data-testid, validations FormRequest, SSOT pricing, wizard parité avec kiosk, split bill, discount staff, tab table.

**Sous-agent 2 : Centralisation multi-surfaces (drawer, filtres, actions).**
Focus : le POS voit-il 100 % des sources ? actions POS → events corrects → KDS/OSS/Kiosk synchronisés ? rafraîchissement temps réel Pusher + fallback polling ? branch_id isolation ?

**Sous-agent 3 : Stock, fin de journée, tiroir, permissions, dashboard.**
Focus : Z fiscal conforme, tiroir auditable, rôles Spatie, dashboard tuiles, tests antifraude.

**Sous-agent 4 : Backend POS (services, controllers, events, jobs, migrations).**
Focus : `OrderService`, `FrontendOrderService` partagé, `PricingService`, `OrderStateMachine`, idempotency, outbox `domain_events`, observers, listeners, notifications FCM.

Chaque sous-agent produit un mini-rapport avec au moins **10 findings** (`id`, `title`, `criticity`, `file:line`, `description`, `impact`, `fix_proposal`).

Tu agrèges les 4 rapports + les 10 briefs 001-010 + les rapports antérieurs dans un rapport consolidé unique.

## 5. Structure du rapport final attendu

`reports/review/AUDIT_POS_GLOBAL_<DATE>.md` doit contenir **exactement** ces sections (miroir de l'audit kiosk pour cohérence) :

1. **Périmètre + méthode + verdict global** (3 axes forces / 3 axes faiblesses structurelles).
2. **Bilan parcours commande POS** (wizard step-par-step + cart + paiements, avec tableau).
3. **Centralisation multi-surfaces** (tableau des 4+ sources, synchronisation, gaps).
4. **Stock / 86 / fin de journée / tiroir / dashboard**.
5. **Permissions + audit log + conformité fiscale**.
6. **Events & state machine POS**.
7. **Tests existants & trous de couverture**.
8. **Priorité consolidée (50+ findings triés P0/P1/P2/P3)** — identique au format kiosk.
9. **Matrice de recouvrement avec kiosk** — findings partagés kiosk + POS qui seront traités en une seule passe backend (ex: `ItemRequest` étendu, `AllergenService`, `idempotency_key` scope branche).
10. **Recommandation** : structure des vagues POS-9.1 → POS-9.10 avec dépendances + durée.

## 6. Invariants à vérifier (check-list obligatoire, 1 réponse par ligne)

- [ ] SSOT pricing POS : `posOrderStore` ne lit-il **jamais** le prix du payload ?
- [ ] `branch_id` : tous les endpoints admin POS filtrent-ils par `$user->branch_id` ? Jamais lu du payload ?
- [ ] `OrderStateMachine::apply()` utilisé partout (pas d'écriture directe `status`) ?
- [ ] `DB::afterCommit()` systématique avant dispatch ?
- [ ] `EventContract V1` respecté par tous les events POS ?
- [ ] Idempotency POS (double-submit, retry TPE) protégé par `X-Idempotency-Key` + `Cache::lock` ?
- [ ] Permissions Spatie check avant cancel / refund / discount > seuil ?
- [ ] Audit log immutable pour actions sensibles ?
- [ ] Z fiscal séquentiel, signé, non-supprimable (conformité loi Finance 2018 anti-fraude TVA) ?
- [ ] Tiroir-caisse : toute ouverture loggée (motif, staff, timestamp) ?
- [ ] Allergens visibles pour caissier (conseil client) ?
- [ ] TVA cascade correcte item → variation → extra selon order_type ?
- [ ] Multi-tenders : encaissement partiel puis complément supporté ?
- [ ] Temps réel : Pusher subscribe + fallback polling + reconnection backoff ?
- [ ] Dashboard tuiles : data scopées branche, rafraîchissement cohérent ?

## 7. Règles de conduite

- Aucune modification de code. Aucun fichier écrit en dehors du rapport final + `tasks/phase9-pos/FINDINGS_POS_TRACKER.md` (registre vide à créer, colonnes : id, title, criticity, file:line, status=open, commit_sha=null, verifier_run=null).
- Si tu détectes un bug qui te démange, tu **résistes** et tu le consignes en finding. Cursor #1 (Track A Kiosk) est peut-être en train de modifier la même zone → conflit garanti si tu commits.
- Si l'audit POS révèle qu'un finding kiosk P9 est plus large que prévu (ex : `is_available` à exposer aussi POS), tu le notes en **section 9 (recouvrement)** mais tu **ne modifies pas** le plan kiosk — tu produis juste un signal pour la synchronisation inter-tracks.

## 8. Estimation

- Phase POS-A (audit lecture seule) : 4-6 h de scan avec 4 sous-agents parallèles.
- Durée en parallèle de kiosk P9.1 (6-8 h) → parfaitement alignée, les deux finissent en même temps.

## 9. Gate de fin POS-A

- 4 sous-rapports déposés + rapport consolidé `AUDIT_POS_GLOBAL_<DATE>.md`.
- `tasks/phase9-pos/FINDINGS_POS_TRACKER.md` créé avec toutes les findings en status=open.
- Section 9 (recouvrement) explicite + livrée à Track A pour qu'il puisse en tenir compte dès P9.2.
- Verdict global clair (PASS / WARN / BLOCKED par grand domaine).

## 10. Next step

Sur validation humaine de POS-A → Phase POS-B (plan d'exécution 10 vagues) sera lancée via un prompt séparé. Phase POS-C (exécution stop-the-bleed POS) uniquement après POS-B validée.
