# W1-C2 — Audit UI/UX flux ENCAISSEMENT (round 1)

**Scout C2 · 2026-06-11 · app :8768 (DB jetable foodking_e2e) · viewport 1440×900 fr-FR**
Screenshots : `shots-c2/` (18 captures, toutes lues + analysées). Scripts jetables : `tests/e2e/_w1-c2-*.mjs`.
Mutations réelles effectuées (DB jetable) : **3 commandes borne encaissées** via /admin/encaissement — #4328 Espèces (50 € reçus), #4329 Ticket restaurant, #4330 Carte réf `SUMUP-TX-TEST-4242` — toutes 200, sorties de la file (50→47). Remboursement **NON exécuté** (capture only, owner-gated). PaymentComponent (FROZEN) non rencontré dans ce flux — le parcours encaissement borne passe par `PosCounterCollectModal` (sibling non-frozen) ; aucun finding [FROZEN-GATE].

---

## FINDINGS

### [P1] `PosCounterCollectModal.vue:589` — toast d'erreur 429 brut en ANGLAIS « Too Many Attempts. », doublé du toast FR global
- reproduction : encaisser plusieurs commandes en rafale (panneau POS « À encaisser borne » → Encaisser → Confirmer) ; le POST `/counter-collect/{id}/confirm` répond 429 → **2 toasts simultanés** : « Too Many Attempts. » (message Laravel brut via `err?.response?.data?.message`) + « Trop de requêtes — patientez 1s avant de réessayer. » (interceptor global `bootstrap.js:246`).
- evidence : `shots-c2/14-pos-apres-encaissement.png` (les 2 toasts visibles, EN au-dessus du FR).
- recommendation : dans le `catch` du modal, ne pas re-toaster quand `status === 429` (l'interceptor global est déjà la « single source » — pattern documenté dans `PaymentComponent.vue:943`). Vérifié grep : `PosCounterCollectModal.vue:589`, `bootstrap.js:246-247`.

### [P2] `PosCounterCollectModal.vue:612-623` (`.cc-modal` max-height 92vh) — CTA « Confirmer & Imprimer ticket » sous la ligne de flottaison à 900 px de hauteur
- reproduction : ouvrir Encaisser (mode Espèces, défaut) à 1440×900 → mesure DOM : modal scrollHeight 928 / clientHeight 828, bouton Confirmer top=888 bottom=940 (> viewport 900) → le caissier doit **scroller dans le modal** pour atteindre l'action primaire (le hero total sort aussi de l'écran pendant le scroll).
- evidence : `shots-c2/13-pos-modal-encaisser.png` (footer coupé), géométrie loggée par `_w1-c2-retake.mjs`. Grille DESIGN_REFERENCES §2 Toast : « action bar sticky verrouillée en bas ».
- recommendation : `position: sticky; bottom: 0` sur `.cc-modal-footer` (+ fond opaque), ou compacter le numpad. Atténuant : Entrée valide depuis l'input (`@keyup.enter`), pré-rempli → cas exact-change OK sans scroll.

### [P2] `EncaissementComponent.vue:272` — toast succès parent au numéro VIDE « Commande N° encaissée », en doublon du toast du modal
- reproduction : encaisser n'importe quelle commande depuis /admin/encaissement → le modal toaste correctement (« Tiroir ouvert (simulation) — Commande N°A0009 encaissée ») PUIS le parent toaste `$t('label.encaisser_success', { order: '' })` → « **Commande N° encaissée** » (placeholder vide, dégénéré).
- evidence : `shots-c2/06-apres-encaissement-cash-toast.png` + `10-apres-carte-double-clic.png` (2 toasts empilés, celui du haut sans numéro).
- recommendation : supprimer le toast parent (le modal couvre déjà les 3 modes) ou lui passer le `queue_number` du payload `confirmed`.

