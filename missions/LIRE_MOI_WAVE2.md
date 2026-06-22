# Wave 2 — la liste simple (Option B)

Tu n’as **rien** à mémoriser. C’est une **liste de courses** : tu fais le **numéro 1**, quand c’est fini tu passes au **2**, et ainsi de suite.

---

## En deux phrases

- **Wave 2** = une suite d’améliorations découpées en **36 petits morceaux** (data, caisse, borne), dans un **ordre fixe**.  
- **Option B** = on ne fait **pas** le gros “ledger paiement complet” (ça s’appelle M-04A, il reste volontairement de côté). Le reste peut avancer **un morceau après l’autre**.

---

## Comment tu t’y retrouves au quotidien

1. Ouvre la table **plus bas** (36 lignes).  
2. Regarde le **prochain numéro** que tu n’as pas encore fait.  
3. Si la colonne **« à faire maintenant ? »** dit **Oui** → ton outil (Codex, etc.) travaille **uniquement** sur l’identifiant de la colonne **Code**.  
4. Si ça dit **Non, bloqué** → tu **sautes** ce numéro pour l’instant (ou tu demandes à un humain de débloquer le sujet : migration, fiscal, etc.).  
5. Quand une ligne est finie, tu coches mentalement : **suivante**.

Tu n’as **pas** besoin de lancer 36 commandes d’un coup. Un morceau à la fois, comme avant.

---

## Les 4 numéros qui ne partent pas tout de suite (c’est normal)

| Numéro | Sujet simple | Pourquoi c’est en pause |
|--------|----------------|-------------------------|
| 15 | Paiement borne + WebSocket (K-05) | Fichier / garde “figé” (F21) : il faut qu’on vérifie le gate avant. |
| 17 | Park commande + date d’expiration (P-06) | Peut toucher la **base** : décision humaine sur migration. |
| 26 | Remboursement / ledger (P-10) | Avec **Option B**, le gros modèle “ledger” n’est pas le même : il faut **redéfinir** le périmètre avant de coder. |
| 32 | Clôture fiscale Z renforcée (P-13) | Pareil : **base** + **fiscal** = gate avant de lancer. |

Tout le reste de la liste suit l’ordre, sauf instruction contraire dans le dossier `missions/CV1-LOT-…` (fichier `input.json`, champ `status`).

---

## La liste (1 → 36) — noms **humains** + code machine

