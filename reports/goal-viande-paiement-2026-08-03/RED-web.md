# RED-team — Réfutation « page de commande UNIQUE » (web HEAD `e7d14a1`)

Date : 2026-08-03 · Repo audité : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne` (READ-ONLY)
Vérifs croisées backend : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
Commit attaqué : `e7d14a1` « feat(commander): page UNIQUE retrait+coordonnées+paiement »

---

## FINDINGS

### [P2] funnel.jsx:937-950 + index.html:151 — bloc « Compte (recommandé) » mensonger après validation inline (prop `isAuth` jamais mise à jour)
**Scénario.** Invité sur la page unique : remplit prénom/nom/téléphone/email, valide l'OTP → `authStep='done'`, token Sanctum stocké (`api.js` `guestVerify` → `setToken`). Il EST authentifié. Pourtant le bloc jaune `{!isAuth && …}` (funnel.jsx:937) reste affiché : « Connecte-toi ou crée un compte (30 sec) pour cumuler tes points fidélité » — FAUX, ses points seront cumulés (le compte téléphone existe déjà). S'il clique « Compte » (funnel.jsx:947 → `onAccount` → `AccountFlow`), il refait un OTP complet, et au succès `onAuthed` (index.html:419) fait `setRoute('loyalty')` → **éjecté de sa commande en cours** (panier conservé, mais il doit re-naviguer).
**Preuve.** `isAuth` est un `useState` parent initialisé une seule fois (index.html:151 `useState(() => !!(…isAuthed()))`) ; le seul setter `setIsAuth(true)` est dans `onAuthed` de la modale (index.html:419) et n'est JAMAIS appelé par le flux inline `verifyOtp` de funnel.jsx:703-729. Aucun re-render ne relit `api.isAuthed()`.
**Impact.** Copy mensongère + détour OTP inutile + navigation hors funnel en pleine commande. Pas de perte d'argent ni de commande → P2.

### [P2] account-v2.jsx:41-42 vs backend GuestSignupEmailOtpRequest.php:34-35 — incohérence min:2 : un nom d'1 caractère passe le front, 422 backend affiché SOUS LE CHAMP TÉLÉPHONE
**Scénario.** Modale « Se connecter » : prénom « O » (nom réel : O, Y, Ng sans 2e lettre…). `validate()` (account-v2.jsx:41 `!form.first.trim()` / :42 `!form.last.trim()`) n'exige que non-vide → passe. Le backend exige `'first_name' => ['required','string','min:2','max:100']` et idem `last_name` (`app/Http/Requests/GuestSignupEmailOtpRequest.php:34-35`, vérifié dans testttt) → 422. Le catch (account-v2.jsx:96-99) fait `setErrors(er => ({ ...er, phone: e.message }))` → le message de validation nom/prénom s'affiche **sous le champ Téléphone**, champ qui est correct.
**Contraste.** Le funnel, lui, est cohérent : funnel.jsx:696-697 exige `first.length < 2` / `last.length < 2` AVANT l'appel. Seule la modale account-v2 a le trou.
**Impact.** Client au nom d'1 lettre bloqué avec un message au mauvais endroit. P2 (population rare, contournable).

### [P2] funnel.jsx:724-728 — `verifyOtp` pose `authStep='done'` sans vérifier `api.isAuthed()` (garde présente dans account-v2, absente ici)
**Scénario théorique.** Si `guestVerify` répond 2xx SANS `r.token`, `api.js:234` ne stocke rien (`if (r && r.token)`) mais funnel.jsx:728 pose quand même `'done'` → badge « ✓ Coordonnées confirmées » alors que `isAuthed()=false` → clic « Payer » → la garde submit (funnel.jsx:652-653) rebascule `'done'→'phone'` avec message → le client peut boucler verify→done→Payer→phone.
**Pourquoi P2 et pas P0 : le chemin n'est pas démontrable aujourd'hui.** Backend vérifié (`app/Http/Controllers/Auth/GuestSignupController.php:178-213`) : `verify()` soit jette (→422), soit appelle `register()` qui émet TOUJOURS un token (`:319 createToken` → `:334 'token' => $this->token`) ; aucun 2xx sans token n'existe. Mais account-v2.jsx:118-121 fait explicitement ce check (« Un 2xx ne prouve pas l'auth ») — la refonte a perdu cette défense-en-profondeur dans le funnel. Fix 1 ligne : `if (api.isAuthed()) setAuthStep('done'); else setAuthErr(…)`.
**Nota (Q2, boucle sans issue) : RÉFUTÉ.** Token expiré pendant paiement → `api.js:198-201` purge le token + jette `kind:'auth'` → funnel.jsx:681 `setAuthStep('phone')` + `apiError`. Le client re-valide (`'done'`), re-clique « Payer » (clé d'idempotence conservée, funnel.jsx:491-507) → commande part. `'done'` avec `isAuthed()=false` est toujours rattrapé par la garde submit (:652-653) qui rebascule `'phone'` — pas de dead-end.

### [P1 — PRÉ-EXISTANT commit `6858540` (2026-08-01), PAS introduit par HEAD] funnel.jsx:528-561 + :857 — champs carte Mollie MORTS après un aller-retour carte→comptoir→carte
**Scénario.** `onlineCard` actif. Client clique « Carte » → l'effet (:528, deps `[onlineCard, ctx.method, mollieProfileId]`) monte les 4 composants Mollie dans `#lc-mollie-*` et pose `mollieRef.current = m` (:544). Client revient sur « Payer sur place » → le rendu conditionnel `{ctx.method === 'card' && …}` (:857) **détruit les divs** (composants Mollie montés dans des nœuds détachés). Client re-clique « Carte » → divs recréés VIDES ; l'effet re-tourne mais `mollieRef.current` non-null → early return (:529) → jamais re-montés. `createCardToken()` (:564) opère sur des composants orphelins → échec, client bloqué sauf reload.
**Rapport au HEAD attaqué (Q5).** La présélection comptoir ne change RIEN : `method:'counter'` était DÉJÀ le défaut du ctx parent avant ce commit (index.html:158, ligne non touchée par le diff). Le bug appartient au commit inline-payment `6858540`. Signalé car découvert pendant cette passe ; à réparer (démonter/re-monter au changement de méthode, ou ne pas démonter le formulaire).

