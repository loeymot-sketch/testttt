/**
 * [ONB-10 2026-08-27] Le libellé d'une taxe doit porter son TAUX.
 *
 * Mesure sur la base de travail, sur les six taxes ACTIVES — celles réellement
 * proposées au commerçant :
 *
 *   nom='No-VAT'   code='VAT-0'      taux= 0,00 %
 *   nom='VAT'      code='VAT-5%'     taux= 5,00 %
 *   nom='VAT'      code='VAT-10%'    taux=10,00 %   ← 81 articles
 *   nom='GST'      code='GST-5%'     taux= 5,00 %
 *   nom='GST'      code='GST-10%'    taux=10,00 %
 *   nom='VAT 5.5'  code='VAT-5.5%'   taux= 5,50 %
 *
 * DEUX options affichaient exactement « VAT », pour 5 % et 10 %. Sur le champ le
 * plus lourd de conséquence du formulaire produit, le commerçant jouait sa TVA à
 * pile ou face — et rien à l'écran ne lui permettait de savoir laquelle il venait de
 * choisir. Même chose pour les deux « GST ».
 *
 * Correction de ma propre correction du même jour : j'étais passé de
 * `label-by="code"` (« VAT-10% ») à `label-by="name"` (« VAT ») pour rendre les
 * libellés lisibles. Le code était laid, mais il portait le taux et levait
 * l'ambiguïté ; en le retirant j'ai rendu l'écran plus joli et moins sûr.
 *
 * Le libellé est désormais construit à partir de `tax_rate` — la SEULE valeur que
 * `PricingService::calculateOrder` facture réellement. Un libellé dérivé du taux ne
 * peut pas contredire ce qui sera facturé. La base contient d'ailleurs des noms qui
 * mentent (« TVA 97% » au taux réel de 20 %, « TVA 67% » au taux réel de 0 %) : si
 * l'une d'elles était réactivée, elle s'afficherait ici avec son vrai taux.
 *
 * @param {{name?: string, tax_rate?: number|string}} taxe
 * @returns {string}
 */
export function libelleTaxe(taxe) {
    const nom = String(taxe?.name ?? '').trim();
    const brut = taxe?.tax_rate;

    // `Number(null)` et `Number('')` valent 0, tous deux finis : sans ce test
    // explicite, un taux ABSENT s'afficherait « 0 % » — exactement l'affirmation
    // qu'il ne faut pas faire sur un champ fiscal.
    const absent = brut === null || brut === undefined || String(brut).trim() === '';
    const taux = absent ? NaN : Number(brut);

    if (!Number.isFinite(taux)) {
        // Taux illisible : on n'invente pas un pourcentage. Mieux vaut un nom seul
        // qu'un « 0 % » affirmé à tort sur un champ fiscal.
        return nom || '—';
    }

    // Virgule décimale française, et pas de « ,00 » inutile : « 10 % », « 5,5 % ».
    const rendu = (Math.round(taux * 100) / 100)
        .toString()
        .replace('.', ',');

    return nom ? `${nom} — ${rendu} %` : `${rendu} %`;
}

export default libelleTaxe;
