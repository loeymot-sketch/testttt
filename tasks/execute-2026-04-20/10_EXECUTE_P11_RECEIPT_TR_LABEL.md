# EXECUTE — P11_RECEIPT_TR_LABEL — 2026-04-20

## Status
**STATUS:** `READY_TO_LAUNCH`
**GATE_REQUIRED:** **NON** (presentation only, aucune logique métier ni backend)
**VAGUE:** V3 salve 2 (P1 hardening — plan §1.2 ligne 50 + §2 V3 ligne 141)
**BLOCKING:** Aucun

## Source
- `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.2 ligne 50 + §2 V3 ligne 141
- `reports/review/VERIFY_TRACKER_2026-04-20.md` F-VERIFY-02-02
- `reports/review/VERIFY_02_P2_MULTI_TENDER_2026-04-20.md` §74 V3 + §116-119 F-02 + §166

## Constat factuel pré-cycle (vérifié read-only)

**Cible précise** : `resources/js/components/admin/pos/ReceiptComponent.vue:211-216`

```js
posPaymentMethodEnumArray: {
    [posPaymentMethodEnum.CASH]: this.$t("label.cash"),
    [posPaymentMethodEnum.CARD]: this.$t("label.card"),
    [posPaymentMethodEnum.MOBILE_BANKING]: this.$t("label.mobile_banking"),
    [posPaymentMethodEnum.OTHER]: this.$t("label.other"),
},
```

**Manque** : entrée pour `pos_payment_method = 5` (Ticket Restaurant). Sans elle, toute commande TR créée par API directe imprime un reçu avec champ "Type de paiement" vide → non conforme NF525 (lisibilité du moyen de paiement requise).

**Contrainte forte (anti-empiétement avec P11_FRONT_TR_UI bloqué)** :
- `resources/js/enums/modules/posPaymentMethodEnum.js` ne contient **PAS** `TICKET_RESTAURANT: 5` aujourd'hui (juste 1-4)
- Ajouter `TICKET_RESTAURANT: 5` à l'enum est dans le scope de **P11_FRONT_TR_UI** (cycle séparé bloqué par dépendance `P11_AUDIT_TENDER_ON_CREATE` GPT-5.4 GATE)
- Ce cycle (RECEIPT_TR_LABEL) **NE DOIT PAS** modifier l'enum JS
- Solution : utiliser la valeur numérique `5` directe avec commentaire explicite. Quand `P11_FRONT_TR_UI` ajoutera l'enum, un refacto trivial remplacera `5` → `posPaymentMethodEnum.TICKET_RESTAURANT`

**i18n** :
- 5 langues détectées dans `resources/js/languages/` : fr, en, ar, de, bn
- Aucune des 5 ne contient `label.ticket_restaurant` actuellement (vérifié via grep)
- Backend (`lang/{fr,en,de,ar,bn}/pos_payment_method.php:10`) couvre déjà TR — les libellés français/anglais y sont (`Titre-restaurant` / `Meal voucher`) → on s'aligne dessus

## Routing (AGENTS.md §Model Roles)
- **PRIMARY_MODEL:** `Composer` (AGENTS.md:16 — "isolated UI fixes, presentation, i18n")
- **SUBAGENT:** `foodking-routine-implementer`
- **RUNNER_MODE:** `single-session`

## Scope

### SUBSYSTEMS_TOUCHED
- `resources/js/components/admin/pos/ReceiptComponent.vue` (1 ligne ajoutée + commentaire)
- `resources/js/languages/{fr,en,de,ar,bn}.json` (1 clé ajoutée par fichier)

### SCOPE_FILES (whitelist stricte — 6 fichiers max)
- `resources/js/components/admin/pos/ReceiptComponent.vue`
- `resources/js/languages/fr.json`
- `resources/js/languages/en.json`
- `resources/js/languages/ar.json`
- `resources/js/languages/de.json`
- `resources/js/languages/bn.json`
- `reports/execution/RUN_P11_RECEIPT_TR_LABEL_2026-04-20.md` (création)

### SUBSYSTEMS_OFF_LIMITS (strict — leçons cycles 02, 05, 07)
- ❌ `resources/js/enums/modules/posPaymentMethodEnum.js` (réservé P11_FRONT_TR_UI)
- ❌ `resources/js/components/admin/pos/PaymentComponent.vue` (réservé P11_FRONT_TR_UI)
- ❌ Tout autre composant Vue (`resources/js/components/**/*.vue` autres que ReceiptComponent.vue)
- ❌ Tout backend PHP (`app/`, `routes/`, `database/`, `config/`)
- ❌ Tests (`tests/`)
- ❌ `lang/**/*.php` (i18n backend déjà OK selon V02 §38-40)
- ❌ `package.json`, `composer.json`, lockfiles
- ❌ `webpack.mix.js` (build config)

## Invariants at Risk
- **Aucun** — pure présentation receipt + i18n.
- Risque potentiel : si la clé i18n est mal nommée (ex. `ticketRestaurant` vs `ticket_restaurant`), l'affichage tombera en clé brute. Mitigation : utiliser exactement `label.ticket_restaurant` (cohérent avec convention `label.cash`, `label.card`).
- **Pas** de NF525 risk car ce cycle complète une lacune NF525, ne modifie pas la chaîne d'audit ni la séquence fiscale.

## Dependencies
- Aucune (cycle indépendant)

## Plan bref

### Étape 1 — Lire (vérité terrain confirmée)
- `resources/js/components/admin/pos/ReceiptComponent.vue:200-225` (déjà lu pré-cycle — confirmer l'objet `posPaymentMethodEnumArray`)
- `resources/js/languages/fr.json` (chercher où injecter sous `label.*`, alignement cycle V1 #06 a ajouté `availability` etc.)
- `lang/fr/pos_payment_method.php:10` et `lang/en/pos_payment_method.php:10` (référence libellés backend pour traduction cohérente)

### Étape 2 — Modifier `ReceiptComponent.vue:211-216`

Ajouter une 5ème entrée :

```js
posPaymentMethodEnumArray: {
    [posPaymentMethodEnum.CASH]: this.$t("label.cash"),
    [posPaymentMethodEnum.CARD]: this.$t("label.card"),
    [posPaymentMethodEnum.MOBILE_BANKING]: this.$t("label.mobile_banking"),
    [posPaymentMethodEnum.OTHER]: this.$t("label.other"),
    // [P11_RECEIPT_TR_LABEL] TICKET_RESTAURANT (back enum value = 5).
    // Refacto futur : remplacer `5` par `posPaymentMethodEnum.TICKET_RESTAURANT`
    // une fois que P11_FRONT_TR_UI aura complété l'enum JS.
    5: this.$t("label.ticket_restaurant"),
},
```

### Étape 3 — Ajouter clé i18n dans 5 fichiers

Sous la section `label.*` de chaque fichier (où sont déjà `cash`, `card`, etc.) :

| Fichier | Valeur |
|---|---|
| `fr.json` | `"ticket_restaurant": "Titre-restaurant"` (aligné `lang/fr/pos_payment_method.php:10`) |
| `en.json` | `"ticket_restaurant": "Meal voucher"` (aligné `lang/en/pos_payment_method.php:10`) |
| `de.json` | `"ticket_restaurant": "Essensgutschein"` (aligné backend si présent ; sinon proposition standard de) |
| `ar.json` | `"ticket_restaurant": "قسيمة طعام"` (proposition arabe standard ; aligner backend si différent) |
| `bn.json` | `"ticket_restaurant": "মিল ভাউচার"` (proposition bengali standard ; aligner backend si différent) |

> **Important** : si les libellés backend existent dans `lang/{de,ar,bn}/pos_payment_method.php:10`, **les utiliser tels quels** (autorité backend prime). Sinon, utiliser les propositions ci-dessus. Le subagent doit lire ces fichiers backend en read-only pour confirmer.

### Étape 4 — Validation
- `git diff --stat` (preuve scope respect — exactement 6 fichiers : 1 vue + 5 json)
- `git status --short` (vérifier aucun fichier hors whitelist)
- Vérifier syntaxe JSON valide pour les 5 fichiers (pas de virgule trailante, encodage UTF-8 préservé) :
  - `php -r "json_decode(file_get_contents('resources/js/languages/fr.json'), true); var_dump(json_last_error() === JSON_ERROR_NONE);"` pour chaque fichier
- Vérifier syntaxe Vue : pas de test JS à lancer (le cycle V1 #06 montre que tests Vitest existants pour PaymentComponent ne couvrent pas Receipt — pas obligatoire d'en créer un ici, scope strict)

### Étape 5 — Rapport
`reports/execution/RUN_P11_RECEIPT_TR_LABEL_2026-04-20.md` avec gabarit Final report.

## Acceptance Tests
- [ ] `ReceiptComponent.vue:211-217` contient la 5ème entrée pour `5: this.$t("label.ticket_restaurant")` avec commentaire de référence
- [ ] `posPaymentMethodEnum.js` **inchangé** (preuve : `git diff resources/js/enums/modules/posPaymentMethodEnum.js` retourne vide)
- [ ] Chaque fichier `resources/js/languages/{fr,en,ar,de,bn}.json` contient `"ticket_restaurant": "..."` sous `label.*`
- [ ] Tous les 5 JSON sont parsables (`json_decode` OK)
- [ ] **Aucun** fichier hors whitelist modifié

## Exit Criteria
- [ ] 6 fichiers modifiés exactement
- [ ] JSON valides
- [ ] Commentaire de référence à `P11_FRONT_TR_UI` présent
- [ ] `reports/execution/RUN_P11_RECEIPT_TR_LABEL_2026-04-20.md` avec Final report

## Scope Pressure Protocol (renforcé — leçons V1)
**STOP IMMÉDIAT** si :
- Tentation d'ajouter `TICKET_RESTAURANT: 5` à `posPaymentMethodEnum.js` → ❌ SCOPE_PRESSURE (réservé P11_FRONT_TR_UI)
- Tentation de modifier `PaymentComponent.vue` pour ajouter le bouton TR → ❌ SCOPE_PRESSURE (réservé P11_FRONT_TR_UI)
- Tentation d'ajouter validation `pos_payment_note` → ❌ SCOPE_PRESSURE (réservé P11_FRONT_TR_UI)
- Tentation de modifier `lang/**/*.php` (PHP backend) → ❌ déjà OK selon V02
- Tentation d'ajouter un test Vitest `ReceiptComponent.spec.js` → ❌ pas dans scope V3 salve 2 (cycle test séparé possible plus tard)
- Tentation de modifier `KioskConfirmationComponent.vue` ou autre receipt kiosk → ❌ scope strict POS admin
- **Anti-pattern** : `git checkout` ou bypass lockfile → STOP + escalade

## Remediation
- Attempt 1 KO (JSON invalid après édition) → fix syntaxe via re-édition propre
- Attempt 2 KO (Vue syntax) → simplifier l'édition
- Attempt 3 même `bug_signature` → STOP + escalade humaine

## Deliverables
- Diff `ReceiptComponent.vue` (≤ 5 lignes ajoutées)
- Diff 5 fichiers `*.json` (1 ligne par fichier sous `label.*`)
- `reports/execution/RUN_P11_RECEIPT_TR_LABEL_2026-04-20.md`

## Communication
Subagent renvoie : verdict, output `git status --short`, `git diff --stat`, confirmation enum JS inchangé, validation JSON OK pour les 5 langues, libellés utilisés (avec référence backend si lus).
