# Audit SITE WEB client Le Cayenne — repo Vercel PRODUCTION
**Date** : 2026-07-15 · **Repo audité** : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne` (remote `loeymot-sketch/Site-lecayenne`, branch `main` == `origin/main` @ `c1fdc8e`, working tree clean → ce qui est audité = ce que Vercel sert).
**Méthode** : lecture intégrale des 13 fichiers app (index.html, api.js, data/*, 8 .jsx, CSS) + greps ciblés (secrets, URL, innerHTML, min-width). Read-only, aucun fichier du site modifié.
**⚠ La copie `/Users/1millnonstop/Downloads/web` (divergée) n'a PAS été auditée.** Plusieurs fixes livrés dans la copie divergée (commit `4bfedbd` du 2026-07-12) n'ont JAMAIS été portés dans ce repo prod — c'est la cause racine de 4 findings ci-dessous (notes, OTP min-width, email facultatif, coupon 0 €).

---

## Synthèse
| Sévérité | Nb | Résumé |
|---|---|---|
| P0 | 1 | API = `http://127.0.0.1:8766` → commande/OTP/fidélité/coupon 100 % morts en prod |
| P1 | 4 | Note allergies jetée ×2 · créneau retrait fictif + jamais envoyé · OTP déborde mobile (connexion bloquée) · « Itinéraire » → mauvaise adresse |
| P2 | 7 | +25 pts fantôme ×3 · contradiction livraison · FAQ Stripe/Apple Pay mensongère · email/prénom exigés puis jetés · choix wizard payés perdus (viande supp, sauce frites menu) · fausses stats + « OUVERT » statique · bloc app mobile fictif |
| P3 | 8 | Voir liste (durcissement, code mort trompeur, divers) |

Contrôles NÉGATIFS (sains, vérifiés) : aucun secret réel en dur (la X-API-Key est la clé app front publique, cf. P3-1) ; XSS : un seul `dangerouslySetInnerHTML` (components.jsx:83) alimenté par la lib QR vendorisée sur un token signé de notre backend — pas de données user injectées en HTML, tout le reste passe par React (échappé) ; prix : AUCUN prix client envoyé (api.js:8, items = ids/quantités seulement, promo/livraison recalculées backend) ; fix wizard plein écran mobile + footer flex PRÉSENTS (styles.css:652-670, styles-mobile.css:20-29,190-204) ; SRI sur les 3 scripts unpkg ; headers sécurité vercel.json présents ; page paiement honnête (carte OFF derrière flag, funnel.jsx:470-475).

---

## P0

### [P0-1] index.html:11 — API base = `http://127.0.0.1:8766` : le funnel de commande entier est décoratif en production
- **Occurrences (TOUTES, vérifiées)** :
  - `index.html:11` → `<meta name="api-base-url" content="http://127.0.0.1:8766">` — **la valeur ACTIVE** (la meta prime sur le fallback).
  - `api.js:20` → fallback `metaContent('api-base-url', 'http://127.0.0.1:8766')`.
  - `data/menu.js:54` → fallback images `http://127.0.0.1:8766/images/menu/` — **neutralisé** en prod par la meta `menu-image-base=assets/menu/` (index.html:19) : les photos sont OK, seule l'API est morte.
  - (Mentions non exécutables : index.html:18 commentaire, data/menu.js:46 commentaire, VERCEL_DEPLOY.md:15 qui documente exactement ce piège.)
- **Impact** : depuis `https://site-lecayenne.vercel.app`, TOUT appel `fetch(CFG.base + path)` (api.js:127) meurt deux fois — mixed content (page https → http interdit) ET loopback client. Cassés : envoi OTP (`/api/auth/guest-signup/otp`) → **connexion impossible** ; `placeOrder` → **aucune commande ne part en caisse** (le funnel affiche « La commande a échoué » funnel.jsx:427) ; fidélité (profil, QR, historique, redeem) ; validation coupon ; suivi commande.
- **Repro** : prod → panier → paiement → « Confirmer la commande » → gate OTP → « Recevoir le code » → console : `Mixed Content: … requested an insecure resource 'http://127.0.0.1:8766/api/auth/guest-signup/otp'` → `Réseau indisponible`.
- **Fix scope-minimal** : remplacer la valeur de `index.html:11` par l'URL HTTPS publique du backend (owner doit la fournir — VPS) + côté backend : CORS pour l'origin Vercel. Bonus 1 ligne : garde dans api.js qui log/alerte si `location.protocol==='https:'` et `CFG.base` commence par `http://`.

