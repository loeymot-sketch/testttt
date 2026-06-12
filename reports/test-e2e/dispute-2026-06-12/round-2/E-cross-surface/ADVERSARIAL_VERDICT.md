# ADVERSARIAL VERDICT — Vague E cross-surface (Round 2 post-heal, dispute-2026-06-12)

Superviseur adversarial R2 de mauvaise humeur. L'agent capture R2 est MORT sans WAVE_REPORT
(151 artefacts orphelins). Ce verdict (1) reconstruit la couverture depuis les logs/JSON/PNG,
(2) recalcule l'intégrité en DB, (3) complète les vérifs cross-surface EN LIVE (scripts
`tests/e2e/_d2red-E-cross-surface-verify.mjs` + extraction `#print`), (4) juge les heals
H1/H2/H3 + wire-up. Je ne corrige RIEN.

## Statut: COMPLET — verdict final rendu

## Verdict global: **YELLOW** — 0 P0 / 1 P1 / 1 P2 / 0 P3 NOUVEAUX
Les 2 P0 et 3 des 4 P1 du Round 1 sont **FERMÉS et re-prouvés** (promo facturée, vue caisse
unifiée voit les ventes POS, identité « Client borne », refund espèces, order_payments mono-mode,
copie « espèces uniquement » corrigée). UN nouveau défaut survit : le **rachat fidélité borne
reste silencieusement perdu pour tout client status=5** (seed/caisse) — le heal C-RED-02 ne
marche que pour les clients inscrits via la borne (status=1). Le sous-total receipt mélange
deux bases HT (P2).

---

## 1. RECONSTRUCTION DE LA COUVERTURE R2 (depuis _log-*.txt + JSON + PNG)

| Run | Trace | Résultat |
|---|---|---|
| E10 | Borne: Tacos+Coca 10,00 € → promo BORNEAUDIT5 −5,00 → commande | Order **4516** total 5,00 (cart 5,00 / payment 5,00 / cash-instruction 5,00) — **cohérent** (vs R1 0,00→1,50) |
| E11 | Borne: même panier + fidélité VICT1234 (165 pts=1,65 €) stacké promo | Order **4520** : cart 3,35 / payment « 3,35 € » → **cash-instruction 5,00 € / DB total 5,00** — fidélité droppée (E2-P1-1) |
| E12 | Probes replay/tamper quote | **INVALIDE** — 3/3 réponses 405 = bug du script (mauvaise route `tend/` au lieu de `frontend/`). REFAIT live (§3) |
| E20 | Caisse: encaissement 4520 carte SumUp, show/historique/tracker/cash-overview/transactions/KDS | Montants **5,00 € identiques sur TOUTES les surfaces** ; « Client borne » partout ; transactions COUNTER-4520 carte +5,00 |
| E21/E22 | KDS probe + sniff API | feed 200, 14 cmd, 4520 présent (status 7/pay 5), écran 8 slots → overflow badge « EN ATTENTE ENCAISSEMENT » |
| E30 | POS direct: Tiramisu+Eau 4,80 € espèces 5,00 | Order **4530** 201, rendu 0,20, receipt HT 4,36+TVA 0,44=4,80, NF525 2176, « Prix HT »/« SOUS-TOTAL HT » ✓ |
| E40 | Encaisse 4516 espèces puis REFUND | Refund modal « Espèces » ; transactions TXN −5,00 **Espèces** ; **wallet admin 2,00 € INCHANGÉ** |
| E50 | Facture 4520 | Champs fiscaux vides en capture (artefact timing) — **re-extraits live OK** (NF525 2171, empreinte 3fb09271f811) |

Quartet DOM/console/network présent (~37 états, DOM exploitable en R2 — **pas de P1 process**).
Seul bruit console : `OTS parsing error: invalid sfntVersion` (parse woff asset = bruit tiers,
allowlist, non compté).

## 2. RECALCULS INTÉGRITÉ DU SUPERVISEUR (DB foodking_e2e directe)

