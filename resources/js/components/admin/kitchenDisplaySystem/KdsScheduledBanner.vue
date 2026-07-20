<!--
  [E6 KDS-SCHEDULED 2026-07-20] Bandeau « commandes programmées à venir ».
  Strip horizontal compact rendu UNIQUEMENT si la liste est non-vide
  (meta.scheduled_upcoming de GET admin/kds-order, committée par le store
  kitchenDisplaySystemOrder → getter scheduledUpcoming, rafraîchie par le
  poll KDS existant). V1 : affichage seul — pas de son, pas d'interaction.

  Format : ⏰ Programmées (N) : 20:30 — #1234 · 21:00 — #1240 …
  [FIX SCHEDULED-STALE P3 2026-07-20] Cible un AUTRE jour (J+1..J+7) → heure
  préfixée du jour court FR : « sam. 26/07 20:30 — #1234 » (source :
  scheduled_date Y-m-d serveur, repli défensif sur la date de scheduled_at).
  Design : aligné sur KdsStatusBanner (strip 32px, tokens info bleus
  #EFF6FF/#1E40AF/#BFDBFE, Inter pour le libellé, mono pour heures/serials).

  NOTE i18n : libellé FR en dur — resources/js/languages/*.json est HORS de
  la lane E6 (fichiers exclusifs) et la V1 est FR-locked (ADR-007). Une clé
  $t('label.kds_scheduled_upcoming') pourra remplacer ce littéral quand une
  lane i18n sera ouverte.
-->
<template>
  <div
    v-if="stripEntries.length > 0"
    class="kds-scheduled-banner"
    role="status"
    aria-live="polite"
    data-testid="kds-scheduled-banner"
  >
    <span class="kds-scheduled-banner__icon" aria-hidden="true">⏰</span>
    <span class="kds-scheduled-banner__label">Programmées ({{ stripEntries.length }})&nbsp;:</span>
    <span class="kds-scheduled-banner__list keep-latin">
      <template v-for="(entry, idx) in stripEntries" :key="entry.key">
        <span v-if="idx > 0" class="kds-scheduled-banner__sep" aria-hidden="true"> · </span>
        <span
          class="kds-scheduled-banner__entry"
          :data-testid="`kds-scheduled-entry-${idx}`"
        >{{ entry.time }} — #{{ entry.serial }}</span>
      </template>
    </span>
  </div>
</template>

<script>
/**
 * Formatte `scheduled_at` en « H:i » (heure locale d'affichage). Défensif :
 *  - "2026-07-20 20:30:00" (datetime SQL naïf) → parsé LOCAL → "20:30"
 *  - ISO 8601 (avec ou sans timezone) → converti heure locale
 *  - inparsable → repli regex sur le premier motif HH:MM, sinon "".
 * Exportée pour testabilité directe (vitest).
 */
export function formatScheduledTime(value) {
    if (value === null || value === undefined || value === '') return '';
    const str = String(value);
    const d = new Date(str.includes(' ') ? str.replace(' ', 'T') : str);
    if (!Number.isNaN(d.getTime())) {
        return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    }
    const m = str.match(/(\d{1,2}):(\d{2})/);
    return m ? `${m[1].padStart(2, '0')}:${m[2]}` : '';
}

// Jours courts FR en dur (index = Date.getDay()) — V1 FR-locked (ADR-007),
// déterministe sans dépendre de l'ICU/locale de la machine (CI incluse).
const FR_SHORT_DAYS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];

/**
 * [FIX SCHEDULED-STALE P3 2026-07-20] Préfixe jour « sam. 26/07 » quand la date
 * cible ≠ aujourd'hui — le board admet désormais les programmées J-1..J-7, le
 * bandeau peut donc porter des cibles au-delà d'aujourd'hui ; une heure nue
 * serait ambiguë. Défensif :
 *  - accepte "2026-07-26" (scheduled_date serveur) OU tout string contenant un
 *    motif YYYY-MM-DD (repli sur scheduled_at ISO, date lue TELLE QU'ÉCRITE par
 *    le serveur Paris-local — pas de conversion TZ) ;
 *  - même jour / vide / inparsable → "" (affichage historique intact).
 * `today` injectable pour testabilité (défaut : maintenant, heure locale).
 * Exportée pour testabilité directe (vitest).
 */
export function formatScheduledDayPrefix(value, today = new Date()) {
    if (value === null || value === undefined || value === '') return '';
    const m = String(value).match(/(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return '';
    const todayYmd = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    if (`${m[1]}-${m[2]}-${m[3]}` === todayYmd) return '';
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    if (Number.isNaN(d.getTime())) return '';
    return `${FR_SHORT_DAYS[d.getDay()]} ${m[3]}/${m[2]}`;
}

export default {
    name: 'KdsScheduledBanner',
    props: {
        // Entrées meta.scheduled_upcoming — [{ id, order_serial_no,
        // scheduled_at, scheduled_date?, order_type, customer_name? }] triées
        // asc côté backend (max 20). Toute forme inattendue est neutralisée ici.
        entries: { type: Array, default: () => [] },
    },
    computed: {
        stripEntries() {
            const raw = Array.isArray(this.entries) ? this.entries : [];
            return raw
                .filter((e) => e && typeof e === 'object')
                .map((e, idx) => {
                    const hasId = e.id !== null && e.id !== undefined;
                    const serial = (e.order_serial_no !== null && e.order_serial_no !== undefined && e.order_serial_no !== '')
                        ? e.order_serial_no
                        : (hasId ? e.id : '?');
                    const time = formatScheduledTime(e.scheduled_at) || '--:--';
                    // [FIX SCHEDULED-STALE P3] scheduled_date (serveur) prioritaire,
                    // repli sur scheduled_at si le backend ne l'expose pas encore.
                    const dayPrefix = formatScheduledDayPrefix(
                        (e.scheduled_date !== null && e.scheduled_date !== undefined && e.scheduled_date !== '')
                            ? e.scheduled_date
                            : e.scheduled_at
                    );
                    return {
                        key: hasId ? `sched-${e.id}` : `sched-idx-${idx}`,
                        time: dayPrefix ? `${dayPrefix} ${time}` : time,
                        serial,
                    };
                });
        },
    },
};
</script>

<style scoped>
.kds-scheduled-banner {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 32px;
    padding: 4px 24px;
    background: #EFF6FF;
    color: #1E40AF;
    border-bottom: 1px solid #BFDBFE;
    font-family: 'Inter', system-ui, sans-serif;
    overflow: hidden;
}
.kds-scheduled-banner__icon {
    flex: none;
    font-size: 14px;
    line-height: 1;
}
.kds-scheduled-banner__label {
    flex: none;
    font-size: 13px;
    font-weight: 600;
}
/* Une seule ligne compacte : les entrées excédentaires sont élidées (…) —
   le compteur (N) du libellé porte le total exact. */
.kds-scheduled-banner__list {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.kds-scheduled-banner__sep {
    opacity: 0.5;
}
</style>
