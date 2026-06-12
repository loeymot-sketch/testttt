# DISPUTE Round 2 — VAGUE A : CAISSE vente & encaissement (post-heal, vérif des heals H1/H3)

- Date : 2026-06-12 · App : http://127.0.0.1:8768 (DB foodking_e2e jetable, bundles rebuildés ~32 heals)
- Compte : pos@lecayenne.fr (caissier) · Viewport principal 1440×900 · re-capture 1366×768
- Agent : GSTACK MAIN TEAM Round 2 — capture + exercice des heals ; sévérité = adversaire
- Quartet par état : PNG + `#app` outerHTML (corrigé R2, slice -120KB) + console + network ≥400
- FROZEN observés sans édition : PaymentComponent.vue, PosV5TrancheRow.vue, public/js/pos-wizard.js
- Disque : 3,2 Gi libres / 460 (79%) — OK, plus l'incident ENOSPC de R1.

## Produits (DB foodking_e2e réelle, vérifiés items table)
| id | nom | prix DB |
|----|-----|---------|
| 52 | Coca-Cola 33cl | 1,50 € |
| 26 | Tacos | 8,50 € |
| 51 | Tiramisu | 3,80 € |

---

## 1. HEALS EXERCÉS — VERDICTS

### ✅ A-RED-1 + A-RED-2 [P0+P1] — remise manuelle 10% → encaissement → 401 logout + panier perdu — **HEAL CONFIRMÉ**
Heal H1 Fix1 (`9b4cb6af3`). Exercé **2 fois** (stabilité) :
- **Run A** : Tiramisu 3,80 € → remise 10 % + motif → panier **3,42 €** (remise −0,38 €) → Espèces 10 reçu → `POST /api/admin/pos` **201**, order **4512** / serial 1206264512, total 3.42, discount 0.38. **Pas de 401, pas de logout, panier intact**, receipt rendu.
- **Run B** : identique → **201**, order **4514** / 1206264514, total 3.42, discount 0.38. Toast « Nouvelle commande #4513 ».
- Réseau ≥400 sur les deux runs : **AUCUN**. URL reste `/admin/pos`, header « Caissier Le Cayenne » toujours présent.
- Receipt : « REMISE: 0,38 € », « TOTAL: 3,42 € », « Espèces: 10,00 € », « Rendu : 6,58 € », « Opérateur: Caissier Le Cayenne », NF525 2167/2168.
- Artefacts : `hA-01..04`, `hB-01..04`. → **Le défaut R1 (401 « Order quote intent mismatch » → logout → panier perdu) n'est PLUS reproductible.**

### ✅ Anti-tamper toujours strict (non-régression du heal) — **CONFIRMÉ**
Script `_d2-a-02-tamper.mjs` : interception `page.route` du POST order, `items[0].quantity` **1 → 5** (champ liant du quote).
- Réponse **HTTP 409** `{"status":false,"message":"Order quote intent mismatch."}` — **pas de 401, pas de logout** (url `/admin/pos`), **panier conservé** (Coca 1,50 € intact après fermeture modal).
- Défense en profondeur observée en amont : tamper `discount=1.4` sans motif → **422 motif requis** ; avec motif → **422 « Discount above 10% requires manager approval »**. Le check d'intégrité d'intention (409) ne se déclenche qu'une fois les gardes remise franchies — chaînage correct.
- Artefacts : `t01-tamper-rejected`, `t02-cart-after-tamper`. → **Le fix restaure le flux légitime SANS affaiblir l'anti-tamper.**

### ✅ A-RED-7 [P2] — « Poulet mariné ,Sauce » espace avant virgule — **HEAL CONFIRMÉ** (preuve DOM)
Heal H3 (`2e901994e`). Tacos composé (Poulet mariné + Algérienne) → receipt.
- DOM rendu (`card04-receipt.dom.html`) : `…Poulet mariné<span>, </span></span><span>Sauce (1ère Gratuite): …Algérienne` — **séparateur collé à l'interpolation, aucun espace AVANT la virgule, espace APRÈS**. Idem ticket cuisine `<span> · </span>`.
- `spaceBeforeComma=false` (sonde innerText). NB : l'innerText brut affiche « mariné,Sauce » uniquement parce que l'espace tombe sur un retour-ligne souple (`max-w-[200px]`) — la source rend bien « mariné, Sauce » (preuve DOM ci-dessus). **Pas de nouvelle anomalie.**

### ✅ A-RED-6 [P2] — Ticket « Prix » / « SOUS-TOTAL » HT sans marqueur — **HEAL CONFIRMÉ**
Tous les receipts R2 portent désormais **« Prix HT »** (en-tête colonne) + **« SOUS-TOTAL HT: »**, le TOTAL (TTC payé) restant sans marqueur. Conforme au fix H3 (`a56ef8ee8`).

### ✅ Identité opérateur + source canal — **INTACTS**
- Tous les receipts : « Opérateur: **Caissier Le Cayenne** » (jamais « Client passage »).
- `admin/pos-order/show` : les 4 commandes R2 → **source=15** (POS forcé server-side, heal Fix7 `d71131352`) + payment_status=5 (PAID).

### ⚠️ A-RED-12 [P2] — CTA modal paiement POS sous la ligne de flottaison 1366×768 — **GATE PERSISTE (frozen, constat)**
`_d2-a-07-1366.mjs` (1366×768) : modal Espèces, hit-test CTA « Confirmer & Imprimer ticket » → `ctaBottom=864 > vh=768` (**ctaBelowFold=true**), `modalHeight=768`. Le pavé bord-écran, CTA hors viewport. Composant FROZEN `PaymentComponent.vue` — connu, non re-compté. Artefact `1366-01-paymodal-cash`.

