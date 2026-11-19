export interface ForecastPoint {
    start: string;
    end: string;
    label: string;
    actual?: number;
    forecast?: number | null;
}

export interface ForecastMetrics {
    count: number;
    mae: number | null;
    mape: number | null;
    rmse: number | null;
    wape?: number | null;
    mape_count: number;
    zero_actual_count: number;
    accuracy_indicator: number | null;
    target_met: boolean | null;
    comparisons: ForecastPoint[];
}

export interface DemandPattern {
    label: string;
    zero_share: number | null;
    intermittent: boolean;
}

export interface PeriodProduct {
    product: {
        id: number;
        name: string;
        sku: string;
        category: string;
        stock: number;
        unit: string;
    };
    analysis: {
        selected_window: number | null;
        best_window: number | null;
        selection_validated: boolean;
        available: boolean;
        forecast: number | null;
        total_forecast: number | null;
        history_periods: number;
        reason: string | null;
        accuracy_band: string;
        demand_pattern?: DemandPattern;
        in_progress?: ForecastPoint;
        accuracy: ForecastMetrics;
        validation_start: string | null;
        test_start: string | null;
        historical: ForecastPoint[];
        future: ForecastPoint[];
        configurations: Record<
            string,
            {
                window: number;
                available: boolean;
                validation: ForecastMetrics;
                test: ForecastMetrics;
            }
        >;
    };
}

export interface PeriodPlanning {
    options: {
        period: 'weekly' | 'monthly' | 'annual';
        window: string;
        history_periods: number;
        horizon: number;
        category: string;
        search: string;
        product_id: number | null;
        from_date: string | null;
        to_date: string | null;
    };
    products: {
        data: PeriodProduct[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    categories: string[];
    training_end: string;
    forecast_start: string;
}