---

## P1

### [P1-1] funnel.jsx:301-313 + flows.jsx:119 — La « Note pour la cuisine » (allergies) est COLLECTÉE puis JETÉE (2 chemins)
- **Preuve** :
  - Checkout : `ctx.notes` rempli (funnel.jsx:303, placeholder « **Allergie au gluten**, sauce à part, sans oignons… ») mais `placeRealOrder` construit `orderOpts = { cart, paymentMethod, couponId, loyaltyCode, idempotencyKey }` (funnel.jsx:385) — pas de note ; `api.placeOrder` n'a aucun champ note (api.js:421-448). `grep "ctx.notes"` → seules occurrences = l'UI (funnel.jsx:303,312).
  - Panier : `CartDrawer` a SA PROPRE textarea `notes` (flows.jsx:11,119) en state local ; `onCheckout(promo)` ne forwarde que le code promo (flows.jsx:133 → index.html:124 `goCheckout`). Note perdue à la fermeture.
- **Aggravant** : FAQ_HOME (screens-v3.jsx:112) dit aux végétariens « commandés sans viande (**note dans instructions**) » — instructions qui n'arrivent jamais en cuisine. Risque sécurité-alimentaire (allergie déclarée non transmise).
- **Repro** : taper une note au checkout → confirmer → payload POST `/api/frontend/order` : aucun champ note/instruction (hors menuNoteParts).
- **Fix scope-minimal** : passer `note: ctx.notes` dans `orderOpts` → dans `placeOrder`, l'apposer sur la 1ʳᵉ ligne via le champ `instruction` déjà supporté (api.js:388-389, tronqué 500). CartDrawer : lifter `notes` vers `ctx.notes` (prop) au lieu d'un state local. (Fix équivalent déjà écrit dans la copie divergée `4bfedbd` — à porter.)

### [P1-2] funnel.jsx:142-156 — Créneau retrait : dates/heures CODÉES EN DUR (fausses) et choix JAMAIS envoyé au backend
- **Preuve** :
  - Jours : `{ id:'today', d:'AUJ', n:'14' }, { id:'tomorrow', d:'JEU', n:'15' } … n:'18'` (funnel.jsx:150-156) — figés ; aujourd'hui = mer. **15** juil. → le site affiche « AUJ 14 » (hier) et des jours faux.
  - Heures : `'Dans 20 min' → '19h45'`, `'1h30' → '20h55'`… (funnel.jsx:143-148) — figées quelle que soit l'heure réelle. Idem CartDrawer (flows.jsx:49-53 : « 19h45 », « 20h05 »).
  - Transmission : `placeRealOrder` (funnel.jsx:385-396) n'envoie NI jour NI heure ; `is_advance_order` toujours 0 (api.js:432), `delivery_time:'ASAP'` seulement en livraison. Le gating CTA exige pourtant un slot (funnel.jsx:207).
