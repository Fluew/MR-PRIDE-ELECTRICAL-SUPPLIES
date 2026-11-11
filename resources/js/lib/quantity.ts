/**
 * Quantities typed by the client on the quotation request form. The server applies the same
 * rules (PublicQuotationController::typedQuantity).
 */

export const MAX_QUANTITY = 1_000_000;
export const MAX_DECIMALS = 3;

/** "20", "1,000", "2.5" → number; anything else is an error message. */
export function parseQuantity(text: string): {
    value: number | null;
    error: string | null;
} {
    const v = text.trim();
    if (v === '') {
        return { value: null, error: 'Quantity is missing.' };
    }
    if (/^\d+,\d{1,2}$/.test(v)) {
        return {
            value: null,
            error: `"${v}" could mean ${v.replace(',', '.')} or ${v.replace(',', '')}. Please enter the quantity.`,
        };
    }
    const plain = /^\d{1,3}(,\d{3})+(\.\d+)?$/.test(v)
        ? v.replace(/,/g, '')
        : v;
    const match = /^\d+(?:\.(\d+))?$/.exec(plain);
    if (!match) {
        return {
            value: null,
            error: /^-\s*\d/.test(v)
                ? 'Quantity must be greater than zero.'
                : 'Quantity must be a number.',
        };
    }
    if ((match[1] ?? '').length > MAX_DECIMALS) {
        return {
            value: null,
            error: `Use at most ${MAX_DECIMALS} decimal places.`,
        };
    }
    const value = Number(plain);
    if (value <= 0) {
        return { value: null, error: 'Quantity must be greater than zero.' };
    }
    if (value > MAX_QUANTITY) {
        return {
            value: null,
            error: 'Quantity is too large (more than 1,000,000).',
        };
    }

    return { value, error: null };
}
