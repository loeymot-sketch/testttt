# ADR-007 — Kiosk Runtime FR-Immutable (V1)

**Status:** Accepted (iter15-P1a) — Restored Sprint 3D 2026-05-16
**Branch:** `feature/mobile-app-le-cayenne-2026-05-10`
**Owners:** Backend lead + frontend lead (Le Cayenne V1)

## Context

Le Cayenne V1 est un fast-food français mono-restaurant. La borne client
(kiosk) tourne dans un environnement physique restaurant — pas un device
personnel utilisateur. Le menu, les CTA, le clavier virtuel, la voix
Web Speech, les libellés a11y sont **conçus, testés et calibrés en
français uniquement** pour la V1.

Permettre un basculement runtime FR → EN/AR depuis l'UI :

1. **Casse l'i18n cache** : les bundles EN/AR ne sont pas exhaustivement
   QA — risque réel d'afficher des raw labels (`kiosk.foo.bar`) à un
   client qui passe commande, ce qui dégrade la confiance et bloque le
   parcours.
2. **Ouvre des chemins non-NF525-testés** sur la caisse cross-surface
   (les screens admin POS/KDS qui partagent des composants Vue).
3. **Empêche les bornes physiques d'être prédictibles** côté ops :
   l'équipe restaurant doit pouvoir aider n'importe quel client en
   sachant à 100% ce qui est affiché.
4. **N'apporte pas de valeur métier V1** : les clients étrangers ont
   accès aux pictogrammes produits + équipe humaine en backup.

## Decision

**Le kiosk runtime est FR-immutable en V1.**

Aucun composant UI kiosk ne doit :

- importer `setLocale` depuis `resources/js/i18n.js`
- appeler `setLocale(...)` à l'exécution
- exposer un picker FR/EN/AR à l'utilisateur final

La locale i18n est posée **une seule fois au boot** via
`detectLocale()` (`isKioskPath()` → 'fr') et `applyKioskA11yFromStore`,
puis verrouillée pour toute la session.

## Implémentation

### iter15-P1a (initial lock)

- `resources/js/i18n.js` : `detectLocale()` retourne 'fr' sur
  `/kiosk*` indépendamment de `navigator.language` ou localStorage.
- `KioskAppComponent.vue` + `KioskIdleScreenComponent.vue` :
  watchers `kioskSettings.locale` supprimés, plus aucun appel runtime
  `setLocale()`.
- Régression couverte par `tests/js/kioskFrLockImmutable.spec.js`.

### Sprint 3D 2026-05-16 (breach K-001 P0 restoré)

Audit Wave B a découvert que `KsA11ySettings.vue` (drawer a11y monté
sur l'écran idle) exposait toujours un radiogroup FR/EN/AR + dispatch
`kioskSettings/setLocale`. Cela contredisait directement le commentaire
`KioskAppComponent.vue:181-184`.

Correctifs Sprint 3D :

1. **`resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue`** :
   suppression du radiogroup `kiosk-a11y-lang-*`, de la constante
   `LOCALE_OPTIONS`, et de la méthode `selectLocale`. Le drawer
   conserve : contraste AA/AAA, PMR, audio, audio description,
   reduced motion, thème (light/dark/auto), reset.
2. **`resources/js/store/index.js`** :
   `"kioskSettings.locale"` retiré de la liste `createPersistedState`.
   Effet : un localStorage hérité avec `locale=en` ne réinjecte plus la
   valeur au boot — le store retombe sur le default `'fr'`.
   L'action `setLocale` du module Vuex est conservée pour la
   testabilité (`tests/js/kioskSettingsStore.spec.js`,
   `tests/js/kioskA11yComposable.spec.js`,
   `tests/js/kioskSpeechComposable.spec.js`).
3. **`config/kiosk.php`** :
   nouvelle clé `'locale_switch_allowed' => env('KIOSK_LOCALE_SWITCH_ALLOWED', false)`,
   exposée au SPA via `master.blade.php` (`window.foodkingConfig.kioskLocaleSwitchAllowed`).
   Sert de gate explicite pour un pilote multi-langue post-V1 — ne
   ré-active pas l'UI à elle seule.

### Fichiers de référence

- `resources/js/i18n.js`
- `resources/js/composables/useKioskA11y.js` (propagation au boot)
- `resources/js/store/modules/kioskSettings.js` (action conservée)
- `resources/js/store/index.js` (persistedState — locale exclu)
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue`
- `resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue`
- `config/kiosk.php`
- `resources/views/master.blade.php`
- `tests/js/kioskFrLockImmutable.spec.js` (régression)
- `tests/js/kioskA11ySettingsDrawer.spec.js` (UI absence)

## Conséquences

### Positives

- UI client borne **100% prévisible** : équipe restaurant peut
  accompagner sans surprise.
- Aucun risque de raw label (FR JSON est canonique, EN/AR sont
  legacy iter15).
- Tests d'audit (Wave B) ne retombent plus sur la breach K-001 P0.
- Persisted state assaini : pas de drift legacy.

### Négatives

- Clients non-francophones : pas de support langue native pendant
  la commande. Mitigation V1 : pictogrammes produits + équipe humaine.
- Module Vuex `kioskSettings` conserve une action `setLocale`
  inutilisée par l'UI — purement infra de test. Si elle gêne lors
  d'un futur refactor, on peut la supprimer en même temps que les
  tests qui la consomment.

### À surveiller post-V1

Si un pilote multi-langue est ouvert (export, partenaire, autre
ville) :

1. Basculer `KIOSK_LOCALE_SWITCH_ALLOWED=true` dans `.env` de la borne
   ciblée uniquement (ne pas changer le default V1).
2. Réintroduire une UI dédiée (modale "Langue / Language / اللغة" en
   page d'accueil, pas dans le drawer a11y).
3. Repenser le clavier virtuel + Web Speech pour les locales
   activées (actuellement `'fr-FR'` codé en dur dans
   `useKioskSpeech.js`).
4. Ajouter `kioskSettings.locale` dans la persistedState **seulement
   si** un cas métier le justifie (sinon le default 'fr' au reload
   reste préférable).
5. Étendre `kioskFrLockImmutable.spec.js` pour ne s'appliquer qu'au
   default OFF, et ajouter une suite parallèle pour le mode ON.

## Références

- iter15-P1a (initial lock)
- Audit Wave B 2026-05-13 — finding K-001 P0
- `feedback_kiosk_wizard_not_protected.md` (frozen-zone matrix —
  `KsA11ySettings.vue` n'est PAS dans la liste frozen, modifiable
  sous gate ADR)