---

## 2. COUVERTURE R1 MANQUANTE — COMPLÉTÉE

### Vente CARTE (terminal manuel, référence) → receipt — **OK**
Tacos composé 8,50 € → mode **Carte (TPE)** → select terminal = **« TPE Le Cayenne #1 - simulation »** (un TPE réel proposé, plus « Aucun TPE configuré »), réf carte « 4242 » → `POST` **201**, order **4530** / serial 1206264530, total 8.50. Receipt « Type de paiement: Carte », NF525 2169. Artefacts `card01..04`.

### Annulation mi-paiement — **OK**
Coca 1,50 € → modal Espèces ouvert (reçu 5) → fermeture par la croix SANS confirmer → **modal fermé, panier préservé** (Coca 1,50 €), **0 order POST**, pas de logout. Artefacts `cancel01-02`.

### Commande parquée → rappelée → encaissée — **OK**
Tiramisu 3,80 € → « Mettre en attente » (libellé « R2 parked dessert ») → **panier vidé, badge « En attente »** → panneau « Commandes en attente » (carte en tête, boutons Restaurer/Supprimer) → **Restaurer** → panier rétabli 3,80 € → Espèces 10 → **201**, order **4526** / 1206264526, total 3.80. Artefacts `park01..04`.

---

## 3. INTÉGRITÉ NUMÉRIQUE — chiffre par chiffre (cart → modal → POST → receipt → show)

| Commande | Cart POS | Modal paiement | Receipt TOTAL | POST total/discount | show (API) total/discount/payMethod | Verdict |
|---|---|---|---|---|---|---|
| 4512 Tiramisu remise espèces | 3,42 € | 3,42 € (rendu 6,58/10) | 3,42 € (REMISE 0,38) | 3.42 / 0.38 | 3.42 / 0.38 / 1=cash | ✅ |
| 4514 Tiramisu remise espèces | 3,42 € | 3,42 € | 3,42 € | 3.42 / 0.38 | 3.42 / 0.38 / 1=cash | ✅ |
| 4530 Tacos composé carte | 8,50 € | 8,50 € | 8,50 € | 8.50 / 0 | (probe non lancé sur 4530) | ✅ |
| 4526 Tiramisu parquée→espèces | 3,80 € | — | 3,80 € | 3.8 / 0 | 3.8 / 0 / 1=cash | ✅ |
| 4528 Coca ×3 TR (multi) | 4,50 € | 4,50 € (couvert 4,50 / dû 0,00) | 4,50 € | 4.5 / 0 | 4.5 / 0 / 5=ticket_restaurant | ✅ |

**0 divergence — aucun P0 d'intégrité.** Tous : `payment_status=5` (PAID), `source=15` (POS). Le TR mono-tranche persiste bien `payMethod=5`.

---

## 4. OBSERVATIONS (sévérité = adversaire)

- **A-SUS-2 [P3 cosmétique, REPORTÉ DE R1, NON dans le set de heal ni la liste « ne pas re-compter »]** — incohérence typographique deux-points sur le ticket : « Rendu **:** 6,58 € » (espace avant `:`) vs « Espèces**:** 10,00 € » / « SOUS-TOTAL HT**:** » (pas d'espace). **file:line** `resources/js/components/admin/pos/ReceiptComponent.vue:238` (`{{ $t('label.change') }} : {{ … }}` — espace avant `:` codé en dur) + `:251` (même motif tranche split), alors que `:237` rend `{{ $t('label.cash') }}: ` sans espace. NB : la typo FR veut une espace fine AVANT `:` → c'est plutôt « Espèces: » qui devrait s'aligner sur « Rendu : ». Mineur. Présent sur 100 % des receipts espèces R2 (`hA-04`, `hB-04`, `park04`).
- **MFS / « Autre » dans le select tranche multi-paiement** — toujours présents (`["Espèces","Carte","MFS","Ticket Restaurant","Autre"]`, `tr02-multi-tr`). **Gates connues A-SUS-4 / A-RED-10 (frozen render) — NON re-comptées.**
- Gates connues revues, inchangées (NON re-comptées) : « VAT (10%) » DATA `taxes.name`, dates « 12-06-2026 » à tirets sur l'en-tête ticket, banner « MODE TEST — IMPRESSION BYPASSÉE » (marqueur E2E attendu).

---

## 5. SYNTHÈSE

- **États couverts** : remise+espèces ×2 (heal), tamper (409 clean), carte+wizard composé, annulation mi-paiement, parquée→rappelée→espèces, Coca ×3 → TR multi, 1366×768 modal, sonde intégrité 4 commandes.
- **Heals de mon périmètre — TOUS CONFIRMÉS** : A-RED-1/A-RED-2 (plus de 401/logout/panier perdu, 201 ×2), anti-tamper (409 sans affaiblissement), A-RED-7 (séparateur, preuve DOM), A-RED-6 (« Prix HT »/« SOUS-TOTAL HT: »), identité opérateur + source=POS.
- **Gate persistante (frozen, connue)** : A-RED-12 (CTA modal sous la flottaison 1366×768).
- **Anomalies suspectées** : 1 seule, **A-SUS-2** (espace-avant-`:` « Rendu : » vs « Espèces: »), P3 cosmétique reporté de R1, file:line confirmé, hors set de heal et hors liste « ne pas re-compter » → à arbitrer par l'adversaire.
- **0 P0/P1 nouveau. 0 divergence d'intégrité.**
