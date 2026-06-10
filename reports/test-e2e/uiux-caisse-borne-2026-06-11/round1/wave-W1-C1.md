# W1-C1 — Audit UI/UX profond POS caisse (/admin/pos) — round 1

Date: 2026-06-11 · App: http://127.0.0.1:8768 (DB jetable `foodking_e2e`) · Viewport 1440×900 fr-FR
Compte utilisé: `pos@lecayenne.fr` (Caissier) — l'admin partagé subit une purge de token permanente
(relogins parallèles d'autres agents → Sanctum révoque), le caissier est stable et = persona réelle.
Scripts jetables: `tests/e2e/_w1-c1-*.mjs` · Screenshots: `reports/test-e2e/uiux-caisse-borne-2026-06-11/round1/shots-c1/`
Grilles appliquées: `docs/design/DESIGN_SYSTEM_POLICY_2026-06-10.md` + `DESIGN_REFERENCES_2026-06-11.md` §3/§4.

Parcours réellement exécutés: login → grille (catégories, recherche, état vide) → ajout boisson
(wizard popup) → ajout via wizard → steppers qté ± / corbeille / annuler dernière ligne → type de
commande (À emporter ⟷ Livraison, formulaire inline) → modal Ajouter un client → mise en attente
(prompt) → panneau « Commandes en attente » → Restaurer → Commande → modal Paiement (frozen, capture)
→ encaissement réel d'une commande borne A0012 en Espèces (succès, toast) → Suivi commandes →
Écran client → Ouvrir tiroir → Caisse (session) → CTA fidélité.

---

## FINDINGS

### P1

**[P1] `resources/js/components/admin/pos/v5/PosV5Button.vue:5-7` — bouton « Écran client » totalement inerte**
- reproduction: POS → clic « Écran client » (header). Aucune navigation, aucun onglet, aucun feedback. Probe DOM: l'élément rendu est `<a target="_blank" class="pos-v5-btn…">` **sans `href`**.
- cause (vérifiée): `PosV5Button` rend `as="router-link"` via `<component :is>` mais bind explicitement `:href="tag === 'a' ? href : null"` → le `href=null` en fallthrough écrase le href calculé par router-link ; avec `target="_blank"`, router-link laisse le navigateur gérer le clic → ancre sans href = no-op. La route `admin.order-status-screen` existe bien (`router/modules/orderStatusScreenRoutes.js:8-12`, page OK en accès direct, cf. `35-oss-direct.png`).
- evidence: `shots-c1/23-ecran-client-state.png`, `32-ecran-client-failed.png` (aucun changement post-clic), probe loggée `{tag:"A", href:null, target:"_blank"}`.
- recommendation (scope-minimal, non-frozen): dans PosV5Button, ne passer `href` que si `tag==='a'` via `v-bind` conditionnel (`v-bind="tag === 'a' ? { href } : {}"`) au lieu d'un bind permanent à null.

**[P1] `resources/js/services/appService.js:71-77` — montant « 1.50€ » format EN-US sur le modal de paiement caisse**
- reproduction: panier 1 Coca → « Commande · 1,50 € » → modal « Paiement De Commande » affiche MONTANT TOTAL **« 1.50€ »** (point décimal, symbole collé) alors que tout le ticket affiche « 1,50 € ».
- cause (vérifiée): `appService.currencyFormat` = `toFixed(decimal) + currency` brut ; `PaymentComponent.vue:535-537` (frozen) y délègue, alors que `PosComponent.vue` a son propre formateur `toLocaleString('fr-FR')` — d'où l'incohérence à l'écran le plus critique (encaissement).
- evidence: `shots-c1/30b-payment-surface.png` (modal 1.50€) vs ticket « 1,50 € » même écran.
- recommendation: harmoniser `appService.currencyFormat` (fichier NON-frozen) sur le formateur fr-FR de PosComponent (`toLocaleString('fr-FR') + ' ' + symbole`) — corrige le modal frozen sans le toucher ; passer Vitest sur les consommateurs.

**[P1][FROZEN-GATE] `public/js/pos-wizard.js:218-221` — wizard caisse : prix « €1.50 » EN-US partout**
- reproduction: clic n'importe quel produit → popup wizard : en-tête produit « €1.50 », total sticky « €1.50 ».
- cause (vérifiée): `fmtPrice = '€' + num.toFixed(2)` (POS-ERG-07 connu). Fichier frozen §7 — **aucune édition proposée**, gate owner + LOCK requis.
- evidence: `shots-c1/08-after-click-coca.png`, `09-wizard-frites.png`.
- recommendation: LOCK owner dédié (fmtPrice → format FR) ; à défaut, accepter en V1 documenté.

### P2

**[P2] `resources/js/components/admin/pos/PosComponent.vue:3493` — mise en attente via `window.prompt` natif**
- reproduction: panier non vide → « Mettre en attente » → prompt navigateur brut « Libellé optionnel pour la commande parkée ». Non stylé, bloquant, pas touch-friendly, et invisible/auto-rejeté dans certains contextes (webview/kiosk-mode).
- evidence: log DIALOG du run `_w1-c1-40` ; le park aboutit ensuite (panneau « Commandes en attente », `28-parked-orders.png`, Restaurer OK).
- recommendation: remplacer par une mini-modale PosV5 (ou champ inline dans le panneau parked) — même pattern que le reste de la caisse.

**[P2] page `/admin/pos` + `/admin/pos-orders` — signaux « À encaisser » contradictoires (47 vs 0) et file borne sans cap de date**
- reproduction: au même instant, le POS affiche badge « À encaisser 47 » + panneau « À ENCAISSER BORNE (47) » (N°A0012… horodatés « 01:47 » sans date), et « Suivi commandes » affiche « 0 actives · 0 aujourd'hui » + lane À encaisser vide.
- grounding DB: les commandes listées ont `business_date` 2026-06-07/06-08/06-10 (impayées multi-jours) — le endpoint `counter-collect/pending` n'a pas de borne de date, le tracker si.
- evidence: `02-pos-initial.png` vs `22-tracker.png` ; requête `orders WHERE queue_number IN ('A0012'…)`.
- recommendation: borner la file au `business_date` du jour (+ purge/expiration auto des commandes borne impayées) et afficher la date quand ≠ aujourd'hui (le « 01:47 » seul se lit comme un âge).

**[P2] `PosComponent.vue:1016-1085` — création client caisse exige EMAIL\* et MOT DE PASSE\* (pré-rempli)**
- reproduction: ticket → « + » (Ajouter un client) → modal avec NOM\*, TÉLÉPHONE, EMAIL\* requis, MOT DE PASSE\* requis pré-rempli (dots).
- friction comptoir: pour un client de passage, nom/téléphone suffisent (références Toast/Square) ; un mot de passe pré-rempli silencieux est en plus trompeur.
- evidence: `14-customer-modal.png`.
- recommendation: rendre email/password optionnels côté caisse (génération backend silencieuse), ou mode « client rapide » nom+téléphone.

**[P2][FROZEN-GATE] wizard caisse — CTA « Ajouter au panier » vert menthe hors palette**
- Le CTA principal du wizard est vert/teal (≈#2EC4A6) alors que toute la caisse utilise l'orange brand #F4501E pour le CTA primaire ; le total wizard est aussi vert. `pos-wizard.css` frozen → gate.
- evidence: `08-after-click-coca.png`.

**[P2] DATA `items.description` — « Upsell item » (anglais brut) visible sur les tuiles**
- reproduction: grille POS, tuiles « Menu (Frites + Boisson) », « Frites Seules », « Boisson Seule » → sous-titre **« Upsell item »**. Mandat FR exclusif (checklist §3.25).
- evidence: `51-tile-closeup.png`.
- recommendation: fix data (UPDATE items SET description FR) — vérifier aussi la borne qui projette le même catalogue.

### P3

**[P3] Titres modaux en Title Case fautif FR** — « Paiement De Commande » (`PaymentComponent.vue:15`, classe `capitalize` — frozen → [FROZEN-GATE]), « Ajouter Un Client » (`PosComponent.vue:1019`, non-frozen), « Encaisser La Commande Borne » (PosCounterCollectModal). La classe Tailwind `capitalize` capitalise chaque mot ; en FR seule l'initiale se capitalise. evidence: `30b`, `14`, `21`. recommendation: retirer `capitalize` sur les titres non-frozen.

**[P3] « Impossible d'ouvrir le tiroir. » sans cause** — Ouvrir tiroir → 422 `cash-drawer/open` (imprimante non configurée) alors que la session caisse est active ; le toast FR existe (bien) mais n'explique ni cause ni action. evidence: `24-ouvrir-tiroir.png`, `25-caisse-session.png`. recommendation: message différencié (« imprimante non configurée » / « pas de session »).

**[P3] Suivi commandes — placeholder recherche tronqué** « Rechercher par N° ou » (input trop étroit). evidence: `22-tracker.png`.

**[P3] Chips catégories tronquées indistinguables** — « Sandwich … » ×2 (Cayenne vs Classique) impossibles à différencier sans tooltip. evidence: `02-pos-initial.png`. recommendation: 2 lignes claires ou abréviation distinctive.

**[P3] Modal encaissement borne — CTA de validation sous le pli** à 900px de haut (numpad coupé, bouton valider atteint par scroll). Pattern action-bar sticky (Toast Go) recommandé. evidence: `40-collect-modal.png`.

**[P3] Ligne ticket qté>1 sans suppression directe** — corbeille seulement à qté=1 (`PosV5QtyStepper :show-trash="quantity===1"`) ; à qté 3 il faut 3 décréments ou « Annuler la dernière ligne » (dernière ligne seulement). evidence: `10/12`, probe `50-stale-wizard-probe.png`.

---

## ✅ Ce qui est bon

- **Encaissement borne bout-en-bout fonctionnel** : Encaisser → modal Espèces (montant pré-rempli « 3,80 », numpad à virgule FR, 4 tenders symétriques) → toast succès « Tiroir ouvert (simulation) — Commande N°A0012 enc… », file décrémentée 47→46 (`40/41`).
- **Ticket latéral solide** : merge de lignes au ré-ajout, steppers ± avec aria-labels FR explicites (« Augmenter la quantité — Coca-Cola 33cl »), total héro FR « 1,50 € », CTA « Commande · 1,50 € » avec montant intégré, « Annuler la dernière ligne », remise gated motif obligatoire (3 car. min) — checklist §3.13/23 OK.
- **États vides illustrés FR partout** : panier (« Aucun article… »), recherche sans résultat (illustration + « Aucun article trouvé avec ces critères »), tracker (5 lanes avec icônes) — §3.18 OK.
- **Recherche & catégories** : filtre instantané (skeleton tiles pendant chargement), chips visuelles avec état actif anneau brand, « Toutes les catégories ».
- **Park/Resume fiable** : park vide le panier, badge « En attente », panneau avec recherche + Restaurer/Supprimer, restore re-remplit le ticket.
- **Type de commande** : segmented À emporter/Livraison avec formulaire livraison inline propre (Nom/Téléphone/Adresse + autocomplete).
- **Caisse session** : modal claire (fond initial, ouverture, mouvements, montant attendu, Clôturer rouge).
- **Suivi commandes** : page dédiée 5 lanes, filtres Toutes/Caisse/Borne/En ligne, « Retour caisse » — bonne topologie.
- **CTA fidélité désactivé avec tooltip explicite** (« Créez d'abord une commande… ») — gate LOCK respectée, pas de capture du modal (nécessite commande en vol) ; design LOCKED non contesté.
- **Palette/branding** : light-mode 100 %, orange #F4501E réservé aux CTA primaires, texte #1A1A1A ; prix tuile #F4501E 15px = tension AA connue gated owner (non reportée comme défaut).
- **Console propre** en session caissier stable (0 erreur JS hors churn de token admin partagé — artefact harness multi-agents, pas un défaut produit).

## Notes harnais (pas des findings)
- `admin@lecayenne.fr` partagé entre scouts parallèles → 401 en boucle (révocation Sanctum au relogin) ; un compte par agent.
- Cache Playwright chromium supprimé en cours de run (disque plein) → fallback `channel: 'chrome'`.
- Probe `_w1-c1-70` : le wizard se démonte proprement après ajout (le « double Coca » mi-run = artefact de séquence harnais, pas un bug).

**Bilan: 3 P1 · 5 P2 · 6 P3 (14 findings) — 0 P0, aucun flux cassé bloquant l'encaissement.**
