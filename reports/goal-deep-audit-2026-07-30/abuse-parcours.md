# ABUSE — Parcours de commande web Le Cayenne (adversaire)

**Cible** : http://127.0.0.1:8899 (React 18 prod + Babel JSX ES5). Playwright headless **Pixel-7 + desktop 1280**. Aucune commande réelle créée (arrêt avant paiement final ; API pointe sur VPS). Scripts : `/tmp/lc-abuse/*.js` — captures `/tmp/lc-abuse/shots/`.
**Bilan : P0 = 0 · P1 = 0 · P2 = 2.** Le parcours est globalement robuste ; 2 défauts réels reproduits 100 % sur les 2 devices.

---

## P2-1 — Course « auto-avance radio 200 ms » vs clic « Continuer » → saut d'étape (dont une étape OBLIGATOIRE sans défaut)
**Fichier** : `wizard-v2.jsx:521-523` — un clic sur une option **radio** programme `setTimeout(… setIdx(i=>i+1), 200)`. Si l'utilisateur tape aussi **« Continuer »** dans la fenêtre de 200 ms, **deux** `setIdx+1` s'exécutent → **une étape est sautée**. `canAdvance` (l.729) ne garde que l'étape *visible*, jamais l'étape survolée.
**Repro A (script `race.js`)** : Cayenne → étape 1 « Pain ou galette ? » → clic « Pain » **puis** « Continuer » immédiat → saut **1/6 → 3/6**, l'étape **Sauce disparaît**. Masqué ici car la sauce a un défaut (« Fromagère maison » conservé) → l'utilisateur ne choisit jamais sa sauce sans le savoir.
**Repro B (script `cascade-race.js`, plus grave)** : Cayenne → « Faire un menu ? » = **Menu complet** → étape « Choix de la boisson » (6/8) → clic boisson **puis** « Continuer » → saut **6/8 → 8/8 Récap**, l'étape **« Sauce pour les frites »** (`wizard-v2.jsx:331-337`, `required:true, min:1`, **AUCUN défaut**) est **contournée**. « Ajouter au panier » **actif** à 9,90 € → article ajoutable **sans** la sauce frites pourtant obligatoire. Preuve : `shots/cascade-race-mobile.png` (récap 8/8, section « Suppléments → Aucun », frites-sauce non choisie), console `race.js`/`cascade-race.js`.
**Impact** : étape de personnalisation escamotée en silence ; sur la cascade menu, un champ **obligatoire sans défaut** part vide en cuisine. Prix inchangé, pas de crash. **Fix suggéré** : au clic « Continuer », `clearTimeout` de l'auto-avance en attente (ou drapeau anti-double-advance).

## P2-2 — Message de validation **en ANGLAIS** sur un site 100 % français
**Fichier** : `api.js:187` renvoie tel quel `json.message` du backend ; `funnel.jsx:228` fait `promoErr: e.message`. La route coupon `/api/frontend/coupon/coupon-checking` (`api.js:758`) renvoie la validation Laravel par défaut, non traduite.
**Repro (script `verify.js`, 2 devices)** : Checkout → Code promo → saisir **> 20 caractères** → « Appliquer » → **« The code must not be greater than 20 characters. »** Preuve : `shots/checkout-mobile.png`, `shots/verify-promo-*.png`. Les autres erreurs sont correctement FR (« Le coupon n'existe pas », « Réseau indisponible »). Seule la contrainte longueur fuit en anglais. **Fix** : traduire côté backend (lang FR) ou mapper le message dans `applyPromo`.

---

## Mineurs (non comptés — reproduits mais faible/by-design)
- **Quantité panier sans plafond** : +121 atteint (`flows.jsx:51` borne seulement le bas). Prix correct (229,90 €), **pas** d'overflow drawer. Plancher 1 OK.
- **Récap Tacos L** : entête « 2 VIANDES » alors que 5 listées (2 incluses + 3 en plus @ +7,50 €). Libellé ambigu mais chiffré juste.
- Promo « espaces seuls » → coupon retiré silencieusement (acceptable).

## Robustesse CONFIRMÉE (a tenu sous abus)
- **Aucun XSS** : `<img src=x onerror=alert(1)>` et `<script>` en promo/note rendus en **texte** (échappement React), **0 alert()** déclenchée. QR = order-id backend uniquement (`funnel.jsx:40-49`).
- **« Continuer » désactivé** tant qu'une étape obligatoire n'est pas satisfaite (`canAdvance`, `wizard-v2.jsx:729`) — impossible de sauter *par le bouton*.
- **Multi-clic « Continuer »** capé (`Math.min(len-1, i+1)`) ; **fermer/rouvrir** → reset étape 1, pas de fuite d'état.
- **Réseau coupé** : menu rend **28 cartes** (couche data statique, pas d'écran mort) ; apply promo → **« Réseau indisponible. Vérifie ta connexion. »** (FR propre).
- **Supprimer dernier article** → état vide correct (« Ton panier est vide »). *(Le P2 « empty-state manquant » du 1er passage était un ARTEFACT de harnais — drawer fermé/inert — écarté après repro propre.)*
- **Créneau « Choisir une heure » vide** → bouton payer **désactivé**.
- **Note** capée à 190 (`funnel.jsx:410`).
- **Max viandes/sauces/suppléments** appliqués (erreur « Maximum N sélections », `wizard-v2.jsx:535`).
- **Page paiement** atteinte pré-auth : propre, 0 label brut, 0 erreur JS, totaux/points cohérents (`shots/verify-payment-*.png`).

**Total : P0=0 · P1=0 · P2=2.**
