const paymentTypeEnum = Object.freeze({
    CASH_ON_DELIVERY: 1,
    E_WALLET        : 2,
    PAYPAL          : 3,
    // [REP-SALES-PAYTYPE-02 FIX 2026-06-07] Mirror backend App\Enums\PaymentGateway:
    // CARD=4 + TICKET_RESTAURANT=5 existed server-side (kiosk TPE / titre-restaurant)
    // but were absent here → kiosk card/TR orders rendered a BLANK payment-type cell.
    CARD            : 4,
    TICKET_RESTAURANT: 5,
});
export default paymentTypeEnum;
