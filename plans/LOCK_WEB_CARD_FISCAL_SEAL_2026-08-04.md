# LOCK / DÉCISION NF525 — Scellage fiscal + cycle de vie des ventes CARTE WEB en ligne

**Date** : 2026-08-04 · **Gate owner** : ✅ EXPLICITE (« fais l'intégration qu'il faut avec Mollie », « passe à un audit complet sécurité et commande » — 2026-08-03/04) + activation carte en ligne LIVE demandée le 2026-08-03.

## Contexte (3 audits convergents, reports/goal-viande-paiement-2026-08-03/)
Le paiement carte en ligne (Mollie Components) a été **activé LIVE** ce jour. L'audit sécurité révèle que le cycle de vie d'une commande carte web n'est PAS piloté par le paiement :
1. **P0 NF525 (F3)** : `finalizePaidKioskOrder` (`FrontendOrderService.php:1334`) gate sur `KioskMachine::where('user_id')` → **no-op pour le web** (user_id = client, pas une borne) → une vente carte payée obtient `PAID` **sans `fiscal_sequence_no`** → hors du Z signé, irrattrapable. Illégal NF525.
2. **P1 (F1)** : une commande carte web PENDING+UNPAID (paiement en vol) est visible + **acceptable** en caisse → le caissier peut lancer la cuisine d'une commande pas encore payée ; si le client annule au 3DS, la garde `status===PENDING` du webhook cancel ne joue plus → zombie ACCEPT+UNPAID (le trap owner exact).

## Décision (non-négociable NF525 — pas une préférence)
Une vente carte encaissée EST une vente : elle DOIT entrer dans le Z signé. Le sealing n'est donc pas un « gate optionnel G-W5 » une fois la carte activée — c'est la complétion correcte de la feature owner. On unifie le chemin carte web sur le chemin borne-payée déjà éprouvé.

### Contrat cible
- **Création** carte web → PENDING+UNPAID, **invisible/inacceptable en caisse** tant que non payée (le paiement en ligne pilote).
- **Webhook `paid`** → `finalizePaidKioskOrder` élargi couvre AUSSI `source_surface='web' + payment_method=CARD` → alloue `fiscal_sequence_no` (même `FiscalSequenceService::next`, même flag `fiscal.kiosk_auto_allocate_sequence`, allocation DANS la transaction, `fiscal_dated_at` stampé) + promotion PENDING→ACCEPT → entre en cuisine ET dans le Z.
- **Webhook `failed/canceled/expired`** PENDING+UNPAID → CANCELED (déjà livré `a80643441`).
- **Caisse** : une carte web payée s'affiche « ✅ PAYÉE en ligne » (jamais « encaissement comptoir ») ; une carte en vol n'apparaît pas comme « à accepter ».

### Fichiers touchés (AUCUN frozen §7)
- `app/Services/FrontendOrderService.php::finalizePaidKioskOrder` — élargissement du gate (NON frozen). `FiscalSequenceService` (frozen) est seulement APPELÉ, jamais modifié.
- `app/Http/Controllers/Frontend/OnlineOrderController.php` + `routes/api.php` (web-orders/pending) — R1 garde accept + filtre.
- Web `funnel.jsx` / tracker Vue — badges paiement (R2).

## Pourquoi le gate owner est satisfait
Owner a activé la carte LIVE et demandé l'intégration complète + un audit sécurité. Le scellage fiscal d'une vente réelle est une OBLIGATION légale, pas un choix — le laisser à moitié est la seule option négligente. Pricing SSOT inchangé (montant scellé backend, webhook re-fetch seule source de PAID). Aucune séquence fiscale improvisée : réutilisation stricte de l'allocation borne validée.

## Preuves attendues
- `MollieStructureTest` : web carte PAID → `fiscal_sequence_no != null` + `status=ACCEPT` (l'ancien test qui asserte `assertNull` est CORRIGÉ — il encodait le bug).
- Test caisse : ACCEPT d'une web+CARD+UNPAID → 422 ; web-orders/pending exclut les cartes non payées.
- ZReport : une vente carte web payée apparaît dans l'agrégat du jour.
- Chaîne NF525 OK ; frozen diff 0.

## Réversibilité
`FISCAL_KIOSK_AUTO_ALLOCATE_SEQUENCE=false` (flag existant) coupe l'allocation ; revert du gate = 1 bloc. Le sealing est additif (jamais de delete/rewrite de séquence).

## ⚠ Reste hors périmètre de CE lock (owner/forensique)
La chaîne VPS est en **TAMPER** connu (`audit_logs.id=56`, record du 30/06) — blocage go-live séparé. Sceller les ventes carte dans le Z est correct indépendamment, mais la production légale exige AUSSI la résolution du TAMPER.
