# VAGUE B — ROUND 2 (post-heal) — Caisse gestion & clôture

> Compte : `bm.t2admin@lecayenne.fr` (Branch Manager, user_id=11). App :8768, DB foodking_e2e (reset seed 10/06 + ~32 heals, bundles rebuildés 12/06 13:07 — vérifié : `enc-day-badge` présent dans `public/js/admin-shell.js`, `pos-counter-collect-day-badge` dans `pos-shell.js`, `paymentGatewaysUnavailable` dans `admin-shell.js`).
> Quartet round-2 : PNG + DOM `#app` outerHTML (tail 120KB) + console cumulée + network ≥400 cumulé. Scripts `tests/e2e/_d2-B-*.mjs`.
> Contexte DB au démarrage (probe MySQL 11:09) : 50 commandes PENDING_COUNTER **toutes du 10/06** (avant-hier), 2 sessions caisse OPEN zombies (id 19 user 1, id 20 user 3), dernière transaction id 749 = COUNTER-4327.

---

## ÉTAT 1 — /admin/transactions rôle BM (heal B-R1-19) — ✅ HEAL CONFIRMÉ

Scripts : `_d2-B-01-transactions-bm.mjs`, `_d2-B-01e-filter-final.mjs`. Quartets : `b1-01-transactions-initial`, `b1-06-filter-options`, `b1-07-filtered-credit`, `b1-08-filtered-by-txn-no`, `b1-09-after-export`.

| Vérification | Round 1 (RED) | Round 2 (observé) |
|---|---|---|
| `GET /api/admin/setting/payment-gateway` rôle BM | **403** + PAGEERROR AxiosError à chaque visite | **200** (75 bytes) — `_b1-gateway-responses.json` |
| Secrets gateway dans la réponse réseau | gate 403 = seul rempart | **AUCUN** : body intégral = `{"data":[{"id":2,"name":"Credit","slug":"credit","status":5,"options":[]}]}` — `options` STRIPPÉ (heal H1 fix 6, `PaymentGatewayResource`), scan patterns `sk_live|sk_test|stripe_secret|secret_key|client_secret|whsec_` = 0 hit |
| Console | AxiosError non interceptée | **0 erreur** — seule la WARNING WebSocket :6001 connue (SYNC-WS-01, fallback polling) |
| Réseau ≥400 | 403 systématique | **0** sur toute la session (3 runs) |
| Filtre « Mode de paiement » | jamais rendu (flux mort au 403) | **rendu + fonctionnel** : option « Credit » → `?payment_method=credit` → 1 row exacte (TXN-JETe3vhjRnfR · Carte bancaire · −2,00 € · 07-06, artefact seed historique pré-heal) |
| Filtre ID transaction | non testé R1 | `COUNTER-4327` → 1 row « Espèces · 1006264327 · +3,80 € » |
| Export | non testé R1 | **XLS téléchargé** (`Transactions.xlsx`, sauvé `_b1d…/_b1e-transactions-export.xlsx`) |

