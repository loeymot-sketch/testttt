# REGISTRE AUDIT CAISSE/STOCK/SYNC — 2026-07-22

Audit adversarial 7 dimensions (47 agents) → 39 findings confirmés (1 P1, 16 P2, 22 P3) + 48 améliorations. Worker de queue relancé (temps réel ré-armé = racine ops de plusieurs symptômes sync).

## VAGUE 1 — TRAITÉ (commits 66d1a29d3 → de5d7b919)

- **[P1] KDS-V2-BLIND-BANNERS** — Layout V2 (défaut prod) : le badge fraîcheur « synchro incertaine » ET la bannière d'erreur persistante ne ren
- **[P2] UX-NOTIF-01** — Nouvelle commande web/borne : bip+toast dépendent EXCLUSIVEMENT d'Echo — la voie polling est muette, et le pip
- **[P2] UX-TRACKER-02** — Tracker commandes : fenêtre aveugle de 60s quand la socket est « connectée » (poll 60s flat, sans échappatoire
- **[P2] UX-CART-03** — Panier tactile : stepper qty 22×22px, le − devient 🗑 à qty=1 → suppression de ligne composée SANS confirmation
- **[P2] UX-PANEL-04** — Panneaux « À encaisser » / « Commandes web » VIDÉS sur erreur API transitoire — l'écran affiche « Aucune comma
- **[P2] UX-PARK-05** — Drawer « En attente » : « Écarter » détruit le panier parqué d'un client sans confirmation, bouton danger coll
- **[P2] UX-RESET-06** — « Annuler » sous le bouton Encaisser vide TOUT le panier en un tap, sans confirmation ni undo
- **[P2] handler-queryexception-fuite-sql** — Le handler global renvoie le message brut d'une QueryException (SQL + valeurs bindées) au client, même en prod
- **[P2] POSPERF-02-n1-poll** — Tempête N+1 sur les 2 polls chauds caisse (counter-collect/pending + web-orders/pending) : ~10 requêtes SQL PA
- **[P2] POSPERF-09-tracker-ws-stale** — Queue-worker DOWN (état actuel du box) : le tracker croit le temps-réel sain (socket soketi UP) et polle à 60 
- **[P2] SYNC-W1** — Worker de file DOWN = extinction totale du temps réel + jobs, strictement silencieuse (healthz reste OK, banni
- **[P2] SYNC-W3** — Beep + toast « nouvelle commande » caisse déclenchés UNIQUEMENT par le handler Echo — silencieux dès que la di
- **[P2] KDS-SOUND-EMPTY** — Le carillon « nouvelle commande » du KDS ne peut JAMAIS sonner : /public/sounds/kds-new-order.mp3 fait 0 octet
- **[P3] pos-orderdetails-pii-solde-email** — OrderDetailsResource POS expose email + username + solde cagnotte de n'importe quel client à tout opérateur PO

## VAGUE 2+ — RESTANT (registre pour continuation)

- [P2] POSPERF-01-entry-graph (eff M) — Entrée « slim » pos-app.js anéantie : DefaultComponent importe le router COMPLET → tout l'admin+frontend (2,23
- [P2] POSPERF-07-tracker-unbounded (eff S) — Tracker : le poll récupère TOUTES les commandes du jour (per_page:100 ignoré — paginate absent) avec 8 relatio
- [P2] KDS-SCHEDULED-CARD-MISLEADS (eff M) — Commande programmée libérée à T-20 : la carte KDS l'affiche comme une ASAP ultra-en-retard (timer depuis creat
- [P2] MP-02-ticket-papier-split-aveugle (eff S) — Ticket fiscal papier aveugle aux tranches split : une seule ligne tender (dominant) — paiement affiché < total
- [P3] UX-FKEY-07 (eff S) — Raccourcis F1-F12 catégories : mappés sur la liste BRUTE alors que la strip affiche la liste featured-filtrée 
- [P3] UX-SCAN-08 (eff S) — Scan code-barres pendant que la recherche a le focus : les caractères fuient dans le champ → grille filtrée su
- [P3] pos-customer-id-non-valide-attribution-farming (eff S) — customer_id / loyalty_customer_code POS non validés (pas d'exists/rôle/branche) → attribution d'une vente et f
- [P3] POSPERF-03-dup-oss (eff S) — Chaque tick de poll caisse tire DEUX GET identiques /api/admin/oss-order (loadActiveOrdersStats + loadReadyOrd
- [P3] POSPERF-04-idle-hammer (eff S) — Cadence de poll inversée : file « Prêt » VIDE ⇒ 5 s forcées même WebSocket sain → la caisse martèle 48 req/min
- [P3] POSPERF-06-vendor-apex (eff M) — vendor.js chargé par la page caisse embarque apexcharts+svg.js (~450-500 Ko raw, ~moitié du chunk) jamais util
- [P3] POSPERF-08-locales-dead (eff M) — ar.json (120 Ko) + en.json (112 Ko) embarqués statiquement dans pos-app.js/app.js alors que la caisse est FR-l
- [P3] hub-sidebar-lands-on-catalogue-tab (eff S) — Fusion CatalogHub : l'entrée sidebar « Produits & Stock » ouvre l'onglet Catalogue, pas le dashboard Stock
- [P3] pos-86-propagation-dead-no-poll (eff S) — Worker down (état actuel) : la propagation 86 vers les tuiles/panier POS est morte et la caisse n'a AUCUN poll
- [P3] quota-daily-reenable-cron-only (eff M) — max_daily_qty : la ré-activation après minuit dépend EXCLUSIVEMENT du cron 00:05 (box resto éteinte la nuit) —
- [P3] panel-manual-86-reason-collision (eff S) — Le 86 MANUEL du panel caisse/cuisine écrit reason='stock_rupture' — la raison réservée aux ruptures AUTO que S
- [P3] photo-upload-authz-and-feedback (eff S) — Photo upload sur la ligne stock : bouton affiché sur items_edit alors que le serveur exige le RÔLE Admin/Tenan
- [P3] global-flag-defeats-dashboard-toggle (eff M) — items.is_available GLOBAL (formulaire d'édition) vs toggle branche du dashboard : la remise « EN STOCK » est s
- [P3] SYNC-W2 (eff M) — Contrat KdsSyncService↔WebSocketService cassé 3 fois (.state inexistant, {from,to} vs {previous,current}, 'CON
- [P3] SYNC-W6 (eff S) — eventContract.unsubscribe() : stopListening SANS callback → retire les handlers de TOUS les co-abonnés du même
- [P3] KDS-SCHEDULED-NO-UPPER-BOUND (eff M) — Une programmée no-show (jamais bumpée/encaissée) reste sur le board À VIE : l'échappement `orWhereNotNull('sch
- [P3] MP-01-double-refund-cash-plus-avoir (eff M) — Remboursement ESPÈCES = sortie tiroir + avoir wallet cumulés (double remboursement client enregistré) — toujou
- [P3] MP-03-fallback-cash-phantom-out (eff S) — Repli remboursement 'cash' aveugle au cas « tranches présentes mais aucune CASH » → sortie tiroir fantôme du t
- [P3] MP-04-recu-remboursement-marque-vente (eff S) — Reçu d'un remboursement/miroir (RTN-, totaux négatifs) imprimé « Operation : VENTE » — aucun marquage REMBOURS
- [P3] POSPERF-05-wizard-cachebust (eff S) 🧊FROZEN(gate) — pos-wizard.js (315 Ko) re-téléchargé + re-parsé à CHAQUE chargement de la caisse : cache-bust `?v=9-{{ time() 
- [P3] SYNC-W5 (eff S) 🧊FROZEN(gate) — Contrats événement↔consommateur désalignés : 3 broadcasts orphelins (SettingsUpdated, OrderPaymentStatusChange

## AMÉLIORATIONS retenues (backlog, valeur caissier/cuisine)

- [S] caisse-ux/IMP-REMINDER — Rappel sonore/visuel PÉRIODIQUE tant qu'une commande web/borne attend (pas juste un ding à l'arrivée
- [M] caisse-ux/IMP-SYNC-CHIP — Indicateur d'état sync basé sur la FRAÎCHEUR DES ÉVÉNEMENTS, pas la socket
- [M] caisse-ux/IMP-HOTKEYS — Hotkeys visibles et sûrs : badges F-keys sur pills, « / » → recherche, Ctrl+Entrée → Encaisser ; REJ
- [S] caisse-ux/IMP-HIT-AREAS — Passe hit-areas 44-48px sur les zones tapées en rush (stepper panier, boutons print/CTA des panneaux
- [S] caisse-ux/IMP-AGING — Vieillissement visible : carte rouge/pulsée après seuil dans « À encaisser », âge par ligne dans les
- [S] caisse-ux/IMP-TILE-FEEDBACK — Feedback immédiat au tap tuile produit (pressed + micro-spinner) pendant le fetch item/details
- [M] caisse-ux/IMP-UNDO-PATTERN — Pattern « toast Annuler 5s » unifié pour toutes les actions destructives caisse (ligne supprimée, pa
- [S] caisse-ux/IMP-A11Y-CLEANUP — Nettoyer la fausse couverture a11y : posA11y.js (trapFocus/announce) testé mais importé NULLE PART —
- [L] caisse-secu/pos-verrou-caissier-pin-idle — Verrou caissier: pas de PIN switch-user ni d'auto-lock sur poste POS partagé (token Sanctum ~8h)
- [S] caisse-secu/counter-collect-eager-load-user — counter-collect/pending: lazy-load user->roles->media par ligne (jusqu'à 200) sur la file la plus so
- [M] caisse-secu/pos-uniformiser-enveloppe-erreur — Uniformiser les réponses d'erreur POS sur le pattern PosLoyaltyController (Log::error + message géné
- [S] caisse-secu/counter-collect-indicateur-backlog — counter-collect/pending tronque silencieusement à 200 — exposer le backlog restant au caissier
- [M] caisse-perf/IMP-01-lean-panel-resource — Resource « panneau » unique pour les 3 files caisse (à encaisser / web / prêt) — payload ÷5-10
- [M] caisse-perf/IMP-02-tracker-in-pos-app — Monter le tracker DANS pos-app en chunk lazy — supprimer le hard-reload POS↔tracker
- [S] caisse-perf/IMP-03-lodash-extract — Extraire 'lodash' dans vendor.js (il est dupliqué dans pos-app.js ET app.js)
- [M] caisse-perf/IMP-04-async-modals — defineAsyncComponent pour les modales secondaires de PosComponent — réduire le parse initial de pos-
- [M] caisse-perf/IMP-05-304-notmodified — Réponses 304/hash courtes sur les 3 polls caisse quand rien n'a changé
- [S] caisse-perf/IMP-06-tick-isolation — Isoler les libellés « Mis à jour il y a Xs » dans un micro-composant — stopper le re-render complet 
- [M] caisse-perf/IMP-07-item-show-prefetch — Précharger le profil composer/wizard des 10 items les plus vendus après idle — ouverture wizard inst
- [S] caisse-perf/IMP-08-gzip-box — Activer gzip/brotli sur le nginx du box resto (gate connue 2026-07-17 toujours ouverte)
- [S] stock-ssot/imp-ruptures-first-sort — Dashboard stock : trier les ruptures en tête + badge compteur rouge par bucket
- [S] stock-ssot/imp-accent-insensitive-search — Recherche accent-insensible sur le dashboard ET le panel caisse/cuisine (« mega » → « Méga »)
- [M] stock-ssot/imp-quota-counter-visible — Compteur quota visible (vendus X / max Y aujourd'hui) sur la carte dashboard
- [M] stock-ssot/imp-max-daily-qty-ui — Brancher un réglage UI sur l'endpoint max-daily-qty (orphelin)
- [M] stock-ssot/imp-bulk-toggle-category — Bulk-toggle : 86 / ré-activer tout un bucket (fin de service, four en panne)
- [S] stock-ssot/imp-rupture-reason-chip — Afficher raison + « depuis » sur les cartes rupture (86 manuel vs quota vs stock)
- [S] stock-ssot/imp-freshness-indicator — Indicateur de fraîcheur « Mis à jour il y a Xs » + état temps-réel sur le dashboard stock
- [M] sync-engine/IMP-1 — Bannière « Temps réel dégradé » unifiée caisse/KDS/OSS pilotée par le FLUX d'événements, pas par l'é
- [S] sync-engine/IMP-2 — healthz : intégrer queue_pending et la fraîcheur ws:heartbeat au statut (degraded)
- [M] sync-engine/IMP-3 — outbox:rescue mode broadcast inline — self-heal du temps réel quand le worker est mort
- [S] sync-engine/IMP-4 — KDS : afficher « Dernier événement reçu il y a Xs » à côté du badge de fraîcheur poll
- [S] sync-engine/IMP-5 — OSS : resserrer la cadence connectée 60s → 20-30s
- [S] sync-engine/IMP-6 — Consommateur SettingsUpdated côté POS (refetch réglages live)
- [S] sync-engine/IMP-7 — Test sentinelle du contrat wsService ⇄ services de sync (anti-régression du mismatch SYNC-W2)
- [S] sync-engine/IMP-8 — Diff+beep sur les chemins poll du tracker et de l'encaissement (miroir du pattern KDS)
- [S] kds-oss/IMP-TICKET-ALLERGENES — Ticket cuisine : imprimer une ligne ALLERGÈNES structurée depuis allergens_snapshot
- [M] kds-oss/IMP-RECALL-FROM-STRIP — « Annuler bump » direct depuis les pastilles « Récemment servies » (au lieu du drawer Historique seu
- [S] kds-oss/IMP-TRACKER-FRESHNESS — Étendre le traitement fraîcheur (badge « synchro incertaine ») au tracker caisse
- [S] kds-oss/IMP-AGING-CONFIG — Seuils d'aging KDS configurables (3/6 min hardcodés)
- [S] kds-oss/IMP-OSS-WALL-SOUND-UNLOCK — Mur OSS public : bouton one-shot « Activer le son » à l'installation pour débloquer le carillon « Pr
- [S] kds-oss/IMP-SCHEDULED-BANNER-COUNTDOWN — Bandeau programmées : compte à rebours « dans 42 min » + surbrillance à l'approche de la fenêtre
- [S] kds-oss/IMP-A11Y-OPTIMISTIC-ANNOUNCE — KdsV2Grid : annonce aria-live après résolution du PATCH (pas optimiste avant)
- [S] money-path/IMP-01-branche-morte-self-service-payment — Supprimer (ou garder) la branche self-service morte de changePaymentStatus qui flip payment_status s
- [M] money-path/IMP-02-duplicata-increment-avant-impression — DUPLICATA compté à la demande, pas à l'impression réussie — une 1re impression échouée marque l'orig
- [M] money-path/IMP-03-badge-mouvements-tiroir-sautes — Badge « mouvements tiroir sautés aujourd'hui » dans le dialog de session caisse
- [S] money-path/IMP-04-overpay-split-explicite — Imprimer l'excédent toléré d'un split cash (« Arrondi/pourboire ») sur ticket + note tiroir
- [S] money-path/IMP-05-bandeau-kill-switch-remises — Bandeau POS « Remises désactivées (V1) » quand manual_discount_enabled=false
- [S] money-path/IMP-06-ligne-fidelite-sur-ticket — Ligne « FIDÉLITÉ -X,XX € (N pts) » sur le ticket papier quand un redeem est appliqué

## RÉFUTÉ
- SYNC-W4 (prémisse fausse : la classe existe bien).

## Zones frozen : 0 touchée. NF525 chaîne OK ×4. Tests : vitest 38 nouveaux + 851 PHPUnit POS/Order/Sync + e2e réel caisse/KDS.