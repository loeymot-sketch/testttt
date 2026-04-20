# VERIFY-02 — P2 Multi-tender (ticket-restaurant + extension future)

**Date :** 2026-04-20  **Origine :** P2 (commit `a43c5b9e2`)  **Priorité :** P1  **Mode :** AUDIT-ONLY

## 1. Contexte
P2 a ajouté `PosPaymentMethod::TICKET_RESTAURANT`, rendu la note obligatoire pour TR, ajouté traductions et un test. La vision multi-tender va plus loin (split bill, paiement partiel, encaissement mixte cash + carte + TR + bon). Il faut prouver que :
- TR est isolé (pas de fuite dans les flux carte/cash).
- Aucune logique n'écrit le total fiscal ou ne déclenche un Z avec un type de tender mal mappé.
- Le sol multi-tender futur (`order_payments`) n'est pas en contradiction avec ce qui est posé.

## 2. Sources OBLIGATOIRES
- `app/Enums/PosPaymentMethod.php` (et énumérations associées)
- `app/Http/Requests/PosOrderRequest.php`
- `app/Services/PaymentService.php`, `app/Services/OrderService.php` (méthodes `changePaymentStatus`, `posOrderStore`)
- Controllers : `Admin/PosOrderController.php`
- Front : `resources/js/components/admin/pos/PaymentComponent.vue`, `PosComponent.vue`
- i18n : `lang/*/pos_payment_method.php`, `resources/js/languages/*.json`
- Tests : `tests/Feature/PosTicketRestaurantPaymentTest.php`, autres tests payment
- Audits : `reports/review/AUDIT_POS_110_PAYMENTS_REFUND_2026-04-19.md`, `..._FISCAL_NF525_*`

## 3. Hypothèses à challenger
- H1 : TR contourne le contrôle de change (rendu monnaie) ou crée un avoir injustifié.
- H2 : TR est mal mappé dans le ticket fiscal / Z report.
- H3 : Multi-tender futur cassera la signature de `posOrderStore` (changement breaking non documenté).
- H4 : Front autorise la note TR vide via un chemin alternatif.

## 4. Plan multi-agent
1. **Explore A** : back-end — recense tous les usages de `pos_payment_method`, mapping vers fiscal, persistance.
2. **Explore B** : front — tous les chemins UI qui sélectionnent un tender, valident la note, postent au back.
3. **GeneralPurpose** : synthèse, table de mapping tender → fiscal → audit log → reçu, et liste des risques pour l'évolution multi-tender.

## 5. Vérifications obligatoires
- [ ] V1 : Note TR obligatoire validée côté back (422) **et** côté front (bouton désactivé tant que vide).
- [ ] V2 : TR n'apparaît pas comme "carte" dans `order.payment_method` après commit.
- [ ] V3 : Le reçu / impression affiche correctement TR (clé i18n présente FR + EN au minimum).
- [ ] V4 : Un Z report sur une journée avec TR additionne correctement (ou note explicitement la limite si non implémenté).
- [ ] V5 : Aucun test legacy ne casse à cause de la nouvelle valeur d'enum (recherche usages `match`/`switch` exhaustifs).
- [ ] V6 : Le plan multi-tender (`plans/PLAN_P2_MULTI_TENDER_HANDOFF.md`) liste explicitement la prochaine étape `order_payments`.
- [ ] V7 : Audit log écrit le tender utilisé (clé `payment_method`) — vérifier `AuditLogService`.

## 6. Critères d'acceptation
- ALL_GREEN si V1–V7 prouvés.
- WARN si V4 limité à TR seul (Z partial), avec ticket P.
- FAIL si TR mal mappé fiscalement ou note contournable.

## 7. Livrables
- `reports/review/VERIFY_02_P2_MULTI_TENDER_2026-04-20.md` (matrice tender × surface, findings, cycles P proposés).

## 8. Suite
Si FAIL : créer `P11_TR_FISCAL_FIX` ou `P11_ORDER_PAYMENTS_FOUNDATION`.

---

### PROMPT À COLLER
```
Tu es l'orchestrateur d'un cycle AUDIT-ONLY.
Lis: tasks/verify-2026-04-20/02_VERIFY_P2_MULTI_TENDER.md et applique §4-§7.

OBLIGATOIRE:
- 2 subagents `explore` en parallèle (A back, B front) selon §4.
- 1 subagent `generalPurpose` ensuite pour synthèse + matrice tender×fiscal×audit log×reçu.
- Aucune écriture de code.
- Livrable: reports/review/VERIFY_02_P2_MULTI_TENDER_2026-04-20.md

Avant de démarrer, donne en 5 lignes ton plan d'attaque (subagents, fichiers ciblés, ordre).
Termine par "GLOBAL: ALL_GREEN | WARN | FAIL" + liste cycles P proposés.
```
