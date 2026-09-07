<?php

namespace Symphoria\Apex\Services\Bench\Support;

class PercentileCalculator
{
    /**
     * @param  array<int, float|int>  $values
     * @return array{count:int,min:float,max:float,avg:float,p50:float,p95:float,p99:float}
     */
    public static function summarize(array $values): array
    {
        $count = count($values);

        if ($count === 0) {
            return [
                'count' => 0,
                'min' => 0.0,
                'max' => 0.0,
                'avg' => 0.0,
                'p50' => 0.0,
                'p95' => 0.0,
                'p99' => 0.0,
            ];
        }

        $sorted = $values;
        sort($sorted);

        return [
            'count' => $count,
            'min' => (float) $sorted[0],
            'max' => (float) $sorted[$count - 1],
            'avg' => (float) (array_sum($sorted) / $count),
            'p50' => self::percentile($sorted, 50),
            'p95' => self::percentile($sorted, 95),
            'p99' => self::percentile($sorted, 99),
        ];
    }

    /**
     * @param  array<int, float|int>  $sorted
     */
    public static function percentile(array $sorted, float $percentile): float
    {
        $count = count($sorted);

        if ($count === 0) {
            return 0.0;
        }

        if ($count === 1) {
            return (float) $sorted[0];
        }

        $rank = ($percentile / 100) * ($count - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);
        $weight = $rank - $lower;

        return (float) ($sorted[$lower] * (1 - $weight) + $sorted[$upper] * $weight);
    }
}
