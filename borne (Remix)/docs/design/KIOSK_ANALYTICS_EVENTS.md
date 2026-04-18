# KIOSK_ANALYTICS_EVENTS.md

> Vocabulaire des événements analytics émis par le design FoodKing V1.
> Endpoint : **`POST /api/frontend/kiosk-event`**
> Granularité : **décisions uniquement** (pas tap-level — sinon ×100 volume).
>
> Source : §⚡ point 1 du brief validé.

---

## 1. Enveloppe commune

```jsonc
{
  "branch_id": "uuid",           // obligatoire, depuis config client
  "session_id": "uuid",          // éphémère, renouvelé à chaque idle reset
  "timestamp": "2026-04-18T14:32:01.234Z",  // ISO 8601 ms, UTC
  "event_name": "add_to_cart",   // cf. liste §3
  "payload": { /* spécifique à event_name */ }
}
```

### Règles
- **`session_id`** : UUID v4 généré côté client au 1er tap après idle ; détruit au reset.
- **Jamais de PII** dans `payload` (pas d'email, tel, nom, CB). Uniquement IDs opaques.
- **Un seul HTTP POST par événement**, fire-and-forget (`navigator.sendBeacon` si dispo).
- **Échec réseau** = retry silencieux (queue locale max 200 événements, FIFO).
- **Opt-in RGPD** requis avant d'envoyer quoi que ce soit (cf. `ScreenConsentHeatmap`).

---

## 2. Modes de consentement

| Consentement | Comportement |
|---|---|
| Non donné (avant écran consent) | **Aucun** POST. Queue en RAM rejetée au reset. |
| Refusé | **Aucun** POST, y compris pour événements fonctionnels. Session anonyme totale. |
| Accepté | POST autorisés pour la session courante uniquement. |

---

## 3. Catalogue d'événements V1

### Navigation & découverte

#### `menu_viewed`
Arrivée sur l'écran menu (après idle ou retour panier).
```json
{ "category_id": "cat_tacos", "locale": "fr" }
```

#### `category_selected`
Tap sur une catégorie ou sous-catégorie.
```json
{ "category_id": "cat_sandwich_chaud", "parent_category_id": "cat_sandwich" }
```

#### `search_performed`
Validation d'une recherche (pas à chaque keystroke).
```json
{ "query_length": 6, "results_count": 3 }
```
> `query_length` et non `query` : pas de contenu texte en analytics.

---

### Parcours produit

#### `item_opened`
Tap sur une ProductCard qui ouvre le wizard ou l'ajoute direct.
```json
{ "item_id": "s-kebab", "category_id": "cat_sandwich_chaud", "from": "grid" }
```
`from` ∈ `"grid" | "search" | "chef_pick" | "promo_carousel" | "upsell" | "turbo"`

#### `wizard_step_entered`
Entrée sur une étape du wizard.
```json
{ "item_id": "s-kebab", "step": "meat", "step_index": 2, "total_steps": 7 }
```

#### `wizard_step_completed`
Sortie OK d'une étape (vers la suivante).
```json
{
  "item_id": "s-kebab",
  "step": "meat",
  "duration_ms": 8400,
  "auto_advanced": false
}
```

#### `wizard_abandoned`
Confirmation modale "Abandonner l'article".
```json
{ "item_id": "s-kebab", "last_step": "sauce", "steps_completed": 3 }
```

---

### Panier

#### `add_to_cart`
Ajout confirmé au panier (fin wizard ou produit simple).
```json
{
  "item_id": "s-kebab",
  "qty": 1,
  "extras_total_cents": 150,
  "line_total_cents": 890,
  "has_menu_upgrade": true
}
```

#### `remove_from_cart`
Retrait d'une ligne (pas via qty → 0, mais via bouton Retirer).
```json
{ "item_id": "s-kebab", "qty_removed": 1, "reason": "explicit_remove" }
```
`reason` ∈ `"explicit_remove" | "qty_zero" | "undo_toast"`

#### `quantity_changed`
Tap +/- sur une ligne panier.
```json
{ "item_id": "s-kebab", "qty_before": 1, "qty_after": 2 }
```

---

### Upsell / cross-sell

#### `upsell_shown`
Apparition d'une suggestion upsell.
```json
{
  "trigger_item_id": "s-kebab",
  "suggested_item_id": "frite-cheddar",
  "rule_id": "upsell_42",
  "screen": "cart"
}
```
`screen` ∈ `"cart" | "wizard_end" | "payment"`

#### `upsell_accepted`
Tap "Ajouter" sur upsell.
```json
{ "rule_id": "upsell_42", "suggested_item_id": "frite-cheddar" }
```

#### `upsell_rejected`
Tap "Non merci" ou fermeture.
```json
{ "rule_id": "upsell_42", "reason": "explicit_reject" }
```
`reason` ∈ `"explicit_reject" | "timeout" | "navigated_away"`

---

### Checkout & paiement

#### `checkout_started`
Tap "Continuer" depuis l'écran panier.
```json
{ "total_cents": 1490, "lines_count": 3, "dine_in": true }
```

#### `payment_method_selected`
```json
{ "method": "CB" }
```
`method` ∈ `"CB" | "TR" | "CB_TR_SPLIT"`

#### `payment_completed`
Succès TPE.
```json
{ "method": "CB", "total_cents": 1490, "duration_ms": 12400 }
```

#### `payment_failed`
Échec TPE ou timeout.
```json
{
  "method": "CB",
  "reason": "card_declined",
  "retry_count": 1,
  "duration_ms": 45000
}
```
`reason` ∈ `"card_declined" | "timeout" | "tpe_error" | "user_cancelled"`

#### `order_completed`
Commande validée backend (après ticket imprimé).
```json
{ "order_id": "ord_abc123", "total_cents": 1490, "order_number": 42 }
```

#### `order_cancelled`
Abandon complet de commande (après panier, avant paiement OK).
```json
{ "last_step": "payment", "total_cents": 1490, "lines_count": 3 }
```

---

### Accessibilité & a11y

#### `a11y_mode_activated`
```json
{ "mode": "pmr" }
```
`mode` ∈ `"pmr" | "high_contrast" | "audio_description" | "reduced_motion"`

#### `a11y_mode_deactivated`
Mêmes valeurs `mode`.

#### `locale_changed`
```json
{ "from": "fr", "to": "ar" }
```

#### `loyalty_scanned`
QR fidélité scanné avec succès.
```json
{ "method": "qr", "has_allergens": true, "has_last_order": true }
```
`method` ∈ `"qr" | "nfc"`
> Pas de `customer_id` dans l'event — anonymisation totale.

#### `turbo_mode_activated`
Tap "Ma commande habituelle".
```json
{ "lines_count": 3, "total_cents": 1490 }
```

---

### Idle & système

#### `idle_warning_shown`
Apparition overlay "Êtes-vous toujours là ?".
```json
{ "elapsed_s": 180, "screen": "wizard", "has_cart": true }
```

#### `idle_reset`
Reset automatique complet (absence interaction 180 s + 10 s countdown).
```json
{ "had_cart": true, "last_screen": "menu" }
```

#### `idle_dismissed`
L'utilisateur a cliqué "Je suis là" pendant le countdown.
```json
{ "elapsed_s": 188 }
```

#### `consent_given`
```json
{ "consent_type": "heatmap", "granted": true }
```
`consent_type` ∈ `"heatmap" | "loyalty_scan" | "mobile_transfer"`

#### `hardware_error`
Erreur périphérique remontée par `onHardwareEvent` pendant le parcours.
```json
{
  "component": "printer",
  "severity": "warning",
  "code": "printer_paper_low"
}
```

---

## 4. Événements INTERDITS en V1 (rappel)

- ❌ `tap_recorded` ou tout événement tap-level
- ❌ `scroll_position` ou tracking scroll en continu
- ❌ `hover_detected` (pas de hover sur tactile, mais l'interdiction est explicite)
- ❌ Tout événement contenant : email, téléphone, nom, prénom, IBAN, numéro CB, montant en clair au-delà de `*_cents`.

---

## 5. Payload synthétique — table de référence

| Event | Payload keys |
|---|---|
| `menu_viewed` | `category_id`, `locale` |
| `category_selected` | `category_id`, `parent_category_id?` |
| `search_performed` | `query_length`, `results_count` |
| `item_opened` | `item_id`, `category_id`, `from` |
| `wizard_step_entered` | `item_id`, `step`, `step_index`, `total_steps` |
| `wizard_step_completed` | `item_id`, `step`, `duration_ms`, `auto_advanced` |
| `wizard_abandoned` | `item_id`, `last_step`, `steps_completed` |
| `add_to_cart` | `item_id`, `qty`, `extras_total_cents`, `line_total_cents`, `has_menu_upgrade` |
| `remove_from_cart` | `item_id`, `qty_removed`, `reason` |
| `quantity_changed` | `item_id`, `qty_before`, `qty_after` |
| `upsell_shown` | `trigger_item_id`, `suggested_item_id`, `rule_id`, `screen` |
| `upsell_accepted` | `rule_id`, `suggested_item_id` |
| `upsell_rejected` | `rule_id`, `reason` |
| `checkout_started` | `total_cents`, `lines_count`, `dine_in` |
| `payment_method_selected` | `method` |
| `payment_completed` | `method`, `total_cents`, `duration_ms` |
| `payment_failed` | `method`, `reason`, `retry_count`, `duration_ms` |
| `order_completed` | `order_id`, `total_cents`, `order_number` |
| `order_cancelled` | `last_step`, `total_cents`, `lines_count` |
| `a11y_mode_activated` | `mode` |
| `a11y_mode_deactivated` | `mode` |
| `locale_changed` | `from`, `to` |
| `loyalty_scanned` | `method`, `has_allergens`, `has_last_order` |
| `turbo_mode_activated` | `lines_count`, `total_cents` |
| `idle_warning_shown` | `elapsed_s`, `screen`, `has_cart` |
| `idle_reset` | `had_cart`, `last_screen` |
| `idle_dismissed` | `elapsed_s` |
| `consent_given` | `consent_type`, `granted` |
| `hardware_error` | `component`, `severity`, `code` |

---

## 6. Helper client recommandé

```ts
// resources/js/kiosk/analytics.ts
import { useSession } from '@/composables/useSession';

const queue: AnalyticsEvent[] = [];

export function track(event_name: string, payload: object = {}) {
  if (!useSession().consent.heatmap) return;  // opt-in gate
  const evt = {
    branch_id: useSession().branch_id,
    session_id: useSession().id,
    timestamp: new Date().toISOString(),
    event_name,
    payload,
  };
  const blob = new Blob([JSON.stringify(evt)], { type: 'application/json' });
  if (!navigator.sendBeacon('/api/frontend/kiosk-event', blob)) {
    queue.push(evt);
    setTimeout(flush, 2000);
  }
}

function flush() {
  while (queue.length) {
    const evt = queue.shift()!;
    fetch('/api/frontend/kiosk-event', {
      method: 'POST',
      body: JSON.stringify(evt),
      headers: { 'Content-Type': 'application/json' },
      keepalive: true,
    }).catch(() => queue.unshift(evt));
  }
}
```

---

## Annexe B — Statut d'alimentation final (Phase 6 + 7, 2026-04-18)

Traçabilité : quel event est émis par quel composant, via quel canal, et quel test le couvre.

| Event                          | Source (code)                                              | Canal       | Test            |
|--------------------------------|------------------------------------------------------------|-------------|-----------------|
| `menu_viewed`                  | `KioskCategoriesComponent.mounted`                         | track()     | Vitest P6       |
| `category_selected`            | `KioskCategoriesComponent.selectCategory`                  | track()     | Vitest P6       |
| `add_to_cart`                  | `kioskAnalyticsPlugin` → mutation `kioskCart/ADD_ITEM`     | Vuex plugin | Vitest P6       |
| `remove_from_cart`             | `kioskAnalyticsPlugin` → mutation `kioskCart/REMOVE_ITEM`  | Vuex plugin | Vitest P6       |
| `quantity_changed`             | `kioskAnalyticsPlugin` → mutation `UPDATE_QUANTITY`        | Vuex plugin | Vitest P6       |
| `upsell_shown`                 | `KioskUpsellComponent.loadSuggestions` (quand items>0)     | track()     | Vitest P6       |
| `upsell_accepted`              | `KioskUpsellComponent.addAndContinue`                      | track()     | —               |
| `upsell_rejected` (4 reasons)  | `KioskUpsellComponent.skip(reason)`                        | track()     | —               |
| `checkout_started`             | `KioskPaymentComponent.confirmPayment`                     | track()     | —               |
| `payment_method_selected`      | `KioskPaymentComponent.selectMethod`                       | track()     | —               |
| `payment_completed` (cash+card)| `KioskPaymentComponent.processCash/CardPayment`            | track()     | —               |
| `payment_failed`               | `KioskPaymentComponent.processCardPayment` (refus/timeout) | track()     | —               |
| `order_cancelled { stage }`    | `KioskPaymentComponent.cancelCardPayment`                  | track()     | —               |
| `idle_warning_shown`           | `KioskAppComponent.showIdleWarning`                        | track()     | —               |
| `idle_reset`                   | `KioskAppComponent.resetKiosk`                             | track()     | —               |
| `idle_dismissed`               | `KioskAppComponent.dismissIdleWarning`                     | track()     | —               |
| `loyalty_scanned`              | `KioskLoyaltyComponent._doSubmitRegister` (success)        | track()     | —               |
| `consent_given`                | `KsConsentModal.accept`                                    | track()     | Vitest consent  |
| `a11y_mode_activated`          | `kioskA11y` composable (toggles AAA/PMR/audio)             | track()     | Vitest a11y     |
| `locale_changed`               | `kioskSettings/setLocale` (plugin ou composant header)     | track()     | Vitest settings |

**Observabilité serveur** (non listés ci-dessus, mais persistés dans ActionLog) :
- `type=admin_action, subtype=idle_timeouts` : staff modifie les délais idle → PHPUnit P7.1
- `type=admin_action, subtype=consent_override` : staff force consent RGPD → PHPUnit P7.1
- `type=hardware_health` : healthcheck périodique (90s) → KioskEventPhase5WhitelistTest
- `type=hardware_event` / `hardware_error` : pannes hardware auto-reportées par `kioskHardware.runSafe`

**Guards RGPD serveur** :
- `FORBIDDEN_PAYLOAD_KEYS` (email/phone/name/iban/card_number/pan/cvv/cvc/full_address/customer_*) → 422.
- `ALLOWED_ANALYTICS_EVENTS` whitelist stricte pour `type=analytics`.
- `branch_id` lu depuis `KioskMachine::user_id` serveur, jamais depuis le payload.

