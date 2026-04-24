# Master Review POS + KDS — Finitions avant lancement production
**Auteur** : Claude terminal (Anthropic CLI, claude-sonnet-4-6)
**Date** : 2026-04-26
**Brief source** : `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_BRIEF_2026-04-26.md`
**Sortie** : ce fichier

---

## §3.1 — Verdict global de readiness POS+KDS

**NOT-READY 4/10**

Deux portes P0 BLOCKER restent ouvertes sans approbation humaine : `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` (8 cycles P0 non signés couvrant OrderService, PaymentService, DiscountCalculator, idempotency, coupons, pricing) et `GATE_PAYMENT_PROP_MUTATION_2026-04-26` (16+ sites de mutation directe de props dans PaymentComponent). La surface POS de paiement est fonctionnellement cassée pour les cas 100 % discount faute de v-model sur `discountReason`. La couverture tests POS Feature est réduite à 3 fichiers pour une surface qui couvre caisse, TPE, NFC, parked orders, void et remboursement ; la couverture KDS Feature se limite à 2 fichiers sans aucun test de transition d'état. L'i18n Bengali KDS est vide (27 clés manquantes). Le KDS est inutilisable en RTL arabe (Swiper `dir="ltr"` câblé en dur). La table `sync_metrics` grossit sans borne ni purge. Ces défauts cumulés rendent la surface POS+KDS non prête pour un lancement multi-branches opérateur réel. Les points solides existants (B1 reconnect storm, B4 single-flight checkout, G2 composition_snapshot immutable, A2 enums corrects, A4 dispatch post-commit) constituent une base saine mais insuffisante à ce stade.

---

## §3.2 — Liste des finitions par priorité

---

### FIND-01 — P0 BLOCKER
```
Surface       : POS-FE
Description   : Le champ discountReason est déclaré en data() et lu dans applyDiscount()
                mais ne possède aucun v-model dans le template. La saisie opérateur n'est
                jamais liée à la donnée Vue, rendant le motif de remise (obligatoire ≥ 3
                caractères à la ligne 1668-1671) toujours vide → validation bloquante
                systématique sur tout discount ≥ 1 %.
Fichier(s)    : resources/js/components/admin/pos/PosComponent.vue:781 (déclaration),
                resources/js/components/admin/pos/PosComponent.vue:1668 (lecture)
Invariant     : pricing-ssot
Évidence      : Grep exhaustif sur PosComponent.vue — aucun `v-model="discountReason"` ni
                `v-model:discountReason` dans le template ; data property définie à la ligne
                781 ; applyDiscount() lit `this.discountReason` ligne 1668 avec guard
                `.trim().length < 3` → la condition est toujours vraie en l'état, bloquant
                l'application de tout discount.
Effort        : XS (<1h) — ajout d'un v-model sur l'input existant
Risque blocage: 100 % des flux POS nécessitant une remise (happy path + coupon + offert)
                sont bloqués en production opérateur.
Fix proposé   : Ajouter `v-model="discountReason"` sur l'élément <input> ou <textarea>
                correspondant dans la section discount du template PosComponent.vue ; vérifier
                que l'élément input existe (sinon l'ajouter) ; ajouter un test unitaire
                Vitest vérifiant la liaison.
Dépendances   : aucun gate humain requis (modification template non-frozen).
```

---

### FIND-02 — P0 BLOCKER
```
Surface       : POS-BE / KDS-BE
Description   : GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20 reste en statut
                PENDING_HUMAN_GATE depuis le 2026-04-20. Il couvre 8 cycles P0 critiques
                (OrderService, PaymentService, routes/api.php, DiscountCalculator, migrations
                idempotency/coupons/pricing) qui n'ont reçu aucune approbation humaine.
                Aucune reprise de boucle sur ces zones frozen n'est autorisée sans décision.
Fichier(s)    : docs/gates/GATE_LOG.md:31 (entrée PENDING_HUMAN_GATE),
                docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md (brief)
Invariant     : frozen
Évidence      : GATE_LOG.md ligne 31 — champ Decision = `PENDING_HUMAN_GATE`, champ
                Approver = `(non documenté — en attente humain sur le brief)`. Le brief
                liste explicitement 8 cycles P0 (§1-2). Date d'ouverture : 2026-04-20.
                Durée sans décision au moment de la review : 6 jours.
Effort        : N/A — décision humaine requise, pas d'implémentation
Risque blocage: Toute exécution de cycle touchant OrderService, PaymentService, pricing ou
                idempotency sans ce gate approuvé viole la politique frozen et invalide la
                traçabilité d'audit. Bloque également FIND-07.
Fix proposé   : Planifier session de revue humaine (TL + Backend owner + QA NF525) sur le
                brief GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md ; consigner la
                décision dans GATE_LOG.md avant toute reprise.
Dépendances   : Gate humain strict — ABSOLUTE_PROHIBITION (GATE_LOG.md:82-85).
```

---

