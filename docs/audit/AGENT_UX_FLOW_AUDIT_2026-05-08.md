# AGENT UX-FLOW — Audit cohérence flow + i18n FR + wording — 2026-05-08

> Rôle GSTACK : UX restaurant tech hostile. Lecture des 47 screenshots du
> MEGA PARCOURS + DOM probes + sources Vue/i18n. Aucun fix code, audit pur.
> Surfaces couvertes : POS V5 (caissier), Kiosk (client), KDS (cuisine).

## 0. Méthodologie + corpus

**Sources lues (durables)**

- `tests/e2e/screenshots/mega-parcours-2026-05-08/*.png` (47 screenshots, fullPage)
- `tests/e2e/screenshots/mega-parcours-2026-05-08/dom-probes.json` (19 probes)
- `tests/e2e/screenshots/mega-parcours-2026-05-08/findings.json` (28 findings)
- `tests/e2e/screenshots/mega-parcours-2026-05-08/INDEX.md`
- `docs/audit/MEGA_PARCOURS_E2E_REPORT_2026-05-08.md` (rapport agent E2E)
- `resources/js/i18n.js` (config locale runtime)
- `resources/js/languages/fr.json`, `en.json`
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `resources/js/components/admin/pos/ItemComponent.vue`

**Trust-but-verify** : chaque leak EN observé screenshot → grep source + fr.json
pour distinguer (a) hardcoded EN dans template vs (b) clé manquante dans
traductions vs (c) locale runtime EN qui sert simplement la version EN d'une
clé existant aussi en FR.

---

## 1. Bilan U1 — i18n drift FR/EN

### Découverte clé : navigator.locale décide la langue POS

Dans `resources/js/i18n.js` :

```js
const KIOSK_LOCALE = 'fr';   // /kiosk → forcé FR (OK)
function detectLocale() {
    if (isKioskPath()) return KIOSK_LOCALE;
    if (typeof navigator !== 'undefined') {
        const lang = navigator.language?.split('-')[0];
        if (lang && SUPPORTED_LOCALES.includes(lang)) return lang;
    }
    return DEFAULT_LOCALE; // 'fr'
}
```

Conséquences :

- **Kiosk (`/kiosk/*`)** : locale forcée `fr` → ✅ tous les screenshots kiosk
  rendent du français propre (« Bienvenue », « À emporter », « Carte bancaire »,
  « Espèces », « Titre restaurant », « Confirmer », « Rendez-vous en caisse »).
- **POS (`/admin/pos/*`)** : locale = `navigator.language`. La harness
  Playwright tourne probablement en `en-US` → POS rendu **en EN** alors que la
  cible est une caisse française (NF525). Ce **n'est pas** un bug de
  hardcoding ; c'est un bug de **stratégie de detection locale POS**.
  Risque prod : un caissier dont le navigateur est paramétré en EN voit son
  POS en EN — cas non rare sur un poste partagé.

### Leaks EN observés sur POS (P1 cumulés)

| Screenshot | Texte EN observé | Source / Cause |
|---|---|---|
| `pos-1-step-04-categories.png` | « SOLD OUT » badge sur tuile Tacos M | `pos.item_86_d` clé existe en FR (« Épuisé ») et EN (« Sold out »). Locale=en au runtime → EN servi. CSS uppercase probablement appliqué. |
| `pos-2-step-08-payment-modal.png` | « Cash » / « Card (TPE) » / « **label.split_payment** » / « MONTANT REÇU »* / « Order - 7.00€ » | `label.cash`, `label.card` existent FR ; **`label.split_payment` n'existe NI en FR NI en EN** → fallback `||'Multi-paiement'` jamais déclenché car `$t()` retourne la clé littérale (truthy). |
| `pos-2-step-09-card-mode.png` | « ENTER LAST 4 DIGITS OF CARD » / « Confirm & Print Receipt » | clé `label.enter_card_last_4_digits` (FR=« Saisir les 4 derniers chiffres de la carte »). Existe → leak runtime locale. |
| `pos-1-step-05-after-tile-click.png` | « Total / €3.00 » dans wizard | format `€3.00` US-locale (devise avant montant, point décimal) au lieu du FR `3,00 €`. |
| `pos-1-step-07-cart-state.png` | « Sub Total » dans bandeau cart | clé `label.sub_total` (FR=« Sous-total »). Existe → leak runtime locale. |

