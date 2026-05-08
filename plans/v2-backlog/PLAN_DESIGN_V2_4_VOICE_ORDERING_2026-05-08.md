# PLAN — V2-4 : Voice Ordering kiosk (Web Speech API V1 → NLU production V2)

> Wave gamma G2. Owner: orchestrator (Claude). Created: 2026-05-08.
> Greenfield scaffolding livré dans le même commit. Pas de modification de
> frozen zones (KioskApp/KioskPayment/KioskWizard/POS wizard).

---

## 1. Vision

Le kiosk FoodKing vise un parcours commande **inclusif et plus rapide** :
- accessibilité (utilisateurs avec mobilité réduite, malvoyants — la voix est
  un complément clavier/écran tactile)
- drive-thru futur (cf. roadmap SaaS B2B 2026-Q4)
- réduction du temps moyen de commande (target -15 % en pic flux)

Stack progressive :
- **V1.x (cette wave)** : wrapper `Web Speech API` (browser native) — STT
  passive, transcript visuel, UX bouton autonome non-câblé dans le wizard
  frozen.
- **V1.y** : intent parsing rule-based ("ajouter X", "supprimer Y",
  "valider commande") côté frontend ; aucun appel backend NLU.
- **V2 production** : remplacement Whisper API / Azure Speech / Google
  Speech-to-Text si la qualité Web Speech API est insuffisante en
  ambiance bruyante (food-court).

**Cible métier V1.x** : zéro régression. Composant **standalone** non câblé
dans le wizard kiosk frozen — testable isolé, démontrable côté preview, mais
non actif en production tant que le wizard ne l'embarque pas (futur ticket
hors scope frozen).

---

## 2. Architecture

### 2.1 Service JS (greenfield)

```
resources/js/services/kioskVoiceOrdering.js
```

Wrapper léger autour de `window.SpeechRecognition || window.webkitSpeechRecognition`
exposant :

- `isSupported()` — détection feature
- `initialize()` — création instance + handlers
- `start()`, `stop()` — contrôle micro
- `on(event, handler)` / `off(event, handler)` — bus interne (`start`, `result`, `end`, `error`)

**Singleton + named export** : on exporte la classe `VoiceOrderingService`
(pour tests) et un singleton par défaut (pour usage runtime). Mirroring du
pattern `resources/js/services/appService.js`.

### 2.2 Composant Vue (standalone)

```
resources/js/components/frontend/kiosk/KioskVoiceOrderingButton.vue
```

Caractéristiques :
- Bouton autonome avec accessibility complète (aria-label, aria-pressed,
  fallback visuel si Web Speech API absente).
- Émet `voice-input` (transcript final) et `voice-error` (erreur).
- Affiche le transcript intermédiaire (interim results) pour feedback
  visuel — important pour utilisateurs malentendants (pas de feedback audio
  uniquement).
- **PAS** importé dans `KioskWizardComponent` (frozen). Le composant est
  livré seul ; un futur ticket non-frozen l'intégrera ailleurs (preview,
  page de démo admin, ou un wizard non-frozen).

### 2.3 Relation avec `useKioskSpeech` existant

Le composable `resources/js/composables/useKioskSpeech.js` existe déjà pour
la **TTS** (Text-to-Speech, output audio). Le nouveau service est **STT**
(Speech-to-Text, input audio). Ils sont **complémentaires** :
- `useKioskSpeech.speak()` peut être appelé depuis le composant pour
  feedback audio "j'écoute" / "j'ai compris X" pour utilisateurs malvoyants.
- En V1.x, on documente cette intégration mais on **n'intègre pas** :
  preview composant standalone d'abord, on évite la double dépendance.

### 2.4 Stack Web Speech API — limites connues

