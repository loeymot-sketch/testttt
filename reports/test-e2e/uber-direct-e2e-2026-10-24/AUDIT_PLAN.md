# Audit E2E « livraison via livreur Uber Direct » — 2026-10-24

Demande du propriétaire : « active la livraison via livreur Uber Direct … et test-e2e ».

## Fait établi avant l'audit (bloque l'activation RÉELLE, pas le banc)
- Les identifiants Uber du `.env` n'obtiennent que le scope `direct.organizations` ; `eats.deliveries` → `400 invalid_scope` ;
  un devis réel → `401 requires eats.deliveries`. L'organisation « LE CAYENNE » existe (billing ACTIVE), mais le tableau de bord
  direct.uber.com du compte connecté n'a ni onglet Développeur, ni « Nouvelle livraison » actif ; developer.uber.com n'a aucune
  application. Donc : **l'activation réelle dépend d'Uber / du compte administrateur**, pas du code.
- Conséquence : ce banc prouve que TOUTE la chaîne serveur est prête (OAuth → devis → course → webhooks → états de la commande →
  caisse / livreur / site) contre un **faux Uber local fidèle** ; passer au vrai Uber = changer deux URL du `.env`.

## Banc (pré-vol vérifié par l'orchestrateur)
| Élément | Valeur |
|---|---|
| Backend + caisse | `http://127.0.0.1:8766` (arbre principal, MySQL `foodking_e2e`, bundles recompilés, `php -S` → `.env` relu à chaque requête) |
| Faux Uber | `http://127.0.0.1:8790` = `tests/e2e/helpers/uber-direct-stub.js` (lancé ; journal `GET /__control/log`, pilotage `/__control/*`) |
| `.env` (sauvegarde `/private/tmp/claude-501/env.avant-uber-e2e`) | `UBER_DIRECT_ENABLED=true`, `UBER_DIRECT_TOKEN_URL=http://127.0.0.1:8790/oauth/v2/token`, `UBER_DIRECT_API_BASE=http://127.0.0.1:8790`, `UBER_DIRECT_WEBHOOK_SECRET=e2e-uber-direct-secret` |
| Vérifié | `UberDirectClient::isConfigured()=true`, jeton obtenu du faux Uber, devis réel `dqt_1` 7,42 € / 25 min (journal du faux Uber : `POST /oauth/v2/token`, `POST /v1/customers/…/delivery_quotes`) |
| Site | copie corrigée servie sur `http://127.0.0.1:8013` (metas api → :8766) — pour le SUIVI client d'une commande livrée par Uber |
| Temps réel | soketi `:6001` actif |
| Comptes | caisse `pos@lecayenne.fr` / `123456` ; admin `admin@lecayenne.fr` / `123456` ; livreur de test : voir `tests/Playwright/caisse-site-complet-2026-10-23/C2-caisse-livraison.spec.js` (const `COURIER`) ; client : compte invité créé par l'API (code OTP lu en base, cf. `site-livraison.spec.js`) |
| Production / vrai Uber | JAMAIS : `vps-418872ac`, `lecayenne.fr`, `auth.uber.com`, `api.uber.com` coupés dans le navigateur ; le journal du faux Uber doit montrer que c'est LUI que le backend appelle |

## Vague U — Uber Direct de bout en bout (spec `tests/Playwright/uber-direct-e2e-2026-10-24/U-uber-direct.spec.js`)
Données : commandes de LIVRAISON créées par l'API caisse comme dans `tests/Playwright/e2e-complet-2026-10-22/B-livraison-tournees-uber-r2.spec.js`
(B-L2 : adresse via `pos-delivery/address`, devis `admin/pos/quote`, `posOrder/save`) ; client = compte invité avec téléphone valide
(Uber exige un téléphone) ; note « E2E-UBER-… » ; annuler les commandes de test en fin de passe (statut 16).

| # | Scénario | Preuves attendues |
|---|---|---|
| U-1 | Planche « Tournées » avec Uber disponible | bouton « Confier à Uber » actif sur un arrêt PAYÉ ; `board` dit disponible |
| U-2 | Livraison PAYÉE (comptoir, espèces) → « Confier à Uber » → prix 7,42 € / 25 min → « Confirmer Uber » | journal faux Uber : `delivery_quotes` puis `deliveries` avec `quote_id`, `idempotency_key=lecayenne-order-{id}`, `external_id`, adresses JSON (437 Rue Élie Gruyelle / adresse client), téléphones E.164 (+33…), `manifest_items`, `pickup_ready_dt` ; `uber_direct_deliveries` : `delivery_id`, `status=pending`, `tracking_url` ; arrêt `delivery_boy_id=null` ; planche « 🚗 Uber » ; écran livreur `/livreur` ne liste plus l'arrêt |
| U-3 | Webhooks SIGNÉS `pickup` → `pickup_complete` → `delivered` | commande 10 (EN ROUTE) puis 13 (LIVRÉE) ; tableau « Suivi commandes » colonne En livraison puis Livrés ; suivi client sur le site : « En route » puis « Livrée » ; `audit_logs` `delivery.uber_dispatched` ; chaîne NF525 OK après |
| U-4 | 2ᵉ livraison payée : Confier → Confirmer → « Nos livreurs » avant la prise du sac | faux Uber reçoit `cancel` ; ligne `canceled` ; arrêt rendu (livreur assignable, visible sur `/livreur`) |
| U-5 | 3ᵉ livraison : dispatch → webhook `pickup_complete` → « Nos livreurs » | refus « Le coursier Uber a déjà la commande » ; aucune annulation envoyée |
| U-6 | « Commande livraison » payée à la PORTE | bouton grisé avec la raison « À encaisser à la porte… » ; AUCUN appel au faux Uber |
| U-7 | Pannes : `mode quote=uncovered` → message « Uber n'a pas donné de prix… reste à nos livreurs » ; `mode create=fail` → « Uber n'a pas confirmé… » ; double appui « Confirmer » → 1 seule `deliveries` au journal ; devis périmé (`UPDATE uber_direct_deliveries SET quote_expires_at = NOW()-INTERVAL 1 MINUTE`) → nouveau prix à confirmer, pas de course |
| U-8 | Sécurité / robustesse webhook : signature fausse → 401 et rien ne change ; `pickup` rejoué APRÈS `pickup_complete` → pas de recul ; `canceled` par Uber avant prise du sac → arrêt rendu à nos livreurs ; `returned` après prise du sac → alerte rouge sur la planche, commande non livrée |
| U-9 | Annulation de la commande à la caisse pendant une course active (avant sac) | `cancel` reçu par le faux Uber AVANT la disparition de l'arrêt |

Captures (quatuor PNG+DOM+console+réseau via `tests/e2e/helpers/mega-audit-snap.js`, ≤ 15 PNG) dans
`reports/test-e2e/uber-direct-e2e-2026-10-24/captures-U/` : planche avant / prix à confirmer / confié / en route / livrée / rendu /
grisé porte / panne devis / alerte rouge ; suivi site en route + livrée ; écran livreur sans l'arrêt ; 1280×720 et 1920×1080 de la planche.
Constats adverses : `round-<N>/wave-U-findings.json` (schéma `REVIEWER_PROTOCOL.md`).

## Hors périmètre (déjà décidé / connu)
D3 « ≥ 7 km : devis Uber payé par le client » non construit (le client paie notre barème, Uber = coût du restaurant, `docs/LIVRAISON.md §6`) ;
pas de webhook réel ; activation réelle = propriétaire / Uber (voir BILAN).
