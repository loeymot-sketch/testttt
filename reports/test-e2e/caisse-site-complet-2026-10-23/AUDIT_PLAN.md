# Audit E2E « caisse + site » — 2026-10-23

Demande du propriétaire : « passe un test complet de caisse et site web d'intelligence ».
Code testé : branche `pos/category-first-caisse-2026-06-23` @ `c7b6bc907` (= production depuis ce soir).

## Banc (pré-vol vérifié par l'orchestrateur)

| Élément | Valeur |
|---|---|
| Caisse / backend | `http://127.0.0.1:8766` (arbre principal, base MySQL locale `foodking_e2e`) — bundles recompilés (`pos-shell.cdb40ddd.js`, contient « Commande livraison ») |
| Site web | `http://127.0.0.1:8012` = **miroir du site EN LIGNE** (www.lecayenne.fr, fichiers téléchargés ce soir, identiques en empreinte), `api-base-url` et `api-key` rebranchés sur le backend local. ⚠️ Le dépôt local du site N'EST PAS ce qui est en ligne. |
| Temps réel | soketi `:6001` actif |
| Livraison | active en local (`order_setup_delivery = 5`) ; itinéraires OSRM actifs (réseau) |
| Comptes | `pos@lecayenne.fr`, `admin@lecayenne.fr` / `123456` ; livreurs de test (rôle `Delivery Boy`) dont `livreur.e2e@lecayenne.test` |
| Production | JAMAIS touchée : aucune commande, aucun appel vers vps-418872ac / lecayenne.fr API |

## Vagues

- **C1 — Caisse : vente, encaissement, ticket** (`tests/Playwright/caisse-site-complet-2026-10-23/C1-caisse-vente.spec.js`)
- **C2 — Caisse : livraison de bout en bout + écran livreur** (`.../C2-caisse-livraison.spec.js`)
- **S — Site web en ligne (miroir) : parcours client, emporter + livraison** (`.../S-site.spec.js`)

Captures : `reports/test-e2e/caisse-site-complet-2026-10-23/captures-<W>/` (quatuor PNG + DOM + console + réseau, ≤ 15 PNG par vague — disque à 99 %).
Constats adverses : `round-<N>/wave-<W>-findings.json` (schéma `REVIEWER_PROTOCOL.md`).

## Décisions propriétaire EN ATTENTE (ne pas compter comme défauts nouveaux, les noter « connu »)

Vouvoiement (D5), seuil 7 km + devis Uber payé par le client (D3), paiement choisi après confirmation (D6), QR d'avis (D7),
suivi GPS / TPE livreur (D8). Source : `reports/test-e2e/etat-des-lieux-2026-10-23/SYNTHESE.md`.
