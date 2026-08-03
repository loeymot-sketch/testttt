<template>
    <div class="skeleton-grid" :aria-busy="true" role="status" :aria-label="$t('label.loading')">
        <div v-for="n in count" :key="n" class="skeleton-tile">
            <div class="skeleton-shimmer"></div>
        </div>
    </div>
</template>
<script>
export default {
    name: 'SkeletonGrid',
    props: { count: { type: Number, default: 12 } },
};
</script>
<style scoped>
/* [POS-V5] SkeletonGrid — shimmer warm aligné tokens V5 */
.skeleton-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(168px, 1fr));
    gap: var(--pos-v5-space-3);
}
.skeleton-tile {
    aspect-ratio: 4 / 3.4;
    background: var(--pos-v5-bg-subtle);
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-lg);
    overflow: hidden;
    position: relative;
    box-shadow: var(--pos-v5-shadow-sm);
}
.skeleton-shimmer {
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg,
        var(--pos-v5-bg-subtle) 0%,
        var(--pos-v5-bg-panel) 50%,
        var(--pos-v5-bg-subtle) 100%);
    background-size: 200% 100%;
    animation: pos-v5-shimmer 1.6s infinite var(--pos-v5-ease-standard);
}
@keyframes pos-v5-shimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
@media (prefers-reduced-motion: reduce) {
    .skeleton-shimmer { animation: none; }
}
</style>