| Vérif | Recalcul | Verdict |
|---|---|---|
| 4516 promo | 10,00−5,00=5,00 ; DB discount=5/total=5 ; uses_count consommé | ✓ |
| 4520 affiché vs facturé | promesse 10−5−1,65=3,35 ; DB total=**5,00** ; `loyalty_transactions(4520)`=**0 row** ; user44 points INTACTS | ✗ **P1** (E2-P1-1) |
| 4520 TVA receipt | gross 0,91 (DB) → netted 0,91×(5/10)=0,455→**0,46** = receipt — convention F1 | ✓ |
| 4530 POS | 3,45+0,91=4,36 HT ; +0,44 = 4,80 ; rendu 0,20 | ✓ |
| ADV-B-07 montants 4520 cross-surface | encaissement 5,00 = modal 5,00 = show 5,00 = historique 5,00 = cash-overview BORNE +5,00 (22,94+5,00=27,94, delta grand total ✓) = transactions +5,00 | ✓ **FERMÉ** |
| E-ADV-9 order_payments mono | 4516 mode=1/5,00/tend 5 ; 4520 mode=2/5,00 ; 4530 mode=1/4,80/tend 5/rendu 0,20 — **1 row chacun** | ✓ **FERMÉ** |
| B-R1-15 refund | `transactions` cash_back payment_method=**counter_cash** (4516) ; `users.balance` admin=**2,00 inchangé** | ✓ **FERMÉ** |
| E-ADV-2 POS visibles | POS-4530/POS-4531/POS-4528(Split) dans `transactions` ; cash-overview CAISSE inclut ventes directes | ✓ **FERMÉ** |
| E-ADV-3 identité | orders 4516/4520 user_id=1 mais `customer_name`=« Client borne » sur encaissement+historique+tracker+show | ✓ **FERMÉ** |

## 3. VÉRIFS LIVE SUPERVISEUR (`_d2red-E-cross-surface-verify.mjs`, route `frontend/`, header `x-api-key`)

Probes corrigées (la route réelle est `/api/frontend/order[/quote]` — le `tend/` du script mort
était un faux-ami pour « fron**tend** ») :

- **P4 — DROP FIDÉLITÉ PROUVÉ LIVE** : `POST /api/frontend/order/quote` avec
  `loyalty_code=VICT1234` (status=5) + `loyalty_redeem_discount=1.65` + `kiosk_promo_code=BORNEAUDIT5`
  → **HTTP 200, `discount: 5`** (promo SEULE — la fidélité 1,65 € N'EST PAS appliquée ; attendu
  6,65). Reproduit le défaut sans aucune mutation.
- **P2 — anti-replay tient** : replay du quote consommé de 4520 → **410 « Order quote expired »**
  (rejet propre, pas de double-création).
- **P3b — anti-tamper tient (heal A-RED-1 confirmé)** : quote frais (200) puis order avec items
  altérés (qty 1→2) → **409 « Order quote intent mismatch »** (et non 401/logout). Le fix H1 Fix 1
  est live.
- **Facture 4520 re-extraite** (`#print` node) : SIRET/TVA intra/Opérateur/NF525 2171/empreinte
  audit **présents** → l'E50 « champs vides » était un artefact de timing de capture, **pas** un défaut.

---

## 4. FINDINGS

### E2-P1-1 — **P1 NOUVEAU/SURVIVANT (famille C-RED-02)** — Rachat fidélité borne silencieusement perdu pour tout client status=5 : promis 3,35 €, facturé 5,00 €, motif d'échec FAUX
- **Catégorie** : numeric_integrity (#11) + silent-ish business failure (disclosé mais trompeur).
- **Preuve live (order 4520, production conditions)** : panier « Réduction fidélité −1,65 € / Total 3,35 € »
  (E11-02), écran paiement « TOTAL À RÉGLER : 3,35 € » (E11-03) ; requêtes quote+order portaient
  `loyalty_code=VICT1234` ET `loyalty_redeem_discount=1.65` (wire-up H2/orchestrateur OK) ;
  **réponse quote `discount: 5`** (promo seule) ; DB orders 4520 discount=5/total=5 ; 0 row
  `loyalty_transactions` pour 4520 ; points user44 non débités. La borne affiche post-commit un toast
  jaune « Votre réduction fidélité n'a pas pu être appliquée (**points insuffisants au moment du
  paiement**) » (E11-04) — **le motif est FAUX** : l'utilisateur avait 165 points (≥ les 165 requis).
