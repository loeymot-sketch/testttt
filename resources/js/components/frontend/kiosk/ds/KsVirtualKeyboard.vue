<template>
  <div
    v-if="visible"
    class="ks-vkeyb"
    :class="[
      'ks-vkeyb--' + layout,
      shift ? 'ks-vkeyb--shift' : '',
      isRtl ? 'ks-vkeyb--rtl' : '',
    ]"
    role="group"
    :aria-label="$t('kiosk.a11y.virtual_keyboard_label')"
    data-testid="kiosk-vkeyb"
  >
    <!-- Preview de la valeur (affiché pour feedback visuel, masqué SR) -->
    <div v-if="showPreview" class="ks-vkeyb__preview" aria-hidden="true" data-testid="kiosk-vkeyb-preview">
      <span class="ks-vkeyb__preview-value">{{ displayValue }}</span>
    </div>

    <!-- Rangées de touches -->
    <div
      v-for="(row, rIdx) in rows"
      :key="'row-' + rIdx"
      class="ks-vkeyb__row"
    >
      <button type="button"
        v-for="(key, kIdx) in row"
        :key="'k-' + rIdx + '-' + kIdx"
        class="ks-vkeyb__key"
        :class="[
          key.wide ? 'ks-vkeyb__key--wide' : '',
          key.action ? 'ks-vkeyb__key--action' : '',
          key.toggle && key.toggle === 'shift' && shift ? 'ks-vkeyb__key--active' : '',
        ]"
        :aria-label="key.ariaLabel || key.label"
        :data-testid="'kiosk-vkeyb-key-' + (key.testid || key.label.toLowerCase())"
        @click="pressKey(key)">{{ displayLabel(key) }}</button>
    </div>

    <!-- Actions globales -->
    <div class="ks-vkeyb__row ks-vkeyb__row--actions">
      <button type="button"
        class="ks-vkeyb__key ks-vkeyb__key--action"
        :aria-label="$t('kiosk.a11y.vkeyb_clear')"
        data-testid="kiosk-vkeyb-clear"
        @click="clearAll">{{ $t('kiosk.a11y.vkeyb_clear_short') }}</button>
      <button type="button"
        class="ks-vkeyb__key ks-vkeyb__key--action ks-vkeyb__key--wide"
        :aria-label="$t('kiosk.a11y.vkeyb_space')"
        data-testid="kiosk-vkeyb-space"
        @click="pressChar(' ')">{{ $t('kiosk.a11y.vkeyb_space_short') }}</button>
      <button type="button"
        class="ks-vkeyb__key ks-vkeyb__key--action"
        :aria-label="$t('kiosk.a11y.vkeyb_backspace')"
        data-testid="kiosk-vkeyb-backspace"
        @click="backspace">⌫</button>
      <button type="button"
        class="ks-vkeyb__key ks-vkeyb__key--action ks-vkeyb__key--submit"
        :aria-label="$t('kiosk.a11y.vkeyb_submit')"
        data-testid="kiosk-vkeyb-submit"
        @click="submit">{{ $t('kiosk.a11y.vkeyb_submit_short') }}</button>
    </div>
  </div>
</template>

<script>
/**
 * KsVirtualKeyboard — clavier virtuel maison (pas de dépendance externe).
 * -----------------------------------------------------------------------------
 * FoodKing Kiosk — Phase 4.6.
 *
 * Pourquoi maison :
 *  - Windows kiosk mode désactive TabTip (clavier tactile natif) → besoin d'un
 *    fallback in-app.
 *  - Aucune lib UI lourde autorisée (DS maison uniquement — cf. brief).
 *
 * Props :
 *  - modelValue     : v-model de la valeur saisie.
 *  - layout         : 'fr' | 'en' | 'ar' (défaut = useKioskSettings.locale).
 *  - visible        : boolean contrôlé par le parent (show/hide).
 *  - maxLength      : contraintes de saisie (défaut 200).
 *  - allowSpace     : bool (défaut true) — désactive espaces sur champs emails.
 *  - showPreview    : bool (défaut true) — affiche la valeur en haut.
 *
 * Émissions :
 *  - update:modelValue (chaque keystroke)
 *  - submit   (touche ✓ ou Enter)
 *  - close    (touche Esc virtuelle — non implémentée ici, géré par parent)
 *
 * A11y :
 *  - role="group" + aria-label
 *  - Chaque touche : élément HTML `button` natif — focus + Enter/Space OK d'office.
 *  - aria-label custom pour ⌫ / ✓ / espace (lecteurs d'écran).
 *
 * Invariants :
 *  - N'envoie RIEN au backend — c'est une pure UI primitive.
 *  - Ne modifie pas l'a11y globale (ni lang ni dir) — elle les consomme.
 *  - Aucun state interne à part `shift` (toggle maj). La valeur est
 *    contrôlée par le parent via v-model pour éviter les divergences.
 */

