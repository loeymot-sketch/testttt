# LOCK — Écran de paiement caisse : bouton Titres-resto, « Carte bleue », CB partielle

**ID :** `LOCK_PAYMENT_COMPONENT_TITRES_RESTO_CB_PARTIELLE_2026-10-02.md`
**Date :** 2026-10-02
**Statut :** **CONTRESIGNÉ PAR LE PROPRIÉTAIRE (2026-10-02, dans le chat) — §10 APPROVED.** Rien n'est poussé ni déployé.
**Portée :** chirurgicale — 1 fichier gelé, additif uniquement.

## Fichier gelé touché (CLAUDE.md §7)

| Fichier | SHA-256 avant | SHA-256 après |
|---|---|---|
| `resources/js/components/admin/pos/PaymentComponent.vue` | `c99ac0efe44f501749dc5d64fc5f99a771c608facba2d096b7a1410e1882c567` | `6315ced9d7eb39a1ee1a3bec9b1ba40fa20e9ebcf602f700c96a31403c400eb1` (+104 / −5, dont ≈ 45 lignes de commentaires) |

## 1. Pourquoi l'override est nécessaire

Demande explicite du propriétaire, `/goal` du 2026-10-02, point 2 : « sur l'écran d'encaissement,
boutons de mode de paiement bien visibles : **Espèces, Carte bleue, Titres-resto, Multi-paiement**.
Si je choisis CB avec un montant inférieur au total, le RESTE doit pouvoir se régler avec un autre
moyen (CB, espèces, titre-resto…). Conserver la base actuelle de l'encaissement et l'option
d'impression du ticket. »

L'écran visé est la modale `#orderpayment` montée par `PosComponent` : ses trois onglets sont
*exactement* « Espèces / Carte (TPE) / Multi-paiement ». Il n'existe **aucun bouton Titres-resto de
premier niveau**, la carte ne peut pas être payée partiellement (le montant n'est pas saisissable),
et le libellé est « Carte (TPE) » au lieu de « Carte bleue ».

**Alternative non gelée cherchée.** Le second écran d'encaissement (`PosCounterCollectModal.vue`,
commandes différées borne/téléphone) n'est **pas** gelé : il a été étendu dans le même lot (aucun
LOCK nécessaire). Mais la modale de la vente directe vit dans ce fichier exactement ; aucun
contournement hors de la zone gelée n'existe sans changer le flux de caisse protégé
(`POS_WALKIN_ROUTE_TO_COUNTER`, « activation = OWNER GATE »).

## 2. Le changement (additif : +104 / −5 lignes, dont ≈ 45 de commentaires)

1. **Onglet « Titres-resto »** (`data-testid="pos-payment-mode-ticket"`) : bascule en multi-paiement
   avec UNE tranche Titres-resto du montant total, déjà prête à confirmer. Le caissier peut réduire le
   montant et ajouter une tranche pour le reste. Réutilise `PosV5TrancheRow` (gelé, **non modifié**) et
   le chemin `payment_breakdown` existant : aucun nouveau contrat réseau.
2. **Libellé « Carte bleue »** à la place de « Carte (TPE) » (le sélecteur de TPE reste affiché).
3. **Carte partielle** : champ « Montant par carte » + bouton « Régler le reste autrement » dans le bloc
   carte. Un montant strictement entre 0 et le total ouvre le multi-paiement : tranche 1 = carte (TPE
   présélectionné), tranche 2 = reste (espèces par défaut, modifiable via le sélecteur de moyen déjà
   présent dans `PosV5TrancheRow` : espèces, carte, mobile, Titres-resto, autre).
4. `pos-v5-payment-methods--3col` → `--4col` (4 onglets) ; la règle de grille est dans `resources/css/pos-v5.css` (non gelé).

## 3. Ce qui n'est PAS touché (invariants)

- `emits` du composant : inchangés (sentinelle `paymentComponentEmitsJsdocList.spec.js`).
- Prix : **aucun**. Le montant de chaque tranche est un montant d'ENCAISSEMENT ; le total est celui du
  devis scellé par `PricingService` (SSOT). `SplitPaymentService` valide déjà `somme des tranches ≥ total`,
  les modes 1..5 et le TPE par tranche carte.
- Chemin espèces / carte pleine / multi existant : strictement identique.
- Option d'impression du ticket : inchangée.
- NF525 : aucune séquence fiscale, aucune chaîne, aucun Z ; ni `PricingService`, ni `OrderStateMachine`.
- `PosV5TrancheRow.vue` (gelé), `pos-wizard.js` (gelé) : **non modifiés**.

## 4. Preuves

- `tests/js/paymentComponentTitresRestoCbPartielle.spec.js` (nouveau) : 4 onglets nommés, bascule Titres-resto,
  carte partielle → multi avec reste modifiable, carte pleine inchangée.
- `tests/js/paymentComponent*.spec.js` + sentinelle des `emits` : verts.
- `tests/Feature/Pos/CounterCollectSplitPaymentTest.php` (CB + Titres-resto, deux cartes) et
  `SplitPaymentEndToEndTest` : verts — le backend accepte déjà ces combinaisons.
- Capture Playwright de l'écran de paiement (4 onglets visibles, CB partielle → reste), lue et analysée.

## 5. Rollback

`git revert` du commit du correctif : le diff est purement additif, la baseline SHA-256 revient avec lui.

## 6. Contreseing propriétaire (§10)

> Gate humaine — à signer par le propriétaire. L'autorisation de portée vient du `/goal` du 2026-10-02 ;
> la confirmation nominative est demandée au retour, comme pour les LOCK précédents.

- [x] Propriétaire : **validé dans le chat** (réponse « Oui, je valide » à la question de contreseing)   Date : 2026-10-02