\* « MONTANT REÇU » est en réalité FR — leak inverse à confirmer ; probablement
hardcodé ou clé `label.received_amount` (existe FR=« Montant reçu »).

### Vérification CS1 (RED-R1 « Add Customer » aria-label)

**REFUTÉ comme hardcoding** — grep source :
```
PosComponent.vue:236  :aria-label="$t('button.add_customer')"
```
Source utilise bien `$t()`. Les traductions existent :
- `fr.json:940  "add_customer": "Ajouter un client"`
- `en.json:1084 "add_customer": "Add Customer"`

→ Si RED-R1 a vu `aria-label="Add Customer"` au runtime, c'est parce que
**navigator.language = en-US** au moment du test → locale `en` active sur POS.
Pas un bug i18n hardcoding ; c'est le même problème **systémique** que la
detection locale POS (cf P1 #2 ci-dessous). Le fix locale POS = `fr` forcée
résout CS1 automatiquement.

### Cause racine du « mix FR/EN » sur POS

Hypothèse initiale (« navigator=en force tout en EN ») partiellement fausse.
La cause **réelle** est triple :

1. **Locale runtime POS = en** (navigator.language) sur la harness
   Playwright et potentiellement sur poste navigateur EN en prod.
2. **`en.json` contient des entrées non traduites en FR** :
   - `en.json:129 "park": "Mettre en attente"` (FR resté dans EN)
   - `en.json:130 "parked_orders": "Commandes en attente"` (idem)
   - `en.json:757 "hello": "Bonjour"` (idem)
   - `en.json:964 "received_amount": "Montant reçu"` (idem)
   → quand locale=en, ces clés **rendent FR** car la valeur EN est en FR.
3. **Clé `label.split_payment` absente des deux fichiers** → fallback
   `||'Multi-paiement'` jamais déclenché car `$t()` retourne la clé
   littérale (`'label.split_payment'`, truthy → court-circuite le `||`).

C'est pourquoi le screenshot POS montre un mélange Cash/Card/Sub Total (EN
servi correctement) + Mettre en attente / Aucun article (EN qui contient
en réalité du FR) + label.split_payment (clé brute affichée).

### Pollution des fichiers de traduction (P2)

