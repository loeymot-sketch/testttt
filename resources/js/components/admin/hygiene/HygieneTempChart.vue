<template>
    <figure class="tch" data-testid="tmp-chart">
        <p v-if="model.empty" class="tch-empty" data-testid="tmp-chart-empty">Aucun relevé sur les {{ days }} derniers jours.</p>
        <template v-else>
            <svg :viewBox="`0 0 ${model.w} ${model.h}`" class="tch-svg" role="img" :aria-label="summary" preserveAspectRatio="xMidYMid meet">
                <g class="tch-ticks">
                    <template v-for="t in model.ticks" :key="t.y">
                        <line :x1="40" :x2="model.w - 10" :y1="t.y" :y2="t.y" class="tch-grid" />
                        <text :x="36" :y="t.y + 4" text-anchor="end" class="tch-tick">{{ t.label }}</text>
                    </template>
                </g>
                <g v-for="l in model.lines" :key="l.kind">
                    <line :x1="40" :x2="model.w - 10" :y1="l.y" :y2="l.y" class="tch-limit" />
                    <text :x="model.w - 12" :y="l.kind === 'max' ? l.y - 4 : l.y + 12" text-anchor="end" class="tch-limit-label">{{ l.label }}</text>
                </g>
                <path :d="model.line" class="tch-line" fill="none" />
                <circle v-for="(d, i) in model.dots" :key="i" :cx="d.x" :cy="d.y" :r="d.alert ? 5 : 3.5" :class="d.alert ? 'tch-dot is-alert' : 'tch-dot'" />
            </svg>
            <figcaption class="tch-caption" data-testid="tmp-chart-caption">{{ summary }}</figcaption>
        </template>
    </figure>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-17 · méga-fichier P1.1] Courbe de température d'un équipement : les relevés des derniers jours, les seuils
 * en pointillés, les écarts en rouge. Lecture seule ; le calcul est dans hygieneTemperature.js (chartModel).
 */
import { chartModel, formatTemperature } from "../../../helpers/hygieneTemperature";

export default {
    name: "HygieneTempChart",
    props: {
        readings: { type: Array, default: () => [] },
        min: { type: [Number, String], default: null },
        max: { type: [Number, String], default: null },
        days: { type: Number, default: 14 },
    },
    computed: {
        // 360 × 200 : la largeur d'une carte — le texte reste lisible (pas de réduction de moitié sur tablette)
        model() { return chartModel(this.readings, this.min, this.max, 360, 200); },
        summary() {
            const m = this.model;
            if (m.empty) return "";
            const w = (n) => `${n} relevé${n > 1 ? "s" : ""}`;
            const range = m.minV === m.maxV ? `${formatTemperature(m.minV)} °C` : `de ${formatTemperature(m.minV)} à ${formatTemperature(m.maxV)} °C`;
            return `${w(m.count)} sur ${this.days} jours, ${range}${m.alerts ? `, dont ${m.alerts} écart${m.alerts > 1 ? "s" : ""}` : ", aucun écart"}`;
        },
    },
};
</script>

<style scoped>
.tch { margin: 8px 0 0; }
.tch-svg { width: 100%; height: auto; max-height: 260px; display: block; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
.tch-grid { stroke: #e2e8f0; stroke-width: 1; }
.tch-tick { font-size: 13px; fill: #475569; }
.tch-limit { stroke: #b45309; stroke-width: 1.5; stroke-dasharray: 6 4; }
.tch-limit-label { font-size: 13px; font-weight: 800; fill: #92400e; }
.tch-line { stroke: #1e40af; stroke-width: 2; }
.tch-dot { fill: #1e40af; stroke: #fff; stroke-width: 1.5; }
.tch-dot.is-alert { fill: #dc2626; }
.tch-caption { margin: 6px 2px 0; font-size: 14px; color: #334155; }
.tch-empty { margin: 4px 0; font-size: 14px; color: #475569; }
</style>
