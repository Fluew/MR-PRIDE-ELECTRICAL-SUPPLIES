import { readonly, ref } from 'vue';

/**
 * Privacy Mode: hides monetary values on screen so financial figures are not exposed to anyone
 * looking over a shoulder. It is a presentation layer only. Amounts, calculations, sorting,
 * filtering, charts' underlying data, API responses and exports are all unchanged; money text is
 * replaced by MASK where it is displayed.
 *
 * One shared state for the whole app, so toggling it updates every module at once. The choice is
 * kept in this browser (localStorage) and read synchronously when the app loads, before the first
 * page renders, so a hidden value never flashes. Other open tabs follow the change.
 *
 * Every money formatter (useCurrency.formatMoney / formatDisplayAmount) already goes through here.
 * For money text built by hand, use `mask(text)`.
 */

export const PRIVACY_MASK = '••••••';

const STORAGE_KEY = 'mrp.privacy-mode';
const HIDDEN = 'hidden';
const VISIBLE = 'visible';

function readSaved(): boolean {
    try {
        return typeof window !== 'undefined' && window.localStorage.getItem(STORAGE_KEY) === HIDDEN;
    } catch {
        // Storage blocked (private window, site data cleared): amounts are shown, as by default.
        return false;
    }
}

// Read at module load, so the very first render already knows the saved choice.
const hidden = ref<boolean>(readSaved());

if (typeof window !== 'undefined') {
    window.addEventListener('storage', (event) => {
        if (event.key === STORAGE_KEY) {
            hidden.value = event.newValue === HIDDEN;
        }
    });
}

function setHidden(value: boolean): void {
    hidden.value = value;
    try {
        window.localStorage.setItem(STORAGE_KEY, value ? HIDDEN : VISIBLE);
    } catch {
        // Not saved, but it still applies for this visit.
    }
}

/** True while monetary values are hidden. Reactive when read inside a template, computed or watcher. */
export function isPrivacyHidden(): boolean {
    return hidden.value;
}

/** The masked text for an already formatted money string, or the text itself when values are shown. */
export function maskIfHidden(text: string): string {
    return hidden.value ? PRIVACY_MASK : text;
}

export function usePrivacyMode() {
    return {
        /** Read-only: change it with toggle() or setHidden(). */
        hidden: readonly(hidden),
        toggle: () => setHidden(!hidden.value),
        setHidden,
        mask: maskIfHidden,
        maskText: PRIVACY_MASK,
    };
}
