# Round4 — A11y / WCAG 2.1 profond (lane A11y)

Audit READ-ONLY. Findings prouvés (Read/grep). Borne client = P2 (loi accessibilité EU),
admin = P3. Le socle a11y est globalement bon (KioskWizardComponent + ds/KsModal.vue
implémentent un vrai focus-trap Tab + role/aria ; KioskCartComponent bourré d'aria-label,
role=radiogroup/list/listitem). Les écarts ci-dessous sont les gaps réels restants.

---

## [P2] resources/js/components/frontend/kiosk/KioskCartComponent.vue:33-41 — Dialog « vider le panier » sans focus déplacé ni trap (Esc inerte)
- **Titre** : Le mini-dialog de confirmation `role="dialog" aria-modal="true"` ne reçoit jamais le focus à l'ouverture → `@keydown.esc` inopérant + Tab s'évade derrière la modale.
- **WCAG** : 2.4.3 Focus Order (A), 2.1.1 Keyboard (A), 2.4.7 partiel.
- **Repro** : `sed -n '33,58p' KioskCartComponent.vue`. Le `<div v-if="showClearConfirm" role="dialog" aria-modal="true" @keydown.esc="showClearConfirm=false">` n'a ni `tabindex` ni `.focus()` à l'ouverture (`grep -c "focus()" KioskCartComponent.vue` = 0). Le déclencheur (bouton « vider » l.20) reste focalisé HORS du dialog → un keydown Esc bulle vers le déclencheur, pas vers le `<div role=dialog>` (qui n'est pas son ancêtre) → Esc sans effet tant que l'utilisateur n'a pas Tab-é dans le dialog. Aucun confinement Tab : la tabulation atteint le contenu de fond.
- **Evidence** : grep `focus()`=0 dans le fichier ; le pattern correct existe pourtant juste à côté (KioskWizardComponent.vue:2220-2305 et ds/KsModal.vue:149-158 implémentent first/last focusable + cycle Tab + déplacement focus au panel).
- **Lentille** : jumeau-systémique — le projet a un focus-trap canonique (KsModal/KioskWizard) NON appliqué à ce dialog inline. Même classe potentielle sur KioskPaymentComponent.vue:212 (overlay TPE) mais non-interactif → écarté.
- **Reco (NON-frozen)** : déplacer le focus sur le bouton « annuler » à l'ouverture (`$nextTick`+ref) et/ou réutiliser ds/KsModal.vue pour ce confirm ; ajouter `tabindex="-1"` au div dialog. Borne = client public.
- **Frozen** : NON (KioskCartComponent.vue hors liste §7).

---

## [P3] resources/js/components/admin/pos/PosRefundModal.vue:32,291 (+ PosCounterCollectModal.vue, PosLoyaltyRedeemModal.vue) — `aria-modal="true"` annoncé mais focus NON confiné ni restauré
- **Titre** : Les modales argent POS déclarent `aria-modal="true"` (contrat AT : fond inerte) et le commentaire PosRefundModal:32 affirme « focus trap », mais l'implémentation ne pose qu'un focus initial — aucun confinement Tab, aucune restauration du focus au déclencheur à la fermeture.
- **WCAG** : 2.4.3 Focus Order (A). (Pas un piège clavier 2.1.2 ; l'inverse : focus s'échappe.)
- **Repro** : `grep -c "=== 'Tab'\|keydown.tab" PosRefundModal.vue PosCounterCollectModal.vue PosLoyaltyRedeemModal.vue` = 0/0/0 ; `grep -c "activeElement\|restore\|previousFocus"` = 0/0/0. PosRefundModal.vue:291 fait seulement `this.$refs.reasonInput.focus()` ; commentaire l.32 « focus trap (focus on first… » trompeur. Idem PosCounterCollectModal.vue:316 (`receivedInput.focus()`) et PosLoyaltyRedeemModal.vue:235 (`codeInput.focus()`).
- **Evidence** : 0 handler Tab et 0 sauvegarde d'`activeElement` dans les 3 fichiers.
- **Lentille** : contrat ARIA non tenu — `aria-modal` ment au lecteur d'écran tant que le focus n'est pas réellement confiné. Classe partagée aux 3 modales encaissement/remboursement/fidélité.
- **Reco (NON-frozen)** : factoriser un mixin focus-trap (calqué sur ds/KsModal.vue:149-158) + sauvegarder/restaurer `document.activeElement` au mount/unmount. Admin → P3.
- **Frozen** : NON (ces 3 fichiers hors §7 ; PaymentComponent/PosV5TrancheRow frozen, eux, NON touchés).

---

## [P3] resources/js/components/admin/pos/ItemComponent.vue:56,91 (+ CreateCustomerAddressComponent.vue:7) — boutons fermeture icône-seule sans nom accessible
- **Titre** : Boutons « fermer » uniquement icône (font-icon), sans texte ni `aria-label` → annoncés « bouton » vide par lecteur d'écran.
- **WCAG** : 4.1.2 Name, Role, Value (A), 2.4.4 partiel.
- **Repro** :
  - ItemComponent.vue:56 `<button class="modal-close fa-regular fa-circle-xmark" @click.prevent="infoModalHide">` — pas d'aria-label, pas de texte.
  - ItemComponent.vue:91 `<button class="modal-close lab-close-circle-line ..." @click.prevent="variationModalHide">` — idem (fermeture du modal variation v5).
  - CreateCustomerAddressComponent.vue:7 `<button class="modal-close fa-solid fa-xmark ..." @click="reset">` — idem.
- **Evidence** : `grep "<button" ... | grep -vi aria-label` ; à comparer au pattern CORRECT déjà en place PosComponent.vue:1074 (`:aria-label="$t('button.close')"`) → l'incohérence prouve l'oubli, pas un choix.
- **Lentille** : jumeau-systémique — clé i18n `button.close` existe déjà et appliquée ailleurs ; 3 boutons l'ont ratée.
- **Reco (NON-frozen)** : ajouter `:aria-label="$t('button.close')"` aux 3 boutons (ItemComponent.vue hors §7 — frozen = `public/js/pos-wizard.js`, pas le composant Vue). Admin → P3.
- **Frozen** : NON.

---

### Vérifié-propre (pas de finding)
- KioskWizardComponent.vue:2220-2305 & ds/KsModal.vue:149-158 : focus-trap Tab complet + role/aria-modal/aria-labelledby. Conforme.
- Tokens cibles tactiles borne : css/kiosk/tokens.css:141 `--kiosk-touch-min:48px` (≥44px AA OK) ; tokens-pmr.css:49 plancher 64px. Conforme — pas de finding.
- KeyboardNavigationSentinel.spec.js:192-201 : contrat `:focus-visible` CSS présent (button + [role=button] + .kiosk-touch-btn). L'échec « pré-existant » mentionné dans le brief relève du CSS visuel runtime, non source-grep ; non reproduit comme régression source ici.
