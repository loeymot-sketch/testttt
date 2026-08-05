# [S4] V1 — AUDIT TOTAL DU SITE CLIENT (2026-07-29)

> Plan : `plans/GOAL_S4_WEB_FIDELITE_SUIVI_2026-07-29.md` · Discipline : `plans/DISCIPLINE_MULTI_SESSIONS_2026-07-29.md` §6.
> Statut des findings : APRÈS dispute RED (reproduce -> dispute -> prove). Les sévérités
> ci-dessous sont les sévérités POST-RED, pas celles des auditeurs de premier tour.

## Contexte verifie au demarrage
- Depot web `main` == `origin/main` == `5f220be`, arbre propre.
- Le LIVE (www.lecayenne.fr) sert `?v=20260729c` = exactement cette version. Aucune divergence.
- Backend VPS `https://vps-418872ac.vps.ovh.net` UP (`/up` -> 200).
- Baseline e2e `tests-e2e/nav-smoke.local.js` : **13/13 verts, 0 erreur JS**.
- Captures : 50 surfaces (desktop 1440x900 + mobile 390x844) -> `V1-shots/` + `surfaces.json`.
  Aucune erreur JS hors 404/offline provoques. Aucun label brut (undefined/NaN/cle i18n) sur 50 ecrans.
  Palette Cayenne respectee partout, y compris pages legales.

---

## P1 — GHOST-1 : fausse confirmation de commande avec QR de retrait reel
**Prouve visuellement par le lead** (`V1-shots/desktop-17-hash-confirm-direct.png`, idem mobile-17) :
ecran « C'EST PARTI ! / Ta commande #— est envoyee. Presente ce QR a la caisse pour la recuperer.
Tu paies sur place. » avec un **QR genere** et un ticket « — ». Idem `#track` : « EN PREPARATION »,
« ~12 MIN », NUMERO « — », TOTAL **0,00 EUR**, « +0 pts credites ».

**Mecanique exacte** (le RED avait conclu « retombe sur l'accueil » — vrai au chargement A FROID
seulement, il a manque la voie popstate) :
- `index.html:109` `RESTORE_ROUTES = {home, menu, orders, loyalty}` protege bien le **cold load**.
- MAIS `index.html:207` : `var r = (e.state && e.state.route) || String(window.location.hash||'').replace('#','') || 'home';`
  puis `:218` `setRoute(r)` — **aucune validation contre RESTORE_ROUTES**.
- Chemin client REEL, sans outil de dev : commander -> confirmation -> « Retour a l'accueil »
  (`index.html:351` vide `ctx.orderId`, `orderDbId`, `orderTotal`) -> bouton **Precedent** du navigateur
  -> popstate -> `r='confirm'` -> rendu avec `ctx` vide -> ticket fantome.
- Aggravant : `funnel.jsx:917` `if (!api || !ctx.orderDbId) return;` empeche le polling mais
  **n'empeche pas le rendu** de la page.

## P1 — LOY-1 : le redeem web debite les points et ne rend AUCUNE remise (texte mensonger)
RED : **CONFIRME**, et meme « plus vrai qu'annonce ». Requalifie P0 -> **P1** (les points ne sont
PAS perdus : le reaper les rend).
- `LoyaltyController.php:405` `'source_surface' => $isKiosk ? 'kiosk' : 'pos'`, `:401` `order_id => null`,
  debit reel `:391-393`. Un client web n'est ni KioskMachine (`:343-347`) ni staff (`:348`) => `'pos'`.
- Seul consommateur des pending : `FrontendOrderService.php:987` qui filtre `source_surface = 'kiosk'`.
  RED a greppe : **aucun** autre job/listener/commande/controleur POS ne lit ces lignes.
- La caisse ne peut PAS retrouver la remise : `PosRedemptionService.php:200` cree une ligne NEUVE et
  **redebite** le solde courant. Le texte `screens.jsx:884-885` « remise enregistree cote caisse »
  est donc **factuellement faux** ; pire, si le caissier rachete, il ponctionne une 2e fois.
- Aggravant independant : `ctx.loyaltyCode` n'est **jamais assigne** (grep : un seul hit, la lecture
  `funnel.jsx:529`), et `api.js:618-643` n'envoie jamais de champ `discount` => le web ne reclame
  aucune remise au checkout. Elargir le filtre backend serait **insuffisant seul**.