- **Re-preuve live isolée (P4 ci-dessus)** : quote avec exactement ces champs → `discount: 5`.
- **Root cause re-greppée (verify-before-report)** :
  - `app/Services/Order/OrderQuoteService.php:272` → lookup `User::where('loyalty_code',…)->where('status', 1)` (quote) ;
  - `app/Services/FrontendOrderService.php:936` → même `->where('status', 1)` (order-side miroir) ;
  - or `app/Enums/Status.php` : `ACTIVE = 5`. Victim Secret (id 44) = **status 5** (ACTIVE) → le lookup
    `status=1` renvoie `null` → `withKioskLoyaltyDiscount` retourne `$pricing` inchangé (loyalty droppée).
  - **Incohérence de gate prouvée** : `app/Http/Controllers/Frontend/LoyaltyController.php:100-102`
    accepte « BOTH legacy status 1 AND Status::ACTIVE (5) » (commentaire [SUPERVISOR-AUDIT 2026-06-06]
    contre le même bug) → l'endpoint `/loyalty/check` a bien renvoyé 200 + « 165 points = 1,65 € »
    (E11-01) pour ce client status=5. La borne MONTRE le solde rachetable, AUTORISE l'application,
    affiche −1,65 € au panier et à l'écran de paiement, puis le **quote/order le laisse tomber** car
    son lookup utilise `status=1`-only. `LoyaltyService` lui-même utilise `Status::ACTIVE` (lignes 41,162).
- **Confirmation par contre-épreuve (vague C, C12)** : l'agent capture C a dû MUTER user44.status 5→1
  avant son flux pour que le rachat passe (order 4532 stacke 5,00+1,65=6,65, points débités −165,
  ledger écrit, promo consommée) puis RESTORE status 5 (`round-2/C-borne-edge/_c12-log.txt`). Le
  mécanisme H1 ne marche QUE sur une DB mutée à la main.
- **Portée** : tout client fidélité créé par seed ou en caisse est status=5 → fidélité borne non
  fonctionnelle pour eux. Seuls les clients inscrits via la borne (`LoyaltyController.php:185` crée
  status=1) passent → défaillance PARTIELLE et silencieuse = la plus traître à diagnostiquer.
- **Pourquoi P1 et non P0** : l'échec EST disclosé (toast jaune + l'écran cash-instruction montre
  le vrai 5,00 € AVANT que le client paie au comptoir — il paie 5,00 en connaissance), la chaîne
  fiscale est cohérente avec le montant réellement facturé, aucun trop-perçu silencieux. Mais c'est
  une fonctionnalité monétaire cassée pour la majorité des clients + un message d'erreur faux + une
  surprise tarifaire borne (3,35→5,00). User-visible → P1.
- **Recommandation** : aligner les 2 lookups sur `Status::ACTIVE` (ou `whereIn('status',[1,5])`)
  comme `LoyaltyController`/`LoyaltyService`, et corriger le motif du toast (la cause n'est pas
  « points insuffisants »).