### [P2] `PosOrderShowComponent.vue` (bloc Informations Client) — commande borne affichée avec le compte ADMIN comme client, incohérent avec le masquage « Client borne »
- reproduction : /admin/pos-orders/show/4328 (commande borne encaissée) → « Informations Client : Admin Le Cayenne / admin@lecayenne.fr / +330600000000 ». La page /admin/encaissement masque pourtant l'identité machine en « Client borne » (`EncaissementComponent.vue:223-231`, garde anti-fuite token kiosk).
- evidence : `shots-c2/15b-order-show-net.png`.
- recommendation : appliquer le même resolver (source_surface=kiosk → « Client borne ») sur la fiche commande ; le téléphone placeholder `+330600000000` ne devrait pas s'afficher tel quel.

### [P2] `PosOrderReceiptComponent.vue:119-120` — ticket fiscal FR imprime « VAT (10%) » (anglicisme) dans la ventilation TVA
- reproduction : fiche commande payée → contenu `#print` : « Total taxes: 0,09 € / **VAT (10%)** · Base HT 0,91 € — 0,09 € ». Le template rend `line.tax_name` verbatim ; la taxe en DB s'appelle « VAT ».
- evidence : textContent `#print` loggé (`_w1-c2-receipt.mjs`) — ticket complet ci-dessous §✅.
- recommendation : DATA-fix (renommer la taxe « TVA » en DB, geste owner/data-ops) ou mapping d'affichage `VAT→TVA` côté receipt. Mention « Mentions légales : TVA intracommunautaire - Merci de votre visite » = texte de réglage à revoir aussi (data).

### [P3] `PosCounterCollectModal.vue:637` — `text-transform: capitalize` sur le titre → « Encaisser La Commande Borne » (Title Case non conforme typo FR)
- evidence : `shots-c2/02b-modal-cash-element.png`, `13-…png`. Même motif global breadcrumb (« Tableau De Bord / Commandes Caisse / Voir »).
- recommendation : retirer `capitalize` (la clé FR `encaisser_mode_title` est déjà correctement casée).