### [P2] tests-e2e/one-page-checkout-2026-08-03.spec.js — le spec est vert mais ne teste PAS le changement de comportement central
**Preuves.**
- Le titre du commit dit « verify OTP authentifie seulement — c'est Payer qui envoie » : **aucun check ne le couvre** (en-tête du spec :8 « Aucune commande créée (on ne valide jamais d'OTP) »). L'état `'done'`, le badge « ✓ Coordonnées confirmées », et surtout « verify ne déclenche PLUS placeRealOrder » ne sont jamais exercés — c'est LA régression la plus dangereuse de la refonte et elle n'a aucun filet.
- Checks de PRÉSENCE, pas de VISIBILITÉ : `:76-80` `ok('bloc coordonnées visible…', !!(await contact.count()))` — un bloc en `display:none` passerait vert. Idem `#auth-first/#auth-last/#auth-phone/#auth-email`.
- `:96` « mode comptoir présélectionné » est vert grâce au défaut parent `method:'counter'` (index.html:158, pré-existant), PAS grâce au nouvel `useEffect` (funnel.jsx:743-745) — lequel est du quasi-code-mort (voir Q4 ci-dessous). Le test « valide » donc un mécanisme qui n'est pas celui du diff.
- Spec standalone (`node …spec.js`, serveur :8899 requis) — hors de toute suite automatique.
**Ce qui est honnête :** les 17 checks existants (fusion des sections, ordre vertical DOM via `compareDocumentPosition`, garde « Payer sans OTP ne navigue pas » + message exact `Confirme d'abord tes coordonnées`, 0 pageerror) correspondent à de vrais comportements du code — pas de mensonge actif, un trou de couverture.

---

## RÉFUTATIONS (findings cherchés, non confirmés — preuve à l'appui)