`fr.json` contient des **traductions automatiques cassées** (probablement
sortie d'un outil de traduction qui a laissé EN passer dans la chaîne) :

```
"label": "Label"                  ← devrait être « Étiquette »
"twitter": "Twitter"               ← OK (nom propre)
"useful_links": "Useful Liens"     ← franglais cassé
"username": "Utilisateurname"      ← concaténation foirée FR+EN
"veg": "Veg"                       ← devrait être « Végétarien »
"web": "Web"                       ← OK
"work": "Work"                     ← devrait être « Travail »
"xlsx": "xlsx"                     ← OK
"your_address": "Your Adresse"     ← franglais cassé
```

→ même en locale FR forcée, l'utilisateur verrait ces strings cassées dès
qu'on rencontre `$t('label.useful_links')` ou `$t('label.your_address')`.

### Verdict U1

**FAIL P1** — drift i18n confirmé sur POS via **trois** mécanismes :
1. Stratégie `detectLocale()` non-déterministe sur POS (navigator-dependent)
   alors que NF525 impose une caisse FR.
2. Clé `label.split_payment` absente des deux fichiers (régression V1 split
   payment introduite sans i18n).
3. Pollution fr.json (au moins 5 entrées cassées par traducteur auto) +
   **en.json incomplet** (pos.park, hello, received_amount restés en FR
   dans le fichier EN).

**P3 collatéral** : `pos-1-step-04-categories.png` affiche « Bonjour
Caissier » dans le coin haut-droit. La salutation `$t('header.hello')`
+ nom utilisateur. Si l'utilisateur a un genre féminin, dire « Bonjour
Caissier » est un oubli de neutralité. Trivial à fixer (utiliser le
prénom au lieu du rôle, ou neutraliser le terme).

CS1 (« Add Customer » aria-label) **refuté comme hardcoding** — c'est
une conséquence du P1 #1 (locale runtime EN) + traduction EN existante.

---

## 2. Bilan U2 — Cohérence wording (Confirmer / Valider / OK / Submit)

### Termes observés sur les 47 screenshots

| Surface | Terme bouton primaire | Cohérence |
|---|---|---|
| POS wizard | « Ajouter au panier » | ✅ FR pro |
| POS wizard | « Annuler » | ✅ |
| POS payment modal | « Order - 7.00€ » | ❌ EN leak — devrait être « Encaisser 7,00 € » ou « Valider la commande » |
| POS payment modal | « Confirm & Print Receipt » | ❌ EN leak (FR existe : `label.confirm_and_print` = « Confirmer & Imprimer ») |
| Kiosk idle | « Sur place » / « À emporter » | ✅ |
| Kiosk payment | « Confirmer — €1,00 » | ✅ FR (mais format €1,00 = devise avant montant — convention FR = `1,00 €`) |
| Kiosk cart | « Valider ma commande » | ✅ FR pro |
| Kiosk cash counter | « J'ai compris » | ✅ FR pro, ton client |
| Kiosk cart | « Ajouter des articles » | ✅ |
| Kiosk cart | « Abandonner ma commande » | ✅ ton clair |

### Drift détecté

- **POS « Order - X€ »** vs **Kiosk « Confirmer — €X,XX »** : deux verbes
  différents pour la même action de validation finale. POS = anglais,
  Kiosk = français. Le caissier qui forme un employé sur les deux surfaces
  doit traduire dans sa tête.
- **POS « Confirm & Print Receipt »** vs **Kiosk « Imprimer le ticket »**
  (kiosk-1 step-09) : verbe différent (« Confirm » vs « Imprimer »), syntaxe
  différente (« & » vs séparateur français), terme différent (« Receipt » vs
  « Ticket »).
- **POS wizard « Ajouter au panier »** vs **Kiosk wizard `goToCart`** : le
  kiosk redirige automatiquement vers le panier ; POS reste sur la grille.
  Pas le même verbe car pas le même flow — acceptable.
- **« Annuler » (POS wizard)** vs **« Abandonner ma commande » (kiosk)** :
  ton différent (technique vs émotionnel) — cohérent avec le contexte
  caissier/client. ✅
- Format devise : POS=`€3.00` (point + devise avant) ; Kiosk=`€1,00` (virgule
  + devise avant) ; KDS-trace inconnu. Le format français standard est
  `1,00 €` (devise après, virgule décimale). **Drift double** sur POS, drift
  partiel sur Kiosk.

### Verdict U2

**HEAL P2** — wording POS hétérogène avec Kiosk. Format devise non conforme
à la convention française (NF Z 21-001 / ISO 4217 fr-FR). Pas un blocker
NF525 mais friction UX visible pour un caissier français.

---

## 3. Bilan U3 — Flow logique (retour, disable, navigation)

### Observations

- **POS surface** (`pos-1-step-04-categories.png`) : barre haute affiche
  « Caisse FoodKing / Commande / À encaisser / Commandes / Écran client /
  Plan de salle / Ouvrir le tiroir ». Multiples points d'entrée visibles.
  ✅ Bon affordance.
- **POS wizard** : bouton « Annuler » en bas à gauche, « Ajouter au panier »
  en bas à droite. Pattern modal classique. ✅
- **POS payment modal** : bouton « ✕ » en haut droite + bouton « Order » en
  bas. Pattern modal correct. ⚠️ Pas vu de « Retour » explicite — `✕` est
  ambigu (annule le paiement ? ferme la modale et retient les articles ?).
- **Kiosk catégories** (`kiosk-1-step-04-categories.png`) : sidebar
  catégorie + grille produits + footer « Abandonner ma commande / Payer ».
  ✅ Pas de bouton retour explicite dans le header — le client n'en a pas
  besoin (navigation par catégorie sidebar).
- **Kiosk cart** : « Ajouter des articles » et « Valider ma commande ».
  ✅ Affordance correcte de retour vers catégories.
- **Kiosk payment** (`kiosk-1-step-08-payment-screen.png`) : bouton « ✕ » en
  haut gauche + 3 méthodes en cards + « Confirmer — €1,00 » en footer.
  ⚠️ « ✕ » pour revenir au cart est subtil pour un client public sans
  formation. Un bouton explicite « ← Retour au panier » serait plus clair.
- **Kiosk after-confirm** : `kiosk-1-step-09-after-confirm.png` montre une
  loading state quasi vide avec juste le bouton « Imprimer le ticket ».
  ⚠️ Manque de feedback transitionnel (spinner ? message « Paiement en
  cours » ?).
- **Kiosk OOS catalog** : kiosk-4 prouve que items OOS sont **filtrés
  pré-affichage** côté backend. ✅ Pas d'ambiguïté UX pour le client.

### Verdict U3

**OK avec 2 P2** — flow globalement cohérent. Améliorer (a) bouton retour
explicite kiosk payment screen (P2 a11y/UX public), (b) feedback
transitionnel kiosk after-confirm (P2).

---

## 4. Bilan U4 — Erreurs claires

### Cas observés

- **POS-4** : tile OOS rendue avec badge `SOLD OUT` (EN locale) +
  `is-unavailable` + `disabled=true`. ✅ Pas de message d'erreur cryptique
  (la tile est juste bloquée).
- **POS-1/POS-2** : 422 backend « Article 363 indisponible pour cette branche
  (mega-kiosk-4). » → ce message backend est **bon** (FR, lisible, contexte
  branche). **MAIS** ce message n'apparaît dans aucun screenshot UI — il est
  retourné en JSON et l'UI ne le surface pas (ou le surface dans un toast non
  capturé). À tester en cycle suivant : screenshot du toast/banner après
  rejet 422 sur ajout d'article OOS.
