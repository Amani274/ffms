<?php

declare(strict_types=1);

final class Weather
{
    private const CODES = [
        0 => 'Clear', 1 => 'Mostly clear', 2 => 'Partly cloudy', 3 => 'Overcast', 45 => 'Fog', 48 => 'Fog',
        51 => 'Light drizzle', 53 => 'Drizzle', 55 => 'Heavy drizzle', 61 => 'Light rain', 63 => 'Rain', 65 => 'Heavy rain',
        80 => 'Showers', 81 => 'Showers', 82 => 'Heavy showers', 95 => 'Thunderstorm', 96 => 'Thunderstorm', 99 => 'Thunderstorm',
    ];

    public static function farmLocation(int $farmId): array
    {
        $f = Db::one('SELECT latitude, longitude FROM farms WHERE id = ?', [$farmId]);
        return [(float) ($f['latitude'] ?? -15.4167), (float) ($f['longitude'] ?? 28.2833)];
    }

    /** Forecast rows for the next 7 days, cached; live Open-Meteo data with an offline estimate as fallback. */
    public static function forecast(int $farmId, bool $forceRefresh = false): array
    {
        $today = gmdate('Y-m-d');
        $cached = Db::all('SELECT * FROM weather_forecasts WHERE farm_id = ? AND forecast_date >= ? ORDER BY forecast_date LIMIT 7', [$farmId, $today]);
        $fresh = $cached && strtotime((string) $cached[0]['fetched_at'] . ' UTC') > time() - 3 * 3600;
        if ($fresh && !$forceRefresh && count($cached) >= 5) return $cached;

        if (Env::get('WEATHER_LIVE', '1') === '1') {
            try {
                self::fetchLive($farmId);
            } catch (Throwable $e) {
                error_log('FFMS weather fetch failed: ' . $e->getMessage());
            }
            $cached = Db::all('SELECT * FROM weather_forecasts WHERE farm_id = ? AND forecast_date >= ? ORDER BY forecast_date LIMIT 7', [$farmId, $today]);
            if (count($cached) >= 3) return $cached;
        }
        if (count($cached) >= 3) return $cached;
        self::estimate($farmId);
        return Db::all('SELECT * FROM weather_forecasts WHERE farm_id = ? AND forecast_date >= ? ORDER BY forecast_date LIMIT 7', [$farmId, $today]);
    }

