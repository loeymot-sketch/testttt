# KIOSK_HARDWARE_CALLS.md

> Tous les appels `window.borne.*` déclenchés par le design FoodKing V1.
> **Règle d'or** : jamais de `new Audio()`, `navigator.vibrate()`, ou appel
> hardware direct. Tout passe par le bridge.
>
> Source : §5-A point 2 du brief validé.

---

## 1. API bridge — surface complète

```ts
interface BorneBridge {
  // Audio
  play(sound_id: SoundId): Promise<void>;
  stopAllSounds(): void;
  speak(text: string, lang: Locale): Promise<void>;
  stopSpeak(): void;

  // Impression
  print(buffer: Uint8Array): Promise<{ ok: boolean; error?: string }>;

  // Haptique
  haptic(pattern: 'tap' | 'confirm' | 'error'): void;

  // Scan
  scanQR(timeoutMs: number): Promise<{ data: string } | null>;
  readNFC(timeoutMs: number): Promise<{ uid: string } | null>;

  // Paiement
  tpeCharge(
    amountCents: number,
    method: 'CB' | 'TR'
  ): Promise<{ ok: boolean; tx_ref?: string; error?: string }>;
  tpeRefund(
    tx_ref: string,
    amountCents: number
  ): Promise<{ ok: boolean }>;

  // Tiroir caisse
  openDrawer(): Promise<{ ok: boolean }>;

  // Diagnostics
  info(): Promise<{
    firmware_version: string;
    printer_status: 'ready' | 'paper_low' | 'paper_out' | 'error';
    tpe_status: 'ready' | 'error';
    camera_status: 'ready' | 'error' | 'not_connected';
    nfc_status: 'ready' | 'error';
  }>;
  healthcheck(): Promise<{ ok: boolean; components: Record<string, boolean> }>;

  // Événements hardware
  onHardwareEvent(cb: (evt: HardwareEvent) => void): () => void;
}

type SoundId =
  | 'tap' | 'confirm' | 'error'
  | 'success_chime' | 'idle_subtle' | 'upsell_prompt';

type Locale = 'fr-FR' | 'en-US' | 'ar-SA';

type HardwareEvent =
  | { type: 'printer_paper_low' }
  | { type: 'printer_paper_out' }
  | { type: 'tpe_disconnected' }
  | { type: 'card_presented' }
  | { type: 'nfc_detected'; uid: string };
```

### Convention de gestion d'erreur

Aucune méthode `throw` — toutes les erreurs sont dans `result.error`.
Le design doit toujours gérer le cas erreur et afficher le fallback UX approprié.

```ts
const res = await window.borne.tpeCharge(1490, 'CB');
if (!res.ok) {
  showError(res.error ?? 'payment_failed_generic');
  return;
}
goToScreen('success');
```

---

## 2. Appels par écran

### `ScreenIdle` (veille)

| Trigger | Appel bridge | Notes |
|---|---|---|
| Tap "Commander ici" | `haptic('tap')` + `play('tap')` | Entrée parcours |
| Animation idle 8 s | `play('idle_subtle')` | Uniquement si `!lowPerf` |
| Bouton 🔊 audio description | `speak('Bienvenue chez FoodKing. Touchez l\'écran pour commander.', 'fr-FR')` | 1 fois au tap |

### `ScreenMenu`

| Trigger | Appel bridge | Notes |
|---|---|---|
| Tap catégorie | `haptic('tap')` + `play('tap')` | |
| Tap ProductCard | `haptic('tap')` + `play('tap')` | |
| Tap "Ajouter au panier" (produits simples sans wizard) | `haptic('confirm')` + `play('confirm')` | Toast aussi |
| Tap filtre (Halal/Végé/etc.) | `haptic('tap')` + `play('tap')` | |
| Ouvrir recherche | `haptic('tap')` + `play('tap')` | |
| Scan QR fidélité (bouton header) | `scanQR(15000)` | Résultat → `POST /api/frontend/loyalty/scan` |
| Tap 🔊 audio description | `speak(visible_content, locale)` | Re-déclenchable, `stopSpeak()` avant |
| Bouton contraste renforcé | *aucun bridge* | Juste toggle CSS class |
| Bouton PMR | *aucun bridge* | Juste toggle CSS class |

