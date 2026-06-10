# PILOTE W-D — KDS/OSS PROFOND (GOAL VALIDATION PROFONDE 100%)
**Date** : 2026-06-10 · **Branche** : `heal/wd-kds-validation-2026-06-10` (base `heal/pre-cloud-exec-2026-06-05` @ 46cb9727e)
**Serveur** : http://127.0.0.1:8766 (clone jetable `foodking_e2e`, servi depuis worktree pre-cloud-exec, même commit)
**Spec** : `tests/e2e/zz-kds-oss-profond-2026-06-10.spec.js` — **8/8 PASS** (run 3, ~9,7 min, `--retries=0`, serial)
**Verdict global** : **GREEN** — 0 P0 · 0 P1 · 1 P2 · 5 P3. Tous les invariants tenus (NF525 append-only recall, cap 409, pas de doublon, fallback polling 5s, bannière dégradée fail-safe-to-visible).

## Tableau parcours → statut → preuve

| # | Parcours | Statut | Preuve (capture / DB / réseau) |
|---|----------|--------|--------------------------------|
| D1.1 | Commande kiosk fraîche (item 58, Plan-B caisse) | ✅ ×2 cycles | `c1-d1-01-kiosk-confirmation.jpg` (« Rendez-vous en caisse » #A0129, 1,00 €) ; POST /order 201 ; DB `status0=4`, `source_surface=kiosk` |
| D1.2 | KDS carte NOUVELLE + CTA « Démarrer » | ✅ ×2 | `c1-d1-02-kds-nouvelle.jpg` (A0129 NOUVELLE, badge BORNE, EN ATTENTE ENCAISSEMENT) ; CTA text « Démarrer » asserté |
| D1.3 | « Démarrer » → EN COURS persisté serveur | ✅ ×2 | `c1-d1-03-kds-encours.jpg` ; DB `orders.status=7` pollé ; CTA devient « Prêt » |
| D1.4 | OSS « En préparation » | ✅ ×2 | `c1-d1-04-oss-preparation.jpg` (N°A0129 colonne rouge) ; latence 7,2s (c1) / 6,7s (c2) après Démarrer |
| D1.5 | « Prêt » → PREPARED persisté serveur | ✅ ×2 | DB `orders.status=8` pollé |
| D1.6 | Footer « RÉCEMMENT SERVIES » | ✅ ×2 | pill `N°A0129` DOM-asserté + label exact ; **capture élément** `post-01-strip-recemment-servies.jpg` (N°A0130-A0133 + « il y a X min ») |
| D1.7 | DB transitions 4→7→8 | ✅ ×2 | `order_status_transitions` 4485 & 4487 : `1>4` (création), `4>7`, `7>8` — actor_id=4 (chef) sur les bumps |
| D1.8 | OSS « Prêt » | ✅ ×2 | `c1-d1-06-oss-pret.jpg` (N°A0129 colonne verte) ; recall ne déplace PAS la commande (status reste 8) |
| D2.1 | Recall via drawer = POST serveur (fix KDS-OSS-01, pas localStorage-only) | ✅ ×2 | `c1-d2-01-drawer-recall-dispo.jpg` (« ↶ Annuler bump » + compteur 59s) ; **POST `/api/admin/kds-order/recall/{id}` → HTTP 200**, `transition_id` retourné (5448 c1 / 5455 c2) |
| D2.2 | Invariant NF525 append-only | ✅ ×2 | DB : `orders.status` RESTE 8 ; transition `8>8 reason=kitchen_recall actor_id=4` APPEND-ONLY (1 ligne) |
| D2.3 | Badge RAPPELÉ sur carte ré-injectée | ✅ ×2 | `c1-d2-02-kds-badge-rappele.jpg` (badge orange « RAPPELÉ » sur A0129, carte re-live, pill servies exclue — pas de double rendu) |
| D2.4 | Re-recall immédiat → idempotent/cap | ✅ ×2 | POST direct (clé idempotency fraîche) → **HTTP 409** ; count `kitchen_recall` reste 1 (pas de spam) |
| D3 | Drawer historique : ouvrir/lister/capture | ✅ | `d3-01-drawer-historique.jpg` (« Historique du jour (50) », PASSÉE À/TERMINÉE À, items) ; GET `history-today` 200 |
| D3 | Refetch | ✅ (documenté) | PAS de bouton refresh dédié (« Réessayer » = état erreur seulement) ; close/reopen → **2e GET history-today 200 prouvé réseau** ; `d3-02-drawer-refetch.jpg` |
| D4.1 | 2 postes /kds simultanés voient une commande fraîche | ✅ ×2 | contexts chef + clone storageState (pas de relogin → pas de révocation token) ; apparition **simultanée** A/B : c1 ~9,8-10,0s, c2 ~3,6s après POST |
| D4.2 | Bump Démarrer poste A → poste B | ✅ ×2 | **611ms / 610ms** (<6s) ; `c1-d4-05/06`, `c2-d4-05/06` (A0132 EN COURS sur B à 00:04) |
| D4.3 | Bump Prêt poste A → poste B (pill servies) | ✅ ×2 | **405ms / 406ms** (<6s) ; `c1-d4-07/08`, `c2-d4-07/08` ; 0 doublon de carte sur B |
| D5.1 | Kill soketi → bannière dégradée | ✅ | `d5-01-kds-banniere-degradee.jpg` : **« Mode secours actif — actualisation automatique toutes les 5s. » + tag SYNC·LOCAL visible** (KdsStatusBanner V2). NB : la mesure testid `-1ms` est un artefact (testid legacy `kds-sync-mode-banner` ne monte jamais en V2 → P3 infra) |
| D5.2 | Commande kiosk pendant coupure → KDS via polling | ✅ | **2278ms** (≤ cible 6s, cadence 5s) ; `d5-03-kds-carte-via-polling.jpg` (A0133 NOUVELLE, bannière secours active, carte unique) |
| D5.3 | Relance soketi (OBLIGATOIRE) | ✅ | `restartSoketi()` + garde-fou afterAll ; `lsof :6001` LISTEN re-vérifié ; **soketi UP en fin de run** |
| D5.4 | Retour temps réel sans doublon | ✅ | bump A→B propagé **2554ms** post-relance ; 0 doublon ; `d5-04/05` (bannière encore affichée pendant le backoff de reconnexion = comportement fail-safe correct) ; **post-check à +5 min : bannière secours DISPARUE** (`post-02-kds-apres-reconnexion-ws.jpg`) |
| D6 | OSS mur public sans auth | ✅ | route alias `/order-status-screen` (orderStatusScreenRoutes.js:8-11) + redirect `/order-status` (router/index.js:134) + feed public `GET /api/frontend/oss-order` throttle `oss-public` (routes/api.php:1297+, CDSOrderDetailsResource sans PII) ; `d6-01-oss-mur-public.jpg` : **rendu plein écran SANS chrome admin, pas de redirect /login, N°A0131 (cycle 2) visible colonne Prêt** ; cadence poll mur public 5s |
| ZZ | Console / pageerrors / HTTP≥400 | ✅ | **0 pageerror** sur 5 surfaces ; seuls bruits attendus : 409 délibéré (D2), WS `ERR_CONNECTION_REFUSED/RESET :6001` pendant la fenêtre D5 uniquement |

**Cycle 2 complet : OUI** (D1-D4 rejoués intégralement sur commande multi-items 49+56 puis 55 — ordres 4487/A0131 + 4488/A0132). **D5 exécuté 1× (justifié)** : kill soketi est destructif pour les pilotes parallèles W-A/W-B/W-C partageant :8766/:6001 ; le fallback est déterministe (cadence 5s hardcodée `_pollingInterval`, KitchenDisplaySystemComponent.vue:1966-1973) — une 2e passe n'apporterait aucune information nouvelle.

## Mesures de latences (réelles, run 3)

| Mesure | Cycle 1 | Cycle 2 | Cible | Verdict |
|---|---|---|---|---|
| Apparition commande fraîche → KDS (2 postes, sonde +238ms après POST) | ~9,8-10,0s | ~3,6s | — | variable (voir P2 KDS-WD-01) |
| Propagation bump Démarrer poste A→B | **610ms** | **611ms** | <6s | ✅ (run 2 : 1 spike 8895ms sous contention, voir P2) |
| Propagation bump Prêt poste A→B | **405ms** | **406ms** | <6s | ✅ |
| OSS « En préparation » après Démarrer | 7238ms | 6653ms | — | même chaîne queue que KDS-WD-01 |
| OSS « Prêt » | déjà affiché à la sonde (≤ durée recall ~15s) | idem | — | ✅ |
| D5 polling fallback (soketi down) | **2278ms** | 1× | ≤6s | ✅ |
| D5 propagation post-relance | 2554ms | 1× | — | ✅ temps réel restauré |
| D6 mur public affiche commande Prêt | déjà affiché à la sonde | — | poll 5s | ✅ |

## Findings

**P0 — aucun. P1 — aucun.**

- **P2 KDS-WD-01 — Latence d'apparition borne→KDS variable (3,6s → 10s) et spike de propagation 8,9s sous contention.** La carte d'une commande borne fraîche arrive sur le KDS via l'outbox (`domain_events` → `DispatchDomainEventsJob` → broadcast), pipeline partagé avec un worker unique (`queue:work --queue=high,default,broadcasts,notifications`). Mesuré 9,8-10,0s (c1) vs 3,6s (c2) ; en run 2, une propagation de bump a aussi spiké à 8895ms. Caveat : env e2e partagé avec 3 pilotes parallèles (W-A/W-B/W-C) + boucle d'échec FCM (cf. P3) — à re-mesurer isolé avant de durcir ; piste : worker dédié `broadcasts` ou priorité queue.
- **P3 KDS-WD-02 — testid bannière dégradée legacy non monté en V2.** `data-testid="kds-sync-mode-banner"` (KitchenDisplaySystemComponent.vue:77) ne monte jamais en layout V2 ; la vraie bannière est `KdsStatusBanner` (`.kds-banner`, AUCUN data-testid). Mes sondes D5 ont produit des `-1` artefacts ; les captures prouvent la bannière. Reco : ajouter `data-testid="kds-status-banner"` + attribut niveau.
- **P3 KDS-WD-03 — Label « ATTENTE » tronqué** en haut à droite des cartes KDS à 1280px (toutes captures : « ATTENT… »). Cosmétique.
- **P3 KDS-WD-04 — Pas de bouton refresh dédié dans le drawer historique** (fetch au watch-open + « Réessayer » seulement en état erreur). Close/reopen refetch prouvé réseau. Design accepté — documenté.
- **P3 KDS-WD-05 — Bannière permanente « Les pastilles Prêt (bump) … ne se synchronisent pas entre plusieurs écrans KDS » potentiellement trompeuse** : les statuts de commande, eux, se synchronisent serveur en <1s (D4 prouvé). Le message ne vise que les pastilles locales par item. Reformulation/conditionnalité à évaluer.
- **P3 KDS-WD-06 — (env e2e) `SendFcmNotificationJob` FAIL en boucle (~15s)** dans le worker (creds FCM absentes du clone) — bruit + churn worker, peut amplifier KDS-WD-01 dans cet env.

## Notes de méthode
- Worktree dédié, base = même commit que le serveur :8766 (provenance vérifiée via `lsof` cwd → pre-cloud-exec @ 46cb9727e).
- Poste B = clone `storageState` du contexte chef (PAS de 2e login — `LoginController.php:155` révoque les anciens `auth_token` au relogin ; même garde-fou pour le token kiosk partagé avec W-A : création de commande wrappée re-login+retry ≤3).
- Run 1 → fix sonde re-recall (axios baseURL projet inclut `/api/` + middleware `idempotency` exige `X-Idempotency-Key` ; clé fraîche requise pour atteindre le 409 contrôleur). Run 2 → fix atterrissage kiosk Plan-B `/kiosk/cash-instruction` (pas confirmation/waiting) + politique latence honnête (mesure + plafond 20s + finding>6s). Run 3 = 8/8.
- Items utilisés : 49, 52, 54, 55, 56, 58 uniquement (anti-interférence W-C E2E-WC respectée). Purge backlog >10min avant chaque phase KDS + purge finale de courtoisie.
- `results.json` = mesures brutes + 46 entrées console/réseau collectées (0 pageerror).
