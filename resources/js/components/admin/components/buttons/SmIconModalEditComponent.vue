<template>
    <button
        type="button"
        class="db-table-action edit modal-btn"
        :aria-label="editLabel"
        :title="editLabel"
    >
        <i class="lab lab-edit-line"></i>
        <span class="db-tooltip">{{ editLabel }}</span>
    </button>
</template>

<script>
// [abuse-e2e A11Y-EDIT-01 2026-06-16] Icon-only edit button had NO accessible name —
// its only text child (.db-tooltip) is visibility:hidden so screen readers excluded it
// (WAI-ARIA). Mirror the already-fixed sibling SmIconDeleteComponent: aria-label + title +
// FR fallback. Fixes the row-edit action across every admin table in one shared component.
export default {
    name: "SmIconModalEditComponent",
    computed: {
        editLabel() {
            const translated = this.$t("button.edit");
            return translated === "button.edit" || translated === "Edit" ? "Modifier" : translated;
        }
    }
}
</script>

<style scoped>

</style>
