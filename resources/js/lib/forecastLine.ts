type Value = number | null | undefined;

export interface ContinuousForecastLine {
    /** Completed months only. */
    actual: (number | null)[];
    /** Only the in-progress month (drawn as a standalone diamond); null elsewhere. */
    partial: (number | null)[];
    /** Forecast, starting at the last completed month so the dashed line continues the solid one. */
    forecast: (number | null)[];
    /** Index of the in-progress month, or -1 when there is none. */
    partialIdx: number;
}

/**
 * Lays out a demand chart as one unbroken line: actual (solid) → forecast (dashed), joined at the
 * last completed month. The month in progress is NOT on the line: it is only partly over, so
 * joining it would show a false drop. It is drawn as a separate diamond at its value so far, and
 * the dashed line passes straight over it (the forecast dataset needs spanGaps: true).
 *
 * Display only: the in-progress value is never fed into the forecast.
 */
export function continuousForecastLine(actual: Value[], partial: Value[] = [], forecast: Value[] = []): ContinuousForecastLine {
    const length = Math.max(actual.length, partial.length, forecast.length);
    const at = (list: Value[], i: number): number | null => (list[i] === undefined || list[i] === null ? null : Number(list[i]));

    const actualOut = Array.from({ length }, (_, i) => at(actual, i));
    const partialIdx = Array.from({ length }, (_, i) => at(partial, i)).findIndex((v) => v !== null);
    const partialOut = Array.from({ length }, (_, i) => (i === partialIdx ? at(partial, i) : null));

    // The dashed line starts at the last completed month before the first forecast value.
    const firstForecast = Array.from({ length }, (_, i) => at(forecast, i)).findIndex((v, i) => v !== null && actualOut[i] === null);
    let anchor = -1;
    for (let i = (firstForecast >= 0 ? firstForecast : length) - 1; i >= 0; i--) {
        if (actualOut[i] !== null) {
            anchor = i;
            break;
        }
    }
    const forecastOut = Array.from({ length }, (_, i) => (firstForecast >= 0 && i >= firstForecast ? at(forecast, i) : null));
    if (anchor >= 0 && forecastOut.some((v) => v !== null)) {
        forecastOut[anchor] = actualOut[anchor];
    }

    return { actual: actualOut, partial: partialOut, forecast: forecastOut, partialIdx };
}