| Limite | Impact | Workaround |
| --- | --- | --- |
| Chrome/Edge only (Safari support partiel) | Détection `isSupported()` + bouton désactivé | Fallback texte (déjà couvert par UI clavier virtuel kiosk existante) |
| HTTPS obligatoire | Bloque dev http:// | Annoncer dans la phase test : `npm run dev` derrière un proxy https |
| Permissions micro | First-time consent prompt navigateur | UI doit afficher pourquoi (a11y label) |
| Ambient noise (food court) | Reconnaissance dégradée | V2 : Whisper API server-side avec audio chunk |
| Multi-langue (fr-FR, en-US, ar-SA) | Précision variable selon navigateur | Test manuel obligatoire par locale |

---

## 3. Phases

### Phase A — V1.x scaffolding (CETTE WAVE, livré 2026-05-08)
**Livraison** :
- Service JS standalone (testable, mockable)
- Composant Vue standalone (non importé dans le wizard frozen)
- Tests vitest (mock SpeechRecognition)
- Strings i18n minimum fr+en (kiosk.voice.*)
- Pas d'intégration runtime dans wizard kiosk

**Acceptance** :
- vitest vert
- build production OK (`npm run prod`)
- composant ne fuit pas dans aucun chemin du wizard frozen (grep verify)

### Phase B — V1.y intent parsing rule-based
- Composable `useVoiceCommandParser` qui mappe transcript → action
  (ex. "ajouter big mac" → `cart.addItem(itemId)`)
- Catalogue de phrases par locale (fr/en/ar)
- Intégration dans un wizard kiosk **non-frozen** (vérifier liste de
  référence avant edit) ou dans un nouvel écran "Voice Mode"
- Confirmation visuelle obligatoire avant validation (pas d'action
  irréversible déclenchée par voix seule)

### Phase C — V2 production NLU
- Backend endpoint `POST /api/frontend/voice/transcribe` qui forward audio
  chunk vers Whisper API (ou Azure Speech / Google Cloud Speech)
- Streaming via WebSocket ou MediaRecorder + chunk POST
- Latence cible < 800ms (acceptable pour conversational UX)
- Multi-langue robuste avec auto-detect

---

## 4. Sub-tasks atomiques (Phase A — cette wave)

| # | Tâche | Fichiers | Owner | Tests |
| --- | --- | --- | --- | --- |
| A1 | Crée service `kioskVoiceOrdering.js` | `resources/js/services/kioskVoiceOrdering.js` | Claude | vitest unit × 6 |
| A2 | Crée composant `KioskVoiceOrderingButton.vue` | `resources/js/components/frontend/kiosk/KioskVoiceOrderingButton.vue` | Claude | vitest mount × 4 |
| A3 | Ajoute clés i18n `kiosk.voice.*` (fr+en) | `resources/js/languages/fr.json`, `resources/js/languages/en.json` | Claude | n/a |
| A4 | Spec test `tests/js/kioskVoiceOrdering.spec.js` | `tests/js/kioskVoiceOrdering.spec.js` | Claude | suite vitest |
| A5 | Vérifie zero import depuis wizard frozen | `grep KioskVoiceOrderingButton` | Claude | manuel |

---

## 5. Tests required

### 5.1 Vitest — `tests/js/kioskVoiceOrdering.spec.js`

Cas couverts (au minimum 6) :

1. `isSupported() returns false when SpeechRecognition is absent`
2. `isSupported() returns true when window.SpeechRecognition exists`
3. `initialize() sets up handlers correctly`
4. `start() emits 'start' and updates isListening`
5. `stop() cancels recognition and emits 'end'`
6. `on() / off() registers and removes handlers idempotently`
7. (bonus) `result event is emitted with transcript and isFinal`
8. (bonus) `error event is emitted on recognition error`

Mock pattern (mirror `kioskSpeechComposable.spec.js`) :

```js
beforeEach(() => {
    window.SpeechRecognition = vi.fn(() => ({
        start: vi.fn(),
        stop: vi.fn(),
        addEventListener: vi.fn(),
        // ...
    }));
});
```

### 5.2 Vitest mount — composant

(Inclus dans le même fichier ou en spec dédiée selon volume)
- Bouton désactivé si `isSupported()` retourne false
- `aria-label` présent
- Click → toggle listening
- Émission `voice-input` au final transcript

