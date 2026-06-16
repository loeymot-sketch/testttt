<template>
    <button
        type="button"
        data-drawer="#sidebar"
        class="db-table-action edit"
        :aria-label="editLabel"
        :title="editLabel"
    >
        <i class="lab lab-edit-line"></i>
        <span class="db-tooltip">{{ editLabel }}</span>
    </button>
</template>

<script>
// [abuse-e2e A11Y-EDIT-01 2026-06-16] Same fix as SmIconModalEditComponent for the
// sidebar-drawer edit variant: give the icon-only button an accessible name (the
// .db-tooltip span is visibility:hidden so it does not provide one).
export default {
    name: "SmIconSidebarModalEditComponent",
    computed: {
        editLabel() {
            const translated = this.$t("button.edit");
            return translated === "button.edit" || translated === "Edit" ? "Modifier" : translated;
        }
    }
}
</script>

<style scoped></style>