- **Impact** : un client qui « programme » dimanche 21h25 déclenche une commande ASAP — la cuisine la prépare tout de suite, le client vient 4 jours plus tard. La confirmation affiche en plus « Prêt à {slotTime} » (funnel.jsx:677).
- **Repro** : checkout → jour « DIM 18 » + « Dans 2 h » → payer → payload : `is_advance_order:0`, aucun champ heure ; KDS reçoit la commande immédiatement.
- **Fix scope-minimal** (gate owner sur l'option) : (a) générer jours/heures depuis `Date.now()` + horaires 18h-00h ; (b) transmettre le créneau (`is_advance_order:1` + champ horaire du contrat backend) OU retirer le sélecteur et assumer « Dès que prêt » honnête. Le minimum honnête immédiat = (b)-retrait + heures dynamiques.

### [P1-3] styles.css:921-936 + account-v2.jsx:247-260 — Cases OTP sans `min-width:0` → débordent et sont clippées sur mobile → connexion cassée (fix jamais porté ici)
- **Preuve** : `.lc-otp-grid { grid-template-columns: repeat(4, 1fr); }` (styles.css:922) et `.lc-otp-cell` (styles.css:925-934) **sans `min-width`/`width`**. Un `<input>` a une largeur min intrinsèque ≈ 170 px (size 20 par défaut ; `maxLength=1` ne la réduit pas) ; en grid, `1fr` = `minmax(auto,1fr)` → la rangée réclame ~700 px ; le modal plein écran mobile (~390 px) a `overflow: hidden` (`.lc-modal` styles.css:626-632) → cases 3-4 clippées hors écran. Le MÊME bug a été reproduit LIVE et fixé (`min-width:0`) dans la copie divergée le 2026-07-12 (commit `4bfedbd`) — **absent du repo prod** (`grep min-width styles.css` : rien sur .lc-otp-cell).
- **Portée** : modal compte (inscription/connexion) = le seul chemin d'auth → bloque commande + fidélité sur mobile. (Le gate OTP inline du paiement, funnel.jsx:599-602, utilise un input simple : non affecté.)
- **Repro** : viewport 390 px → « Se connecter » → Inscription → « Recevoir le code » → écran « Entre ton code » : cases débordent/clippées.
- **Fix scope-minimal** : `.lc-otp-cell { min-width: 0; width: 100%; }` (2 déclarations).

### [P1-4] screens.jsx:249 — Bouton « Itinéraire » (home, bloc horaires) pointe vers l'ANCIENNE adresse fausse
- **Preuve** : `href="https://www.google.com/maps/dir/?api=1&destination=14+rue+de+la+R%C3%A9publique+62110+H%C3%A9nin-Beaumont"` alors que l'adresse réelle (corrigée partout ailleurs le 2026-07-12) est **437 Rue Élie Gruyelle** — affichée 3 lignes au-dessus (screens.jsx:246) et correcte au funnel (funnel.jsx:188-191 via `brand.address`).
- **Impact** : le GPS du client l'envoie dans la mauvaise rue — exactement la classe « toutes les infos incorrectes » que l'owner a fait corriger.
- **Repro** : home → section horaires → « Itinéraire » → Google Maps ouvre 14 rue de la République.
- **Fix scope-minimal** : réutiliser la construction du funnel : `'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(brand.address)`.

---

## P2

### [P2-1] screens.jsx:79, screens.jsx:129-131, funnel.jsx:561 (+ data/menu.js:524-526) — « +25 pts à l'inscription » : bonus FANTÔME encore promis à 3 endroits vivants
- Le backend `/loyalty/register` crée le compte à **0 point** (api.js:474-477) ; le heal 2026-07-08 n'a retiré la mention QUE de l'onglet « Comment ça marche » (screens.jsx:865, commentaire explicite) et le fix `c1fdc8e` a manqué ces occurrences : carte WHY home (:79), badge flottant hero « +25 Points à l'inscription » (:129-131), encart compte de la page paiement (:561). Source de données : `PEPPER_CLUB.welcome_bonus:25` + perk « +25 pts inscription » (data/menu.js:524,526).
- **Fix** : remplacer par « 1 € = 1 pt » (comme fait ailleurs) + purger `welcome_bonus`/perk.

### [P2-2] screens-v3.jsx:110 + components.jsx:189 vs funnel.jsx:230-251 — Le site VEND la livraison ET affirme ne pas livrer
- FAQ home : « Vous livrez ? **Non, on est 100 % à emporter** » (screens-v3.jsx:110) ; footer : « **Pas de livraison**, juste du fait maison à venir chercher » (components.jsx:189) ; hero home : « Pas de livraison qui prend 1h » (screens.jsx:100). MAIS le checkout propose « Livraison · Offerte dès 30 € » avec devis géocodé + frais (funnel.jsx:230-251, barème api.js:36-44, order_type 5).
- **Impact** : quoi qu'il en soit une des deux surfaces ment au client. Gate owner : soit la livraison existe (corriger FAQ/footer/hero), soit elle n'existe pas (retirer le mode livraison du funnel).

### [P2-3] screens-v3.jsx:113 — FAQ « Comment je paie ? » : « En ligne : CB sécurisée **Stripe** ou **Apple/Google Pay** » = mensonger
- Le paiement en ligne est OFF (`feature-online-card=0`, index.html:15) et ces options ont été SUPPRIMÉES du DOM paiement précisément comme « copy mensongère » (funnel.jsx:466-470, 499, 545-548). La FAQ live du home les re-promet. Écho légal : legal/cgv.html:133 liste aussi Apple/Google Pay. Item voisin : carte WHY « paye en caisse **ou en ligne** » (screens.jsx:77).
- **Fix** : « En caisse : espèces ou CB. La commande en ligne se règle au comptoir au retrait. » + aligner cgv.html.

### [P2-4] account-v2.jsx:46-54 — Inscription EXIGE prénom + email valides… qui ne sont JAMAIS transmis
- `validate()` bloque si `!form.email.includes('@')` (:49, s'applique au signup) et si prénom vide (:48) ; `submit()` n'envoie que `phone` (`api.guestOtp(phone)`, :74 ; api.js:146-148 body `{phone, code}`) ; `guestVerify` idem. Prénom/email = collectés, obligatoires, puis jetés → friction inutile (échec « Email invalide » pour un champ sans usage) + minimisation RGPD violée (données exigées sans finalité ni transmission). La copie divergée les a rendus FACULTATIFS (`4bfedbd`) — jamais porté.
- **Fix scope-minimal** : rendre prénom/email facultatifs (supprimer les 2 lignes de validate, marquer les champs « facultatif »), ou les transmettre réellement si le backend les accepte.

### [P2-5] api.js:333-339 + wizard-v2.jsx:272-282 — Deux choix du wizard sont PERDUS à l'envoi (payé sans précision / obligatoire puis jeté)
- **(a) Viande supplémentaire +2,50 €** : le client choisit UNE viande précise (« Tenders », wizard-v2.jsx:128-134, max 2) ; `resolveLine` n'envoie que l'extra générique « Viande supplémentaire » × quantité (api.js:335-339). Ni variation ni `instruction` ne porte la viande choisie (menuNoteParts = frites/boisson uniquement, api.js:342-367) → la cuisine facture 2,50 € sans savoir QUOI griller.
- **(b) Sauce des frites de la formule menu** : étape `cascade_frites_sauce` **OBLIGATOIRE** (required min1 max1, wizard-v2.jsx:272-282) ; `grep cascade_frites_sauce api.js` → **0 occurrence** : le choix n'est ni variation, ni extra, ni note. Le client est forcé de choisir, puis son choix s'évapore.
- **Repro** : Tacos M + viande supp Tenders + menu complet sauce Algérienne → payload : extra « Viande supplémentaire » ×1, instruction = « Frites: … · Boisson menu: … » sans Tenders ni Algérienne.
- **Fix scope-minimal** : dans `resolveLine`, pousser dans `menuNoteParts` : `'Viande supp: ' + noms` (depuis `st.viande_extra` → pool meats) et `'Sauce frites: ' + nom` (depuis `st.cascade_frites_sauce` → pool sauces) — le champ `instruction` existe déjà (api.js:388-389).

### [P2-6] screens.jsx:303-305, :98, :245 — Fausses statistiques + statut « ouvert » permanent sur le home
- Compteurs animés « **12 500+** Commandes servies » et « **1 200+** Inscrits Pepper Club » (screens.jsx:304-305) : chiffres inventés — la même donnée « 1 200 inscrits » a été retirée de la page L'enseigne comme fausse le 2026-07-14 (`c1fdc8e`), le home a été oublié.
- « **OUVERT** · HÉNIN-BEAUMONT » (hero, :98) et « **Ouvert maintenant** » (:245) : libellés statiques, faux 75 % de la journée (ouverture 18h-00h).
- **Fix** : retirer/remplacer les compteurs par des faits vérifiables (ex. nb de plats — déjà calculé W_ITEMS.length) ; calculer le statut ouvert/fermé depuis l'heure (18h-00h) ou libeller « Ouvert 7j/7 · 18h-00h ».

### [P2-7] screens.jsx:266-284 — Bloc « L'app Le Cayenne » : app inexistante présentée comme téléchargeable, liens morts `href="#"`
- « Télécharger App Store » / « **Disponible** Google Play » pointent sur `#` (screens.jsx:276-283) + promesses (« push quand c'est prêt », « temps réel ») sans backend. Le footer a été healé (toast « arrive bientôt », components.jsx:222-223) mais ce bloc home a été manqué. Écho : FAQ_HOME:114 « carte plastique… liée au même compte que **l'app** ».
- **Fix** : même traitement que le footer (toast « bientôt ») ou retirer la section.

---

## P3

- **[P3-1] api.js:21 — X-API-Key en dur** `b6d68vy2-…` : clé app front FoodKing, publique par nature sur un site statique (pas un secret exposé) — vérifier que le backend ne la traite jamais comme frontière d'auth (l'auth réelle = Bearer Sanctum) ; rotation = redeploy.
- **[P3-2] funnel.jsx:34-50 + :661 — faux QR de retrait** : `TicketQR` = mock décoratif (classe `lcf-ticket-qr-mock`, motif calculé) mais la copy dit « **Présente ce QR à la caisse** » — il ne scanne rien. Encoder l'`orderId` avec vendor/qrcode.js (déjà chargé) ou reformuler « Présente ce numéro ».
- **[P3-3] funnel.jsx:172-174 — coupon appliqué à −0,00 €** : pas de garde `discount > 0` → un coupon valide mais à remise nulle affiche « ✓ CODE · −0,00 € appliqué » (fix « coupon 0 € » de la copie divergée non porté).
- **[P3-4] funnel.jsx:390 — adresse livraison re-sauvée à CHAQUE tentative** de paiement (retry après échec = nouvelle ligne address backend, label suffixé uuid api.js:89) — dédupliquer ou sauver après succès.
- **[P3-5] api.js:66-76 — géocodage Nominatim (OSM)** : l'adresse du client part vers `nominatim.openstreetmap.org` (tiers) — absent de la politique de confidentialité ; prévoir mention RGPD + respecter l'usage policy (volume faible OK).
- **[P3-6] index.html:51-53 — React DEVELOPMENT + Babel standalone en prod** : 10 fichiers `text/babel` transpilés dans le navigateur à chaque visite (lent sur mobile, warnings console) ; dépendance unpkg (SRI présent ✓) ; pas de CSP dans vercel.json ; token Sanctum en localStorage (standard mais exposé si XSS — CSP le mitigerait).
- **[P3-7] Code mort TROMPEUR à purger avant qu'il ne re-fuit** : `WebAbout` non routé (aucun `setRoute('about')` ; contient « Plus de 1 200 inscrits dès le 1ᵉʳ mois » screens.jsx:893, équipe non vérifiée Karim/Léa via TeamStrip screens-v3.jsx:130-148, FAQ_ABOUT « événements privés » :117-122) ; `PressStrip` citations presse FABRIQUÉES (Voix du Nord/TF1/France 3/Time Out « 4,8★ » — screens-v3.jsx:36-54, non rendu) ; `PAST_ORDERS` démo (orders.jsx:5-11, non rendu) ; branche morte `+0,50/sauce frites` (data/menu.js:513, `fritesSauceIds` toujours `[]` wizard-v2.jsx:312) + rendu `opt.savings` orphelin (wizard-v2.jsx:511).
- **[P3-8] Divers logique/copy** : orders.jsx:64 « pts cumulés » = `Math.round(total dépensé)` **annulées incluses** (sur-affiche ; earn réel = floor par commande livrée) ; « ↻ Commander à nouveau » (orders.jsx:123) = simple lien menu, pas un re-order ; OTP : coller « 1234 » ne remplit qu'une case (account-v2.jsx:254, `slice(-1)`) ; onglet Connexion = champ mot de passe validé (`length<4` → erreur) puis totalement ignoré (account-v2.jsx:51 vs :60-66) — théâtre de formulaire ; toggles « Notifications » persistés localStorage sans aucun backend d'envoi (loyalty-v2.jsx:66-101, libellés promettent push/SMS) ; Compare home « Si tu trouves mieux… **on rembourse** » (screens-v3.jsx:62) = engagement commercial non validé owner.

---

## Restes owner (connus, hors scope — NE PAS re-corriger sans gate)
1. **Mentions légales LCEN** : 26 « [À COMPLÉTER] » (mentions.html ×10, privacy.html ×7, cgv.html ×4, cookies.html ×4, allergens.html ×1) — dont le prestataire de paiement (privacy:186, cgv:139).
2. **Photos Insta = tuiles emoji** (screens.jsx:231-235) + poignée `@lecayenne_` à confirmer (screens.jsx:229).
3. **Lien Uber Eats** : N/A dans CE repo — `grep -i uber` = 0 occurrence (le bouton « livraison Uber » n'existe que dans la copie divergée `/Downloads/web`, qui n'est pas le site déployé).
4. **Page fidélité déco** : N/A ici — la page fidélité de ce repo est câblée au réel (profil/QR/redeem/historique) ; vue non connectée = simple invite à se connecter.
5. **URL backend publique** : prérequis du fix P0-1 — seul l'owner peut fournir l'URL HTTPS du backend (VPS) + ouvrir CORS.