### 5.3 Manuel / Playwright (Phase B)
- HTTPS obligatoire
- Permissions micro acceptées
- Reconnaissance fr-FR sur phrase "ajouter un big mac"
- Robustesse au bruit ambiant (volume sonore mesurable)
- Test multi-langue (fr/en/ar)

### 5.4 Accessibility audit (Phase B)
- WCAG 2.1 AA compliance
- Screen reader test (VoiceOver / NVDA)
- Keyboard navigation alternative (un utilisateur sans micro doit pouvoir
  désactiver / ignorer le bouton)

---

## 6. Risks

| Risque | Sévérité | Mitigation |
| --- | --- | --- |
| Web Speech API ne marche que sur HTTPS | HIGH | Documentation déploiement : kiosk en HTTPS strict (déjà le cas en prod) |
| Permissions micro bloquées par utilisateur final | MEDIUM | Bouton se désactive gracieusement, message i18n clair |
| Reconnaissance dégradée en bruit ambiant (food-court réel) | HIGH | Phase C : Whisper API server-side ; phase B : test in-situ obligatoire |
| Multi-langue précision variable | MEDIUM | Test manuel par locale ; opt-out par locale via flag |
| **Accessibilité hearing-impaired** : feedback audio seul exclurait sourds/malentendants | HIGH | Transcript visuel **obligatoire** (déjà dans le composant V1.x) |
| Accessibilité visually-impaired : feedback visuel seul exclurait malvoyants | HIGH | Phase B : intégration `useKioskSpeech.speak()` pour confirmation audio |
| Privacy : enregistrement audio côté kiosk public | HIGH (RGPD) | V1.x : transcript local seulement, pas de persist. V2 : opt-in explicit + retention 0s |
| Latence WebSocket (Phase C) | MEDIUM | SLO < 800ms ; fallback batch si streaming KO |
| Composant fuite dans wizard frozen | CRITICAL | Vérification grep en CI ; PR review obligatoire |
| Confusion utilisateur si voix "écoute" sans indicateur | MEDIUM | Animation visuelle micro + classe `.is-listening` |

---

## 7. Effort & rollout

- **Phase A scaffolding (cette wave)** : 0.5j orchestrateur (livré dans ce
  même commit avec V2-3).
- **Phase B intent parsing + intégration** : 4-5j (composable + catalogue
  i18n + intégration wizard non-frozen + analytics + a11y audit).
- **Phase C V2 production NLU** : 5-7j (backend audio chunk + Whisper API
  intégration + multi-langue robuste + monitoring).

**Effort total estimé : 9-12j ouvrés**, gated entre phases avec preview
manuel + a11y audit avant rollout.

**Pré-requis Phase B/C** : kiosk hardware avec micro de qualité (food-court
needs noise-cancelling), HTTPS strict, RGPD review.

---

## 8. References

- [`resources/js/composables/useKioskSpeech.js`](../resources/js/composables/useKioskSpeech.js) — TTS composable existante (output audio)
- [`tests/js/kioskSpeechComposable.spec.js`](../tests/js/kioskSpeechComposable.spec.js) — pattern de mock SpeechSynthesis (mirror pour SpeechRecognition)
- [`resources/js/languages/fr.json`](../resources/js/languages/fr.json) — namespace `kiosk.*`
- `plans/AUDIT_STRATEGIC_VISION_2026-05-07.md` — drive-thru roadmap (Phase C target)

---

## 9. Anti-drift checklist

- [x] Plan ne touche **aucun** fichier frozen (KioskApp/Payment/Wizard/POS wizard)
- [x] Composant standalone, **PAS importé** dans le wizard kiosk frozen
- [x] Plan respecte invariant a11y (transcript visuel obligatoire)
- [x] Plan respecte privacy/RGPD (V1.x = local only, pas de persist)
- [x] Plan a un kill-switch (composant désactivable, pas dans le runtime path par défaut)
- [x] Plan adresse l'écosystème existant (`useKioskSpeech` complémentaire)
- [x] Plan a 6+ tests vitest minimum, branchés sur conventions repo (mock window.* + happy-dom)
