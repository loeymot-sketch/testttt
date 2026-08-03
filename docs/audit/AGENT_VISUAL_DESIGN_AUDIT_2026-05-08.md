# AGENT VISUAL-DESIGN — Audit hostile MEGA PARCOURS
## Date: 2026-05-08 | Rôle GSTACK: Designer hostile

---

## 0. Périmètre & artefacts analysés

- Screenshots: 47 PNG dans `tests/e2e/screenshots/mega-parcours-2026-05-08/`
- Findings runtime: `findings.json` (28 entries)
- Index: `INDEX.md`
- Tokens POS V5: `resources/css/foundations/pos-v5-tokens.css` (260 lignes lues)
- Tokens Kiosk Bold: `resources/css/kiosk/tokens-bold.css` + `typography-bold.css`

Méthodologie: lecture visuelle directe des PNG (rendu pixel) + cross-check sources CSS quand doute. Pas d'extraction colorimétrique pixel-précise (limite outil); inférences basées sur tokens documentés + observation.

---

## 1. Bilan V1-V10 (hypothèses adversaires)

### V1. Cohérence colorimétrique tile/CTA — **VERDICT: PARTIEL — incohérence mint dans wizard POS**

**Observation POS V5**:
- Tiles catalogue (`pos-1/2/4-step-04`): fond `#FFFBF5` warm crème **conforme** `--pos-v5-bg-app`. Tiles produit fond blanc, bordure warm, prix `€3.00` rouge brand `#E8001C`.
- Header pill "À encaisser" rouge brand `#E8001C` avec ombre `--pos-v5-shadow-cta`. Conforme.
- Footer cart "Order — 7.00€" gradient rouge brand. Conforme.
- Modal paiement: total `€7.00` rouge brand, CTA "Confirm & Print Receipt" rouge brand. Conforme.
- **Toast confirmation ajout panier** (`pos-2-step-02`): vert pastille `#10B981`-ish — couleur sémantique success **proche mais possiblement hors token** `--pos-v5-success #1B8A3A` (le toast paraît plus vif/saturé que le success token doc).

**Observation P0 INCOHÉRENCE**:
- **Wizard POS** (`pos-1-step-05-after-tile-click`): bouton CTA primaire "Ajouter au panier" est **mint/teal vif** (`#5CDDB1`-ish, fond + ombre vertes). Le total `€3.00` est aussi vert mint. **Aucune correspondance dans `pos-v5-tokens.css`** — `--pos-v5-success #1B8A3A` est un vert sapin, pas mint.
- Conclusion: le wizard POS consomme une palette héritée (probablement design system pré-POS V5 / kiosk-wizard légacy) et n'a pas migré vers warm tokens. **Incohérence visible quand le caissier passe de tile rouge → wizard mint → modal paiement rouge.**

### V2. Typographie Inter + Fraunces — **VERDICT: GO conditionné**

**POS V5**: Inter chargée correctement (rendu net, antialiasing visible). Échelle `--pos-v5-text-h4` (22px "Commande en cours") + `--pos-v5-text-display` (34px totaux) cohérente.

**Kiosk Bold**:
- "Bienvenue !" sur idle: rendu serif visible — **Fraunces probablement chargée** (curve caractéristique du "B" + "v"). Conforme `--kiosk-font-display`.
- Numéro "#A0010" sur ticket: rendu display large, possiblement Fraunces ou Inter bold (incertain visuellement à cette résolution).
- Body "Commandez en quelques touches" en sans-serif → Inter conforme.

**Cross-locale**: aria-labels présents sur boutons (cf. probes `tileProbe`), pas de mix anglo-français visible dans screenshots français (libellés "Commande", "À emporter", "Articles", "Total"). En revanche `pos-2-step-08` modal contient `label.split_payment` **non-traduit** (clé i18n brute affichée) — défaut traduction.

**KDS**: encart "PAIEMENT COMPTOIR - NON RÉGLÉ" français + "Confirmées"/"Borne" → cohérent. Logo "FoodKing" en haut rouge magenta avec curve serif ressemblant à Fraunces. OK.

### V3. Spacing 4/8/16/24 — **VERDICT: GO**

POS V5: padding cartes uniforme, gaps grille catégories tiles cohérents, sidebar "TICKET CAISSE" header eyebrow `--pos-v5-text-eyebrow 11px` lisible. Pas de désalignement visible dans `pos-2-step-04-tile-2-added` (3 tiles + cart compact).

Kiosk: cards "Sur place" / "À emporter" idle alignées symétriquement, padding hero `Bienvenue !` cohérent. Cart `kiosk-1-step-07` items list bien espacés.

