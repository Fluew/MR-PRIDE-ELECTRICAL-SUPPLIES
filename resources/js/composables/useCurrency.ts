import { usePage } from '@inertiajs/vue3';
import { computed, effectScope, reactive, watch } from 'vue';
import { rate as rateRoute, update as updateRoute } from '@/routes/currency';

/**
 * Display currency for the Admin/Staff interface.
 *
 * Every amount the server sends is the stored USD amount. Pages format it with formatMoney(),
 * which shows it in USD, or converts that original USD amount to PHP at the current Frankfurter
 * rate when the user chose PHP. Nothing converted is ever sent back or saved, and an amount is
 * never converted twice. Without a valid current rate, amounts stay in USD and the header
 * switch shows a warning; no old or made-up rate is used.
 *
 * The choice is saved per user (users.preferred_currency) and shared with every page as
 * page.props.currency (null for public visitors and customers).
 */

export type CurrencyCode = 'USD' | 'PHP';

export interface ExchangeRate {
    /** PHP per 1 USD. */
    rate: number;
    /** Date the rate applies to (YYYY-MM-DD). */
    date: string;
    fetched_at: string;
}

interface SharedCurrency {
    preferred: CurrencyCode;
    base: 'USD';
    rate: ExchangeRate | null;
}

type RateStatus = 'idle' | 'loading' | 'ready' | 'unavailable';

/** Matches config('currency.cache_seconds'): after this a rate is fetched again. */
const RATE_MAX_AGE_MS = 60 * 60 * 1000;

const state = reactive({
    userId: null as number | null,
    preferred: 'USD' as CurrencyCode,
    rate: null as ExchangeRate | null,
    status: 'idle' as RateStatus,
    message: null as string | null,
    lastSuccess: null as ExchangeRate | null,
    saving: false,
    saveError: null as string | null,
});

const formatters = new Map<string, Intl.NumberFormat>();

function formatterFor(code: CurrencyCode, decimals?: number): Intl.NumberFormat {
    const key = `${code}:${decimals ?? 'default'}`;
    let f = formatters.get(key);
    if (!f) {
        // en-US gives "$1,000.00"; en-PH gives "₱58,500.00".
        f = new Intl.NumberFormat(code === 'PHP' ? 'en-PH' : 'en-US', {
            style: 'currency',
            currency: code,
            ...(decimals !== undefined ? { minimumFractionDigits: decimals, maximumFractionDigits: decimals } : {}),
        });
        formatters.set(key, f);
    }
    return f;
}

function isValidRate(r: unknown): r is ExchangeRate {
    const x = r as ExchangeRate | null;
    return !!x && typeof x.rate === 'number' && Number.isFinite(x.rate) && x.rate > 0 && typeof x.date === 'string';
}

function isFresh(r: ExchangeRate): boolean {
    const at = Date.parse(r.fetched_at);
    return Number.isFinite(at) && Date.now() - at < RATE_MAX_AGE_MS;
}

function toNumber(value: unknown): number {
    const n = typeof value === 'number' ? value : Number(value ?? 0);
    return Number.isFinite(n) ? n : 0;
}

function csrfHeaders(): Record<string, string> {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    const cookie = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1];
    return {
        ...(meta ? { 'X-CSRF-TOKEN': meta } : {}),
        ...(cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie) } : {}),
    };
}

// ------------------------------------------------------------------ exchange rate

let inflight: Promise<void> | null = null;

/** Fetch the current rate. `refresh` asks the server to contact the service right away (Retry). */
function loadRate(refresh = false): Promise<void> {
    if (inflight) {
        return inflight;
    }
    state.status = 'loading';
    state.message = null;

    inflight = (async () => {
        try {
            const url = rateRoute({ query: refresh ? { refresh: 1 } : {} }).url;
            const res = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => null);

            if (res.ok && data?.status === 'ok' && isValidRate(data)) {
                state.rate = { rate: data.rate, date: data.date, fetched_at: data.fetched_at };
                state.lastSuccess = state.rate;
                state.status = 'ready';
            } else {
                state.rate = null;
                state.status = 'unavailable';
                state.message = data?.message ?? 'The exchange rate could not be loaded.';
                if (isValidRate(data?.last_success)) {
                    state.lastSuccess = data.last_success;
                }
            }
        } catch {
            state.rate = null;
            state.status = 'unavailable';
            state.message = 'The exchange rate could not be loaded. Check your connection.';
        } finally {
            inflight = null;
        }
    })();

    return inflight;
}

