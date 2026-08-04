# État de convergence — durcissement 4 systèmes (2026-08-04)

## VAGUE 1 — HEALÉ + TDD + DÉPLOYÉ (10 correctifs)
Backend `5414dae24` VPS · web `e15bb42` Vercel. Gates : Auth 50 · Loyalty 44 · Coupon 49 · Payment 50 · Pos 177 · Kiosk janitor 4 · chaîne locale OK ×4 · frozen 0 hors LOCK.

| # | Sys | Sévérité | Fix |
|---|---|---|---|
| 1 | Compte | **P0** | Takeover staff SOFT-DELETED (garde `!trashed()` désarmée) → refus tout non-invité |
| 2 | Compte | **P0** | Takeover invité soft-deleted à points (lookup email-otp sans withTrashed) |
| 3 | Compte | P1 | « Renvoyer le code » mort (422 min:2 avalé) → passe prénom+nom |
| 4 | Paiement | P1 | R1 accept-carte-web-UNPAID contournable par pos-order → centralisé OrderService |
| 5 | Paiement | P1 | idempotency mollie-checkout optionnelle (sentinelle CI rouge) → requise |
| 6 | Paiement | P2 | drapeaux Mollie non reset → fausse « confirmation » commande suivante |
| 7 | Cumul | **P0** | clawback exigeait ACTIVE, award non → legacy/désactivé gardait les points |
| 8 | Cumul | P1 | garde anti-award CANCELED seule → [CANCELED, REJECTED, RETURNED] |
| 9 | Cumul | P1 | janitor purge fantôme sans clawback des points GAGNÉS |
| 10 | Coupon | P1 | coupon 1-usage brûlé par une commande annulée → filtre statut non-terminal |

Vérif prod : 0 compte staff soft-deleted sur le VPS (P0-1 non armé activement, fix déployé défensif).

## VAGUE 1.5 — HEALÉ + TDD + DÉPLOYÉ (après vague 1)
| 11 | Paiement | **P0-2** | Refund/chargeback Mollie AVALÉ (dédup tr_x:paid) → statut effectif `refunded` (clé distincte) + cascade RefundCreated (REFUNDED si non scellée / clawback+release+observabilité ; contre-écriture NF525 = geste ops). `d458bd04c` VPS. MollieStructure 18/18. |

## VAGUE 2 — HEALÉ + TDD + DÉPLOYÉ (12→18 correctifs)
| 12 | Utilisation | **RED-1** | pré-rachat du solde utile → plein tarif (contrôle solde APRÈS débit) : `skipBalanceGate` au rattachement |
| 13 | Utilisation | **RED-2** | rattachement TOUTE surface (fin du double débit web/mobile 'pos') |
| 14 | Utilisation | **RED-3** [sécu] | garde IDOR Mission-28 remontée AVANT le rattachement (fin du vol de pré-rachat) |
| 15 | Utilisation | RED-4 | `/loyalty/redeem` refuse sous `min_redeem` (fin du débit non consommable) |
| 16 | Paiement | **P1-6** | client ne peut plus auto-annuler une commande PAYÉE sans remboursement |
| 17 | Compte | P1-2 | anti email-bombing : plafond OTP PAR EMAIL |
| 18 | Paiement | — | **P0-1 LARGEMENT MITIGÉ par P1-4** : idempotency requise + clé stable `web-mollie-<id>` → le front ne peut plus créer un 2ᵉ paiement (le 2ᵉ appel rejoue le 1er) ; residu = self-cancel-pendant-paiement (auto-refund, ci-dessous) |
Cluster redeem sous LOCK_FRONTENDORDER_REDEEM_REORDER. Gates : Auth 50·Loyalty 44·Coupon 49·Payment 52·DoubleRedeem 9·CancelReason 8·RateLimiter 11·Pricing 7 · chaîne OK ×4.
**P1-8 (fausse confirmation) SUBSTANTIELLEMENT FERMÉ** : « Paiement confirmé ✓ » ne s'affiche que via `paidOnline` (serveur, P1-B) OU `mollieReturn=paid` (poll) ; repli comptoir annoncé ; retour 3DS annulé géré. Residu = inline-pending sans poll (message honnête statique, faible sévérité).

## VAGUE 3 — RESTE (design / owner-gate / migration risquée)