- **POS-5 wizard extras OOS** : `oosMarkedCount=0` sur 16 extras → **aucun
  marker visuel**. Caissier peut sélectionner extra indispo et 422 backend
  rejette à `submit`. UX : l'erreur arrive **trop tard** (après 4 clics).
  ❌ **P1 confirmé** (déjà flag par MEGA report).
- **Kiosk-2** : message « Rendez-vous en caisse / Paiement en espèces
  uniquement à la caisse » → ✅ excellent message pédagogique pour client.

### Verdict U4

**HEAL P1** — extras OOS dans wizard POS sans marker visuel = friction
caissier majeure. Toasts d'erreur 422 non capturés en E2E mais le message
backend est lisible (à valider que le toast les affiche bien).

---

## 5. Bilan U5 — Quantity stepper

### Observations screenshots

- **POS wizard** (`pos-1-step-05-after-tile-click.png`) : stepper visible avec
  `−`, `1`, `+`. Boutons ronds, contraste OK, taille tactile OK pour caisse.
  ✅
- **Kiosk cart** (`kiosk-1-step-07-cart.png`) : stepper inline `−ㅤ1ㅤ+` à
  droite de chaque ligne. Aussi `0,00 € / unité` → label clair par-unité
  ce qui aide le client à comprendre le total. ✅
- **Bornes (min=1, max=20 cf `kiosk.max_item_qty`)** : non testable depuis
  les screenshots (pas de tentative de dépassement capturée). À ajouter dans
  prochain cycle E2E (cliquer `+` 21 fois et vérifier que le bouton se
  désactive ou affiche un message « Maximum 20 par article »).