function ensureRate() {
    if (state.preferred !== 'PHP') {
        return;
    }
    if (!state.rate || !isFresh(state.rate)) {
        void loadRate();
    }
}

// ------------------------------------------------------------------ sync with the server

let started = false;

function start() {
    if (started) {
        return;
    }
    started = true;
    const page = usePage();

    // A detached scope: these watchers live for the whole visit, not one component.
    effectScope(true).run(() => {
        watch(
            () => [(page.props as any).auth?.user?.id ?? null, (page.props as any).currency] as [number | null, SharedCurrency | null | undefined],
            ([userId, shared]) => {
                if (!shared) {
                    return;
                }
                // The saved choice is read on the first page and whenever a different user signs
                // in. After that this tab's own choice wins (pages restored from browser history
                // still carry the old value).
                if (userId !== state.userId) {
                    state.userId = userId;
                    state.preferred = shared.preferred === 'PHP' ? 'PHP' : 'USD';
                    state.saveError = null;
                }
                if (isValidRate(shared.rate) && isFresh(shared.rate)) {
                    state.rate = shared.rate;
                    state.lastSuccess = shared.rate;
                    state.status = 'ready';
                    state.message = null;
                }
                ensureRate();
            },
            { immediate: true },
        );
    });
}

/** Save the user's choice. The switch changes at once and goes back if saving fails. */
async function setCurrency(code: CurrencyCode) {
    if (code === state.preferred || state.saving) {
        return;
    }
    const previous = state.preferred;
    state.preferred = code;
    state.saving = true;
    state.saveError = null;
    ensureRate();

    try {
        const res = await fetch(updateRoute().url, {
            method: 'PUT',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...csrfHeaders(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ currency: code }),
        });
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }
    } catch {
        state.preferred = previous;
        state.saveError = 'Your currency choice could not be saved. Please try again.';
    } finally {
        state.saving = false;
    }
}

// ------------------------------------------------------------------ public API

export function useCurrency() {
    start();

    /** The currency amounts are shown in right now (USD until a valid PHP rate is available). */
    const displayCurrency = computed<CurrencyCode>(() => (state.preferred === 'PHP' && state.rate ? 'PHP' : 'USD'));
    const isConverted = computed(() => displayCurrency.value === 'PHP');

    /** Convert a stored USD amount to the display currency (for chart data). */
    function toDisplayAmount(usd: unknown): number {
        const n = toNumber(usd);
        return isConverted.value && state.rate ? n * state.rate.rate : n;
    }

    /** Format a stored USD amount in the display currency, e.g. "$1,000.00" or "₱63,838.00". */
    function formatMoney(usd: unknown, options: { decimals?: number } = {}): string {
        return formatterFor(displayCurrency.value, options.decimals).format(toDisplayAmount(usd));
    }

    /** Format a number that is already in the display currency (chart axis and tooltip values). */
    function formatDisplayAmount(value: unknown, options: { decimals?: number } = {}): string {
        return formatterFor(displayCurrency.value, options.decimals).format(toNumber(value));
    }

    function formatRateDate(r: ExchangeRate | null): string {
        if (!r) {
            return '';
        }
        const [y, m, d] = r.date.split('-').map(Number);
        return new Date(y, m - 1, d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    /** "1 USD = ₱63.84", or null when no rate is in use. */
    const rateLabel = computed(() =>
        state.rate ? `1 USD = ${formatterFor('PHP').format(state.rate.rate)}` : null,
    );

    /** A line for printed documents shown in PHP; null when amounts are in USD. */
    const conversionNote = computed(() =>
        isConverted.value && state.rate
            ? `Amounts converted from USD at ${formatterFor('PHP').format(state.rate.rate)} per US$1 (exchange rate of ${formatRateDate(state.rate)}). For reference only; the official amounts are in USD.`
            : null,
    );

    return {
        state,
        displayCurrency,
        isConverted,
        rateLabel,
        conversionNote,
        formatMoney,
        toDisplayAmount,
        formatDisplayAmount,
        formatRateDate,
        setCurrency,
        retryRate: () => loadRate(true),
    };
}
