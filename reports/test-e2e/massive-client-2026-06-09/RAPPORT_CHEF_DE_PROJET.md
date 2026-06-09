# Rapport de session — Audit UI/UX + Test E2E massif des apps client (Mobile + Web)
**Date :** 2026-06-09 · **Périmètre :** 2 applications client *standalone* (prototypes React/Babel-in-browser, aucun branchement backend)
**Statut final :** ✅ **CONVERGÉ — 0 P0 / 0 P1 ouvert. Tout en local, RIEN poussé.**

---

## 1. Contexte & périmètre

Deux produits client autonomes, sans wireup API (mandat V1 propriétaire → **0 impact NF525 / frozen-zone / backend**) :
- **App Mobile** — `mobile/` — branche `heal/uiux-exec-2026-06-08` — servie en local sur `:8097`.
- **Site Web** — `/Users/1millnonstop/Downloads/web/` — branche `heal/uiux-2026-06-08` — servi sur `:8096`.

Palette imposée mobile : **NOIR / ORANGE / JAUNE / BLANC** (l'orange #FF5A1F est la couleur de marque).

La session a enchaîné 3 phases :
1. **Exécution de l'audit UI/UX** (corrections a11y/i18n/contraste préalables).
2. **Ultra-review par système** (2 sous-agents adverses, lecture seule) + **convergence du P0 fidélité**.
3. **Test E2E massif “jusqu'au vert”** (compétence `test-e2e`) avec **3 rondes d'agent adverse** + **1 décision propriétaire**.

---

## 2. Méthodologie

- **Vérification technique** : `axe-core` (WCAG 2.0/2.1 niveaux A + AA) exécuté écran par écran ; vérification de syntaxe via `@babel/parser` (sans navigateur) ; tests Playwright capturant chaque état.
- **Vérification visuelle** (mandat §6) : lecture réelle des captures d'écran — **indispensable**, car axe ne voit pas tout (cf. incident §3.3).
- **Règle de convergence** : on ne livre que lorsque **2 cycles consécutifs** rapportent **0 P0 + 0 P1** avec des **résultats identiques** (l'égalité d'ensembles tue les “flakes”).
- **Équipe adverse** : sous-agents *lecture seule* dont le rôle est de **contester** chaque conclusion (« qu'est-ce qui a été raté ? »).

---

## 3. Problèmes rencontrés — cause, raisonnement, action

### 3.1 — Incident DISQUE PLEIN (bloquant matériel)
- **Cause :** le disque système était à **100 %** (424/460 Gio). Un processus Chromium *headless* bloqué (issu d'un run Playwright en timeout) faisait osciller l'espace libre à **0**, ce qui empêchait le harness d'écrire la sortie des commandes → **tout Bash bloqué**, y compris le nettoyage.
- **Raisonnement :** ce sont les **lancements de navigateur Playwright** (plusieurs centaines de Mo de temp chacun) qui tuent le disque, **pas** les transcripts des sous-agents.
- **Action :** sous-agents de revue en **lecture-seule sans navigateur** (`curl` + lecture de code) ; vérification de syntaxe via `@babel/parser` au lieu de lancer un navigateur ; suppression des transcripts d'agents terminés ; réinstallation de Chromium (`npx playwright install chromium`, ~91 Mo) après éviction sous pression disque. Récupéré ensuite à 6+ Gio libres.

### 3.2 — P0 FIDÉLITÉ : taux de points incohérent (10 vs 1 pt/€)
- **Cause :** `config.earn_ratio = 10` + une étiquette “10 pts par €”, alors que **tout le contenu réellement écrit** (copie d'onboarding, historique seedé, solde 347, app web) est à **1 pt/€**.
- **Raisonnement initial (ERRONÉ) :** j'ai d'abord raisonné « économie conçue à 10 pt/€ » (cashback 10 % cohérent avec les paliers de récompenses) et **gardé 10**, en routant tout par un helper SSOT `pointsFor`.
- **Ce que l'agent adverse a prouvé :** mon correctif a **DÉPLACÉ** l'incohérence au lieu de la résoudre — la **même commande** affichait **+13 dans l'onglet Fidélité** (seed brut) **vs +130 dans l'onglet Commandes** (recalcul). L'évidence empirique (tout le contenu = 1 pt/€) contredisait mon inférence théorique.
- **Action :** **`earn_ratio 10 → 1`** → `pointsFor` = `round(total)` = 1 pt/€ partout, **zéro re-seed**, mobile désormais == web. **Leçon : l'empirique > la théorie.**

### 3.3 — RÉGRESSION que J'AI introduite, qu'axe a MASQUÉE (la plus importante)
- **Cause :** au Wave M-3 j'ai référencé `var(--green-text)` / `var(--red-text)` dans le mobile — **mais ces tokens n'étaient définis que dans le CSS du WEB** (j'avais mal lu un grep multi-fichiers).
- **Conséquence :** `color: var(--green-text)` (token inexistant) retombait sur la couleur héritée `--ink` = **NOIR** (qui *passe* le contraste axe → mes re-scans paraissaient propres !), et surtout `background: var(--green-text)` sur le badge de commande livrée retombait sur **transparent → badge “RÉCUPÉRÉE” INVISIBLE**.
- **Raisonnement :** axe peut être satisfait par un *mauvais* repli. Seule la **vérification visuelle** + un **grep tokens référencés vs définis** détectent ce cas.
- **Action :** ajout des tokens à `mobile/styles.css` + **vérification visuelle** (badge redevenu vert rgb(12,107,49), points historique verts/rouges et non noirs). **Anti-piège final : 100 % des tokens `--*-text`/`-dark` référencés sont définis (mobile 7/7, web 3/3).**

### 3.4 — Dérive de points créée par mon propre correctif SSOT
- **Cause :** mon override `Number(o.points_earned) || pointsFor()` faisait afficher à la commande C-1100 **+38** sur la carte (seed qui **double-comptait** le bonus de bienvenue, déjà sa propre ligne au grand livre fidélité) vs **+13** au détail.
- **Action :** seed C-1100 `38 → 13` → chaque commande satisfait désormais `seed == round(total)` → **carte == détail** partout. **Leçon : un correctif “tout passe par un seul SSOT” peut créer une dérive si le seed a des cas spéciaux ; vérifier carte == détail par commande.**

### 3.5 — Angle mort systématique : les SOUS-ÉTATS
- **Cause :** un scan axe limité aux pages d'atterrissage (accueil/menu) **rate** les onglets, modales, états “complétés”, commandes livrées, etc.
- **Constat chiffré :** **5 des 8** premiers défauts + **tout le bloc M-3** + tout le long-tail M-5/W-3/W-4 vivaient dans des **sous-états** jamais visités par le scan de surface.
- **Action :** balayage empirique exhaustif de chaque sous-état (Commandes EN-COURS + HISTORIQUE, détail commande, Fidélité Mes-points/Récompenses/Historique, modales gain/redeem) + **grep exhaustif** de tous les `color/background: var(--green|red|orange)`.

### 3.6 — Faux positif d'animation (à NE PAS “corriger”)
- **Cause :** sur le menu web, les cartes à révélation tardive (`.lc-rv-4`, délai 240 ms) déclenchaient 4 nœuds de contraste **uniquement** quand axe scannait **en plein fondu** (opacité < 1 → le crème de la page transparaît derrière le texte).
- **Action :** vérifié qu'à l'état **stabilisé** (opacité = 1, ce que voit l'utilisateur) les cartes sont **propres** ; `prefers-reduced-motion` est géré. Le harness attend désormais la fin de la révélation avant de scanner. **Leçon : distinguer un échec persistant d'un artefact d'animation.**

### 3.7 — DÉCISION PROPRIÉTAIRE : CTA orange de marque (gate, pas correctif silencieux)
- **Cause :** les CTA primaires mobile (`.lc-btn`, 15 px gras, texte blanc sur orange de marque #FF5A1F) échouent WCAG AA à **3,14:1** (ex. “VALIDER MA COMMANDE”, “Confirmer”, “Voir mon QR” — ~14 boutons).
- **Raisonnement :** changer toute la couleur de marque des CTA est une **décision de design** (§10/§12 — la palette orange mobile est un mandat propriétaire). Je **n'ai pas** corrigé en silence ; j'ai escaladé via une question avec aperçu visuel avant/après. Le web avait déjà résolu ça (`--orange-text`, accepté).
- **Décision propriétaire : « miroir du web → `--orange-text` ».**
- **Action :** swap chirurgical (perl conditionné sur `color:'#fff'`) des ~14 fonds CTA blanc-sur-orange → `--orange-text` (#C2410C, **5,18:1**), en **préservant** l'orange non-textuel (avatar encre-sur-orange 6,35:1, barres/blobs décoratifs, “★ Recommandé” orange-sur-foncé). Vérifié : contraste cart CTA = NÉANT, rendu burnt-orange lisible (visuel confirmé).

---

## 4. Résultats vérifiés (état final)

- **16 surfaces × 2 cycles consécutifs + flux redeem** — **toutes axe-CLEAN**, **0 erreur console**, ensembles de résultats **identiques** → règle de convergence satisfaite.
- **Intégrité numérique** : 1 pt/€ cohérent sur les deux apps (mobile `pointsFor(1,50)=2` == web “+2 pts” ; carte fidélité 347 pts = 3,47 € ; 347/500 → 153 restants ; récompenses 100/250 “Disponible” + 500 “153 pts manquants” ; **carte == détail == `pointsFor(total)` pour chaque commande**).
- **Honnêteté “démo”** présente et vérifiée : mobile = feuilles “BIENTÔT DISPONIBLE” (Apple/Google Wallet) ; web = page paiement “100 % sécurisé” **+ badge DÉMO V1**.
- **Tokens** : 100 % des `--*-text`/`-dark` référencés sont définis (mobile 7/7, web 3/3).
- **Frozen-zone / NF525 :** **0 impact** (apps standalone, hors backend).

---

## 5. LISTE COMPLÈTE DES FICHIERS MODIFIÉS

### 5A — MOBILE (`mobile/`, branche `heal/uiux-exec-2026-06-08`) — 11 fichiers de code
| Fichier | Nature des changements |
|---|---|
| `mobile/styles.css` | **Ajout des tokens** `--green-text:#0C6B31` et `--red-text:#A8142A` (corrige la régression §3.3). |
| `mobile/screens-main.jsx` | nested-interactive ×41 (overlay `<button>` + bouton action frère, zIndex) ; hearts “envies” = vrai toggle favoris (button-name ×4) ; balayage contraste orange ; `AllergenBadge` `role=region`→`img` ; ETA panier + bandeau fidélité gatés sur `cart.length>0` ; ligne historique commandes `points_earned||pointsFor` ; aria-label barre de progression fidélité ; pastille statut commande →`--orange-text` ; point source historique `role=img` ; total commande / carte “Dépensé” contraste ; compteurs verts →`--green-text` ; pastilles HALAL/VEGGIE/UTILISER →`--green-dark` ; total reçu →`--orange-text` ; logout + “Code invalide” →`--red-text` ; **CTA blanc-sur-orange →`--orange-text`**. |
| `mobile/screens-modals.jsx` | contrastes VISA / “Retour à mes commandes” ; badge “LIVRÉE/RÉCUPÉRÉE” →`--green-text` ; total détail →`--orange-text` ; “points gagnés” (modale gain, fond JAUNE) →`--ink` ; coût redeem →`--orange-text` ; pastilles toast/RGPD/“★ Récompense” ; avertissement RGPD →`--red-text` ; **CTA blanc-sur-orange →`--orange-text`**. |
| `mobile/screens-item-steps.jsx` | wizard : `aria-label` sur `.rdw-progress` ; compteur “0/1” →`--orange-text` ; conteneur `role=radiogroup`→`role=group` (cases multi-sélection) ; libellés viande/crudité verts →`--green-text` ; pastille “POPULAIRE” →`--orange-text`. |
| `mobile/screens-onboarding.jsx` | CTA login `disabled={!valid}` + `aria-disabled` ; 3 contrastes (“// Étape 1/2”, “Modifier”) →`--orange-text` ; eyebrow “03 — Pickup” → **“03 — Retrait”**. |
| `mobile/components/LoyaltyQR.jsx` | `role="img"` **recentré sur le visuel QR uniquement** (le conteneur englobait le bouton “régénérer” + le timer = nested-interactive). |
| `mobile/components/WizardRedeem.jsx` | pastille “★ Récompense” →`--orange-text` ; bandeau erreur →`--red-text` ; coût “−N pts” →`--orange-text` ; solde-après vert/rouge →`--green-text`/`--red-text` ; **CTA blanc-sur-orange →`--orange-text`**. |
| `mobile/data/loyalty.js` | `earn_ratio 10 → 1` + commentaire SSOT `pointsFor` (résout le P0 fidélité §3.2). |
| `mobile/data/orders.js` | C-1100 `points_earned 38 → 13` (supprime le double-comptage du bonus bienvenue) ; estimation active `33 → 30`. |
| `mobile/redesigns-styles.css` | récompenses verrouillées : suppression `opacity:0.55` sur la ligne (tuait le contraste texte) → désaturation + vignette atténuée seulement ; `.rdl-hist-pts.earn/.spend` →`--green-text`/`--red-text`. |
| `mobile/index.html` | enrobage du contenu dans une balise `<main>` (landmark a11y), positionnée à l'identique. |

### 5B — WEB (`/Users/1millnonstop/Downloads/web/`, branche `heal/uiux-2026-06-08`) — 11 fichiers de code
| Fichier | Nature des changements |
|---|---|
| `account-v2.jsx` | mode par défaut de la modale **signup → login** (état initial + effet de reset à la fermeture, W-FN-1) ; association `id`/`htmlFor` des champs ; “Oublié ?” →`--orange-text`. |
| `screens.jsx` | carte d'accueil “Pickup only” → **“À emporter”** ; accent “cumuler.” →`--orange-text` ; lien Instagram `rel="noopener noreferrer"` + URL réelle. |
| `screens-v3.jsx` | fuites anglaises restantes : citation presse “Pickup-only assumé” → “Le 100 % à emporter, assumé” ; “Pickup en 12 min” → “Prêt en 12 min”. |
| `orders.jsx` | onglet “Livrées” → **“Terminées”** (modèle retrait, pas livraison) ; accent “retrouver” →`--orange-text`. |
| `funnel.jsx` | libellé étape “Pickup” → **“Retrait”** ; ajout badge **DÉMO V1** sur la ligne sécurité paiement ; promo appliquée/total promo →`--green-text`. |
| `flows.jsx` | promo valide (vert) / invalide (rouge) →`--green-text`/`--red-text`. |
| `loyalty-v2.jsx` | texte du bouton “Se déconnecter” `--red`→`--red-text` (bordure laissée en `--red` : composant UI, seuil 3:1). |
| `wizard-v2.jsx` | “OBLIGATOIRE” + bandeau de validation rouge →`--red-text`. |
| `styles-v2.css` | `.lc-diet-chip.is-on` (blanc sur orange) →fond `--orange-text` ; `.lc-hours-status` (vert sur teinte verte) →`--green-text`. |
| `styles-v3.css` | leaderboard (head/name/pts) + prix suggestion panier (orange) →`--orange-text` ; pastille “✕” retrait (sur teinte rouge) →`--red-text`. |
| `styles-v4.css` | valeurs ticket / montant suivi (orange) →`--orange-text` ; numéro d'étape “done” (blanc sur vert) →fond `--green-text` ; message d'erreur champ (rouge) →`--red-text`. |

### 5C — Documents produits (rapports/mémoire)
- `reports/test-e2e/massive-client-2026-06-09/CONVERGENCE_FINAL.md` — rapport de convergence détaillé (le journal technique complet de l'E2E).
- `reports/test-e2e/massive-client-2026-06-09/RAPPORT_CHEF_DE_PROJET.md` — **ce document**.

---

## 6. Historique des commits (chronologique)

### Mobile (`heal/uiux-exec-2026-06-08`)
```
e415b46ae  Wave 1A      a11y: nested-interactive x41 + button-name x4 + price contrast
32efe2b8f  Wave 1A cont contraste petit-texte orange (suite)
d881dbf45  Wave 1B      loyalty: P0 earn-rate 10x via pointsFor SSOT  (← inférence à corriger)
342a0ab80  Wave 1B-fix  loyalty: CONVERGENCE earn-rate à 1 pt/€ (état cohérent)
3c5f6a32b  Wave 1C      a11y: contrast finish + login gate + <main> + empty-cart
3d241ad9d  Wave M       a11y: wizard/loyalty progressbar names + 0/1 contrast + radiogroup→group
8d50c2701  Wave M-2     a11y: LoyaltyQR nested-interactive + contraste orders/rewards
003959b1d  (doc)        rapport convergence — 14 surfaces clean x2
628a8def8  Wave M-3     a11y+data: balayage angle-mort (contraste, aria, dérive points)
3b532fbf2  (doc)        rapport — dispute adverse + re-convergence 16 surfaces
6ffd3cd19  Wave M-4     a11y: DÉFINITION tokens --green-text/--red-text (régression) + pills + Pickup eyebrow
7f57142d9  Wave M-5     a11y: restes --red-as-text → --red-text
a2352a749  (doc)        rapport — catch régression M-4 + long-tail + 16x2
9549b8292  Wave M-6     a11y: WizardRedeem siblings manqués (pill/error/cost/balance)
5e87cbee0  (doc)        rapport — round-3 + gate CTA documenté
fdbc4a5b0  Wave M-7     a11y: CTA blanc-sur-orange → --orange-text (décision propriétaire)
7bc1302b1  (doc)        rapport — gate CTA RÉSOLU (miroir web), convergence totale
```
### Web (`heal/uiux-2026-06-08`)
```
ef84abf  Wave 3a       a11y/ux: login-default + Retrait i18n + badge DÉMO paiement
5bd93a0  Wave 3b       a11y/i18n: Pickup i18n + label assoc + contraste + rel=noopener
dd4db55  Wave 3b cont  i18n: fin du balayage Pickup (presse/features v3)
fc9c35a  Wave W-2      a11y: accents hero loyalty/orders → orange-text
92d5776  Wave W-3      a11y: migration --orange/green/red → -text (petit texte)
5ef1e08  Wave W-4      a11y: restes --green/--red-as-text + teintes → -text
```

---

## 7. État de livraison & reste à faire

- **TOUT EST EN LOCAL — RIEN POUSSÉ.** Branches : mobile `heal/uiux-exec-2026-06-08`, web `heal/uiux-2026-06-08`.
- **Reste (hors périmètre de cet E2E, décision propriétaire / données) :**
  - **P0 légal web** : ~29 mentions “À COMPLÉTER” dans les pages légales (`/Downloads/web/legal/*.html`) = bloqueur LCEN → **nécessite les données du propriétaire** (SIRET, adresse, etc.).
  - **P3-C web** (mineur) : faire ouvrir l'onglet “Inscription” par le CTA “Créer mon compte” (threading `initialMode`).
  - Revue par système des **systèmes backend** (POS/Borne/KDS/Dashboard/Sync/Backend) — **différée** (hors apps client).

---

## 8. Leçons codifiées (réutilisables)

1. **axe est nécessaire mais pas suffisant** — la majorité des défauts vivaient dans des **sous-états** (onglets, modales, états complétés). Toujours scanner *chaque* état, pas seulement la page d'atterrissage.
2. **axe peut être satisfait par un mauvais repli** — un token CSS non défini → texte en noir (passe) ou fond transparent (invisible). **Le mandat visuel §6 est non-négociable** + grep “tokens référencés vs définis” après toute migration `var(--x-text)`.
3. **Un correctif “tout-par-un-SSOT” peut créer une dérive** quand le seed a des cas spéciaux → vérifier carte == détail par élément.
4. **Empirique > théorie** : le contenu réellement écrit a tranché le taux fidélité, pas le raisonnement économique.
5. **Distinguer un échec persistant d'un artefact d'animation** (évaluer l'état stabilisé que voit l'utilisateur).
6. `--green`/`--red` = pour fonds/points/grand-titre/bordures ; le **petit texte** exige `--green-text`/`--red-text` ; le blanc-sur-vert exige `--green-dark`, le blanc-sur-orange `--orange-text`.
7. Une **décision de couleur de marque** se traite en **gate propriétaire**, pas en correctif silencieux.

---
*Apps standalone, aucun écrit en base d'exploitation, aucune zone gelée ni invariant NF525 touché. Convergence E2E : 0 P0 / 0 P1.*
