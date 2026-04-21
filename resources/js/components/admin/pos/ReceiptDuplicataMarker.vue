<template>
    <div
        v-if="isDuplicata"
        class="receipt-duplicata-marker"
        role="status"
        aria-live="polite"
    >
        <p class="receipt-duplicata-text">{{ duplicataLabel }}</p>
    </div>
</template>

<script>
export default {
    name: "ReceiptDuplicataMarker",
    props: {
        order: {
            type: Object,
            required: true,
        },
    },
    computed: {
        printCount() {
            return Number(this.order?.receipt_print_count ?? 0);
        },
        isDuplicata() {
            return this.printCount >= 2;
        },
        duplicataLabel() {
            const count = this.printCount - 1;
            const fallback = `DUPLICATA #${count}`;

            if (typeof this.$t !== 'function') {
                return fallback;
            }

            const translated = this.$t('label.duplicata', { n: count });

            return translated === 'label.duplicata' ? fallback : translated;
        },
    },
};
</script>

<style scoped>
.receipt-duplicata-marker {
    border: 2px dashed #d92626;
    color: #d92626;
    padding: 6px 10px;
    margin: 8px 0;
    text-align: center;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    -webkit-print-color-adjust: exact;
    color-adjust: exact;
    print-color-adjust: exact;
}

.receipt-duplicata-text {
    margin: 0;
    font-size: 11px;
    line-height: 1.2;
}

@media print {
    .receipt-duplicata-marker {
        page-break-inside: avoid;
    }
}
</style>
