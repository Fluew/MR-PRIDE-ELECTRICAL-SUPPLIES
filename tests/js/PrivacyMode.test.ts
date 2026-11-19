import { beforeEach, describe, expect, it, vi } from 'vitest';

// A fresh module per test = a fresh page load.
async function load() {
    vi.resetModules();
    return await import('@/composables/usePrivacyMode');
}

describe('Privacy Mode', () => {
    beforeEach(() => window.localStorage.clear());

    it('shows amounts by default', async () => {
        const { isPrivacyHidden, maskIfHidden } = await load();
        expect(isPrivacyHidden()).toBe(false);
        expect(maskIfHidden('$1,234.00')).toBe('$1,234.00');
    });

    it('applies a saved hidden choice from the very first read, so nothing flashes', async () => {
        window.localStorage.setItem('mrp.privacy-mode', 'hidden');
        const { isPrivacyHidden, maskIfHidden, PRIVACY_MASK } = await load();
        expect(isPrivacyHidden()).toBe(true);
        expect(maskIfHidden('$1,234.00')).toBe(PRIVACY_MASK);
    });

    it('hides and reveals on toggle, saves the choice, and never changes the text itself', async () => {
        const { usePrivacyMode } = await load();
        const p = usePrivacyMode();
        p.toggle();
        expect(p.hidden.value).toBe(true);
        expect(p.mask('₱63,838.00')).toBe('••••••');
        expect(window.localStorage.getItem('mrp.privacy-mode')).toBe('hidden');
        p.toggle();
        expect(p.mask('₱63,838.00')).toBe('₱63,838.00');
        expect(window.localStorage.getItem('mrp.privacy-mode')).toBe('visible');
    });
});