- Points regeles : reaper planifie `app/Console/Kernel.php:136-139` everyFiveMinutes ->
  `CleanupStalePendingKioskOrders.php:223` -> `LoyaltyService.php:220-231`. Gel reel 30 min + <=5 min.

## P1 — LOY-4 : seuil minimum incoherent, promesse intenable AFFICHEE
`LoyaltyController.php:383` et `PosRedemptionService.php:103` imposent un **multiple de 100** ;
`DiscountCalculator.php:57-63` autorise **50 pts = 0,50 EUR**. La page fidelite annonce
« 100 points = 1 EUR de reduction, utilisables des 50 points » (`V1-shots/desktop-14-loyalty.png`, lu par le lead).

## P1 — LOY-5 : paliers web inventes, divergents du backend
`screens.jsx:541-546` code en dur `Novice 0 / Pepper 500 / Master 1500 / Legende 5000`.
Backend publie `tiers = 100,250,500,1000,2000` (`LoyaltyController.php:505,533`), que la borne
consomme correctement (`KioskLoyaltyComponent.vue:351,477`). Violation du mandat « zero doublon ».

## P1 — TRK-2 : aucun temps restant sur l'ecran de suivi
`TrackingPage` n'appelle jamais `api.waitEstimate` (seul appelant : `funnel.jsx:176`, au checkout).
Le titre affiche un repli litteral **`'~12 MIN'` code en dur** (`funnel.jsx:951`) — chiffre invente.
L'endpoint reel existe et est PUBLIC : `GET order/wait-estimate`, `routes/api.php:1425-1427`,
`WaitEstimateService.php:42-87` (`low = min(base + 5*ceil(queue/3), cap)`, file cuisine reelle).
=> Le mandat owner « temps restant estime » n'est PAS rempli aujourd'hui.

## P1 — TRK-3 : aucune bascule visuelle quand la commande est PRETE
`funnel.jsx:950` reutilise `.lcf-track-status` pour tous les statuts ; `styles-v4.css:432-451` et
`481-489` ne definissent aucune variante « ready ». Le passage 7->8 ne change que le texte —
invisible sur un telephone pose sur la table.

## P1 — VIS-1 : le TOTAL de l'apercu live du wizard est clippe par le bas de la modale
Visible des l'etape 6/8 sur `desktop-07/08/09/11`. Le client perd son total au moment le plus sensible.
**HORS VOIE S4** (`wizard-v2.jsx` = S8 selon DISCIPLINE §10) -> handoff.

## P1 — VIS-2 : 404 serveur brute, en anglais, non stylee
`V1-shots/*-24-404-serveur.png` : « Error response / Error code: 404 / Message: File not found. »
Aucun branding, aucun lien de retour, sur un site FR live. (Note : capture faite sur le serveur
python local ; **a confirmer sur Vercel** via `vercel.json` avant de conclure.)

---

## P2 (retenus, post-RED)
- **TRK-1 (requalifie P0 -> P2 par le RED)** : suivi perdu au refresh. RED a prouve que le client
  n'est PAS aveugle : token 30 j (`GuestSignupController.php:252`), onglet « Commandes » restaurable
  (`RESTORE_ROUTES`), `orders.jsx:130` affiche le vrai `status_name` serveur, categorie `ready` `:13`.
  Residu reel : `orders.jsx:112,138` renvoient vers `'menu'`, jamais vers `'track'` ; `orders.jsx` ne
  poll pas (fetch unique au mount `:61`).
- **PAY-1 (requalifie P1 -> P2)** : `funnel.jsx:559` purge `lc.funnel.idem` AVANT le bloc Mollie
  (`:567-577`) et avant le redirect. RED a casse la moitie du scenario (le SPA ne remonte pas sur
  `payment`, exclu de RESTORE_ROUTES) mais le defaut de conception de la cle tient : aucune dedup
  serveur hors idempotence. Correctif sur : deplacer la purge APRES le bloc Mollie.
- **PAY-1bis (trouve par le RED, non signale au 1er tour)** : `submitting` n'est jamais remis a
  `false` sur le chemin succes-Mollie (`funnel.jsx:588-608`, pas de `finally`) => retour bfcache =
  bouton « Confirmation... » fige a vie.
