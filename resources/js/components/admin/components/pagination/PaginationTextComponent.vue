<template>
    <div>
        <p class="text-sm text-gray-700">
            {{
                $t("message.pagination_label", {
                    from: props.page.from, to: props.page.to,
                    total: props.page.total
                }, Number(props.page.total) || 0)
            }}
        </p>
    </div>
</template>

<script>
// [iter15-mega-fix D-004 2026-05-10] FR translation key `message.pagination_label`
// was previously a broken EN/FR mix in resources/js/languages/fr.json.
// Now reads: "Affichage de {from} a {to} sur {total} entrees" (proper French).
export default {
    name: "PaginationTextComponent",
    props: ['props'],
}
</script>
