<?php

namespace App\Enums;

use Carbon\Carbon;
use Carbon\CarbonInterface;

enum ForecastPeriod: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Annual = 'annual';

    public function start(CarbonInterface $date): Carbon
    {
        $date = Carbon::instance($date)->copy()->startOfDay();

        return match ($this) {
            self::Weekly => $date->startOfWeek(CarbonInterface::MONDAY),
            self::Monthly => $date->startOfMonth(),
            self::Annual => $date->startOfYear(),
        };
    }

    public function advance(CarbonInterface $date, int $count = 1): Carbon
    {
        $date = $this->start($date);

        return match ($this) {
            self::Weekly => $date->addWeeks($count),
            self::Monthly => $date->addMonths($count),
            self::Annual => $date->addYears($count),
        };
    }

    public function label(CarbonInterface $date): string
    {
        $start = $this->start($date);

        return match ($this) {
            self::Weekly => $start->format('d M Y').' – '.$this->advance($start)->subDay()->format('d M Y'),
            self::Monthly => $start->format('M Y'),
            self::Annual => $start->format('Y'),
        };
    }

    public function sql(string $column, string $driver): string
    {
        if ($driver === 'sqlite') {
            return match ($this) {
                self::Weekly => "date($column, '-' || ((CAST(strftime('%w', $column) AS INTEGER) + 6) % 7) || ' days')",
                self::Monthly => "date($column, 'start of month')",
                self::Annual => "date($column, 'start of year')",
            };
        }

        return match ($this) {
            self::Weekly => "DATE_SUB(DATE($column), INTERVAL WEEKDAY($column) DAY)",
            self::Monthly => "DATE_FORMAT($column, '%Y-%m-01')",
            self::Annual => "DATE_FORMAT($column, '%Y-01-01')",
        };
    }
}
