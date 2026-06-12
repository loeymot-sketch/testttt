# ADVERSARIAL VERDICT — Vague B caisse-gestion (Round 1, dispute-2026-06-12)

Superviseur adversarial. 45/45 PNG lus en analyse multimodale + quartets DOM/console/network + JSON bruts.
Statut: EN COURS (écriture incrémentale).

## Pass 1 — Visuel (45 PNG lus)

Observations propres (au-delà du WAVE_REPORT GStack) — en cours de vérification technique:

- **[ADV-B-01 candidat]** Dashboard (b3-10/b4-10): « Ticket Moyen 8,29 € » vs « Chiffre d'Affaires du Jour 82,87 € » / « Commandes du Jour 24 » → 82,87/24 = 3,45 €, PAS 8,29 €. Dénominateurs incohérents sur la même rangée de cartes (8,29 ≈ 82,87/10 → base "payées" probable). Le GStack ne l'a PAS relevé. Intégrité numérique cross-cartes.
- **[ADV-B-02 candidat]** Modal « Encaisser la commande borne » (b1-04/05-modal, viewport caisse standard 1440×900): le pavé numérique n'affiche que les rangées 1-2-3-⌫ et 4-5-6 ; les touches 7-8-9-0 sont sous le pli/derrière les boutons d'action. Chemin primaire de saisie du montant reçu. À RE-VÉRIFIER LIVE.
- **[ADV-B-03 candidat]** Borne b0-03: « Paiement en espèces uniquement à la caisse. » alors que le modal caisse propose Espèces / Terminal (manuel) / Mobile / Ticket restaurant (b1-04-modal). Copy client contredit la réalité d'encaissement unifié.
- **[ADV-B-04 candidat]** b2-08-cash-overview-bas.png ≡ b2-07 (même cadrage, pas de scroll) — trou de couverture artefact GStack (le bas réel de la page n'est couvert que par le .txt).
- Confirmations visuelles des findings GStack: B-R1-02 (entête « Écart » sur colonne Sens, b1-07), B-R1-03 (« SSOT modal » en notes, b1-07), B-R1-15 (« TXN-fkDxb6PZtOwj · Carte bancaire · −3,80 € » + « TXN-GXdX82uD6hf7 · Carte bancaire · −25,00 € », b3-12), B-R1-16 (b4-09 = page Transactions), B-R1-17 (CLIENT « Admin Le Cayenne » sur commandes borne, b3-03/b4-02 vs « Client Borne » sur Voir b1-09), B-R1-12 (« Imprimer La Facture », b1-09/b2-01), B-R1-13 (« Remboursé » + « Retournée », b2-04/05), B-R1-08 (b1-16 sans récap), B-R1-20 (écart « 0,50 € » sans signe b3-11 ; « Dernier rapport Z #20 · Fermée » b4-08), B-R1-04 (file A0010..A0014 du 10/06 servie d'abord, « Voir plus (49/48) », b1-04-after/b1-05-after).

(suite: pass technique, vérifs code, live re-checks, disputes FINAL_REPORT 2026-06-11)