### FIND-03 — P0 BLOCKER
```
Surface       : POS-FE
Description   : GATE_PAYMENT_PROP_MUTATION_2026-04-26 reste en statut PENDING_HUMAN_GATE.
                PaymentComponent.vue contient 16+ sites de mutation directe de props via
                this.$props.props.form.X = ... dans confirmOrder() (pos_payment_note,
                pos_payment_method, pos_received_amount, branch_id, items, total, discount,
                et autres). Cette mutation directe viole le contrat Vue one-way data flow
                et provoque des comportements non déterministes sur les cycles annuler/réessayer.
Fichier(s)    : resources/js/components/admin/pos/PaymentComponent.vue:179,192-193,205,
                208-217,221,226,237-240,245-265 (sites de mutation constatés),
                docs/gates/GATE_LOG.md:39 (PENDING_HUMAN_GATE)
Invariant     : frozen
Évidence      : Inspection directe de PaymentComponent.vue — confirmOrder() mute des props
                à 16+ reprises. Gate ouvert le 2026-04-26, statut PENDING_HUMAN_GATE,
                approvers requis : TL + Backend + QA NF525 + UX. Note : le brief GATE cite
                7 sites ; la re-inspection en trouve 16+ (périmètre plus large que l'estimation
                initiale). L'original de 7 était une sous-estimation.
Effort        : M (1j) pour refactor + tests une fois le gate approuvé
Risque blocage: Mutations directes de props dans un composant de paiement → états fantômes
                entre tentatives de paiement, potentiel de double-soumission ou de total
                erroné affiché à la caisse.
Fix proposé   : Option A (recommandée dans le gate brief) : refactorer vers emit() + parent
                state management ; Option B : copie locale des props en data(). Décision
                architecture à valider dans le gate. Cycles dépendants = POS_V4_W2_PAYMENT_REFACTOR.
Dépendances   : GATE_PAYMENT_PROP_MUTATION_2026-04-26 (gate humain). Indépendant de FIND-02.
```

---

### FIND-04 — P1 QUALITY
```
Surface       : POS-FE
Description   : formatKioskPrice() (helpers/kioskFormatPrice.js) code en dur les valeurs
                par défaut locale='fr-FR' et currency='EUR'. Si l'appelant ne transmet pas
                ces options, tous les prix kiosk s'affichent en euros/format français
                indépendamment de la configuration de la branche.
Fichier(s)    : resources/js/helpers/kioskFormatPrice.js:31-32
Invariant     : pricing-ssot
Évidence      : Lecture directe lignes 31-32 : `locale = options.locale || 'fr-FR'` et
                `currency = options.currency || 'EUR'`. Un commentaire ligne 6 affirme
                "not hardcoded fr-FR / EUR" mais le code contredit explicitement ce
                commentaire avec les fallbacks OR.
Effort        : S (1-4h) — lire currency/locale depuis le store Vuex branche et passer
                les options à tous les sites d'appel.
Risque blocage: Toute branche configurée en MAD/DZD/TND ou en locale ar-MA/ar-DZ affiche
                des prix EUR incohérents au kiosk. Bloque l'internationalisation opérateur.
Fix proposé   : Supprimer les valeurs par défaut codées en dur ; injecter currency et locale
                depuis `store.getters['branch/currency']` et `store.getters['branch/locale']`
                à chaque site d'appel ; documenter que l'appel sans options est une erreur
                de programmation.
Dépendances   : aucun gate humain (modification helpers non-frozen).
```

---

### FIND-05 — P1 QUALITY
```
Surface       : KDS-FE
Description   : resources/js/languages/bn.json contient 0 clés kds_*. Les fichiers
                en.json, fr.json et ar.json comportent chacun 27 clés kds_*. L'ensemble
                de l'interface KDS s'affiche en anglais (fallback) pour les utilisateurs
                Bengali, y compris les alertes opérationnelles critiques (kds_order_cap_warning,
                kds_order_list_full_warning).
Fichier(s)    : resources/js/languages/bn.json (0 clé kds_*),
                resources/js/languages/en.json:27 clés kds_* (référence),
                resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:12,19,26,33
                (usages $t("label.kds_*"))
Invariant     : a11y
Évidence      : `grep -c "kds_" resources/js/languages/bn.json` → 0 ;
                `grep -c "kds_" resources/js/languages/en.json` → 27 (vérifié en session).
Effort        : S (1-4h) — traduction des 27 clés kds_* en Bengali avec validation native.
Risque blocage: KDS non localisé Bengali en production multi-branches → opérateurs cuisine
                Bengali face à des libellés anglais pour les alertes de capacité et actions
                critiques.
Fix proposé   : Copier les 27 clés kds_* de en.json vers bn.json ; fournir les traductions
                Bengali (requiert un locuteur natif ou validation externe) ; ajouter un test
                de couverture i18n vérifiant que toutes les clés kds_* existent dans les 5
                fichiers de langue.
Dépendances   : aucun gate humain.
```

---

### FIND-06 — P1 QUALITY
```
Surface       : POS-FE
Description   : focustrap est importé dans PosComponent.vue (import Bootstrap
                focustrap) mais n'est jamais instancié ni activé. Les modals POS
                (NfcCustomer, PaymentChange, CashDrawer) ne piègent pas le focus clavier.
                La navigation Tab sort des modals vers le contenu de fond.
Fichier(s)    : resources/js/components/admin/pos/PosComponent.vue:732 (import),
                resources/js/components/admin/pos/PaymentComponent.vue (aucun import),
                resources/js/components/admin/pos/ReceiptComponent.vue (aucun import)
Invariant     : a11y
Évidence      : Ligne 732 de PosComponent.vue : `import focustrap from
                "bootstrap/js/src/util/focustrap"`. Grep exhaustif sur tous les .vue POS :
                aucun appel à `createFocusTrap()`, `.activate()`, ni usage de la variable
                importée. Import mort.
Effort        : S (1-4h) — instancier focustrap sur mounted/show de chaque modal critique
                et le désactiver sur hide/unmount.
Risque blocage: Non-conformité a11y WCAG 2.1 §2.1.2 (No Keyboard Trap inversé — le focus
                s'échappe). Bloque l'accessibilité en production et les tests a11y
                automatisés si planifiés.
Fix proposé   : Dans chaque modal POS (PaymentComponent, CashDrawer, NfcCustomer,
                ReceiptComponent), instancier focustrap sur l'élément racine du modal au
                moment de son ouverture (`@show` ou `mounted`) et appeler `.deactivate()`
                à la fermeture. Supprimer l'import mort de PosComponent.vue ou le déplacer
                là où il est utilisé.
Dépendances   : aucun gate humain. Lot 2.I a11y déjà entamé — continuer dans le même cycle.
```

