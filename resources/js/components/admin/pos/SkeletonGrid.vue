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
.skeleton-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 0.5rem; }
.skeleton-tile { aspect-ratio: 1; background: #f3f4f6; border-radius: 0.5rem; overflow: hidden; position: relative; }
.skeleton-shimmer { position: absolute; inset: 0; background: linear-gradient(90deg, #f3f4f6 0%, #e5e7eb 50%, #f3f4f6 100%); background-size: 200% 100%; animation: shimmer 1.4s infinite; }
@keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
</style>