// Kiosk Phase 9.1.7 — rangée numérique (0-9) partagée par tous les layouts.
// Les champs loyalty (phone + email) ont besoin de chiffres ; sans cette
// rangée, le KsVirtualKeyboard n'était utilisable que pour des saisies
// alphabétiques strictes et les bornes Windows sans TabTip n'offraient
// aucun moyen de saisir un numéro de téléphone.
const DIGITS_ROW = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];

const ROW_DEFS = {
    // Layout AZERTY simplifié (FR borne)
    fr: {
        normal: [
            DIGITS_ROW,
            ['a', 'z', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'],
            ['q', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l', 'm'],
            ['w', 'x', 'c', 'v', 'b', 'n', '-', '_', '.', '@'],
        ],
        shift: [
            DIGITS_ROW,
            ['A', 'Z', 'E', 'R', 'T', 'Y', 'U', 'I', 'O', 'P'],
            ['Q', 'S', 'D', 'F', 'G', 'H', 'J', 'K', 'L', 'M'],
            ['W', 'X', 'C', 'V', 'B', 'N', '-', '_', '.', '@'],
        ],
    },
    // QWERTY (EN)
    en: {
        normal: [
            DIGITS_ROW,
            ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'],
            ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l', 'm'],
            ['z', 'x', 'c', 'v', 'b', 'n', '-', '_', '.', '@'],
        ],
        shift: [
            DIGITS_ROW,
            ['Q', 'W', 'E', 'R', 'T', 'Y', 'U', 'I', 'O', 'P'],
            ['A', 'S', 'D', 'F', 'G', 'H', 'J', 'K', 'L', 'M'],
            ['Z', 'X', 'C', 'V', 'B', 'N', '-', '_', '.', '@'],
        ],
    },
    // Layout arabe simplifié (sous-ensemble des lettres les plus communes).
    // Les lettres n'ont pas de casse — "shift" affiche harakat (tashkeel).
    ar: {
        normal: [
            DIGITS_ROW,
            ['ض', 'ص', 'ث', 'ق', 'ف', 'غ', 'ع', 'ه', 'خ', 'ح'],
            ['ش', 'س', 'ي', 'ب', 'ل', 'ا', 'ت', 'ن', 'م', 'ك'],
            ['ئ', 'ء', 'ؤ', 'ر', 'لا', 'ى', 'ة', 'و', 'ز', 'ظ'],
        ],
        shift: [
            DIGITS_ROW,
            ['ّ', 'َ', 'ً', 'ُ', 'ٌ', 'ِ', 'ٍ', 'ْ', 'ٰ', 'ٓ'],
            ['ش', 'س', 'ي', 'ب', 'ل', 'أ', 'ت', 'ن', 'م', 'ك'],
            ['ئ', 'ء', 'ؤ', 'ر', 'لا', 'آ', 'إ', 'و', 'ز', 'ظ'],
        ],
    },
};

export default {
    name: 'KsVirtualKeyboard',
    props: {
        modelValue: { type: String, default: '' },
        layout: { type: String, default: 'fr', validator: (v) => ['fr', 'en', 'ar'].includes(v) },
        visible: { type: Boolean, default: true },
        maxLength: { type: Number, default: 200 },
        allowSpace: { type: Boolean, default: true },
        showPreview: { type: Boolean, default: true },
    },
    emits: ['update:modelValue', 'submit', 'close'],
    data() {
        return {
            shift: false,
        };
    },
    computed: {
        isRtl() {
            return this.layout === 'ar';
        },
        rows() {
            const map = ROW_DEFS[this.layout] || ROW_DEFS.fr;
            const src = this.shift && map.shift ? map.shift : map.normal;
            // Ajoute la row shift-toggle en dessous des 3 rangées principales,
            // représentée comme une rangée séparée (la rangée shift-toggle est
            // ajoutée dans le template via .ks-vkeyb__row--actions).
            return src.map((row) =>
                row.map((char) => ({
                    label: char,
                    char,
                    testid: this.safeTestid(char),
                }))
            );
        },
        displayValue() {
            return this.modelValue || '';
        },
    },
    methods: {
        safeTestid(s) {
            // Les caractères arabes / spéciaux ne conviennent pas en testid — on
            // fallback sur le code UTF.
            if (/^[a-zA-Z0-9@._-]$/.test(s)) return s;
            return 'u' + s.codePointAt(0).toString(16);
        },
        displayLabel(key) {
            return key.label;
        },
        pressKey(key) {
            if (key.action) {
                if (key.action === 'backspace') return this.backspace();
                if (key.action === 'submit') return this.submit();
                if (key.action === 'shift') return (this.shift = !this.shift);
                return;
            }
            if (key.char) this.pressChar(key.char);
        },
        pressChar(ch) {
            if (!ch) return;
            if (!this.allowSpace && ch === ' ') return;
            const next = (this.modelValue || '') + ch;
            if (next.length > this.maxLength) return;
            this.$emit('update:modelValue', next);
            // Auto-désactiver shift après une frappe majuscule unique.
            if (this.shift && this.layout !== 'ar') this.shift = false;
        },
        backspace() {
            const cur = this.modelValue || '';
            if (!cur) return;
            // Supporte la suppression d'un point de code Unicode complet
            // (important en AR où une lettre peut être composée).
            const arr = Array.from(cur);
            arr.pop();
            this.$emit('update:modelValue', arr.join(''));
        },
        clearAll() {
            if (!this.modelValue) return;
            this.$emit('update:modelValue', '');
        },
        submit() {
            this.$emit('submit', this.modelValue || '');
        },
        toggleShift() {
            this.shift = !this.shift;
        },
    },
};
</script>

<style scoped>
.ks-vkeyb {
  position: fixed;
  inset-inline: 0;
  bottom: 0;
  z-index: 150;
  background: var(--kiosk-surface);
  border-top: 1.5px solid var(--kiosk-border);
  box-shadow: var(--kiosk-shadow-modal);
  padding: 16px 20px 22px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  /* Les chiffres 1080x1920 : le clavier occupe ~30% de la hauteur borne. */
  max-height: 36vh;
  font-family: var(--kiosk-font-sans, system-ui);
  color: var(--kiosk-text);
}

.ks-vkeyb--rtl {
  direction: rtl;
}

.ks-vkeyb__preview {
  min-height: 44px;
  padding: 10px 16px;
  background: var(--kiosk-surface-alt);
  border: 1.5px solid var(--kiosk-border);
  border-radius: 12px;
  font-size: 20px;
  font-weight: 700;
  color: var(--kiosk-text);
  display: flex;
  align-items: center;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.ks-vkeyb__preview-value { flex: 1; }

.ks-vkeyb__row {
  display: grid;
  grid-template-columns: repeat(10, 1fr);
  gap: 8px;
}

.ks-vkeyb__row--actions {
  grid-template-columns: 1fr 4fr 1fr 2fr;
}

.ks-vkeyb__key {
  height: 62px;
  min-width: 0;
  min-height: var(--kiosk-tap-min, 56px);
  border: 1.5px solid var(--kiosk-border);
  border-radius: 12px;
  background: var(--kiosk-surface);
  color: var(--kiosk-text);
  font-size: 22px;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.12s ease, transform 0.08s ease, border-color 0.12s ease;
  touch-action: manipulation;
  user-select: none;
}

.ks-vkeyb__key:hover { background: var(--kiosk-surface-alt); }
.ks-vkeyb__key:active {
  transform: scale(0.96);
  background: var(--kiosk-primary-soft);
  border-color: var(--kiosk-primary);
}

.ks-vkeyb__key:focus-visible {
  outline: var(--kiosk-focus-width, 3px) solid var(--kiosk-focus-ring, var(--kiosk-primary));
  outline-offset: 2px;
}

.ks-vkeyb__key--wide { font-size: 16px; }

.ks-vkeyb__key--action {
  background: var(--kiosk-surface-alt);
  color: var(--kiosk-text-muted);
  font-size: 16px;
  font-weight: 800;
  letter-spacing: 0.02em;
}

.ks-vkeyb__key--active {
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
  border-color: var(--kiosk-primary);
}

.ks-vkeyb__key--submit {
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
  border-color: var(--kiosk-primary);
}

.ks-vkeyb__key--submit:hover { background: var(--kiosk-primary-dark); }
.ks-vkeyb__key--submit:active { background: var(--kiosk-primary-dark); }
</style>