### `WizardStep` (chaque étape)

| Trigger | Appel bridge | Notes |
|---|---|---|
| Tap option | `haptic('tap')` + `play('tap')` | |
| Tap "Suivant" | `haptic('confirm')` + `play('confirm')` | |
| Tap "Précédent" | `haptic('tap')` + `play('tap')` | |
| Auto-avance choix unique | `haptic('tap')` + `play('tap')` | Délai : 350 ms / 500 ms malvoyant / 600 ms PMR |
| Tap "Abandonner l'article" → confirm modal | `haptic('error')` + `play('error')` | |
| Confirm modal "Abandonner" | `haptic('error')` | pas de son (action destructrice, le son l'a déjà précédée) |
| Allergène détecté (après scan fidélité) | `haptic('error')` + `play('error')` | Modal warning |
| Upsell affiché | `play('upsell_prompt')` | 1 fois |
| Ajouter au panier (fin wizard) | `haptic('confirm')` + `play('confirm')` + toast "Ajouté ✓" | |

### `ScreenCart` (panier)

| Trigger | Appel bridge | Notes |
|---|---|---|
| Tap "+ Ajouter un article" | `haptic('tap')` + `play('tap')` | |
| Tap qty +/- | `haptic('tap')` + `play('tap')` | |
| Tap "Retirer" | `haptic('error')` + `play('error')` | Toast undo 3 s |
| Tap sur place / à emporter | `haptic('tap')` + `play('tap')` | |
| Tap "Code promo" | `haptic('tap')` + `play('tap')` | Ouvre clavier virtuel |
| Tap "Continuer" | `haptic('confirm')` + `play('confirm')` | |

### `ScreenPayment`

| Trigger | Appel bridge | Notes |
|---|---|---|
| Affichage écran | `info()` | Vérifier `tpe_status === 'ready'` |
| Tap "Payer CB" | `tpeCharge(totalCents, 'CB')` | Timeout 90 s côté TPE |
| Tap "Payer TR" | `tpeCharge(trCents, 'TR')` | Puis CB pour le reste |
| Paiement OK | `play('success_chime')` + `haptic('confirm')` | Son signature |
| Paiement KO | `play('error')` + `haptic('error')` | |
| Tap "Annuler" | `tpeRefund(tx_ref, cents)` si tx existait | |
| Event `card_presented` | `haptic('tap')` | Feedback ergonomique |

### `ScreenConfirmation` (ticket + numéro)

| Trigger | Appel bridge | Notes |
|---|---|---|
| Arrivée écran | `print(ticket_buffer)` | Buffer généré backend |
| Erreur impression (`!ok`) | `play('error')` + affichage fallback "Votre commande est validée, présentez-vous au comptoir" | Ne bloque PAS la commande |
| Event `printer_paper_low` (reçu pendant) | Signal silencieux admin, pas d'affichage client | |
| Event `printer_paper_out` | Afficher écran fallback "Présentez-vous au comptoir" | Commande déjà validée backend |
| Sortie écran | `stopAllSounds()` | |

### `ScreenWaiting` (suivi)

| Trigger | Appel bridge | Notes |
|---|---|---|
| Appel numéro | `play('success_chime')` | Signature brand |
| Polling statut | *aucun bridge* | HTTP `GET /api/frontend/order/{id}/status` |

---

## 3. Appels bridge par mode a11y

### Mode PMR
Pas d'appel bridge spécifique — le design repositionne juste les éléments dans la zone 160-1760 px.

### Mode contraste renforcé
- Au toggle ON : `speak('Mode contraste renforcé activé', locale)`
- Au toggle OFF : `speak('Mode normal activé', locale)`

### Mode audio description
- Bouton 🔊 présent **sur chaque écran**
- Au tap : `stopSpeak()` puis `speak(current_screen_content_string, locale)`
- Quitter l'écran : `stopSpeak()` automatique

### Changement de langue
- `stopSpeak()` obligatoire avant reroll
- `play('tap')` + `haptic('tap')`

---

## 4. Diagnostics au boot

Au boot de l'application (avant affichage idle) :

```ts
const health = await window.borne.healthcheck();
if (!health.ok) {
  const info = await window.borne.info();
  // Log côté admin (via /api/frontend/kiosk-event), afficher écran admin
  // si composants critiques KO (tpe_status !== 'ready').
}
```

Composants critiques bloquants : `tpe_status`, `printer_status`.
Composants dégradés acceptables : `nfc_status`, `camera_status` — affichage dégradé, parcours fonctionne.

---

## 5. Tableau synthèse sons

| Sound ID | Longueur max | Quand | Écrans |
|---|---|---|---|
| `tap` | 80 ms | Tap interactif standard | Tous |
| `confirm` | 200 ms | Validation étape / ajout panier | Wizard, Cart |
| `error` | 300 ms | Retour négatif / annulation | Wizard, Cart, Payment |
| `success_chime` | 1 s | Confirmation finale commande | Payment OK, Confirmation |
| `idle_subtle` | 300 ms | Pulse idle toutes les 8 s | Idle |
| `upsell_prompt` | 200 ms | Apparition upsell | Cart, Wizard |

Format : **OGG Vorbis primary + MP3 fallback**, 48 kHz mono, normalisés à −14 LUFS.

---

## 6. Règles absolues

1. **Jamais de `new Audio()`** — toujours `window.borne.play()` ou `speak()`.
2. **Jamais de `navigator.vibrate()`** — toujours `window.borne.haptic()`.
3. **Jamais de window.print()** ou équivalent — toujours `window.borne.print()`.
4. **`stopSpeak()` au démontage** de tout composant qui a appelé `speak()`.
5. **Gérer le cas `bridge undefined`** (environnement de dev sans bridge) : stub no-op.
6. **Jamais d'accès direct à `window.borne.*`** depuis un composant Vue ou un helper.
   Tout passe par `resources/js/services/kioskHardware.js` (wrapper typé, contrat
   `{ok, error?}`, auto-reporting des erreurs hardware vers `POST /kiosk-event`).
   → Audit grep périodique : `grep -rn 'window\.borne' resources/js/components resources/js/helpers`
   ne doit renvoyer que des références en **commentaires de documentation**.

---

## 6.bis Cycle de vie app (Phase 6.5)

| Méthode              | Retour                          | Quand                                          |
|----------------------|---------------------------------|-----------------------------------------------|
| `reload()`           | `{ok: true}` / `{ok:false, error}` | Admin panel → "Recharger l'application". Fallback navigateur : `window.location.reload()` si bridge indisponible. |
| `quit()`             | `{ok: true}` / `{ok:false, error}` | Admin panel → "Quitter l'application" (staff technique / maintenance only). Pas de fallback navigateur (kiosk mode Electron). |

Stub dev : les deux méthodes retournent `{ok: true}` immédiatement.

### Exemple d'usage depuis Vue

```js
import kioskHardware from '@/services/kioskHardware';

async reloadApp() {
  const r = await kioskHardware.reload();
  if (!r.ok) window.location.reload();
}
```

### Stub de dev

```ts
// resources/js/kiosk/bridgeStub.ts
if (typeof window.borne === 'undefined') {
  window.borne = {
    play: async () => console.log('[stub] play'),
    stopAllSounds: () => {},
    speak: async (t, l) => console.log(`[stub] speak ${l}: ${t}`),
    stopSpeak: () => {},
    print: async () => ({ ok: true }),
    haptic: () => {},
    scanQR: async () => null,
    readNFC: async () => null,
    tpeCharge: async () => ({ ok: true, tx_ref: 'stub' }),
    tpeRefund: async () => ({ ok: true }),
    openDrawer: async () => ({ ok: true }),
    info: async () => ({ /* ... */ }),
    healthcheck: async () => ({ ok: true, components: {} }),
    onHardwareEvent: () => () => {},
    reload: async () => ({ ok: true }),
    quit: async () => ({ ok: true }),
  };
}
```
