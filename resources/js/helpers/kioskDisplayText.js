/**
 * Libellés affichés client (borne) : masque les termes techniques type "add-on", "addon".
 * Les noms de produits réels (base de données) ne sont pas traduits ici.
 */
export function sanitizeKioskCustomerFacingText(input) {
  if (input == null) return '';
  let s = String(input).trim();
  if (!s) return '';

  const pairs = [
    [/\badd-?ons?\b/gi, 'options'],
    [/\badd ons?\b/gi, 'options'],
    [/\badd-on\b/gi, 'option'],
    [/\baddon\b/gi, 'option'],
    [/\bextras?\b/gi, 'suppléments'],
    [/\bupgrade\b/gi, 'option'],
    [/\bupsell\b/gi, 'suggestion'],
    [/\bitem variation\b/gi, 'personnalisation'],
    [/\bvariation id\b/gi, ''],
  ];

  for (const [re, rep] of pairs) {
    s = s.replace(re, rep);
  }

  return s.replace(/\s{2,}/g, ' ').replace(/\s+,/g, ',').trim();
}
