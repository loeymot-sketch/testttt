# [P3] KioskCartComponent.vue:32-58 — Modal "vider le panier" sans gestion de focus (focus-trap absent)

## Verdict adversaire : REAL, severity downgrade P2 -> P3

## Repro (confirmée)
- `grep -c 'focus()' resources/js/components/frontend/kiosk/KioskCartComponent.vue` = 0
- Dialog l.32-41 : `role="dialog" aria-modal="true"` + `@keydown.esc="showClearConfirm=false"` mais aucun `ref`, aucun `tabindex`, aucun watcher sur `showClearConfirm` (seul `mounted()` l.484).
- Déclencheur = bouton "vider" l.20-27 (sibling du dialog). À l'ouverture le focus reste sur ce bouton -> le keydown Esc bulle vers un noeud qui n'est PAS ancêtre du dialog -> handler `@keydown.esc` jamais atteint tant que l'utilisateur n'a pas Tab-é dans le dialog. Aucun confinement Tab.

## Evidence
- Référence canonique existante NON appliquée ici : `resources/js/components/frontend/kiosk/ds/KsModal.vue` — focus 1er focusable à l'ouverture (l.133-146), cycle Tab first/last (l.148-166), restauration focus à la fermeture (l.110-118).
- Frozen ? NON. KioskCartComponent.vue et ds/KsModal.vue hors liste frozen (frozen kiosk = Wizard/App/Upsell).

## Pourquoi P3 et non P2
Borne Le Cayenne V1 = kiosk TACTILE mono-poste, pas de clavier physique. Le modal est pleinement opérable au toucher : backdrop `@click.self` (l.39) + bouton "Non" (l.51-55). Esc/Tab = préoccupations clavier-uniquement -> impact utilisateur réel ~nul sur terminal tactile. Vraie dette a11y (EN 301 549 / techno d'assistance) mais pas un défaut UX-réel V1-LOCAL. Rubrique : a11y-polish = P3.

## Lentille
Jumeau-systémique : un focus-trap canonique (KsModal) existe mais ce modal inline ne le réutilise pas. Vérifier les autres modales inline kiosk (ex. promo l.293) pour la même classe.

## Reco (heal non-frozen, TDD)
1. Ajouter `ref="clearCancelBtn"` au bouton "Non" (l.51-55) + `tabindex="-1"` au div dialog (l.33).
2. Watcher : `watch: { showClearConfirm(v){ if(v){ this._prevFocus=document.activeElement; this.$nextTick(()=>this.$refs.clearCancelBtn?.focus()); } else if(this._prevFocus?.focus){ this._prevFocus.focus(); } } }`.
3. (Optionnel idéal) remplacer le modal inline par `<ks-modal v-model="showClearConfirm">` pour hériter du Tab-trap complet.
4. TDD Vitest : `mount(..., { attachTo: document.body })`, set `showClearConfirm=true`, `await nextTick()`, `expect(document.activeElement).toBe(wrapper.find('[data-testid=kiosk-cart-clear-no]').element)`.

## Frozen
NON-frozen. heal_safe = true.
