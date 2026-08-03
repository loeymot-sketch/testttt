<template>
    <div
        v-if="isRefund"
        class="receipt-remboursement-marker"
        role="status"
        aria-live="polite"
        data-testid="receipt-remboursement-marker"
    >
        <p class="receipt-remboursement-text">{{ remboursementLabel }}</p>
        <p v-if="parentSerial" class="receipt-remboursement-parent">
            #{{ parentSerial }}
        </p>
    </div>
</template>

<script>
/**
 * ReceiptRemboursementMarker — Phase F.10 finding F10-OG-1 (P2 → heal F2-HEAL-03).
 *
 * NF525 visual distinction requirement: a refund counter-entry order
 * (RefundWithCounterEntryService → status=22 / payment_status=20 /
 * order_serial_no="RTN-…" / parent_order_id=parent.id) must be
 * VISUALLY identifiable on the printed/displayed receipt.
 *
 * Mirrors the DUPLICATA marker pattern (ReceiptDuplicataMarker.vue):
 *   - same a11y wrapper (role=status, aria-live=polite)
 *   - same red dashed border visual treatment
 *   - same print-color-adjust to survive thermal printing
 *   - same i18n fallback path (label.remboursement → "REMBOURSEMENT")
 *
 * Trigger: order.parent_order_id is set (truthy positive int FK).
 * Co-exists with DUPLICATA marker (a refund original can be reprinted —
 * both badges may appear together on the same ticket).
 */
export default {
    name: "ReceiptRemboursementMarker",
    props: {
        order: {
            type: Object,
            required: true,
        },
    },
    computed: {
        parentId() {
            return Number(this.order?.parent_order_id ?? 0);
        },
        isRefund() {
            return this.parentId > 0;
        },
        parentSerial() {
            // Optional: if backend exposes parent serial, show it for traceability.
            // Falls back to the bare parent_order_id if no serial is attached.
            return this.order?.parent_order_serial_no
                ?? this.order?.parent?.order_serial_no
                ?? null;
        },
        remboursementLabel() {
            const fallback = 'REMBOURSEMENT';

            if (typeof this.$t !== 'function') {
                return fallback;
            }

            const translated = this.$t('label.remboursement');

            return translated === 'label.remboursement' ? fallback : translated;
        },
    },
};
</script>

<style scoped>
.receipt-remboursement-marker {
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

.receipt-remboursement-text {
    margin: 0;
    font-size: 12px;
    line-height: 1.2;
}

.receipt-remboursement-parent {
    margin: 2px 0 0;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: none;
}

@media print {
    .receipt-remboursement-marker {
        page-break-inside: avoid;
    }
}
</style>