- **Disable visuel à 1** : non testable depuis screenshots (pas de probe
  step state stepper-at-1). **P3 lacune E2E**.

### Verdict U5

**INFO** — design stepper cohérent ; bornes non vérifiables sur ce corpus
de screenshots. À couvrir explicitement dans un prochain cycle E2E
(`tests/e2e/quantity-stepper-bounds-spec.js`).

---

## 6. Bilan U6 — Wizard a11y mid-step (allergens, suppléments)

### Observations

- **POS wizard ouvert** (`pos-3-step-03-wizard-extras-probe.png`) :
  - Header : nom item + prix
  - Stepper qty
  - Bouton **« + Suppléments ▼ »** (collapsible) — l'utilisateur doit
    cliquer pour découvrir les extras. **P2 a11y** : pas de progress bar
    « Étape 2/5 » ; pas d'indication du nombre total d'extras disponibles ;
    pas de signal visuel d'allergènes obligatoires.
  - Champ « Instruction spéciale » (textarea libre, placeholder pédagogique)
  - Card « APERÇU TICKET » — récap visible avant validation. ✅
  - Boutons « Annuler / Total €X.XX / Ajouter au panier ». ✅
- **EAA 2025 allergènes mid-process** : INVARIANT du brief — non vérifiable
  depuis les screenshots actuels (l'item Tacos M n'a probablement pas
  d'allergène à afficher, ou la zone allergènes est conditionnelle). Le
  bouton « Suppléments » est replié par défaut → l'utilisateur ne **voit
  pas** les allergènes sans cliquer. ❌ **Risque P1 conformité EAA 2025**
  si la zone allergènes est repliée par défaut.

### Verdict U6

**HEAL P1 (EAA)** — wizard kiosk/POS doit garantir affichage des allergènes
**par défaut visible** (sans interaction utilisateur) pour conformité EAA
2025. À vérifier sur un item avec allergènes réels (burger ou dessert).

---

## 7. Bilan U7 — Cart state visible (total + count + bouton Payer)

### Observations

- **POS cart** (`pos-2-step-04-tile-2-added.png`) : panneau droit fixe
  « TICKET CAISSE / Commande en cours / 3 Articles / Sub Total / Total /
  Order - 7.00€ ». ✅ Visible et persistant pendant l'ajout de tuiles.
  Compteur articles incrémente (3 → 7 → 14 → 21 d'après dom-probes). Total
  monte (3.00€ → 5.00€ → 7.00€). ✅ Bonne transparence.
- **Kiosk** : footer fixe « 0 article / €0,00 / Payer » ;
  après ajout : « 1 article / €1,00 ». ✅
- **POS payment modal ouvert** : cart toujours visible derrière (modale
  semi-transparente). ✅

### Verdict U7

**OK** — cart state visible et cohérent sur les deux surfaces.

---

## 8. Bilan U8 — Pre-validation paiement (récap final)

### Observations

- **POS** : modale `Encaissement` affiche « MONTANT TOTAL / 7.00€ » + cart
  sidebar visible derrière. ⚠️ Le récap est visuel mais pas explicite
  ligne-par-ligne (pas de listing « 1× Frites Seules + 1× Boisson Seule + … »
  dans la modale elle-même). Caissier voit le compteur « 3 Articles » à
  droite mais n'a pas de récap détaillé dans la modale paiement.
- **Kiosk** : pareil — payment screen affiche « TOTAL À RÉGLER / €1,00 » mais
  pas de récap ligne-par-ligne. Le récap était sur l'écran cart précédent.
  ✅ Pour un kiosk c'est acceptable (le client vient de valider depuis cart).

### Verdict U8

**P3** — POS gagnerait à ré-afficher un mini-récap dans la modale paiement
(les caissiers expérimentés peuvent ne pas regarder la sidebar). Pas un
blocker V1.

---

## 9. Bilan U9 — Confirmation post-paiement (POS vs Kiosk)

### Observations

- **POS-2 step-10** (`pos-2-step-10-after-confirm.png`) : modale paiement
  reste affichée après click `Confirm & Print Receipt`. Toast d'erreur
  « ✕ » visible en haut droite (api-fallback échec leftover OOS, normal pour
  ce run). ⚠️ Pas de feedback de succès clair — en cas de succès réel, le
  caissier devrait voir un état post-paiement explicite.
- **Kiosk-1 step-09** (`kiosk-1-step-09-after-confirm.png`) : écran sombre
  quasi vide avec petit bouton « Imprimer le ticket ». ❌ **P1 UX kiosk** —
  manque feedback succès, manque queue number, manque message « Préparation
  en cours ». Comparé à kiosk-2 qui affiche un superbe ticket complet
  (« Rendez-vous en caisse #A0010 21,00 € »), kiosk-1 est dégradé.
- **Kiosk-2 step-07** : ticket impeccable. ✅

### Verdict U9

**HEAL P1** — kiosk-1 (paiement card) n'affiche pas de ticket de confirmation
équivalent à kiosk-2 (cash counter). Investiger : est-ce un bug du flow
bypass qui shortcut l'écran ticket ? Ou un design hole pour la voie card ?

⚠️ **Discrépance avec MEGA report** : le rapport `MEGA_PARCOURS_E2E_REPORT_2026-05-08.md`
ligne 175 affirme « Ticket affiché : queue A0006 — OK visuel "Votre commande
est en préparation" ». Mais `kiosk-1-step-09-after-confirm.png` est un
écran sombre quasi vide. Soit (a) l'agent E2E a lu un toast transient pas
visible sur screenshot tardif, soit (b) screenshot capturé pendant transition
loading. À ré-instrumenter avec `await page.waitForSelector('.kiosk-ticket-state')`
explicite avant capture pour trancher.

---

## 10. Bilan U10 — Empty states

### Observations

- **POS empty cart** : « Aucun article. Sélectionnez un produit dans la
  grille. » ✅ Pédagogique, en français.
- **Kiosk empty cart** : non capturé en screenshot dédié, mais probablement
  géré (cart vide redirige vers catégories ou affiche message).
- **Kiosk-4 OOS** : items filtrés pré-affichage → pas de empty state visible.
  Risque : si **tous** les items d'une catégorie sont OOS, la catégorie
  s'affiche-t-elle vide ? Pas testé sur ce corpus. **P3 lacune E2E**.
- **POS catégorie vide** : non testé.

### Verdict U10

**OK + P3** — empty states POS cart bons. Catégorie kiosk full-OOS non
couverte E2E.

---

## 11. Top 5 incohérences UX (P1/P2)

| # | Sev | Hypothèse | Constat | Scope-minimal fix |
|---|---|---|---|---|
| 1 | **P1** | U1 | Clé `label.split_payment` absente fr.json + en.json → bouton multi-paiement affiche littéralement « label.split_payment » au runtime. Régression V1 split-payment. | Ajouter clé `"split_payment": "Multi-paiement"` (FR) / `"Split payment"` (EN) dans `label` block des 2 fichiers. |
| 2 | **P1** | U1 | `detectLocale()` POS = `navigator.language` → POS rend en EN sur poste navigateur EN, alors que cible NF525 = caisse française. | Forcer `KIOSK_LOCALE`-style mais pour POS : si `path.includes('/admin/pos')` → locale=`fr`. Garder admin général sur navigator. (Ou exposer `app.default_locale='fr'` côté config Laravel et lire ce override avant navigator.) |
| 3 | **P1** | U4 | Wizard POS : 16 extras affichés sans marker visuel pour ceux OOS. Caissier sélectionne extra indispo → 422 backend tardif. (Confirmé MEGA report.) | Ajouter classe `is-extra-unavailable` + tooltip raison sur `<button class="extra-tile">` quand `extra.is_available=false`. Disable click. Ajouter sentinel test. |
| 4 | **P1** | U9 | Kiosk-1 (card) : écran post-confirm dégradé (loading sombre, pas de ticket), vs Kiosk-2 (cash) qui affiche ticket complet « Rendez-vous en caisse / #A0010 ». Asymétrie d'expérience entre voies de paiement. | Investiguer composant `KioskTicketStateComponent` ou équivalent. Soit le flow card bypass shortcut l'écran ticket (bug), soit un état card-specific manque. |
| 5 | **P2** | U2 | Format devise mixte : POS = `€3.00` (point), Kiosk = `€1,00` (virgule), tous deux = devise avant montant. Convention française = `1,00 €` (devise après). | Centraliser via `currencyFormat()` helper aligné sur `setting.site_currency_position` + locale `fr-FR`. Vérifier que tous les composants l'utilisent (au lieu de hardcoder `€` + montant). |

### Bonus P2 (signalés non top-5)

- **U2/U6** : pollution fr.json (« Useful Liens », « Your Adresse »,
  « Utilisateurname », « Work », « Veg ») — corriger les 5 entrées identifiées.
- **U6** : wizard extras / allergens repliés par défaut → risque conformité
  EAA 2025. Étudier `pos-3-step-03-wizard-extras-probe.png` avec un item
  réellement allergène avant V1.

---

## 12. Top 5 wins UX (DS V5 + V1 Bold cohérents)

| # | Win | Surface | Preuve |
|---|---|---|---|
| 1 | **Cash counter ticket impeccable** | Kiosk | `kiosk-2-step-07-ticket-state.png` : « Rendez-vous en caisse / Présentez votre numéro à un membre de l'équipe / #A0010 / 21,00 € / Paiement en espèces uniquement à la caisse » — message clair, tonalité humaine, queue number lisible. Excellence UX. |
| 2 | **Kiosk OOS filtré pré-affichage** | Kiosk | `kiosk-4-step-02` : items indisponibles filtrés backend → menu kiosk apparaît « complet ». Choix design intentionnel et cohérent (caissier doit savoir, client n'a pas besoin). |
| 3 | **POS OOS rendu avec badge + disabled tile** | POS | `pos-4-step-03-tile-after-oos.png` : tile Tacos M = `Sold out` + classe `is-unavailable` + `disabled=true` + `has86Badge=true`. Caissier voit immédiatement, click force ne déclenche pas wizard. ✅ Backend defense-in-depth. |
| 4 | **POS cart sticky avec récap permanent** | POS | sidebar droite « TICKET CAISSE » toujours visible, compteur articles + sub-total + total en temps réel (3.00€ → 5.00€ → 7.00€). Excellent feedback pour caissier rush. |
| 5 | **Kiosk welcome screen UX douce** | Kiosk | `kiosk-1-step-02-idle.png` : « Bienvenue ! / Commandez en quelques touches » + 2 cards larges « Sur place / À emporter » avec icônes + sub-text « Je mange ici » / « Je récupère ma commande ». Tonalité chaleureuse, affordance évidente sans formation. |

---

## 13. Verdict GO/NO-GO UX V1

**Verdict : `heal` — NO-GO V1 strict mais HEAL léger possible**

Justification (CLAUDE.md §8) :

- ✅ Backend correctness : aucun bug pricing, sealing fiscal NF525 OK
  (vu MEGA report POS-3 fiscal_seq=5).
- ✅ Architecture cohérente : i18n centralisé, format devise via helper.
- ❌ **3 P1 produits non négociables avant prod française** :
  1. POS rendu en EN sur navigateur EN (NF525 = caisse FR garantie).
  2. Clé `label.split_payment` absente → littéral affiché.
  3. Wizard POS extras OOS sans marker visuel (friction caissier).
- ⚠️ 1 P1 spécifique : kiosk-1 post-confirm card dégradé vs kiosk-2 cash.
  À investiguer urgemment (potentiel bug bypass-mode-only).
- ⚠️ 1 P1 conformité : EAA 2025 allergens visibility — non vérifié sur ce
  corpus (item Tacos M sans allergène). À tester avant prod France.

**Décision** : `heal` cycle CV1-UX-V1-FR-COMPLIANCE avec :
1. Plan Codex « POS locale fr forcée + ajout split_payment key + extras OOS marker »
2. Re-test E2E sur item avec allergènes (burger / dessert)
3. Sentinel test : `tests/js/sentinels/posLocaleAlwaysFr.spec.js`
4. Sentinel test : `tests/js/sentinels/posExtraOosMarker.spec.js`

Pas de **block** car :
- Aucun risque de corruption fiscale / pricing.
- Aucune régression sécurité.
- Branch isolation préservée.
- Les 3 P1 sont **scope-minimal fixables** en <2 cycles Codex.

Pas d'**escalate** car :
- Aucune contradiction avec CLAUDE.md / BUSINESS_RULES.md.
- Aucune divergence architecture détectée.

---

## 14. Limitations honnêtes

1. **Échantillon screenshots biaisé** : item de test = Tacos M (1 viande, peu
   d'allergènes). U6 (EAA wizard allergens) non vérifiable empiriquement
   sur ce corpus. À re-tester avec un burger / dessert qui déclare
   `allergens=["gluten", "lait", ...]`.
2. **Stepper bornes** : aucune tentative `+×21` capturée — U5 max
   `kiosk.max_item_qty=20` non vérifiable.
3. **Toasts d'erreur 422** : retournés en JSON par le backend (« Article 363
   indisponible pour cette branche... ») mais pas capturés en screenshot. Le
   wording du message backend est bon mais sa **présentation UI** non vérifiée.
4. **Catégorie full-OOS empty state** : non testé.
5. **Locale runtime POS** : Playwright tourne en navigator=en — un screenshot
   de POS avec navigator=fr montrerait probablement les FR (sauf pour la clé
   manquante `label.split_payment`). Ce **fait** ne disculpe pas le P1 : la
   stratégie de detection est défaillante pour une caisse française.
6. **Kiosk-3 multi-add timeout** : test infra issue, ne révèle pas un vrai
   problème UX (cf MEGA report § Kiosk-3 reclassif P3).
7. **POS wizard pour item complexe** : non capturé. Wizard `pos-1-step-05`
   montre Menu Frites+Boisson avec 6 extras max. Un burger configurable
   (viande + sauce + suppléments + cuisson) testerait le scaling UX du wizard.
8. **a11y profonde** : focus order, lecteur d'écran, contraste WCAG AA — non
   testés ici. À couvrir par un agent A11Y dédié.
9. **Asymétrie EN/FR observée mais pas RTL (ar)** : la locale `ar` n'a pas
   été exercée sur ce corpus. `i18n.js` switch `dir=rtl` au runtime — non
   vérifié visuellement.
10. **Verdict basé sur 19 DOM probes + 47 PNG** : si certains screenshots ont
    été capturés trop tôt (avant load complet), des leaks observés peuvent
    être faux-positifs. La cohérence cross-screenshot rend ce risque faible.

---

## 15. Artefacts produits

- Le présent rapport `docs/audit/AGENT_UX_FLOW_AUDIT_2026-05-08.md`

## 16. Références

- `docs/audit/MEGA_PARCOURS_E2E_REPORT_2026-05-08.md` (rapport agent E2E)
- `tests/e2e/screenshots/mega-parcours-2026-05-08/` (47 PNG + 5 JSON)
- `resources/js/i18n.js` (config locale)
- `resources/js/languages/fr.json`, `en.json`
- `resources/js/components/admin/pos/PaymentComponent.vue` (lignes 50, 63, 76, 112)
- `resources/js/components/admin/pos/ItemComponent.vue` (lignes 28, 151, 168, 201, 252)
- `resources/js/components/admin/pos/PosComponent.vue` (ligne 638 : `label.sub_total`)
- CLAUDE.md §7 (anti-shallow-success), §8 (Decision Framework)
