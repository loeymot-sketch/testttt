# Page 1 — /login — Evidence

**Verdict** : GREEN
**State** : `01-login-idle`

## Capture quartet

- PNG : `tests/e2e/__screenshots__/goal-pageby-pos-2026-05-18/01-login-idle.png`
- DOM : `tests/e2e/__screenshots__/goal-pageby-pos-2026-05-18/01-login-idle.dom.html`
- Console : `tests/e2e/__screenshots__/goal-pageby-pos-2026-05-18/01-login-idle.console.json`
- Network : `tests/e2e/__screenshots__/goal-pageby-pos-2026-05-18/01-login-idle.network.json`

## Visual analysis

FoodKing crown logo top-left. Header CTA "Connexion" (orange pill, white text, capitalized). Centered card with title "Bon Retour" (h1, dark navy). Two labeled inputs : Email + Mot De Passe (filled placeholders, rounded borders, neutral gray). Checkbox "Se Souvenir De Moi" + link "Mot De Passe Oublié" (orange link). Primary CTA "Connexion" (full-width orange button, white bold text). Layout breathes, no overflow, no truncation. Brand-consistent French i18n throughout.

## Technical analysis

- Raw-label scan : no `label.x` / `auth.foo` pattern leaks. All labels rendered FR.
- Console : 1 entry, info level only (Vue mounted). No errors.
- Network : `[]` — no 4xx/5xx during initial render.
- DOM : `#formEmail` + `#formPassword` selectable, submit button accessible (`getByRole('button', name=/connexion/i)`).

## Verdict

GREEN. No defect. No fix applied.
