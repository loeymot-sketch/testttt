<template>
    <router-link class="db-table-action view" :to="{ name: this.$props.link, params: { id: this.$props.id } }"
        :aria-label="$t('button.view')">
        <i class="lab lab-view" aria-hidden="true"></i>
        <span class="db-tooltip">{{ $t('button.view') }}</span>
    </router-link>
</template>

<script>
export default {
    name: "SmIconViewComponent",
    props: {
        link: String,
        id: Number
    }
}
</script>