### V4. Contraste OOS / Sold out — **VERDICT: GO**

`pos-4-step-03-tile-after-oos`: badge "SOLD OUT" rouge magenta `#C21E2F`-like (probablement `--pos-v5-danger`) sur fond blanc tile gristé, lock icon noir. Texte "Tacos M (1 Viande) 6.50€" reste lisible (gris atténué mais contraste suffisant ≥ 4.5:1 estimé). **Probe DOM confirme** `is-unavailable` + `has86Badge: true` + `disabled: true` + `aria-disabled: true`.

### V5. States visibles (focus, disabled, hover, active) — **VERDICT: GO PARTIEL**

- Disabled: tile OOS très clair (opacity + badge + lock) → solide.
- Active: tab "À emporter" actif fond gradient rouge (`pos-2-step-04`) — solide.
- Focus ring: pas de screenshot capturant `:focus-visible` (E2E ne tabule pas), donc **non vérifiable empiriquement** — mais token `--pos-v5-focus-color #2563EB` + `--pos-v5-focus-width 3px` documenté.
- Hover: idem, statique screenshots ne capturent pas.

Limite honnête: focus/hover non audités visuellement.

### V6. Iconographie lab-* — **VERDICT: GO**

Icônes catégories (Tacos, Sandwiches, Burgers, Omelettes, Salades) bien chargées en photos rondes. Logo crown jaune top-left `pos-*` présent. Tabs "Commandes / Écran client / Plan de salle / Ouvrir le tiroir" avec icônes flat propres. Pas de boxes vides/fallback détectées.

**Exception**: `kiosk-1-step-03-after-takeaway` montre tile produit unique avec **placeholder image grey "+"** (image produit absente du seed E2E). C'est un défaut data, pas un bug iconographique du DS.

### V7. Mobile/responsive — **N/A**

Screenshots Kiosk en portrait 1080×1920 (cible borne) + POS en landscape ~1280×720. Pas de breakpoints intermédiaires testés. Hors scope MEGA PARCOURS.

### V8. Symétrie POS↔Kiosk même produit — **VERDICT: PARTIEL**

Item Tacos M (363) côté POS: tile blanc + image circulaire tacos + prix `6.50€` rouge brand. Côté kiosk: **filtré pré-affichage** (probe `tacosOnKioskProbe.tacosMatches=0`, `totalCards=1` seul item E2E_PLAYWRIGHT_STUDIO_ITEM affiché). Donc symétrie visuelle **non comparable directement** sur ce parcours — impossible de juger cohérence image/nom/prix POS↔Kiosk pour Tacos.

L'item E2E test `E2E_PLAYWRIGHT_STUDIO_ITEM` à €1.00 apparaît côté kiosk avec placeholder grey, sans pendant POS visible. Identique côté visualisation manquante.

### V9. Visual feedback (cart, payment, success) — **VERDICT: GO**

- Toast vert ajout panier: visible `pos-2-step-02-tile-0-added` top-right.
- Toast d'erreur danger rouge: visible `pos-2-step-10-after-confirm` top-right (icône warning triangle).
- Confirmation paiement kiosk: `kiosk-1-step-09-after-confirm` montre **état post-confirm dramatique** (fond noir warm + circle vide ghost + bouton "imprimer le ticket") — feedback minimaliste mais lisible.
- Kiosk ticket state: `kiosk-2-step-07` affiche numéro #A0010 grand, montant rouge, pickup instructions — feedback fort.

### V10. Marqueur "MODE TEST" bypass — **VERDICT: NON AUDITABLE — pas de receipt POS visible**