### [REFUTED] Q1 — Références mortes
- `window.CheckoutPage` : plus aucun call-site (`index.html:403` rend `PaymentPage` pour les deux vues ; grep repo → seuls des COMMENTAIRES citent encore « CheckoutPage.applyPromo » : flows.jsx:48, index.html:354-355 — cosmétique). L'alias `CheckoutPage: PaymentPage` (funnel.jsx:1259) couvre tout résidu.
- `CheckoutSections` (funnel.jsx:172-438) : ne référence plus `onNext/onBack/canProceed` ; `total` (:214) calculé-non-utilisé (toléré par l'énoncé) ; `dq` (:268) UTILISÉ (:323-325) ; `deliveryEnabled`, `est`, `slots` tous définis localement. Aucune ReferenceError possible.
- `card`/`payError`/sentinelle 4000… : zéro usage restant (grep `payError|setCard\b|card\.num` → 0 hit code). Seul le commentaire :450-452 décrit encore la sentinelle supprimée — commentaire périmé, cosmétique.
- Doublon `total` : `placeRealOrder` (:584 `expectedTotal: total`) lie le `total` OUTER de PaymentPage (:752, avec deliveryFee), pas celui de CheckoutSections (scope de fonction séparé). Appelé post-render → assigné. Pas de reprise du bug Babel-hoist 2026-07-19.

### [REFUTED] Q2 — Boucle OTP sans issue
Voir P2 n°3 : chaque état `'done'`-mais-non-authed est rattrapé par la garde submit (funnel.jsx:652-653) qui rebascule `'phone'`. Le chemin `kind:'auth'` (funnel.jsx:681) rouvre le bloc avec message. Re-cliquer « Payer » après re-validation est le comportement voulu (le verify n'envoie plus) — clé d'idempotence stable, pas de double commande.

### [REFUTED] Q4 — useEffect présélection `'counter'` deps `[]`
funnel.jsx:743-745 : `setCtx` vient d'un `useState` parent (identité stable, capture non dangereuse) et l'update est FONCTIONNELLE avec re-garde interne (`c.method ? c : {…, method:'counter'}`) → aucune écriture stale possible. En pratique l'effet est quasi mort : le ctx parent naît déjà avec `method:'counter'` (index.html:158) et rien ne le remet à null (les resets onHome/onTicket index.html:410-411 ne touchent pas `method`). Inutile mais inoffensif.

### [REFUTED] Q5 — 3DS / retour `?order=` vs page fusionnée
Le handler retour Mollie (index.html:269-304) ne dépend d'AUCUNE route checkout : il purge le panier, lit `lc.mollie.pending`, route directement `'confirm'` et poll le statut serveur (PAID=5). `'confirm'` est inchangé par la refonte. Le bouton « Payer au comptoir à la place » (funnel.jsx:912) purge le pending + `onNext()` → confirmation honnête « Tu paies sur place » (`paidOnline` non posé, `cardFallback` false). Cohérent. (Le vrai problème carte est le P1 pré-existant ci-dessus, indépendant du 3DS.)

### [REFUTED] Q6 — Garde panier vide / ghost-ticket / alias `#checkout`
- Cold load `#checkout` ou `#payment` : `RESTORE_ROUTES` (index.html:145) ne les contient pas → `'home'`. Sain.
- popstate : la map `ok` (:246) contient toujours `checkout:1` ; la garde (:248) `(r==='checkout'||r==='payment') && cart vide → 'menu'` couvre l'alias. Un vieux `#checkout` en historique rend PaymentPage (alias voulu) ou menu si panier vide.
- Ghost-ticket : garde AU RENDU (:366-372) sur `confirm/track` uniquement — non affectée par le diff, revalidée.
- Upsell→`'payment'` avec entrée modale `{modal:true}` au sommet : l'effet `[route]` (:231-235) fait `replaceState` (absorption prévue) — pas d'entrée fantôme, back depuis la page unique retombe sur `{menu}`.

### [REFUTED] Q8 — Upsell : perte des ajouts / boucle
`onProceed` (index.html:459) ferme l'upsell + route `'payment'`. « Retour » de la page unique (index.html:403) → `menu` + panier OUVERT : les ajouts upsell vivent dans `cart` (state + localStorage `lc.cart.v1`) → RIEN n'est perdu. Re-cliquer « Passer commande » rejoue l'upsell (goCheckout :356 `setUpsellOpen(true)`) — répétitif mais pré-existant, sortable en 1 clic, pas une boucle bloquante.

### [REFUTED] Q9 — Syntaxe moderne interdite dans les diffs
Le diff n'introduit AUCUN `?.`/`??`/spread nouveau : chemins durcis à l'ancienne (`function(c){ return Object.assign({}, c, …) }` funnel.jsx:640,653,744 ; `var el` :656). Les `?.` présents dans account-v2.jsx (:40, :106, :254) sont PRÉ-existants, hors diff, et tournent en prod depuis des semaines (babel-standalone 7.29 les compile — cf. comportement ES5 documenté funnel.jsx:598-601). Les `errors.first?'…':''` du diff sont des ternaires, pas de l'optional chaining.

### [REFUTED] Q7 (volet funnel) — min:2 côté page unique
funnel.jsx:696-697 exige déjà `length ≥ 2` avant `guestEmailOtp` → parité exacte avec le backend. Seule la modale account-v2 diverge (voir P2 n°2).

---

## VERDICT

**La refonte TIENT. 0 P0. Aucun crash, aucune référence morte, aucun chemin money-path cassé par `e7d14a1`.**

- Le flux « verify authentifie / Payer envoie » est correct et sans dead-end ; la garde panier/popstate/ghost-ticket et le retour 3DS survivent à la fusion des pages.
- **4 P2 à healer** (par ordre) : (1) `isAuth` parent jamais synchronisé après validation inline → bloc « Compte » mensonger + éjection loyalty ; (2) `verifyOtp` sans check `isAuthed()` (défense perdue vs account-v2) ; (3) min:2 absent du front account-v2 + erreur affichée sous le mauvais champ ; (4) spec e2e aveugle sur LE comportement central de la refonte (verify-sans-commande, état `done`).
- **1 P1 hors périmètre HEAD** (commit `6858540`, 2026-08-01) : champs Mollie morts après toggle carte→comptoir→carte — à corriger avant toute activation `onlineCard` en prod.