---

### FIND-07 — P1 QUALITY [PARTIELLEMENT VÉRIFIÉ]
```
Surface       : POS-BE
Description   : FrontendOrderService.php (871 lignes) et OrderService.php (1976 lignes)
                partagent les mêmes dépendances de pricing (CouponService, PricingService,
                DiscountCalculator) mais présentent une asymétrie de taille significative.
                La vérification complète de la symétrie des chemins de calcul de prix
                post-cycles P0 n'a pas pu être effectuée entièrement sans lever FIND-02.
Fichier(s)    : app/Services/OrderService.php:296-298 (unset client totals),
                app/Services/OrderService.php:328-444 (recalcul SSOT),
                app/Services/FrontendOrderService.php:48-50 (imports partagés)
Invariant     : symmetry / pricing-ssot
Évidence      : OrderService.php confirme le strip des totaux client (lignes 296-298) et
                le recalcul serveur (lignes 328-444). FrontendOrderService.php est présent
                et importe les mêmes services de pricing. La revue de symétrie complète
                (chemins coupon, paths discount, cas remboursement) requiert que FIND-02
                soit levé (gate humain sur les zones frozen). Marqué [PARTIELLEMENT VÉRIFIÉ]
                en accord avec §4 du brief.
Effort        : M (1j) — revue comparative systématique une fois FIND-02 levé.
Risque blocage: Si les deux services divergent sur un chemin de pricing, le frontend kiosk
                et le POS peuvent produire des totaux différents pour la même commande —
                risque fiscal et litige client.
Fix proposé   : Après approbation FIND-02, conduire une revue ligne à ligne des méthodes
                de calcul de prix dans les deux services ; extraire les divergences dans un
                rapport dédié ; aligner sur le même PricingService si divergence détectée.
Dépendances   : FIND-02 (gate humain GATE_VERIFY_P0_FROZEN_CONSOLIDATED doit être approuvé).
```

---

### FIND-08 — P1 QUALITY
```
Surface       : TEST
Description   : Le répertoire tests/Feature/Pos/ ne contient que 3 fichiers de test
                (DiningTableReleaseAfterPosOrderTest, FloorplanControllerTest,
                PosPurgeParkedScheduleTest). Les chemins critiques suivants n'ont aucun
                test Feature : void d'une commande, comptage caisse (cash drawer),
                lookup client NFC, reprise de commande parkée (resume parked).
Fichier(s)    : tests/Feature/Pos/ (3 fichiers seulement),
                app/Http/Controllers/Admin/Pos/CashDrawerController.php (non testé),
                app/Http/Controllers/Admin/Pos/CustomerNfcLookupController.php (non testé)
Invariant     : none
Évidence      : Glob de tests/Feature/Pos/ → 3 fichiers listés. Grep sur tests/Feature/
                pour "CashDrawer", "NFC", "void", "parked.*resume" → aucun match dans
                la couverture Feature. Un fichier PosParkedOrderTest.php existe à la
                racine tests/Feature/ pour park/recall/discard, mais pas le chemin resume
                post-F5 ou post-reconnect.
Effort        : L (2-5j) — écriture des tests Feature manquants (void, cash drawer,
                NFC lookup, parked resume complet).
Risque blocage: Régressions silencieuses sur des flux opérateur quotidiens (comptage de
                caisse, identification client, reprise après crash). En production multi-
                branches, ces chemins sont utilisés à chaque shift.
Fix proposé   : Créer tests/Feature/Pos/VoidOrderTest.php, CashDrawerTest.php,
                CustomerNfcLookupTest.php, ParkedOrderResumeTest.php couvrant les happy
                paths et les cas d'erreur principaux (session expirée, 401, double-void).
Dépendances   : aucun gate humain. À lancer après FIND-01 et FIND-03 pour éviter de tester
                un état cassé.
```

---

### FIND-09 — P2 POLISH
```
Surface       : KDS-FE
Description   : Le composant Swiper dans KitchenDisplaySystemComponent.vue est déclaré
                avec l'attribut dir="ltr" câblé en dur. En langue arabe (RTL), l'ordre
                des cards KDS est inversé et la navigation Swiper est miroir — les
                opérateurs cuisine arabophones voient les commandes dans le mauvais sens.
Fichier(s)    : resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:130
Invariant     : a11y
Évidence      : Lecture directe ligne 130 : `<Swiper dir="ltr"`. Aucun binding dynamique
                ni computed property conditionnelle trouvé dans le même composant.
                PosComponent.vue dispose d'une computed `direction()` retournant 'rtl'/'ltr'
                (lignes 973-974) — pattern existant mais non répliqué dans KDS.
Effort        : XS (<1h) — remplacer `dir="ltr"` par `:dir="swiperDir"` avec computed
                basé sur le store langue.
Risque blocage: KDS inutilisable en arabe RTL → exclut de fait les branches arabophone de
                l'utilisation du KDS en production.
Fix proposé   : Ajouter une computed `swiperDir()` retournant `store.state.lang.dir`
                (ou équivalent) et binder `:dir="swiperDir"` sur le composant Swiper.
                Reproduire le pattern de direction() de PosComponent.vue:973-974.
Dépendances   : aucun gate humain. Indépendant des autres findings.
```

---