Aucun screenshot ne montre le receipt POS imprimé (le bypass court-circuite l'impression). `findings.json PRE` confirme `bypass payment=true printing=true`. Le marqueur "🔧 MODE TEST" attendu sur receipt n'apparaît dans **aucun** des 47 PNG car aucun ticket print n'est rendu en preview UI. Limitation: vérification doit se faire côté template print (`*.blade.php` receipt) hors-périmètre screenshots.

---

## 2. Top 5 incohérences visuelles (P0/P1)

### **P0-DESIGN-1**: Wizard POS palette mint hors design system warm tokens
- **Fichiers**: `pos-1-step-05-after-tile-click.png`, `pos-3-step-03-wizard-extras-probe.png` (mêmes hash)
- **Symptôme**: CTA "Ajouter au panier" + total `€3.00` rendus en **mint/teal `#5CDDB1`-ish** au lieu du rouge brand `--pos-v5-brand-red #E8001C`.
- **Impact**: Caissier voit rouge (catalogue) → mint (wizard) → rouge (modal paiement). Rupture identité visuelle au milieu du flow critique.
- **Origine probable**: `pos-app.js` Vanilla JS wizard rend ses propres styles inline ou consomme un thème legacy (kiosk-wizard.css frozen ?). À investiguer.

### **P1-DESIGN-2**: Extra OOS non marqué visuellement dans wizard POS
- **Fichier**: `pos-5-step-03-wizard-with-oos-extra.png` (probe `extraOosProbe.oosMarkedCount=0/16`)
- **Symptôme**: Extra Salade (id 172) toggle OFF par toggle endpoint, mais wizard liste les 16 extras sans aucun marqueur visuel (pas de strike-through, pas de badge "indispo", pas d'opacity).
- **Impact**: Caissier sélectionne extra OOS → soumission → 422 backend ("Article 363 indisponible"). Frustration + temps perdu.
- **Cohérence**: même pattern que V4 OOS tile catalogue qui lui marque correctement → **divergence DS interne** : tile sait, wizard ne sait pas.

### **P1-DESIGN-3**: Clé i18n brute `label.split_payment` affichée dans modal paiement
- **Fichiers**: `pos-2-step-08-payment-modal.png`, `pos-2-step-09-card-mode.png`, `pos-3-step-04-payment-modal.png`
- **Symptôme**: Le 3e mode de paiement affiche `label.split_payment` (clé i18n non traduite) au lieu de "Paiement fractionné" / "Split payment".
- **Impact**: Crédibilité produit zéro pour V1 release. Erreur traduction observable par tout client.

### **P1-DESIGN-4**: Bandeau "Reconnexion en cours..." orange permanent côté KDS
- **Fichiers**: `pos-1-kds-reception.png`, `pos-2-kds-reception.png`, `pos-3-kds-reception.png`, `kiosk-1-kds-reception.png` (4 screens KDS sur 4 affichent le bandeau)
- **Symptôme**: Bannière warning orange `#B8730B`-ish "Reconnexion en cours..." occupe 32px haut sur 100% width en permanence (websockets DOWN env local).
- **Impact**: En production websockets UP, le bandeau ne devrait jamais apparaître. Mais design banner correct (couleur warning + dismissible cross). Acceptable si comportement réseau réel masque le bandeau.
- **Risque P1**: si websockets flappent en prod, opérateurs cuisine voient bandeau persistant = perte confiance UI.

### **P1-DESIGN-5**: KDS palette violet/bleu `#4F1F8C` ne suit pas warm tokens POS V5
- **Fichiers**: tous `*-kds-reception.png`
- **Observation**: Tab "Toutes" actif fond violet/bleu (probablement `--admin-primary` Rubik), badges "Confirmées" violet, header logo "FoodKing" rouge magenta (≠ `#E8001C` POS V5 — plus saturé/rose).
- **Statut**: probablement **par design** (KDS hérite du namespace admin Rubik, pas `.pos-v5-shell`). Mais **incohérence cross-surface FoodKing** : un client/opérateur qui voit POS chaud crème puis KDS bleuté froid perçoit deux produits.
- **Décision**: **non-bloquant V1** mais à documenter comme dette V1.x design system convergence cross-surface.

---

## 3. Top 5 wins design (solide)

### **WIN-1**: POS V5 warm tokens exécution catalogue
Fond `#FFFBF5` crème, header `pos-v5-pill` rouge brand avec ombre CTA, tiles photo rondes, prix tabular nums rouge — **identité forte cohérente**. `pos-1/2-step-04` rendu pro caisse.

### **WIN-2**: Kiosk Bold idle écran "Bienvenue !"
Fond noir warm `#0E0A07` (dark mode confirmé), Fraunces serif "Bienvenue !" hero size, deux cards "Sur place" / "À emporter" symétriques, copy "Commandez en quelques touches" + footer "CHOISISSEZ UNE OPTION POUR COMMENCER" tracking caps. **Premium et lisible à 1m**.

### **WIN-3**: OOS handling tile POS V5
`pos-4-step-03`: badge "SOLD OUT" magenta rouge contrasté + opacity + lock icon + DOM `disabled=true` + `aria-disabled=true` + `is-unavailable` class. **Multi-canal a11y solide** (visuel + DOM + AT).

### **WIN-4**: Kiosk paiement écran 3-méthodes
`kiosk-1-step-08`: hero pill rouge gradient avec total display XL, 3 cards méthodes (carte/espèces/titre restaurant) avec icônes circulaires colorées (`#3B82F6`, `#10B981`, `#F59E0B` cohérents avec `--kiosk-bold-info/success/warning`), CTA "Confirmer" rouge bottom. **Design food-confidence + a11y AAA candidat**.

### **WIN-5**: Kiosk ticket pickup "Rendez-vous en caisse"
`kiosk-2-step-07`: numéro `#A0010` display XL rouge brand `#E63946`, montant `21,00 €` rouge tabular, copy claire "Présentez votre numéro à un membre de l'équipe", "Paiement en espèces uniquement à la caisse". **Hiérarchie info parfaite, dramatic dark warm — premium**.

---

## 4. Verdict GO/NO-GO design V1

### **HEAL recommandé — pas GO direct**

**Justification**:
- Identité POS V5 + Kiosk Bold globalement **solide et cohérente intra-surface**.
- 1 P0 design (wizard POS mint hors warm tokens) + 3 P1 (extra OOS unmarked, i18n brute, KDS divergence palette) = 4 frictions visibles AVANT release V1.
- Le P0 wizard est **visible sur 100% des flows POS avec produit composable** → bloquant identité V1.
- Le P1 i18n est **trivialement réparable** (clé manquante traduction) mais visible client = bloquant.
- Le P1 extra OOS est **un risque opérationnel** (caissier sélectionne indispo) déjà flagué par red-team — bloquant.

### Conditions GO V1
1. **HEAL P0-DESIGN-1**: wizard POS migrer CTA + total vers `--pos-v5-brand-red` (cible: `pos-app.js` ou source SCSS wizard). Sentinel test: snapshot couleur `getComputedStyle` sur `.pos-v5-wizard__cta-add` doit être `rgb(232, 0, 28)`.
2. **HEAL P1-DESIGN-3**: ajouter clé `label.split_payment` dans `resources/js/languages/fr.json` + `en.json` + `ar.json`.
3. **HEAL P1-DESIGN-2**: marquer extra OOS dans wizard (opacity + badge "indispo" + disabled) — réutiliser pattern tile catalogue `is-unavailable`.
4. **DOCUMENT** P1-DESIGN-5 KDS palette comme dette V1.x (non-bloquant).
5. **VERIFY hors scope screenshots**: receipt POS bypass marker `🔧 MODE TEST` à confirmer côté blade template print.

---

## 5. Limitations honnêtes

1. **Pas d'extraction pixel-précise**: estimations colorimétriques basées sur lecture visuelle PNG (compressé) + tokens documentés. Possible écart 5-10% sur valeurs hex vs token réel `getComputedStyle`.
2. **Focus/hover/keyboard states non capturés**: E2E Playwright n'a pas tabulé/hover-stimulé l'UI; impossible de vérifier visuellement focus ring `--pos-v5-focus-color #2563EB`.
3. **Receipt POS bypass marker non observable**: aucun screenshot de receipt imprimé; vérification doit passer par template blade (`pos/print-receipt.blade.php` ou similaire).
4. **Symétrie POS↔Kiosk Tacos non vérifiable**: kiosk filtre Tacos pré-affichage, donc même produit côté borne absent du parcours; comparaison visuelle directe impossible.
5. **KDS rendu en mode dégradé**: bandeau orange "Reconnexion" présent sur 4/4 KDS screens (websockets DOWN en local) — ne reflète pas l'état production normal.
6. **Mobile/responsive non testé**: screenshots = portrait kiosk 1080×1920 + landscape POS desktop. Breakpoints intermédiaires (tablet 768-1024) hors scope.
7. **Pas de test contrast ratio empirique**: contrastes AA/AAA inférés depuis tokens doc, pas mesurés (ex: WAVE/axe). Tokens POS V5 documentent contraste 15.8:1 sur `bg-app` mais non vérifié runtime.
8. **Une seule capture par état**: pas de variance device/zoom/font-scale PMR captée.

---

## 6. Recommandations agent suivant

- **Agent A11y**: passer axe-core/Lighthouse sur les 47 screens (focus visible, contrast empirique, tab order).
- **Agent i18n**: scan `resources/js/languages/*.json` pour clés brutes type `label.*` apparaissant dans UI.
- **Agent Frontend POS**: investiguer source mint wizard `pos-app.js` (probablement scope `--kiosk-*` legacy ou inline `style` color).
- **Agent Receipt fiscal**: vérifier print template blade pour marker bypass `🔧 MODE TEST`.

---

**Fin audit AGENT VISUAL-DESIGN — 2026-05-08**
