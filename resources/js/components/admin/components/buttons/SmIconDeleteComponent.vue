<template>
    <button
        type="button"
        class="db-table-action delete"
        :aria-label="deleteLabel"
        :title="deleteLabel"
    >
        <i class="lab lab-delete"></i>
        <span class="db-tooltip">{{ deleteLabel }}</span>
    </button>
</template>

<script>
export default {
    name: "SmIconDeleteComponent",
    computed: {
        deleteLabel() {
            const translated = this.$t("button.delete");
            return translated === "button.delete" || translated === "Delete" ? "Supprimer" : translated;
        }
    }
}
</script>

<style scoped>

</style>