### FIND-10 — P2 POLISH
```
Surface       : OPS
Description   : La table sync_metrics (migration 2026_04_23) ne possède ni colonne
                expires_at ni mécanisme de purge. SyncMetricsRecorder.php n'exécute
                que des INSERT. La table grossit sans borne à raison de N insertions
                par dispatch d'événement et par tick KDS polling — croissance non bornée
                en production continue.
Fichier(s)    : database/migrations/2026_04_23_220000_create_sync_metrics_table.php:11-22,
                app/Services/Observability/SyncMetricsRecorder.php:108-128
Invariant     : none
Évidence      : Lecture directe de la migration : schéma = id, metric_type, branch_id,
                value, correlation_id, labels, occurred_at — aucun expires_at. Lecture
                de SyncMetricsRecorder.php : seule méthode de persistance = insertMetric()
                → DB::table('sync_metrics')->insert() ; aucun DELETE, aucun TTL.
Effort        : S (1-4h) — ajouter une colonne occurred_at (déjà présente) pour filtrer,
                créer un job de purge schedulé, et/ou ajouter un index TTL.
Risque blocage: En production continue sur 6 mois avec 1 000 branches actives et polling
                KDS toutes les 3-10s, la table peut atteindre plusieurs centaines de millions
                de lignes → dégradation requêtes observabilité, coût disque.
Fix proposé   : Créer un job SyncMetricsPurgeJob avec policy de rétention configurable
                (ex. 30j) ; l'ajouter au Kernel.php schedulé quotidiennement ; ajouter un
                index sur occurred_at pour la suppression par batch.
Dépendances   : aucun gate humain. Peut être traité en parallèle avec FIND-11.
```

---

### FIND-11 — P2 POLISH
```
Surface       : POS-BE
Description   : La table pos_parked_orders ne contient pas de colonne expires_at. Il n'existe
                aucun critère d'expiration explicite par enregistrement : la purge par le
                scheduler PosPurgeParkedScheduleTest.php est planifiée mais ne dispose
                d'aucune référence temporelle à l'enregistrement au-delà de created_at,
                rendant la politique d'expiration implicite et fragile.
Fichier(s)    : database/migrations/2026_04_20_200000_create_pos_parked_orders_table.php:15-31,
                app/Jobs/CleanupStalePendingKioskOrders.php (job de purge)
Invariant     : none
Évidence      : Lecture directe de la migration — colonnes : id, branch_id, user_id, label,
                payload_json, preview_total, items_count, idempotency_token, timestamps.
                Aucun expires_at. L'index existant est sur (branch_id, user_id, created_at)
                — pas de colonne status dans l'index ni dans le schéma. La purge actuellement
                utilise created_at implicitement.
Effort        : S (1-4h) — migration d'ajout expires_at + gate humain (schéma DB modifié).
Risque blocage: Sans TTL par enregistrement, des commandes parkées orphelines (opérateur
                déconnecté sans discard) persistent indéfiniment, consomment de l'espace
                et peuvent être rappelées par erreur par un autre opérateur.
Fix proposé   : Ajouter une migration `add_expires_at_to_pos_parked_orders` avec colonne
                `expires_at` nullable ; setter lors du park avec durée configurable (ex. 8h) ;
                ajouter `expires_at` dans l'index composite ; gate humain requis (modification
                schéma DB).
Dépendances   : Gate humain requis pour modification schéma (règle GATE_LOG.md:57).
```

---

### FIND-12 — P2 POLISH
```
Surface       : POS-FE
Description   : PaymentComponent.vue ne gère pas les réponses HTTP 401 (token expiré
                pendant la saisie de paiement). En cas d'expiration de session mid-payment,
                l'erreur est catchée generiquement sans tentative de refresh-token ni
                retry — l'opérateur voit un message d'erreur non spécifique et doit
                recommencer manuellement le flux de paiement.
Fichier(s)    : resources/js/components/admin/pos/PaymentComponent.vue:279-297
Invariant     : none
Évidence      : Inspection directe du bloc catch de confirmOrder() (lignes 279-297) :
                vérification de `err?._paymentTimeout`, `err?.response?.data?.errors`,
                message générique en fallback — aucun check `err?.response?.status === 401`,
                aucune logique de refresh token, aucun retry.
Effort        : S (1-4h) — ajouter un intercepteur Axios 401 avec refresh + retry, ou
                gérer le 401 spécifiquement dans le catch de confirmOrder.
Risque blocage: Session expirée pendant un paiement en production (shift long ≥ 8h) →
                l'opérateur croit que la commande a échoué et peut soumettre à nouveau
                → risque de double-paiement si le premier appel a partiellement réussi.
Fix proposé   : Ajouter une détection `status === 401` dans le catch ; appeler le
                mécanisme de refresh token existant (store auth) ; retenter confirmOrder()
                une seule fois. Ou centraliser dans un intercepteur Axios partagé POS.
Dépendances   : FIND-03 — le refactor PaymentComponent (post-gate) est le bon moment pour
                intégrer cette correction.
```

---

