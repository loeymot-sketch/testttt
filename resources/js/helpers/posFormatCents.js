const NBSP = '\u00a0';

function groupThousandsNbsp(intStr) {
  return intStr.replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
}

function resolveSymbol(currency, symbol) {
  if (symbol !== undefined) {
    return symbol;
  }

  if (currency === 'EUR') {
    return '€';
  }

  if (currency === 'USD') {
    return '$';
  }

  if (currency === 'GBP') {
    return '£';
  }

  if (currency === 'CHF') {
    return 'CHF';
  }

  return currency;
}

export function formatCents(cents, options = {}) {
  if (typeof cents !== 'number' || !Number.isFinite(cents)) {
    throw new TypeError('cents must be a finite number');
  }

  if (!Number.isInteger(cents)) {
    throw new RangeError('cents must be an integer');
  }

  if (cents < 0) {
    throw new RangeError('cents cannot be negative');
  }

  const { currency = 'EUR', symbol, variant = 'display' } = options;
  const whole = String(Math.floor(cents / 100));
  const fraction = String(cents % 100).padStart(2, '0');

  if (variant === 'plain') {
    return `${whole}.${fraction}`;
  }

  const groupedWhole = groupThousandsNbsp(whole);
  const amount = `${groupedWhole},${fraction}`;

  if (currency === '') {
    return amount;
  }

  return `${amount} ${resolveSymbol(currency, symbol)}`;
}

export default { formatCents };