- **TRK-4** : polling abandonne apres 3 echecs (`funnel.jsx:933-934`) sans aucun message.
- **TRK-5** : `OUT_FOR_DELIVERY (10)` mappe sur l'etape « Pret » (`funnel.jsx:901`).
- **TRK-6** : notifications promises non tenues (`loyalty-v2.jsx:80,84`, `screens.jsx:320` vs toggles
  purement localStorage `loyalty-v2.jsx:63-77`).
- **LOY-3** : solde jamais visible hors page Fidelite (ni header, ni panier/checkout/confirmation/suivi).
- **LOY-6** : `FrontendOrderService.php:955` exige `status = 1` STRICT, `return` silencieux `:959-961`.
- **LOY-7** : earn recalcule cote client x4 (`funnel.jsx:156,554,735`, `data/loyalty.js:66-69`) avec
  repli `ppe = 1` (`funnel.jsx:13,23`) => « +12 pts » affiche la ou le backend en creditera 120.
- **PAY-3** : jargon anglais visible client (500 « Server Error » ; 425/409 messages d'idempotence).
- **PAY-4** : 400 cle API -> « Erreur 400 » opaque (`ApiKeyMiddleware.php:28` renvoie une chaine).
- **PAY-5** : repli comptoir estampille `payment_method=4` (carte) en base (`funnel.jsx:520,574-576`).
- **PAY-6** : logique de prix dupliquee cote client (`data/menu.js:546-563`, `api.js:85-101`).
- **VIS-3** : CTA principal grise sans explication (wizard) — HORS VOIE (S8).
- **VIS-4** : mobile, la liste de sauces passe sous la barre d'action fixe — HORS VOIE (S8).
- **VIS-5** : emails — `resources/views/emails/order.blade.php` (11 lignes) est en **anglais**, sans
  aucun lien de suivi. (Trouve par le RED suivi.)

## P3
- **PAY-2 (requalifie P1 -> P3)** : pas d'`AbortController` (`api.js:174-181`). RED a refute
  « aucun message » : `funnel.jsx:595-607` a bien un `catch` qui rend la main et affiche
  « La commande a echoue. Reessaie ou paie en caisse. ». Residu : pas de plafond client.

---

## NON-DEFAUTS PROUVES (a ne pas re-remonter)
- **IDOR : FERME.** `FrontendOrderService.php:754-756` `abort(403)` si `user_id != Auth::id()` ;
  historique scope user `:100` ; route sous `auth:sanctum`. Aucune lecture croisee.
- **Chaine « prete » : COMPLETE.** `FrontendOrder` et `Order` partagent la table `orders`
  (`app/Models/FrontendOrder.php:19`) ; le bump KDS ecrit `status = PREPARED (8)` sur cette table.
  **Aucun handoff S3/S5 necessaire.**
- **Idempotence commande : 4 couches** jusqu'a l'UNIQUE DB `(branch_id, idempotency_key)`.
- **PAID exclusivement par webhook** re-fetche, montant + devise compares au total scelle.
- **Prix 100% backend (SSOT)** ; le front n'envoie que `item_id/quantity/option_ids` ;
  `expected_total` + `resolveExtraOrThrow` fail-loud.
- Absence de websocket cote web : polling 20 s assume en V1 mono-poste. Pas un defaut.

## A VERIFIER (non prouvable en lecture statique)
- `.env` VPS : `IDEMPOTENCY_MIDDLEWARE_ENABLED`, `APP_DEBUG`, `MOLLIE_TEST_API_KEY`, `MIX_API_KEY`.
- Comportement 404 reel sur Vercel (vs serveur python local).
- Visibilite en caisse d'une commande web `payment_method=4` restee UNPAID apres abandon Mollie.

## LACUNE DE COUVERTURE ASSUMEE (a fermer en V5)
Les captures `07-panier`, `08-upsell`, `09-checkout`, `10-checkout-validation-vide`, `11-paiement`
n'ont PAS prouve leur ecran cible (le pilote de wizard s'est bloque au 1er passage, l'enchainement
upsell->checkout reste a fiabiliser au 2e). Ces 5 surfaces x2 viewports seront recapturees et lues
apres les correctifs. **Aucun verdict n'est rendu sur ces ecrans dans ce rapport.**
