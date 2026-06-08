<template>
    <div dir="ltr">
        <!-- [DASH-UIUX-2026-06-08 P2] FR labels — the library's default prev/next nav renders
             English "Previous"/"Next" on every admin list page. -->
        <TailwindPagination :data="pagination" @pagination-change-page="page" :active-classes="activeClass" :limit="1">
            <template #prev-nav>{{ $t('label.previous') }}</template>
            <template #next-nav>{{ $t('label.next') }}</template>
        </TailwindPagination>
    </div>
</template>

<script>

import { TailwindPagination } from 'laravel-vue-pagination';

export default {
    name: "PaginationBox",
    components: { TailwindPagination },
    props: {
        pagination: { type: Object },
        method: { type: Function }
    },
    data() {
        return {
            activeClass: [
                "bg-blue-50",
                "border-blue-500",
                "text-primary"
            ]
        }
    },
    methods: {
        page: function (page = 1) {
            this.method(page);
        }
    }
}
</script>