### E2-P2-1 — **P2 NOUVEAU** — Receipt commande remisée : prix de ligne en HT BRUT mais « Sous-total » en HT NET → deux bases sur le même ticket, ligne « Remise » non soustractible
- **Catégorie** : numeric_integrity presentation (#11, sévérité P2 disclosure).
- **Evidence (facture 4520 re-extraite live `_E-RED2-facture-4520.txt` + E50)** :
  - lignes : Tacos **7,73 €** (=8,50/1,1 HT brut) + Coca **1,36 €** (=1,50/1,1) → Σ **9,09 €** ;
  - bloc totaux : **Sous-total : 4,54 €** (= HT NET post-remise, 5,00/1,1), Total taxes 0,46 €,
    **Remise : 5,00 €**, Total 5,00 €.
- **Problème** : le sous-total (4,54) est déjà NET de la remise, alors que les lignes au-dessus
  (Σ 9,09) sont BRUTES. Un lecteur fait « Sous-total 4,54 − Remise 5,00 » = négatif, ou s'attend à
  un sous-total ≈ 9,09. Sur un document fiscal, mélanger HT-brut (lignes) et HT-net (sous-total) +
  une ligne « Remise » non soustractible est ambigu. Le TOTAL (5,00) est correct et concorde avec
  toutes les surfaces — donc P2 disclosure, pas P0.
- **Note** : c'est la convention de netting NF525 (F1) appliquée au sous-total ; elle n'apparaît
  que sur les commandes remisées (la promo healée vient d'activer ce chemin sur la borne). Composant
  `PosOrderReceiptComponent.vue` (admin, non frozen). À cadrer : présenter le sous-total BRUT et
  laisser la « Remise » faire le travail, ou supprimer la ligne « Remise » redondante.

---

## 5. CONVERGENCE DES FINDINGS R1 (chaque finding de ma vague)

| R1 ID | R1 sev | R2 statut | Preuve |
|---|---|---|---|
| E-ADV-1 promo borne non persistée | P0 | **FERMÉ** | 4516 cart/payment/cash-instruction/DB = 5,00 ; promo uses_count consommé ; P4 quote `discount:5` |
| E-ADV-2 vue caisse / transactions excluent POS | P0 | **FERMÉ** | POS-4530/4531/4528 dans `transactions` ; cash-overview CAISSE inclut ventes directes |
| E-ADV-3 identité « Admin Le Cayenne » + wallet refund | P1 | **FERMÉ** | « Client borne » sur encaissement+historique+tracker+show ; wallet admin 2,00 inchangé après refund 4516 |
| E-ADV-4 N° file dupliqués sans date | P1 | **FERMÉ** | E20-01 : dayBadge « 10/06 » sur les A0009/A0011 d'hier, null sur ceux du jour |
| E-ADV-5 refund « Carte bancaire » | P1 | **FERMÉ** | E40 : TXN −5,00 mode **Espèces** ; modal refund « Espèces » |
| E-ADV-6 « espèces uniquement à la caisse » | P1 | **FERMÉ** | E10/E11 cash-instruction : « Réglez à la caisse — espèces, carte ou ticket restaurant. » |
| E-ADV-7 mauvaise session réconciliation | P2 | **SURVIVANT (gate connu)** | multi-sessions ouvertes (19/20/22/23) ; panneau pointe 1 session — H3 SKIPPED backend/owner. Non re-compté (gate « réconciliation multi-session ») |
| E-ADV-8 401 encaissement sans message | P2 | **CONFIRMÉ-par-code** | H3 commit `6d038372d` ajoute toast FR explicite ; non re-déclenché live (token non révoqué cette passe) |
| E-ADV-9 order_payments vide → ventilation Z | P2 | **FERMÉ** | order_payments 1 row/order pour 4516/4520/4530 |
| E-ADV-10 KDS HH:MM ambiguë | P3 | inchangé (gate/cosmétique) | — |
| E-ADV-11 politique KDS inter-branches | P3/arbitrage | inchangé | — |
| **E2-P1-1** fidélité status=5 droppée | — | **NOUVEAU P1** | quote `discount:5` au lieu de 6,65 ; lookup `status=1`-only |
| **E2-P2-1** receipt bases HT mixtes | — | **NOUVEAU P2** | facture 4520 lignes 9,09 brut vs sous-total 4,54 net |

---

## 6. VERDICTS DES HEALS (périmètre cross-surface E)

| Heal | ID | Verdict | Preuve |
|---|---|---|---|
| H1 Fix 2 | C-RED-01 / E-ADV-1 (promo facturée) | **CONFIRMED** | 4516 facturé 5,00 = promesse ; uses_count consommé ; quote P4 `discount:5` |
| H1 Fix 3 | C-RED-02 (fidélité borne) | **PARTIAL** | marche status=1 uniquement ; status=5 (seed/caisse) silencieusement droppé (E2-P1-1), prouvé live + DB + contre-épreuve C12 |
| Wire-up | loyalty_redeem_discount (kioskCart.js) | **CONFIRMED** | payload quote+order porte `loyalty_redeem_discount:1.65` (E11/_E11-order.json) — le wire-up est posé ; l'échec est backend (status filter) |
| H1 Fix 4 | ADV-B-07 / E-ADV-2 (vue caisse unifiée) | **CONFIRMED** | POS-* dans transactions ; cash-overview voit les ventes directes ; montants cross-surface 4520 identiques |
| H1 Fix 5 | B-R1-15 / E-ADV-5 (mode refund réel) | **CONFIRMED** | refund 4516 → cash_back counter_cash ; transactions « Espèces » |
| H1 Fix 8 | E-ADV-3 (identité + wallet) | **CONFIRMED** | « Client borne » cross-surface ; wallet admin 2,00 inchangé après refund |
| H1 Fix 10 | E-ADV-9 (order_payments mono) | **CONFIRMED** | 1 row/order, mode+montant+tendered/rendu exacts |
| H1 Fix 1 | A-RED-1 (quote intent → 401/logout) | **CONFIRMED** | tamper items live → 409 (et non 401/logout) |
| H2 Fix 6 | C-ADV-06 / E-ADV-6 (copie cash-instruction) | **CONFIRMED** | « espèces, carte ou ticket restaurant » FR sur E10/E11 |
| H3 | B-R1-04 / E-ADV-4 (badge date file) | **CONFIRMED** | dayBadge « 10/06 » sur les zombies, null sur le jour |
| H3 | E-ADV-8 (401 feedback) | **CONFIRMED (code)** | toast FR ajouté `6d038372d` ; non re-déclenché live |

---

## 7. NOTES HARNESS (contexte, pas des findings)
- Le script de l'agent mort tapait `/api/tend/order` → 405 systématiques (le préfixe réel est
  `frontend`, et le middleware `apiKey` exige `x-api-key`=`config('app.api_key')`=`MIX_API_KEY`).
  Mes probes corrigées (`_d2red-E-cross-surface-verify.mjs`) passent et prouvent le drop fidélité.
- DB partagée : sessions multiples (19/20/22/23), commandes 4527+/4531+ créées par d'autres vagues.
  Tous mes recoupements décisifs sont par order_id en DB.
- Champs fiscaux receipt « vides » de l'E50 = timing de capture (textContent avant hydratation) —
  réfutés par extraction live.
- Artefacts superviseur ajoutés : `E-RED2-01-kiosk-probes.*`, `E-RED2-02-facture-4520.*`,
  `E-RED2-03-print-node-4520.png`, `_E-RED2-facture-4520.txt`, `_log-E-RED2-verify.txt`,
  script `tests/e2e/_d2red-E-cross-surface-verify.mjs`.

## 8. SYNTHÈSE

| Sévérité | Count R2 (nouveaux/survivants) | IDs |
|---|---|---|
| **P0** | **0** | (2 P0 R1 FERMÉS) |
| **P1** | **1** | E2-P1-1 (fidélité status=5 droppée — heal C-RED-02 PARTIAL) |
| P2 | 1 | E2-P2-1 (receipt bases HT mixtes) |
| P3 | 0 | — |

**Verdict vague E R2 : YELLOW.** Énorme progrès — les 2 P0 monétaires et 3 P1 du Round 1 sont
fermés et re-prouvés en DB + live. Un P1 survit : le rachat fidélité borne est mort en silence pour
tout client status=5 (la majorité), le heal C-RED-02 n'ayant réparé que la moitié du chemin (le
même filtre `status=1` que `LoyaltyController` avait déjà corrigé ailleurs a été ré-introduit). P1
loop-blocking jusqu'à l'alignement des lookups sur `Status::ACTIVE`.
