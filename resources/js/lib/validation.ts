/**
 * Field checks for the public quotation request form. They mirror the server's rules
 * (App\Support\EmailAddress, App\Support\ContactPhone, PublicQuotationController): the server
 * repeats every one of them, so these only give the client an answer while typing.
 */

// ------------------------------------------------------------------ plain required text

/** Company and contact names: not empty, not only spaces, and with at least one letter or digit. */
export function requiredTextError(value: string, message: string): string | null {
    return /[\p{L}\p{N}]/u.test(value.trim()) ? null : message;
}

// ------------------------------------------------------------------ email

const EMAIL_MALFORMED = 'Enter a valid email address, for example name@company.com.';

/**
 * Syntax and domain structure only (the server also checks that the domain can receive mail).
 * Rejects a missing name or domain, a domain without a dot, a top-level domain that is not letters,
 * and a one-letter domain name such as g.com.
 */
export function emailError(value: string): string | null {
    const email = value.trim();
    if (email === '') {
        return 'Enter your email address.';
    }
    if (email.length > 254 || (email.match(/@/g) ?? []).length !== 1 || /\s/.test(email)) {
        return EMAIL_MALFORMED;
    }
    const [local, domainRaw] = email.split('@');
    if (!local || !domainRaw || local.length > 64) {
        return EMAIL_MALFORMED;
    }
    // Characters allowed in the part before the @ (RFC 5322 "atext" plus dots between them).
    if (!/^[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+(\.[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+)*$/.test(local)) {
        return EMAIL_MALFORMED;
    }
    const domain = domainRaw.toLowerCase();
    const labels = domain.split('.');
    if (domain.length > 253 || labels.length < 2) {
        return EMAIL_MALFORMED;
    }
    if (!labels.every((l) => /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/.test(l))) {
        return EMAIL_MALFORMED;
    }
    const tld = labels[labels.length - 1];
    if (!/^([a-z]{2,24}|xn--[a-z0-9-]{2,59})$/.test(tld)) {
        return EMAIL_MALFORMED;
    }
    if (labels[labels.length - 2].length < 2) {
        return EMAIL_MALFORMED;
    }

    return null;
}

// ------------------------------------------------------------------ contact number

export interface PhoneCountry {
    code: string;
    iso: string;
    name: string;
    min: number;
    max: number;
}

export interface PhoneRules {
    default: string;
    countries: PhoneCountry[];
    phAreaCodes: string[];
}

/** Keeps only the digits 0-9 (typing and pasting). */
export const digitsOnly = (value: string): string => value.replace(/\D+/g, '');

/** The message for a country code and a number typed as digits, or null when it is acceptable. */
export function phoneError(rules: PhoneRules, countryCode: string, digits: string): string | null {
    const country = rules.countries.find((c) => c.code === countryCode);
    if (!country) {
        return 'Please choose the country code from the list.';
    }
    if (digits === '') {
        return 'Enter your contact number.';
    }
    if (!/^\d+$/.test(digits)) {
        return 'The contact number can only contain digits (0-9).';
    }
    if (digits[0] === '0') {
        return 'Enter the number without the leading 0: the country code replaces it.';
    }

    if (countryCode === '+63') {
        if (digits[0] === '9') {
            return digits.length === 10 ? null : 'A Philippine mobile number has 10 digits and starts with 9 (for example 9171234567).';
        }
        const hasArea = rules.phAreaCodes.includes(digits.slice(0, 2)) || digits[0] === '2';
        if (!hasArea) {
            return 'Enter a Philippine mobile number (10 digits starting with 9) or a landline with its area code (for example 281234567).';
        }

        return digits.length === 9 ? null : 'A Philippine landline is the area code plus the number, 9 digits in all (for example 281234567 for Metro Manila).';
    }

    if (digits.length < country.min || digits.length > country.max) {
        return country.min === country.max ? `A ${country.name} number has ${country.min} digits.` : `A ${country.name} number has ${country.min} to ${country.max} digits.`;
    }

    return null;
}

// ------------------------------------------------------------------ quantity

export const MAX_WHOLE_QUANTITY = 1_000_000;

/** Digits only: a whole number from 1 to 1,000,000. */
export function wholeQuantityError(value: string): string | null {
    const v = value.trim();
    if (v === '') {
        return 'Enter the quantity.';
    }
    if (!/^\d+$/.test(v)) {
        return 'The quantity must be a whole number (digits only).';
    }
    const n = Number(v);
    if (n < 1) {
        return 'The quantity must be greater than zero.';
    }
    if (n > MAX_WHOLE_QUANTITY) {
        return 'The quantity is too large (more than 1,000,000).';
    }

    return null;
}
