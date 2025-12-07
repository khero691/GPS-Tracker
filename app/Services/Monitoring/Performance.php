<?php declare(strict_types=1);

namespace App\Services\Monitoring;

class Performance
{
    /**
     * @var array
     */
    protected static array $metrics = [];

    /**
     * @var array
     */
    protected static array $timers = [];

    /**
     * Начать измерение времени выполнения
     *
     * @param string $name
     *
     * @return void
     */
    public static function startTimer(string $name): void
    {
        static::$timers[$name] = microtime(true);
    }

    /**
     * Завершить измерение времени выполнения и сохранить метрику
     *
     * @param string $name
     *
     * @return float
     */
    public static function endTimer(string $name): float
    {
        if (!isset(static::$timers[$name])) {
            return 0;
        }

        $duration = microtime(true) - static::$timers[$name];
        
        static::addMetric($name, $duration);
        
        unset(static::$timers[$name]);
        
        return $duration;
    }

    /**
     * Добавить метрику
     *
     * @param string $name
     * @param float $value
     *
     * @return void
     */
    public static function addMetric(string $name, float $value): void
    {
        if (!isset(static::$metrics[$name])) {
            static::$metrics[$name] = [
                'count' => 0,
                'total' => 0,
                'min' => $value,
                'max' => $value,
                'avg' => $value,
            ];
        }

        $metric = &static::$metrics[$name];
        
        $metric['count']++;
        $metric['total'] += $value;
        $metric['min'] = min($metric['min'], $value);
        $metric['max'] = max($metric['max'], $value);
        $metric['avg'] = $metric['total'] / $metric['count'];
    }

    /**
     * Получить все метрики
     *
     * @return array
     */
    public static function getMetrics(): array
    {
        return static::$metrics;
    }

    /**
     * Получить метрику по имени
     *
     * @param string $name
     *
     * @return array|null
     */
    public static function getMetric(string $name): ?array
    {
        return static::$metrics[$name] ?? null;
    }

    /**
     * Сбросить все метрики
     *
     * @return void
     */
    public static function resetMetrics(): void
    {
        static::$metrics = [];
        static::$timers = [];
    }

    /**
     * Логировать метрики
     *
     * @return void
     */
    public static function logMetrics(): void
    {
        foreach (static::$metrics as $name => $metric) {
            logger()->info("Performance metric: {$name}", $metric);
        }
    }
}