    private static function fetchLive(int $farmId): void
    {
        [$lat, $lon] = self::farmLocation($farmId);
        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => $lat, 'longitude' => $lon, 'timezone' => 'auto', 'forecast_days' => 7,
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,wind_speed_10m_max',
            'current' => 'relative_humidity_2m',
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_SSL_VERIFYPEER => true]);
        $cainfo = 'C:/xampp/apache/bin/curl-ca-bundle.crt';
        if (is_file($cainfo)) curl_setopt($ch, CURLOPT_CAINFO, $cainfo);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $http !== 200) throw new RuntimeException("Open-Meteo unavailable ($http $err)");
        $j = json_decode((string) $raw, true);
        if (empty($j['daily']['time'])) throw new RuntimeException('Open-Meteo returned no data');
        $d = $j['daily'];
        $humidity = $j['current']['relative_humidity_2m'] ?? null;
        foreach ($d['time'] as $i => $date) {
            Db::exec(
                'INSERT INTO weather_forecasts (farm_id, forecast_date, temp_min_c, temp_max_c, humidity_pct, rain_probability_pct, rainfall_mm, wind_kph, conditions, source, fetched_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE temp_min_c=VALUES(temp_min_c), temp_max_c=VALUES(temp_max_c), humidity_pct=VALUES(humidity_pct),
                 rain_probability_pct=VALUES(rain_probability_pct), rainfall_mm=VALUES(rainfall_mm), wind_kph=VALUES(wind_kph),
                 conditions=VALUES(conditions), source=VALUES(source), fetched_at=VALUES(fetched_at)',
                [
                    $farmId, $date, $d['temperature_2m_min'][$i], $d['temperature_2m_max'][$i], $i === 0 ? $humidity : null,
                    $d['precipitation_probability_max'][$i] ?? 0, $d['precipitation_sum'][$i] ?? 0, $d['wind_speed_10m_max'][$i] ?? 0,
                    self::CODES[(int) ($d['weather_code'][$i] ?? 0)] ?? 'Variable', 'open-meteo',
                ]
            );
        }
    }

    /** Offline fallback: climatology from the farm's own weather history for the same calendar window. */
    private static function estimate(int $farmId): void
    {
        for ($i = 0; $i < 7; $i++) {
            $date = gmdate('Y-m-d', strtotime("+$i days"));
            $doy = (int) gmdate('z', strtotime($date));
            $s = Db::one(
                'SELECT AVG(temp_min_c) tmin, AVG(temp_max_c) tmax, AVG(humidity_pct) hum, AVG(rainfall_mm) rain, AVG(wind_kph) wind, AVG(rainfall_mm > 1) p
                 FROM weather_records WHERE farm_id = ? AND ABS(DAYOFYEAR(record_date) - ?) <= 10',
                [$farmId, $doy + 1]
            );
            if (!$s || $s['tmin'] === null) $s = ['tmin' => 14, 'tmax' => 28, 'hum' => 55, 'rain' => 0.5, 'wind' => 12, 'p' => 0.1];
            Db::exec(
                'INSERT INTO weather_forecasts (farm_id, forecast_date, temp_min_c, temp_max_c, humidity_pct, rain_probability_pct, rainfall_mm, wind_kph, conditions, source, fetched_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE temp_min_c=VALUES(temp_min_c), temp_max_c=VALUES(temp_max_c), humidity_pct=VALUES(humidity_pct),
                 rain_probability_pct=VALUES(rain_probability_pct), rainfall_mm=VALUES(rainfall_mm), wind_kph=VALUES(wind_kph),
                 conditions=VALUES(conditions), source=VALUES(source), fetched_at=VALUES(fetched_at)',
                [$farmId, $date, round((float) $s['tmin'], 1), round((float) $s['tmax'], 1), round((float) $s['hum'], 1), round((float) $s['p'] * 100, 1),
                 round((float) $s['rain'], 1), round((float) $s['wind'], 1), (float) $s['p'] > 0.45 ? 'Showers likely' : 'Mostly dry', 'estimate']
            );
        }
    }

    public static function overview(int $farmId): array
    {
        $current = Db::one('SELECT * FROM weather_records WHERE farm_id = ? ORDER BY record_date DESC LIMIT 1', [$farmId]);
        $forecast = self::forecast($farmId);
        $history = Db::all('SELECT record_date, temp_min_c, temp_max_c, humidity_pct, rainfall_mm, wind_kph FROM weather_records WHERE farm_id = ? AND record_date >= ? ORDER BY record_date', [$farmId, gmdate('Y-m-d', strtotime('-60 days'))]);
        $monthly = Db::all(
            "SELECT DATE_FORMAT(record_date,'%Y-%m') AS month, ROUND(AVG((temp_min_c+temp_max_c)/2),1) AS avg_temp, ROUND(SUM(rainfall_mm),1) AS rainfall_mm, ROUND(AVG(humidity_pct),1) AS avg_humidity
             FROM weather_records WHERE farm_id = ? AND record_date >= ? GROUP BY month ORDER BY month",
            [$farmId, gmdate('Y-m-d', strtotime('-24 months'))]
        );
        $risks = self::risks($forecast);
        return [
            'current' => $current, 'forecast' => $forecast, 'history' => $history, 'monthly' => $monthly, 'risks' => $risks,
            'source' => $forecast[0]['source'] ?? 'none',
        ];
    }

    /** Extreme-weather alerts for the forecast window. */
    public static function risks(array $forecast): array
    {
        $out = [];
        foreach ($forecast as $f) {
            $d = $f['forecast_date'];
            if ((float) $f['rainfall_mm'] >= 40) $out[] = ['date' => $d, 'severity' => 'critical', 'type' => 'heavy_rain', 'message' => "Heavy rain expected ({$f['rainfall_mm']} mm): risk of waterlogging and erosion."];
            elseif ((float) $f['rainfall_mm'] >= 20) $out[] = ['date' => $d, 'severity' => 'warning', 'type' => 'rain', 'message' => "Significant rain expected ({$f['rainfall_mm']} mm). Delay spraying and fertiliser application."];
            if ((float) $f['temp_max_c'] >= 36) $out[] = ['date' => $d, 'severity' => 'warning', 'type' => 'heat', 'message' => "Heat stress risk: maximum {$f['temp_max_c']}°C. Increase irrigation frequency."];
            if ((float) $f['temp_min_c'] <= 4) $out[] = ['date' => $d, 'severity' => 'critical', 'type' => 'frost', 'message' => "Frost risk: minimum {$f['temp_min_c']}°C. Protect seedlings and sensitive crops."];
            if ((float) $f['wind_kph'] >= 45) $out[] = ['date' => $d, 'severity' => 'warning', 'type' => 'wind', 'message' => "Strong winds ({$f['wind_kph']} km/h). Avoid spraying; secure structures."];
        }
        return $out;
    }

    public static function stats(int $farmId, int $days, ?string $since = null): array
    {
        $from = $since ?? gmdate('Y-m-d', strtotime("-$days days"));
        $s = Db::one(
            'SELECT IFNULL(SUM(rainfall_mm),0) AS rain, AVG((temp_min_c+temp_max_c)/2) AS mean_temp, AVG(temp_max_c) AS max_temp, SUM(temp_max_c > 35) AS hot_days, COUNT(*) AS n
             FROM weather_records WHERE farm_id = ? AND record_date >= ?',
            [$farmId, $from]
        );
        return ['rain_mm' => (float) $s['rain'], 'mean_temp' => $s['mean_temp'] !== null ? (float) $s['mean_temp'] : null, 'hot_days' => (int) $s['hot_days'], 'days' => (int) $s['n']];
    }
}