### FIND-13 — P2 POLISH
```
Surface       : TEST
Description   : Le répertoire tests/Feature/KDS/ ne contient que 2 fichiers
                (KdsAllergenAggregationSplitTest, KdsSnapshotImmutableTest). Les
                transitions d'état KDS (new→preparing→ready→served) et le routing par
                station ne sont couverts par aucun test Feature.
Fichier(s)    : tests/Feature/KDS/ (2 fichiers seulement),
                app/Services/KitchenDisplaySystemOrderService.php (service non testé),
                app/Domain/Order/OrderStateMachine.php (machine non testée côté KDS)
Invariant     : order-status
Évidence      : Glob de tests/Feature/KDS/ → 2 fichiers. Grep sur tests/Feature/ pour
                "changeStatus", "preparing", "station_id", "routing" → aucun match dans
                les tests Feature KDS. KdsAllergenAggregationSplitTest et
                KdsSnapshotImmutableTest couvrent des invariants de données, pas les
                transitions opérationnelles.
Effort        : L (2-5j) — écriture des tests de transition d'état et de station routing.
Risque blocage: Régression silencieuse sur les transitions de statut KDS (la machine à
                états est partagée avec OrderService) → commandes bloquées en cuisine
                sans alerte.
Fix proposé   : Créer tests/Feature/KDS/KdsStatusTransitionTest.php couvrant les
                transitions légales et illégales (new→preparing→ready→served, tentative
                de saut d'état) ; créer KdsStationRoutingTest.php vérifiant que les
                items sont routés vers les bonnes stations selon la configuration branche.
Dépendances   : FIND-02 doit être levé avant de tester les zones OrderService/KDS partagées.
```

---

### FIND-14 — P3 BACKLOG
```
Surface       : OPS
Description   : Les trois gates HG-W2-1 (cutover POS V4), HG-W2-2 (vendor split
                vendor-pos.js) et HG-W2-3 (révision KPI 220→600 KB + LCP réel) restent
                tous en statut PENDING_HUMAN_GATE ou BLOCKED. Aucune mesure LCP réelle
                n'a été collectée. Le cutover de POS V4 comme route principale est bloqué.
Fichier(s)    : docs/gates/GATE_LOG.md:40-42
Invariant     : perf
Évidence      : GATE_LOG.md lignes 40-42 :
                - HG-W2-1 : PENDING_HUMAN_GATE (soft-blocked — attend HG-W2-3 cleared + 1
                  campagne LCP réel)
                - HG-W2-2 : BLOCKED (attend HG-W2-3 ; peut être annulé selon option)
                - HG-W2-3 : PENDING_HUMAN_GATE (décision produit — cible mesure, pas code)
Effort        : N/A — décision humaine Product + UX + TL
Risque blocage: Sans cutover POS V4 validé, le POS opérateur reste sur l'ancienne route.
                Le KPI bundle 220 KB infaisable empêche la validation de la mise en
                production. Bloque le séquencement des cycles W2.
Fix proposé   : Organiser la campagne LCP réelle (mesure Lighthouse/WebPageTest sur
                /admin/pos-v4 en conditions prod) ; soumettre les résultats à HG-W2-3 pour
                décision Product ; débloquer en cascade HG-W2-1 et HG-W2-2.
Dépendances   : Dépend uniquement de la disponibilité humaine Product + UX + TL.
```

---

### FIND-15 — P3 BACKLOG
```
Surface       : POS-FE
Description   : Un bloc @pricing-allowed-block dans PosComponent.vue (affichage du total
                pré-modal) est marqué signoff-pending avec une échéance au 2026-05-10.
                Si le signoff n'est pas obtenu avant cette date, le bloc doit être retiré
                ou encadré par un gate formel.
Fichier(s)    : resources/js/components/admin/pos/PosComponent.vue:1779-1786
Invariant     : pricing-ssot
Évidence      : Lecture directe lignes 1779-1786 :
                `// @pricing-allowed-block start`
                `// [POS-V4 W0+ DISCOVERY 2026-04-26] Pre-modal display total...`
                `// signoff-pending — date_limit: 2026-05-10`
                `// Sign-off owners: Tech Lead + Backend owner.`
                `// Tracking: reports/audit/BACKLOG_POS_V4_W0PLUS_DISCOVERIES_2026-04-26.md §1`
Effort        : XS (<1h) si signoff obtenu ; S (1-4h) si retrait du bloc requis.
Risque blocage: Après 2026-05-10, le bloc devient techniquement non signoff → risque
                de display price côté frontend sans validation Backend owner (violation
                pricing-ssot si le total affiché diverge du total calculé serveur).
Fix proposé   : Planifier la revue du bloc avec TL + Backend owner avant 2026-05-10 ;
                documenter la décision dans reports/audit/BACKLOG_POS_V4_W0PLUS_DISCOVERIES-2026-04-26.md §1.
                Si signoff accordé : remplacer le commentaire signoff-pending par un
                commentaire signoff-granted + date + nom. Si refus : supprimer le bloc.
