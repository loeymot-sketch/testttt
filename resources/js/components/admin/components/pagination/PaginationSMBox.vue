<template>
    <div class="flex flex-1 justify-between sm:hidden">
        <!-- [DASH-UIUX-2026-06-08 P2] FR labels for the mobile prev/next nav (was English). -->
        <TailwindPagination :data="pagination" @pagination-change-page="page" :active-classes="activeClass"
            :limit="-1">
            <template #prev-nav>{{ $t('label.previous') }}</template>
            <template #next-nav>{{ $t('label.next') }}</template>
        </TailwindPagination>
    </div>
</template>
<script>

import { TailwindPagination } from 'laravel-vue-pagination';

export default {
    name: "PaginationSMBox",
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