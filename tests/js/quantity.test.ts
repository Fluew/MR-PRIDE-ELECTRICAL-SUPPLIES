import { describe, expect, it } from 'vitest';
import { parseQuantity } from '@/lib/quantity';

describe('quantities typed on the quotation form', () => {
    it.each([
        ['20', 20],
        ['1,000', 1000],
        ['2.5', 2.5],
        ['0.125', 0.125],
        [' 7 ', 7],
    ])('reads %j as %s', (text, value) => {
        expect(parseQuantity(text)).toEqual({ value, error: null });
    });

    it.each([
        ['', 'Quantity is missing.'],
        ['0', 'Quantity must be greater than zero.'],
        ['-4', 'Quantity must be greater than zero.'],
        ['ten', 'Quantity must be a number.'],
        ['10 pcs', 'Quantity must be a number.'],
        ['2,5', '"2,5" could mean 2.5 or 25. Please enter the quantity.'],
        ['1.2345', 'Use at most 3 decimal places.'],
        ['2000000', 'Quantity is too large (more than 1,000,000).'],
    ])('refuses %j', (text, error) => {
        expect(parseQuantity(text)).toEqual({ value: null, error });
    });
});
