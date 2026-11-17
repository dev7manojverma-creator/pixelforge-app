// Money arrives from the server as whole nanodollars (1 USD = 1,000,000,000).
// Everything here is integer or string work: no fractional floats.

const NANO = 1_000_000_000;

/**
 * Exact amount, e.g. 381887500 → "$0.3818875". Trailing zeros are trimmed
 * down to cents, so nothing the user is charged is ever hidden by rounding.
 */
export function formatUsd(nano) {
    const digits = String(Math.abs(nano)).padStart(10, '0');
    const dollars = Number(digits.slice(0, -9)).toLocaleString('en-US');
    const fraction = digits.slice(-9).replace(/0+$/, '').padEnd(2, '0');

    return `${nano < 0 ? '-' : ''}$${dollars}.${fraction}`;
}

/**
 * Rounded to four decimals for estimates, where exactness would be false
 * precision. Rounds up, so an estimate never understates.
 */
export function formatUsdApprox(nano) {
    const step = NANO / 10_000;
    const rounded = nano + ((step - (nano % step)) % step);

    return formatUsd(rounded);
}

/** Rounded down to four decimals, for compact places like the header balance (never overstates what's available). */
export function formatUsdShort(nano) {
    const step = NANO / 10_000;

    return formatUsd(nano - (((nano % step) + step) % step));
}