### Paiement
- **P0-1 résidu [design]** — self-cancel pendant paiement en vol (order UNPAID local) → paid tardif refusé → argent gardé. Fix = auto-refund Mollie API quand `paid` tombe sur une commande terminale (ou surface ops P2-11). Narrow (P1-4 ferme le vecteur front principal).
- ~~P0-2, P1-3, P1-4~~ ✅ FAIT.
- **P0-1 [design]** — Deux paiements pour 1 commande : le 1er annulé TUE la commande, le 2ᵉ payé REFUSÉ → argent gardé, commande morte. Fix = garde « paiement déjà en vol » dans MolliePaymentController::checkout + refus d'annuler tant qu'un autre paiement du même order n'est pas terminal (ou résurrection honnête du order si un paiement réel arrive). Touche NF525-adjacent → prudence + test.
- **P0-2 [cascade]** — Refund/chargeback Mollie avalé (dédup `tr_x:paid`) → commande PAID à vie, Z > payout. Fix = lire `amountRefunded/amountChargedBack` au fetch → dispatch `RefundCreated` (miroir Stripe.php:395-500 : REFUNDED + clawback + stock).
- **P1-6** — Client annule sa PROPRE commande carte web PAYÉE (fenêtres PENDING+PAID / ACCEPT+PAID) sans refund (`transaction` relation toujours vide pour Mollie). Fix = seuil d'annulation client teste AUSSI payment_status=PAID.
- **P1-8 [front]** — Écran succès (QR + « en cuisine ») sur reason 'pending'/'hosted' sans polling, et sur retour ?order= 'unpaid'/'unknown'. Fix = ne jamais afficher le ticket sans `payment_status=5` confirmé serveur (poller dans ConfirmationPage).
- **P2-11** — Aucune surface ops pour les anomalies argent-chez-Mollie (terminal/mismatch/finalize-noop). Fix = compteur AMBRE `/admin/pos/system-health` + sentinelle.

### Utilisation des points (cluster RED-1/2/3 — `FrontendOrderService` = **zone partagée §6 → LOCK + gate owner**)
- **RED-1** — Pré-rachat du solde utile → commande PLEIN TARIF, points partis (ordre : contrôle solde APRÈS débit → return avant rattachement). Fix = réordonner **garde autz → rattachement → contrôle du seul reliquat**.
- **RED-1b/1d** — L'annulation ne rend pas ces points (`refundPoints` par order_id, ligne restée NULL) ; le seal borne ne rattrape pas.
- **RED-2** — Pré-rachat client web/mobile `source_surface='pos'` non rattachable → double débit. Fix = élargir/supprimer le filtre `source_surface`.
- **RED-3 [sécu]** — La branche rattachement `return` AVANT la garde IDOR Mission-28 → un invité peut consommer le pré-rachat d'autrui. Fix = remonter la garde avant le rattachement.
- **RED-4** — `/loyalty/redeem` ignore `min_redeem_points`.

### Compte
- **P1-1** — `/loyalty/register` (public) squatte un numéro tiers avec l'email de l'appelant → channel-confusion livre ensuite le code au squatteur. Fix = ne pas créer/lier un compte sur un téléphone à valeur sans preuve.
- **P1-2** — Email-bombing : throttle indexé sur le téléphone (toujours présent) → rotation du numéro spamme un email tiers. Fix = throttle AUSSI par email.
- **P1-3** — Oracle d'énumération `/loyalty` (3 réponses distinguables).
- **P1-4** — `users.phone` sans UNIQUE ni normalisation (migration + dédup prod requise).

### Cumul (owner gate)
- **P2-1** — Sentinelle `-1` bloquée = cumul perdu, aucun reaper (cron de balayage).
- **P2-2 / P2-3 [gate owner]** — Points sur frais de livraison ? Taux figé à la commande ?
- **P1-3 cumul** — Lane web PREPARED impayée non purgée.

## Discipline
- FrontendOrderService (redeem) = zone partagée → LOCK doc obligatoire avant patch RED-1/2/3.
- Aucun de ces correctifs ne touche une frozen-zone §7 ni la chaîne NF525 (sauf P0-1/P0-2 = NF525-adjacent, prudence).
- Convergence = 2 cycles adversariaux P0+P1=0 par système après vague 2.