Dépendances   : Tech Lead + Backend owner sign-off. Indépendant des autres findings.
```

---

## §3.3 — Couverture des buckets

### A. Invariants FoodKing

**A1 — pricing SSOT côté POS**
→ FIND-01 (v-model manquant brise le discount path), FIND-04 (EUR/fr-FR hardcodés dans
helper kiosk), FIND-15 (signoff-pending @pricing-allowed-block PosComponent.vue:1779).
Vérification : OrderService.php:296-298 strip les totaux client et recalcule serveur → SSOT
respecté côté backend. Risque résiduel côté frontend sur les deux findings cités.

**A2 — OrderStatus enum (pas de magic int)**
→ RAS — vérifié. resources/js/enums/modules/orderStatusEnum.js définit tous les statuts
par nom symbolique (PENDING=1, ACCEPT=4, PREPARING=7, PREPARED=8…). KitchenDisplaySystemComponent.vue
utilise `enums.orderStatusEnum.PREPARED` et non des entiers bruts. Aucun magic int détecté
dans les composants KDS et POS inspectés.

**A3 — branch_id isolation**
→ RAS — partiellement vérifié. KitchenDisplaySystemController.php applique le middleware
`permission:kitchen-display-system`. L'isolation branch_id au niveau des requêtes service
est supposée par héritage du AdminController ; vérification exhaustive non conduite faute
d'accès complet aux classes parentes dans cette session. Recommandation : vérifier que
`KitchenDisplaySystemOrderService` filtre explicitement par `branch_id` dans toutes les
requêtes de lecture. Marqué RAS provisoire sur la base des controllers POS inspectés.

**A4 — dispatch après commit**
→ RAS — vérifié. OrderService.php:1440-1475 : DB::transaction() encapsule les mutations ;
SendOrderMail, SendOrderSms, SendOrderPush et OrderStatusChanged::dispatch sont appelés
après la fermeture du bloc transaction. Commentaire ligne 1442-1444 confirme "deferred to
afterCommit". Pattern conforme.

**A5 — symétrie OrderService / FrontendOrderService**
→ FIND-07 (partiellement vérifié). Les deux services importent les mêmes providers de pricing.
OrderService.php:296-298 strip les totaux client. Vérification complète bloquée par FIND-02
(gate humain frozen).

**A6 — zones frozen touchées sans gate**
→ FIND-02 (GATE_VERIFY_P0_FROZEN_CONSOLIDATED PENDING — 8 cycles P0 non signés),
FIND-03 (GATE_PAYMENT_PROP_MUTATION PENDING — PaymentComponent frozen). Aucun autre
gate ouvert détecté hors ces deux. GATE_LOG.md:82-85 rappel de l'interdiction absolue
de modification frozen sans gate approuvé.

---

### B. Robustesse opérationnelle

**B1 — reconnect storm (WS, F5, perte réseau)**
→ RAS — vérifié. WebSocketService.js:33-62 : STORM_DETECTION_WINDOW_MS=30 000,
STORM_DETECTION_THRESHOLD=4, circuit breaker `_circuitBreakerOpen`, jitter STORM_MIN_DELAY_MS
=5 000 / STORM_MAX_DELAY_MS=30 000. KdsSyncService.js:234-250 : écoute 'reconnect_storm',
applique jitter 0-500ms avant forceSync. Protection complète implémentée.

**B2 — outbox dedupe sous concurrence**
→ RAS — vérifié par tests existants. tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php
couvre la déduplication concurrente. KdsSyncService utilise le même pattern outbox.
Aucune régression détectée dans cette session.

**B3 — retry exponentiel + circuit breaker KdsSyncService.js**
→ RAS — partiellement vérifié. KdsSyncService.js dispose d'un état KDS_SYNC_STATE.BACKOFF
(ligne 302) et d'une méthode `_recomputeCadence()`. Les intervalles varient (3 000, 5 000,
10 000ms avec jitter, lignes 268-290). Le circuit breaker complet (nombre de retries max,
reset) n'a pas été lisible dans les extraits disponibles. Recommandation : vérifier que
BACKOFF state dispose d'un nombre maximal de retries et d'un reset timer.

**B4 — single-flight checkout POS**
→ RAS — vérifié. PaymentComponent.vue:96-97 : `:disabled="loading.isActive"` sur le
bouton confirm. Ligne 199-201 : double guard `if (this.loading.isActive) return` puis
`this.loading.isActive = true`. Commentaire "[AUDIT-P2] Strict single-flight guard".
Protection correcte contre la double-soumission. Note : ne gère pas les 401 (→ FIND-12).

**B5 — libération table POS sur cancel/refund**
→ RAS — partiellement vérifié. tests/Feature/Pos/DiningTableReleaseAfterPosOrderTest.php
couvre les cas releases_table_when_occupied_by_same_paid_dine_in_order et
does_not_release_when_table_held_by_another_order. L'événement OrderTableChanged est cité
dans le brief. Le chemin refund/cancel explicite n'a pas été entièrement tracé dans cette
session — marqué RAS provisoire sur la base des tests existants.

**B6 — purge parked orders schedule (idempotence)**
→ RAS avec réserve. app/Jobs/CleanupStalePendingKioskOrders.php traite les orders en boucle
via `.each()` avec `OrderStateMachine::apply()` par enregistrement. Pas d'idempotency key
explicite. Risque en cas de double-tick (redémarrage scheduler) : chaque ordre est traité
à nouveau, mais la machine à états devrait rejeter les transitions invalides. Recommandation :
ajouter une guard vérifiante le statut courant avant traitement.

**B7 — son KDS (autoplay browser, fallback silencieux)**
→ RAS — non re-inspecté en détail (lot 2.C livré). Marqué RAS sur la base du rapport
d'activité. Vérification autoplay policy browser non conduite dans cette session.

**B8 — timeout loyalty kiosk**
→ RAS — non re-inspecté (lot 2.E livré). Marqué RAS sur la base du rapport d'activité.
La propagation aux composants enfants n'a pas été vérifiée dans cette session.

---

### C. UX / a11y / i18n

**C1 — focus trap modals POS + KDS**
→ FIND-06. focustrap importé dans PosComponent.vue:732 mais jamais instancié. Aucun modal
POS (PaymentComponent, CashDrawer, NfcCustomer, ReceiptComponent) n'implémente de focus trap.

**C2 — i18n complet 4 langues (fr/en/ar/bn)**
→ FIND-05 (bn.json manque 27 clés kds_*). Les 4 fichiers lang PHP par langue sont en
parité pour les 16 fichiers existants (addressType, orderStatus, pos_payment_method, etc.).
Le gap est limité aux clés kds_* dans le fichier JS resources/js/languages/bn.json.

**C3 — toasts reçu POS**
→ RAS — lot 2.B livré selon rapport d'activité. Wording, durée et dédupe non re-inspectés
en détail dans cette session. Marqué RAS provisoire.

**C4 — aide TPE kiosk**
→ RAS — lot 2.D livré. Responsive et gestes tactiles non re-testés dans cette session.

**C5 — RTL (arabe) POS + KDS**
→ POS : RAS — PosComponent.vue:973-974 expose `direction()` computed retournant 'rtl'/'ltr'
depuis le store ; Tailwind RTL directives (`ltr:` / `rtl:`) utilisées dans le template.
→ KDS : FIND-09 — Swiper dir="ltr" hardcodé ligne 130. RTL cassé en KDS arabe.

**C6 — POS dark mode**
→ RAS — hors scope selon brief (mention explicite "sinon RAS"). Non applicable.

---

### D. Performance / bundle

**D1 — POS first-paint ≤ KPI révisé**
→ FIND-14 (HG-W2-3 PENDING — KPI révisé 220→600 KB en attente décision Product). First-
paint mesuré à 652 KB gz selon rapport d'activité (après -17.6%). Statut : en-dessous de
600 KB cible pressentie mais gate non approuvé.

**D2 — KDS bundle**
→ RAS — admin-kds chunk à 26 KB gz (cohérent avec usage continu écran cuisine). Aucune
anomalie de taille détectée.

**D3 — LCP/TTI réel**
→ FIND-14. LCP réel non mesuré, condition bloquante pour HG-W2-1.

**D4 — memory leaks long-running (KDS 8-12h, POS 4-6h)**
→ RAS — [UNVERIFIED] aucun test de profiling mémoire long-running n'a été conduit ou
documenté. Non testable dans cette session sans benchmark. À ajouter au backlog.

**D5 — Echo/Pusher reconnect cost**
→ RAS — protégé par B1 (storm detection + circuit breaker + jitter). Le coût de reconnect
est borné par STORM_MAX_DELAY_MS=30 000. Aucun profiling de cost réseau spécifique conduit.

---

### E. Couverture tests

**E1 — POS Feature paths critiques manquants**
→ FIND-08. 3 fichiers Feature/Pos/. Manquent : void order, cash drawer counts, NFC lookup,
parked order resume complet (post-F5 / post-reconnect).

**E2 — KDS Feature paths critiques manquants**
→ FIND-13. 2 fichiers Feature/KDS/. Manquent : status transitions (new→preparing→ready→served),
allergen aggregation runtime, station routing, snapshot regeneration on item change,
concurrent state updates WS+poll.

**E3 — Vitest POS couverture composant PosComponent**
→ RAS — [UNVERIFIED] PosComponent.vue fait 3 000+ lignes. Les 112 specs Vitest couvrent
posOrderIdempotency, kdsAllergens, kdsStationFilter, kdsLineSemantics. La couverture unitaire
de PosComponent lui-même n'a pas été mesurée dans cette session. Risque probable de couverture
insuffisante sur un composant de cette taille.

**E4 — E2E Playwright POS+KDS**
→ RAS — non déclaré dans le plan actif selon le brief (§ "sinon RAS — décision plan").
Aucun cycle Playwright POS+KDS n'est en cours. À décider lors du séquencement.

---

### F. Observabilité / OPS

**F1 — SyncMetricsRecorder couverture métriques critiques**
→ RAS avec réserve. SyncMetricsRecorder.php couvre : outbox.dispatch_latency_ms (p95 < 2 000ms),
ws.auth_failure, kds.sync_fallback_interval_ms. Métriques client whitelisted : ws.connect_latency_ms,
ws.disconnect_event, ws.reconnect_storm, ws.auth_failure, kds.sync_fallback_interval_ms.
Gap identifié : pas de métrique directe "outbox depth" (profondeur queue) ni "dispatch failures
count" (taux d'échec dispatch) — les deux sont cités dans le SLO cible du commentaire mais
ne correspondent pas à une métrique enregistrée. Recommandation : ajouter METRIC_OUTBOX_DEPTH
et METRIC_DISPATCH_FAILURE_COUNT.

**F2 — Dashboard ops métriques affichées**
→ RAS — SyncOverviewController créé selon rapport d'activité. Non re-inspecté en détail
dans cette session. La complétude du dashboard est conditionnée par F1 (gap outbox depth).

**F3 — Alarmes sur thresholds**
→ RAS — [UNVERIFIED] aucun fichier d'alarme ou de threshold alert n'a été trouvé dans
le périmètre inspecté. La définition d'alarmes (5xx POST /api/orders, WS disconnect rate,
KDS sync staleness) n'est pas documentée dans le code ou les configs inspectés. À vérifier
dans la config infra (hors scope de ce review).

**F4 — Logs structurés (correlation_id)**
→ RAS — SyncMetricsRecorder.php:115 propage correlation_id à chaque metric ; `resolveCorrelationId()`
fallback sur le header X-Correlation-ID entrant. EnsureCorrelationIdPropagatesToMetricsTest
existant selon le brief. Pattern structuré confirmé dans le code inspecté.

---

### G. Données / état persistant

**G1 — pos_parked_orders schéma et index**
→ FIND-11 (no expires_at). Index présent : (branch_id, user_id, created_at) via
`pos_parked_branch_user_idx` (migration:26) + unique (user_id, idempotency_token) via
`pos_parked_user_idem_uniq` (migration:27). Gap : pas d'index incluant un éventuel `status`
ni `expires_at` (colonne manquante).

**G2 — composition_snapshot immutable**
→ RAS — vérifié. Migration 2026_04_22_000020_add_composition_snapshot_to_order_items.php
présente. OrderItemResource.php:31-35 : "[T07] Prefer immutable composition_snapshot when
present" — retourne `$this->composition_snapshot` en priorité sur les données item live.
KDS et reçu lisent bien la snapshot.

**G3 — release_tracking sur order_items**
→ RAS — [UNVERIFIED] aucune migration portant le terme `release_tracking` n'a été trouvée
dans database/migrations/. Il est possible que ce mécanisme soit implémenté différemment
(colonne dans order_items, table séparée non nommée ainsi, ou non encore implémenté).
À vérifier explicitement avant lancement.

**G4 — sync_metrics rétention/purge**
→ FIND-10. Aucun TTL ni purge mécanisme. Croissance non bornée confirmée.

**G5 — action_logs index composite branch+created**
→ RAS — vérifié. Migration 2026_04_22_200000_add_composite_index_branch_created_to_action_logs.php
crée l'index `action_logs_branch_created_idx` sur (branch_id, created_at). Motivation
documentée dans la migration : DashboardService::auditTrail() query (branch_id = ? ORDER BY
created_at DESC LIMIT ?) — filesort éliminé. Bénéfice prouvé par le commentaire migration.

---

### H. Risques connus restants

**H1 — 5 gates humains en attente**
→ FIND-02 (GATE_VERIFY_P0_FROZEN_CONSOLIDATED PENDING), FIND-03 (GATE_PAYMENT_PROP_MUTATION
PENDING), FIND-14 (HG-W2-1 PENDING + HG-W2-2 BLOCKED + HG-W2-3 PENDING). Total : 5 gates
ouverts confirmés. Aucun auto-approval possible (GATE_LOG.md:82-85 absolute prohibition).

**H2 — KPI 220 KB bundle infaisable**
→ RAS en cours de résolution. First-paint mesuré à 652 KB gz, dans la cible pressentie
600 KB du HG-W2-3. Bloqué sur décision Product (FIND-14). Pas d'aggravation détectée.

**H3 — Codex API instable**
→ RAS — résolu selon brief (Claude orchestre). Aucune anomalie Codex détectée dans cette
session.

---

## §3.4 — Recommandation de séquencement

```
1.  [P0] FIX-FIND-01 : corriger v-model discountReason — aucun gate — XS (<1h)
2.  [P0] HUMAN-FIND-02 : session revue humaine GATE_VERIFY_P0_FROZEN_CONSOLIDATED
    — gate humain (TL + Backend + QA NF525) — N/A implem