### [P3] `PosV5Numpad.vue:53-65` — touches ⌫ et C dupliquées (2×⌫ + 2×C empilées en colonne droite)
- reproduction : ouvrir le modal Espèces → colonne droite du pavé : ⌫ / ⌫ / C / C, quatre boutons distincts identiques deux à deux ; ressemble à un défaut de rendu (l'intention du commentaire « backspace span » était visiblement un bouton sur 2 rangées).
- evidence : `shots-c2/02b-modal-cash-element.png`.
- recommendation : `grid-row: span 2` sur une seule touche ⌫ et une seule C.

### [P3] `PosCounterCollectModal.vue:698` (`.cc-hero-value` 'Rubik Mono One') — montant héro rendu « 3 , 80 € » (espacement anormal autour de la virgule)
- evidence : `shots-c2/13-pos-modal-encaisser.png` (MONTANT TOTAL « 3 , 80 € »). Format FR respecté mais la mono-font écarte la virgule comme un chiffre.
- recommendation : conserver tabular-nums mais via Rubik bold standard (cohérent avec `.enc-amount` de la liste).

### [P3] `EncaissementComponent.vue:244-248` — badge d'attente « 22h58 » illisible comme durée (se confond avec une heure d'horloge)
- reproduction : file avec commandes anciennes → badges rouges « 23h04 », « 22h58 »… positionnés là où on attend un horodatage.
- evidence : `shots-c2/01b-encaissement-liste-net.png` + dump classes (`enc-wait-critical`, rgb(185,28,28) — seuils 15/30 min OK).
- recommendation : préfixer « il y a » ou icône ⏱ (« ⏱ 22 h 58 »).

### [P3] `PosOrderShowComponent.vue` (en-tête) — date « 01:41, 10-06-2026 » (tirets, heure d'abord) non conforme convention FR ; N° borne (A0009) absent de l'en-tête
- reproduction : fiche commande → en-tête « N° Commande: #1006264328 » + « 01:41, 10-06-2026 » ; le numéro court borne A0009 (celui annoncé au client et affiché partout ailleurs) n'apparaît que dans le ticket.
- evidence : `shots-c2/15b-order-show-net.png`.
- recommendation : format `10/06/2026 01:41` + afficher le badge N°A0009 dans l'en-tête.

**Cross-ref (déjà connu, hors C2)** : les selects « Payé / Livré » modifiables sur la fiche d'une commande payée (`15b`) recoupent le P0 connu « changePaymentStatus hors séquence fiscale » (ultra-audit W1-W3 2026-06-10) — non recompté ici.

---

## ✅ CE QUI EST SOLIDE (vérifié réellement)

- **Liste /admin/encaissement** : 50 commandes, chip compteur, **« Total en attente d'encaissement 247,60 € » exact** et décrémenté après chaque encaissement (246,60 → … → 241,80) ; badges source « Borne » ; identité masquée « Client borne » ; items tronqués à 4 + « +n… » ; montants FR `12,50 €` partout ; CTA brand `#F4501E` ; poll 20 s + Echo temps réel ; empty-state prévu (`enc-empty`).
- **Modal encaissement (3 modes TESTÉS pour de vrai)** : pré-remplissage FR « 3,80 » avec auto-select ; **rendu monnaie exact** (50,00−1,00=49,00 € ✓ ; 10,00−3,80=6,20 € ✓, bandeau vert aria-live) ; Espèces/TR/Carte → 200, sortie de file immédiate ; réf SumUp optionnelle persistée (note).
- **Garde-fous saisie** : montant insuffisant → message FR `role=alert` + Confirmer désactivé ✓ ; champ vide → désactivé ✓ ; **double-clic Confirmer → 1 seul POST** (bouton disabled + clé idempotence minute-bucket) ✓ ; Échap ferme ✓ ; focus trap ✓ ; numpad 1er tap remplace le pré-rempli (anti « 8,501 ») ✓.
- **Panneau POS « À encaisser borne (47) »** : top-4 N°A00xx + montants FR + « Voir plus (43) → » + « Mis à jour à l'instant » ; même modal SSOT ; après 429 le modal reste ouvert (retry possible).
- **Refund (capture only)** : modal exemplaire — récap total/mode/date, warning jaune « commande miroir NF525… irréversible », **raison obligatoire min 5 caractères journalisée**, compteur 0/700, Confirmer inhibé tant que vide (`17b-refund-modal.png`).
- **Ticket NF525 (#print, texte intégral capturé)** : SIRET 10417050100019, TVA intra FR19104170501, **Opérateur: Admin Le Cayenne** (le caissier, pas le client ✓), adresse, N°A0009, ventilation TVA base HT, « Espèces: 50,00 € / Rendu : 49,00 € » ✓, « N° ticket NF525: 2167 », « Empreinte audit: 5b87e1221286 ».

**Aléas d'environnement (non-findings)** : binaire Playwright chromium supprimé en cours de run (cache partagé) → bascule `channel:'chrome'` ; revocations de token en boucle (agents concurrents re-login admin → « old tokens revoked ») → retries ; throttle 429 déclenché par la cadence script.

---

## VERDICT C2

| Sévérité | Compte |
|---|---|
| P0 | **0** |
| P1 | **1** |
| P2 | **4** |
| P3 | **5** |

**Top 3** :
1. **[P1] Toast 429 anglais brut + doublon** (`PosCounterCollectModal.vue:589`) — seul texte EN vu par un caissier dans tout le flux.
2. **[P2] CTA Confirmer sous le fold à 900 px** (`.cc-modal`) — l'action primaire d'encaissement ne devrait jamais demander un scroll.
3. **[P2] Toast parent « Commande N° encaissée » vide + doublon** (`EncaissementComponent.vue:272`) — feedback de succès dégradé sur chaque encaissement.

Flux d'encaissement **fonctionnellement irréprochable** (0 P0, rendu monnaie juste, idempotence prouvée, NF525 visible sur ticket) ; les findings sont du polish FR/affordance.
