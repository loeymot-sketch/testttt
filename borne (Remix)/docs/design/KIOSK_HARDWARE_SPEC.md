# KIOSK_HARDWARE_SPEC.md

> Spécifications hardware de référence pour la borne FoodKing V1.
> **Ces valeurs sont figées** — toute itération design les prend comme vérité.
> Source : §5-A point 1 du brief d'intégration validé.

---

## 1. Écran

| Paramètre | Valeur |
|---|---|
| Résolution native | **1080 × 1920 px** (portrait) |
| Rotation OS | 90° (écran 16:9 monté vertical) |
| Diagonale | 27" |
| Ratio d'affichage | 9:16 |
| DPI effectif | ~82 DPI |
| Convention CSS | `1 dvh = 1 px` |
| Profondeur couleur | 24 bits sRGB |
| Fréquence | 60 Hz |

### Viewport CSS de référence

```css
/* Viewport kiosk portrait */
html, body {
  width: 1080px;
  height: 1920px;
  overflow: hidden;
}
```

Tout design doit être conçu **au pixel près** sur 1080×1920. Le composant `<KioskFrame>` gère le scaling pour les postes de dev en desktop.

---

## 2. Hauteur de mount physique

```
╔═══════════════════════╗  ← 145 cm sol
║                       ║
║                       ║
║   ÉCRAN 27" portrait  ║  ~60 cm de haut
║                       ║
║                       ║
╚═══════════════════════╝  ← 85 cm sol (bas d'écran)
          ║
          ║  Mount 85 cm
          ║
 ─────────╨─────────  Sol
```

- **Bas d'écran** : 85 cm du sol (norme ERP FR)
- **Haut d'écran** : 145 cm du sol
- **Hauteur utile écran** : ~60 cm

---

## 3. Zone accessibilité PMR

**Cible physique** : zone interactive entre **90 cm et 140 cm du sol** (norme accessibilité ERP + WCAG 2.2).

### Conversion en pixels (y, depuis le bas d'écran)

```
Bas d'écran       = 0 cm
90 cm sol          = 5 cm du bas d'écran   = ~160 px du bas
140 cm sol         = 55 cm du bas d'écran  = ~1760 px du bas

Donc en coordonnées CSS (y depuis le HAUT) :
y_min_pmr_px = 1920 - 1760 = 160
y_max_pmr_px = 1920 - 160  = 1760

PMR_ZONE : y ∈ [160, 1760] soit 1600 px de haut sur 1920
```

**Traduction design** :
- Zone PMR active = **bande inférieure ~92 % de l'écran**
- Mode PMR activé → **tous les éléments interactifs** doivent être dans cette bande
- Padding-top minimum en mode PMR : `160px`
- Zone haute (0 → 160 px) = header réduit, titres non-interactifs uniquement

### Constantes à exposer dans la config

```ts
// resources/js/config/kiosk.ts
export const KIOSK_HARDWARE = {
  width_px: 1080,
  height_px: 1920,
  dpi: 82,
  mount_bottom_cm: 85,
  screen_height_cm: 60,
  pmr_zone: {
    top_px: 160,
    bottom_px: 1760,
    height_px: 1600,
  },
} as const;
```

---

## 4. Périphériques

| Périphérique | Interface bridge | Commentaire |
|---|---|---|
| Imprimante ticket | `window.borne.print(buffer)` | Thermique 80 mm ESC/POS |
| Terminal paiement (TPE) | `window.borne.tpeCharge(cents, method)` | CB + TR |
| Scanner QR | `window.borne.scanQR(timeoutMs)` | Fidélité + codes promo |
| Lecteur NFC | `window.borne.readNFC(timeoutMs)` | Cartes fidélité |
| Tiroir-caisse | `window.borne.openDrawer()` | Mode staff uniquement |
| Haut-parleurs | `window.borne.play(sound_id)` + `speak(text, lang)` | Sortie audio stéréo |
| Retour haptique | `window.borne.haptic(pattern)` | Via caisson sous écran (si équipé) |
| Caméra | — | Pas utilisée en V1 |

---

## 5. Cibles de performance

| Métrique | Cible | Fallback |
|---|---|---|
| Machine de référence | Intel N5030, 4 Go RAM, Windows 10 | — |
| Framerate animations | 60 fps | 30 fps minimum |
| Time to Interactive (boot) | < 3 s | — |
| Transition d'écran | < 240 ms | mode low-perf : 0 ms |
| Taille bundle initial | < 500 Ko gzip | — |

### Détection runtime du mode low-perf

```ts
const lowPerf =
  import.meta.env.VITE_KIOSK_LOW_PERF_MODE === 'true' ||
  navigator.hardwareConcurrency < 4;
```

En mode low-perf :
- `animation-duration: 0ms` sur toutes les transitions non-essentielles
- Suppression particules idle
- Transitions étapes wizard = `opacity` uniquement (pas de slide)
- Suppression `blur`, `box-shadow` complexes, gradients animés
- Scroll snap smooth → instantané
- Images : préchargement grid (pas de lazy-load)

---

## 6. Environnement logiciel

| Paramètre | Valeur |
|---|---|
| OS | Windows 10 LTSC, mode kiosk |
| Navigateur | Chromium embedded (Electron ou WebView2) |
| User-agent | `FoodKingKiosk/1.0 Chromium/…` |
| Pavé tactile | Capacitif, multi-touch désactivé (single-tap only) |
| Clavier physique | **Aucun** — clavier virtuel custom Vue uniquement |
| TabTip système | **Désactivé** (kiosk lock) |
| Voix TTS installées | `fr-FR` (Hortense), `en-US` (Zira/David), `ar-SA` (Naayf — à provisionner) |

---

## 7. Contraintes légales référencées

| Réglementation | Impact design |
|---|---|
| **EAA** (European Accessibility Act, 28 juin 2025) | Mode PMR + audio description obligatoires |
| Loi **2005-102** (FR, 11 février 2005) | Accessibilité ERP → zone 90-140 cm |
| Arrêté **20 avril 2017** | Cibles tactiles ≥ 48 px, contraste ≥ 4.5:1 |
| **WCAG 2.2 AA** | Défaut en mode normal |
| **WCAG 2.2 AAA** | Obligatoire en mode contraste renforcé (ratio ≥ 7:1) |
| **Règlement 1169/2011** (UE) | Allergènes 14-enum obligatoire |
| **CNIL** (FR) | Analytics heatmap = opt-in explicite |

---

**Fichier figé.** Toute modification passe par un ticket de changement référencé dans `docs/design/CHANGELOG.md`.