3.  [P0] HUMAN-FIND-03 : session revue humaine GATE_PAYMENT_PROP_MUTATION
    — gate humain (TL + Backend + QA NF525 + UX) — N/A implem
    ↳ prérequis : indépendant de FIND-02, peut être parallélisé
4.  [P1] FIX-FIND-04 : retirer hardcodes EUR/fr-FR de kioskFormatPrice.js
    — aucun gate — S (1-4h)
5.  [P1] FIX-FIND-05 : remplir 27 clés kds_* dans bn.json
    — aucun gate — S (1-4h, nécessite locuteur Bengali)
6.  [P1] FIX-FIND-06 : instancier focustrap sur tous les modals POS
    — aucun gate — S (1-4h)
7.  [P1] TEST-FIND-08 : écrire tests Feature POS manquants (void, cash drawer, NFC, parked resume)
    — prérequis : FIND-01 corrigé — L (2-5j)
8.  [P1] VERIFY-FIND-07 : revue symétrie OrderService/FrontendOrderService
    — prérequis : FIND-02 approuvé — M (1j)
9.  [P2] FIX-FIND-09 : Swiper dir dynamique dans KDS (RTL arabe)
    — aucun gate — XS (<1h)
10. [P2] FIX-FIND-10 : job purge sync_metrics (TTL 30j)
    — aucun gate — S (1-4h)
