<template>
    <!-- [VADV-3 heal 2026-06-10] bouton icône-seule : la tooltip est masquée en CSS
         (axe button-name CRITICAL) → aria-label exposé (miroir de SmIconDeleteComponent). -->
    <button data-drawer="#sidebar" type="button" class="db-table-action edit" :aria-label="$t('button.edit')" :title="$t('button.edit')">
        <i class="lab lab-edit-line"></i>
        <span class="db-tooltip">{{ $t('button.edit') }}</span>
    </button>
</template>

<script>
export default {
    name: "SmIconSidebarModalEditComponent",
}
</script>

<style scoped></style>