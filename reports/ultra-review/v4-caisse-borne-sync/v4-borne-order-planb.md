# V4 Audit — CIBLE : BORNE (commande + Plan B + preview SSOT)

Slug: `v4-borne-order-planb` · Date: 2026-07-02 · HEAD `61e9ea7b7` + working-tree
Verdict: **GREEN_HELD** — la borne n'a pas pu être cassée sur 10 angles. Aucune P0/P1/P2 reproduite.

## Surface attaquée (token machine réel)
Token minté: `User#1` (branch 0, admin) + `KioskMachine#1` (branch 1, ACTIVE) → ability `kiosk:order`.
Endpoints: `GET /api/frontend/menu`, `POST /api/frontend/order/quote`, `POST /api/frontend/order`, `POST /api/frontend/pricing/preview`. Auth Bearer + `x-api-key`.

## Invariants Plan B confirmés (LIVE, DB lecture)
Commande cash borne (`order_type=10 TAKEAWAY`, `payment_method=1 CASH_ON_DELIVERY`) #5427/#5436/#5438/#5451:
- `status=4 (ACCEPT)` auto-accept, `payment_status=15 (PENDING_COUNTER)`, `pos_payment_method=6 (COUNTER_DEFERRED)`.
- `fiscal_sequence_no=NULL` (alloué SEULEMENT à l'encaissement — cash-trail NF525 respecté).
- `cash_movement=0` à la création (aucun mouvement de caisse tant que non encaissé).
- `source_surface=kiosk`. Apparaît dans `counter-collect/pending` (clause kiosk + KIOSK/TAKEAWAY, `routes/api.php:823-826`), file « à encaisser ». Held green.

## Attaques exécutées → toutes tenues

| # | Angle | Attaque | Résultat |
|---|-------|---------|----------|
| 1 | Correctness | Tacos M viande+sauce → quote | 200, subtotal=total=6.90 (TTC), tax 0.63 |
| 2 | Correctness | Commande Plan B complète | 201 #5427, invariants ci-dessus |
| 3 | Idempotence | Double POST même `X-Idempotency-Key` | 2e = même order id (replay), pas de doublon |
| 4 | Idempotence | Même clé, payload différent | 409 `IDEMPOTENCY_KEY_CONFLICT` |
| 5 | Concurrence | 4 POST parallèles, MÊME quote token, clés distinctes | 1×201 + 3×409 (`lockForUpdate` → quote single-use) |
| 6 | Security | Réutiliser un quote déjà consommé (nouvelle clé) | 409 « already consumed » |
| 7 | Security | Signature bidon | 401 « signature mismatch » |
| 8 | Security | Items falsifiés (variations valides différentes) même token/sig | 401 « intent mismatch » |
| 9 | Security | Quantité falsifiée 1→5 même token/sig | 401 « intent mismatch » |
| 10 | Security | quote_token/signature absents (borne) | 422 required |
| 11 | Failure | Compo incomplète (Tacos sans sauce) | 422 FR « Sélectionnez au moins 1 Sauce » |
| 12 | Failure | Viande 2 manquante (Tacos L multi-viande) | 422 FR « au moins 1 Viande 2 » |
| 13 | Failure | order_type=25 KIOSK (sur place) machine réelle | 422 FR dine-in désactivé V1 |
| 14 | Security | Quantité 100000 | 422 cap 999 (`ValidJsonOrder`) |
| 15 | Security | Quantité négative | 422 « quantité valide » |
| 16 | Security | item_id inexistant 999999 | 422 « introuvable » |
| 17 | Security | Injection cross-item variation (sauce Tacos sur item 22) | 422 |
| 18 | Security | Injection cross-item extra (extra item22 sur item26) | 422 preview + quote |
| 19 | Correctness | Client envoie `total=0.01 subtotal=0.01 discount=99` | 201, serveur recalcule total=6.90 discount=0 (SSOT) |
| 20 | Correctness | Multi-viande valide (Tacos L V1+V2+sauce) | 201 #5449, snapshot fige Viande1=Mexicanos, Viande2=Cordon Bleu, Sauce=Mayonnaise, 7.90 |
| 21 | Data-NF525 | `composition_snapshot` figé à la création | Immuable, schema_version 1, 2 viandes présentes |
| 22 | Correctness | preview extras chiffrés (viande suppl 2.5 + oignons 0.9) | subtotal 10.30 exact |

## Défenses vérifiées par lecture
- **HMAC quote** = `config('app.key')`, `hash_hmac('sha256')`, throw si vide (`OrderQuoteService::hmacKey` L570). `hash_equals` timing-safe (L426,430). Intent bindé à items+modifiers+qté+payment_method+actor.
- **Quote replay** : `resolveReplay` verrouille (`lockForUpdate`), vérifie branch+expiry(410)+signature(401)+intent(401), `consume()` marque `consumed_order_id` → 409 sur réutilisation.
- **sealForCommit** exige token+signature ensemble (401), et `total_ttc == expectedTotal` tolérance 1e-6 (409 sinon).
- **Totaux client** : `unset($validatedRequest['total','subtotal','discount'])` (`FrontendOrderService:271`), delivery_charge=0 forcé si non-DELIVERY (L280).
- **TTL quote** 300s (`config/quote.php`), `isExpired()` → 410 au commit.

## Anomalie mineure NON-cassante (attested)
`PricingPreviewService::projectLines` (`app/Services/Kiosk/PricingPreviewService.php:143`) : en mode TTC (`PRICING_TAX_INCLUSIVE=true`), le champ par-ligne `line_total = lineSubtotalExTax + taxAmount` re-additionne la taxe déjà incluse → 11.24 au lieu de 10.30 pour un article à 10.30. **Impact nul** : le consommateur kiosk `resources/js/helpers/kioskPricingPreview.js:234` ne lit que `total`/`grand_total` (autoritatif, correct=10.30), jamais `line_total`. Donnée morte, non rendue. Classé P3-latent (cosmétique interne API), pas un bug user-facing. À ignorer sauf si un futur consommateur affiche `line_total`.

## Note discipline
Les commandes ont été créées via les endpoints RÉELS (surface d'attaque légitime, DB `foodking_e2e` de test). Aucune écriture DB directe (tinker = LECTURE seule), aucun fichier projet modifié hors ce rapport. Frozen §7 (PricingService, pos-wizard.js, etc.) lus en lecture seule uniquement.

## Conclusion
« La borne est correcte » : **NON réfuté**. Prix 100% backend (SSOT), Plan B fiscalement conforme (pas de seq/cash à la création), anti-tamper/anti-replay/idempotence/concurrence solides, failure-paths propres (422/401/409/410, jamais 500), multi-viande figé correctement. GREEN_HELD.