11. [P2] FIX-FIND-11 : migration expires_at pos_parked_orders
    — gate humain (schéma DB) — S (1-4h post-gate)
12. [P2] FIX-FIND-12 : 401 refresh-then-retry PaymentComponent
    — prérequis : FIND-03 approuvé (refactor PaymentComponent) — S (1-4h)
13. [P2] TEST-FIND-13 : écrire tests Feature KDS (status transitions, station routing)
    — prérequis : FIND-02 approuvé — L (2-5j)
14. [P3] HUMAN-FIND-14 : campagne LCP réelle + décision HG-W2-1/2/3
    — prérequis humain Product + UX + TL — N/A implem
15. [P3] HUMAN-FIND-15 : signoff @pricing-allowed-block avant 2026-05-10
    — TL + Backend owner — XS (<1h post-décision)
```

---

## §3.5 — Verdict final

Avant lancement production multi-branches opérateur réel, la condition strictement minimale est : **(1)** les deux gates P0 bloquants `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` et `GATE_PAYMENT_PROP_MUTATION_2026-04-26` doivent recevoir une décision humaine formelle consignée dans GATE_LOG.md avant toute exécution de cycle sur les zones concernées ; **(2)** le bug FIND-01 (discountReason sans v-model) doit être corrigé et testé, car il bloque le flux de remise POS en production ; **(3)** les 27 clés KDS Bengali manquantes (FIND-05) doivent être remplies et les tests i18n ajoutés ; **(4)** le focus trap modal POS (FIND-06) doit être instancié pour satisfaire WCAG 2.1 §2.1.2 ; **(5)** le Swiper KDS RTL (FIND-09) doit être dynamisé pour rendre le KDS utilisable en arabe. Les findings P2 (sync_metrics purge, expires_at parked, 401 retry) et les tests manquants (FIND-08, FIND-13) sont des conditions de qualité opérationnelle qui, s'ils ne sont pas corrigés avant lancement, exposeront l'équipe à des régressions silencieuses sur des flux quotidiens en shift continu : ils doivent être traités dans les cycles immédiats post-gates P0, avant la mise en production élargie.
