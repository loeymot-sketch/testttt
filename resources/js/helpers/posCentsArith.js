function assertFiniteNumber(value, label) {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    throw new TypeError(`${label} must be a finite number`);
  }
}

function assertNonNegativeIntegerCents(value, label) {
  assertFiniteNumber(value, label);

  if (!Number.isInteger(value)) {
    throw new RangeError(`${label} must be an integer number of cents`);
  }

  if (value < 0) {
    throw new RangeError(`${label} cannot be negative`);
  }
}

export function addCents(left, right) {
  assertNonNegativeIntegerCents(left, 'left');
  assertNonNegativeIntegerCents(right, 'right');

  return left + right;
}

export function subCents(left, right) {
  assertNonNegativeIntegerCents(left, 'left');
  assertNonNegativeIntegerCents(right, 'right');

  const result = left - right;

  if (result < 0) {
    throw new RangeError('result cannot be negative');
  }

  return result;
}

export function sumCents(...values) {
  return values.reduce((total, value, index) => {
    assertNonNegativeIntegerCents(value, `values[${index}]`);
    return total + value;
  }, 0);
}

export function toCents(amount) {
  assertFiniteNumber(amount, 'amount');

  if (amount < 0) {
    throw new RangeError('amount cannot be negative');
  }

  const milliUnits = Math.round(amount * 1000);

  if (milliUnits % 10 !== 0) {
    throw new RangeError('amount must have at most 2 decimal places');
  }

  return milliUnits / 10;
}

export default {
  addCents,
  subCents,
  sumCents,
  toCents,
};