| # | C’est quoi (pour toi) | Code (pour l’outil) | À faire maintenant ? |
|---|------------------------|------------------------|----------------------|
| 1 | Rien de faux côté “total” saisi par le client | `CV1-LOT-D01-CLIENT-TOTAL-INVARIANT` | **Oui** (premier) |
| 2 | Citation / devis bien “attaché” à la commande caisse | `CV1-LOT-P01-QUOTE-BIND` | Oui |
| 3 | Routes borne propres, pas de vieux bricolage | `CV1-LOT-K01-ROUTING-LEGACY` | Oui |
| 4 | Savoir quels événements partent quand (file, synchro) | `CV1-LOT-D02-ORDER-EVENT-OUTBOX-MAP` | Oui |
| 5 | Plafonds / règles de remise côté serveur | `CV1-LOT-P02-DISCOUNT-GUARD` | Oui |
| 6 | Type de commande clair (emporter, sur place, etc.) | `CV1-LOT-K02-ORDER-TYPE-EXPLICIT` | Oui |
| 7 | Filtre branche partout où il faut (pas de fuite) | `CV1-LOT-D03-BRANCH-FILTER-MATRIX` | Oui |
| 8 | Raison de remise bien liée à l’écran | `CV1-LOT-P03-DISCOUNT-REASON-BIND` | Oui |
| 9 | Prix côté borne calés sur le devis signé | `CV1-LOT-K03-QUOTE-PRICING-PIN` | Oui |
| 10 | Livraison : contrat API propre | `CV1-LOT-D04-DELIVERY-API-CONTRACT` | Oui |
| 11 | Écran paiement caisse : moins de bricolage sur les props | `CV1-LOT-P04-PAYMENT-REFACTOR-PROPS` | Oui |
| 12 | Paiement borne (cash / carte) + hors-ligne clair | `CV1-LOT-K04-PAYMENT-UX-OFFLINE` | Oui |
| 13 | Annulation : trace / audit propre | `CV1-LOT-D05-CANCEL-AUDIT-TRAIL` | Oui |
| 14 | Plan de salle : libérer la table au bon moment | `CV1-LOT-P05-FLOORPLAN-RELEASE` | Oui |
| 15 | Paiement TPE + WebSocket (borne) | `CV1-LOT-K05-PAYMENT-CONFIRM-WS` | **Non** (gate F21) |
| 16 | Si pas de WebSocket : message / doc pour l’opérateur | `CV1-LOT-D06-BROADCAST-FALLBACK-DOC` | Oui |
| 17 | Commandes “mises de côté” : expiration + ménage | `CV1-LOT-P06-PARK-TTL` | **Non** (schéma) |
| 18 | Borne offline : écran d’attente propre | `CV1-LOT-K06-OFFLINE-WAITING-UX` | Oui |
| 19 | Caisse / borne : mêmes règles contractuelles (doc + tests) | `CV1-LOT-D07-FOS-SYMMETRY-CONTRACT` | Oui |
| 20 | Cash borne encaissé côté caisse sans mélange avec la cuisine | `CV1-LOT-P07-KIOSK-CASH-DECOUPLE` | Oui |
| 21 | Un seul “fil” de parcours (wizard) cohérent | `CV1-LOT-K07-WIZARD-UNIFY` | Oui |
| 22 | Règles d’envoi en cuisine (KDS) explicites | `CV1-LOT-P08-KDS-RELEASE-RULE` | Oui |
| 23 | Erreurs borne regroupées / lisibles | `CV1-LOT-K08-GLOBAL-ERRORS` | Oui |
| 24 | Événements **après** enregistrement en base (pas avant) | `CV1-LOT-P09-AFTER-COMMIT-DISPATCH` | Oui |
| 25 | Caisse voit la borne en temps réel (propreté) | `CV1-LOT-K09-POS-REALTIME-KIOSK-VIS` | Oui |
| 26 | Remboursements + “ledger” (gros sujet) | `CV1-LOT-P10-REFUND-LEDGER` | **Non** (Option B : rescope) |
| 27 | Nettoyage commandes + pas de double paiement | `CV1-LOT-K10-CLEANUP-IDEMPOTENCY` | Oui |
| 28 | Impression ticket : traçabilité si échec | `CV1-LOT-P11-PRINT-AUDIT` | Oui |
| 29 | Impression bornee : repli + retour écran calme | `CV1-LOT-K11-PRINT-FALLBACK-IDLE` | Oui |
| 30 | Caisse : rafraîchir quand on revient sur l’onglet | `CV1-LOT-P12-RT-RESYNC` | Oui |
| 31 | Fidélité : refus explicite quand il faut | `CV1-LOT-K12-LOYALTY-REFUSAL` | Oui |
| 32 | Clôture Z : une par jour / pas de doublon | `CV1-LOT-P13-ZREPORT-HARDEN` | **Non** (fiscal + schéma) |
| 33 | Tests : pas de collision d’idempotence | `CV1-LOT-K13-SENTINEL-IDEMPOTENCY` | Oui |
| 34 | Indication branche toujours visible en caisse | `CV1-LOT-P14-BRANCH-BADGE` | Oui |
| 35 | Télémétrie / événements borens homogènes | `CV1-LOT-K14-TELEMETRY-HOMOG` | Oui |
| 36 | Grande passe E2E (fin de vague) | `CV1-LOT-P15-E2E-MATRIX` | Oui (quand le reste est sain) |

**Fichier technique détaillé (ordre identique, plus de détails outil)** : `missions/W2_LOT_CODEX_RUN_ORDER_OPTION_B_2026-04-26.md`

---

## Une phrase à dire à l’assistant (chaque fois)

> « Exécute la mission Wave 2 **numéro [N]** : le code est `CV1-LOT-…` dans le dossier `missions/`, respecte Option B, ne touche pas M-04A, une mission = un run. »

Remplace **[N]** par le numéro de la ligne où tu en es.

---

## Pour Claude (terminal) — si tu veux qu’il reformate ou contrôle toute la liste

Fichier prêt à l’emploi (prompt) : `reports/audit/CLAUDE_PROMPT_WAVE2_LISTE_SIMPLE_2026-04-26.txt`  
Commande type : `bash scripts/foodking-claude-orchestrate.sh audit "$(cat reports/audit/CLAUDE_PROMPT_WAVE2_LISTE_SIMPLE_2026-04-26.txt)"`

(Lis le fichier : il demande à Claude de relire `LIRE_MOI_WAVE2.md` et de produire sa version **checklist** s’il le souhaite, sans imposer de format technique.)