Intégrité chiffres état 1 : liste 746 entrées ; rows fraîches `POS-4514…/POS-4512…` (+3,42 € Espèces, 12-06 — ventes POS directes d'une vague parallèle) **déjà visibles dans le grand livre** = effet write-side ADV-B-07 observé en conditions réelles.

Observation mineure (nouvelle, P3) : le filtre « Mode de paiement » ne propose QUE « Credit » (la liste gateways `excepts=1` exclut Cash) alors que la colonne affiche massivement « Espèces » — un BM ne peut pas filtrer le grand livre sur Espèces. Pas un secret-leak ni une régression du heal ; ergonomie de filtre à arbitrer.

**Verdict heal B-R1-19 : CONFIRMÉ** (backend H1 `42ce66fea` + défense front H3 `2bef9dfc4` — le `v-if` de dégradation n'est pas déclenché puisque le 200 passe).

---

(rapport incrémental — états suivants ci-dessous au fil de l'exécution)

## ÉTAT 2 — /admin/encaissement : badges date + chip modal (heal B-R1-04 UI, H3 `d377da185`) — ✅ HEAL CONFIRMÉ

Scripts : `_d2-B-02-encaissement-queue.mjs`, `_d2-B-02b-modal-chip.mjs`. Quartets : `b2-01-encaissement-queue`, `b2-02-encaissement-queue-bas`, `b2-05-collect-modal-1006-chip`, `b2-06-modal-numpad-hittest`.

| Vérification | Round 1 (RED) | Round 2 (observé) |
|---|---|---|
| Badge date sur cartes d'un autre jour | AUCUNE date sur 48+ cartes du 10/06 | **50 badges `enc-day-badge-<id>` rendus, tous « 10/06 »** (testids `enc-day-badge-4328…4332…` vérifiés) — les cartes du JOUR (créées par les vagues parallèles, ex. A0002 « 8 min ») n'ont **pas** de badge |
| Tri | ancienneté seule → zombies servis d'abord | **jour-récent-d'abord** : ordre DOM = A0002, A0004…A0008 (12/06) PUIS A0009…A0017+ (10/06) — zombies en bas |
| Date dans le modal de confirmation | rien | **chip `pos-counter-collect-day-badge` = « ⚠ Commande du 10/06/2026 »** dans le header du modal (commande 4329, N° A0010, total 3,80 €) — visible AVANT confirmation |
| File header | « À encaisser 52 » sans alerte | « Commandes en attente d'encaissement 57 · Total en attente 280,40 € » (57 = 50 zombies 10/06 + 7 fraîches du jour) |

Bonus — **ADV-F-P0-1 hit-test RÉEL post-rebuild** (modal encaissement, 1440×900, périmètre A/F mais constaté ici) : `keysFound=14, blocked=[]`, `.cc-modal-body` 737/737 (AUCUN scroll), footer `position: static`, CTA visible. Le footer n'intercepte plus AUCUNE touche — l'AFTER simulé de H3 est désormais prouvé sur le bundle réel.

Modal refermé SANS confirmer (aucune mutation dans cet état). Console : seule la WARNING WS connue. Réseau ≥400 : 0.

Restes factuels (non re-comptés comme nouveaux) : la purge backend des PENDING_COUNTER expirés reste NON faite (décision owner explicite H3 — 50 zombies du 10/06 toujours encaissables) ; les numéros A restent dupliqués inter-jours (2× A0009 dans la même liste), le badge date est la mitigation UI retenue.

---

## ÉTAT 3 — Session caisse complète (ouverture 50 € → 2 encaissements → vente directe → no-sale → mouvements)

Scripts : `_d2-B-03-session-cycle.mjs`, `_d2-B-03b-direct-sale.mjs`. Quartets : `b3-01…b3-12`. API : `_b3-api-responses.json`, `_b3b-api-responses.json`.

**Intégrité chiffre par chiffre (session 22, opened_by_user_id=11)** :
| Étape | UI affiché | API/DB | Verdict |
|---|---|---|---|
| Ouverture fond | saisie 50 → display « 50,00 € » ; stats opening=« 50,00 € » expected=« 50,00 € » | POST sessions/open 201, opening_amount=50 | ✅ |
| Encaissement 4334 (6,90 €, compte exact) | modal total « 6,90 € » + chip « ⚠ Commande du 10/06/2026 » | POST counter-collect/4334/confirm 200 ; `transactions` COUNTER-4334 counter_cash +6,90 ; `order_payments` mode=1, 6,90/6,90/0,00 ; `cash_movements` in 6,90 | ✅ |
| Encaissement 4335 (8,90 €, reçu 10,00) | rendu affiché « ✨ Monnaie à rendre 1,10 € » | COUNTER-4335 +8,90 ; order_payments 8,90/10,00/**1,10** ; movement in 8,90 | ✅ |
| Vente POS directe (ADV-B-07) Coca-Cola 33cl 1,50 € espèces reçu 2,00 | rendu « 0,50 € » ; receipt « Opérateur: BM T2 Admin », « Prix HT », « SOUS-TOTAL HT: 1,36 € », TOTAL 1,50 €, Espèces 2,00, Rendu 0,50 | quote 200 + POST /admin/pos **201** order **4531** ; `transactions` **POS-4531 cash +1,50 (1 SEULE row, COUNT=1)** ; order_payments 1,50/2,00/0,50 ; movement in 1,50 | ✅ |
| Expected session après 3 entrées | « **67,30 €** » (stats dialog), mvts=3 | 50+6,90+8,90+1,50 = 67,30 | ✅ exact |
| No-sale (tiroir hors-vente) | bouton `pos-no-sale` présent → clic → **toast rouge FR « Impossible d'ouvrir le tiroir »** | POST cash-drawer/open → **422 {"error":"no_printer"}** (env e2e sans imprimante) | ⚠ erreur SURFACÉE (pas silencieuse) — pas d'UI de mouvement in/out montant, le no-sale POS = kick tiroir uniquement |
| Mouvements (dialog) | 3 rows : 6,90 ↑ Entrée · 8,90 ↑ Entrée · 1,50 ↑ Entrée | `cash_movements` sess 22 : 3 in | ✅ |

**Heal ADV-B-07 : CONFIRMÉ** — la vente POS directe fraîche apparaît dans `transactions` (POS-4531) avec **exactement UNE row** ; les encaissements borne n'ont que LEUR row COUNTER-* (aucun double-compte). Les rows POS-4512/4514/4526/4528/4530 des vagues parallèles peuplent aussi le grand livre (le write-side couvre toutes les ventes).

Observations factuelles restantes (round-1, NON healées — hors liste à-ne-pas-recompter) :
- **B-R1-03 (P2)** : la note des mouvements affiche toujours le jargon technique « Encaissement borne au comptoir **(SSOT modal)** » (b3-11, DB `cash_movements.notes` idem).
- **B-R1-02 (P2)** : l'entête de colonne du tableau Mouvements de caisse affiche toujours « **Écart** » au-dessus des valeurs « ↑ Entrée » (colonne SENS) — b3-11-movements.png.
- Connu frozen (pas re-compté) : popup produit POS affiche « €1.50 » format en-US (POS-ERG-07) ; « VAT (10%) » sur ticket (DATA gate).

## ÉTAT 4 — Refunds espèces → grand livre (heal B-R1-15) — ✅ HEAL CONFIRMÉ

Script : `_d2-B-04-refunds-ledger.mjs`. Quartets : `b4-01-refund-4531-{before,modal,after}`, `b4-02-refund-4334-{before,modal,after}`, `b4-03-transactions-after-refunds`.

| Refund | Modal (méthode affichée) | API | Grand livre /admin/transactions | DB `transactions` |
|---|---|---|---|---|
| 4531 — vente POS directe payée cash | « Espèces » · total 1,50 € | refund-with-counter-entry 200 mode=pre_z | **TXN-NFHlHjxEOLCK · Espèces · − 1,50 €** | cash_back `payment_method='cash'` |
| 4334 — borne counter-collectée | « Espèces » · total 6,90 € | 200 mode=pre_z | **TXN-oRfs9bDD6gmD · Espèces · − 6,90 €** | cash_back `payment_method='counter_cash'` |

Round 1 affichait « Carte bancaire » sur CES MÊMES flux (slug 'credit' en dur). Round 2 : **zéro row « Carte bancaire » fraîche** (seul l'artefact seed TXN-JETe3vhjRnfR du 07-06 reste, antérieur au heal — pas de migration corrective mandatée). Le refund d'une vague parallèle (TXN-mINjwpPQfOH5 · Espèces · −5,00 · 4516) confirme aussi le heal.
Cohérence tiroir : les 2 refunds génèrent des `cash_movements` **out** (1,50 + 6,90) sur la session 22 — le tiroir suit la sortie réelle d'espèces.

**Reste ouvert (round-1 B-R1-06, P1, PAS dans le périmètre des heals livrés)** : le warning du modal promet « génère une commande miroir NF525 et un ticket de remboursement » mais en mode `pre_z` AUCUNE commande miroir n'est créée (vérifié DB : aucun order avec `parent_order_id IN (4531,4334)` ; l'order père passe payment_status=20/status=22 in place). Copy toujours mensongère en pre-Z.

## ÉTAT 5 — Clôture comptage + écart UI (fin du cycle session)

Script : `_d2-B-05-close-session.mjs`. Quartets : `b5-01…b5-06`.

| Comptage saisi | Attendu UI | Écart UI | Raison requise ? |
|---|---|---|---|
| 58,90 (exact) | 58,90 € | **0,00 €** | non (champ masqué) |
| 59,40 | 58,90 € | **+0,50 €** (signé, vert) | **oui** (champ raison apparaît, obligatoire *) |
| 57,90 | 58,90 € | **−1,00 €** (signé) | oui |

- Attendu UI **58,90 €** = 50 + 6,90 + 8,90 + 1,50 − 1,50 − 6,90 → arithmétique exacte incluant les refunds out. ✅
- Clôture finale à 59,40 : POST close 200 (closing_amount=59.4) puis reconcile 200 → `variance=0.5`, `variance_reason` persistée, status `reconciled`. ✅
- L'écart est désormais **SIGNÉ** dans le close-form (round-1 B-R1-20 pointait un « 0,50 € » sans signe) — à re-vérifier sur la page rapport sessions (état 6).
- Note UX mineure : après clôture le dialog ne rebascule pas sur le formulaire d'ouverture (`open-form re-visible: false`) — le POS revient à l'écran vente ; ré-ouvrir le dialog manuellement montre l'état. Cosmétique.
- Vérifié aussi : badge bouton « À encaisser » (57) == compteur panneau « À encaisser borne (57) » (DOM b5-06 ; le « 12 » initialement suspecté était une mauvaise lecture du PNG — REJETÉ par vérification DOM).
