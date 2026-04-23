<?php
/**
 * Embedded JeedomMCP extension for weather data via Open-Meteo.
 *
 * Does not require the Jeedom Weather plugin — calls Open-Meteo directly
 * (free, no API key). Uses the home location configured in Jeedom by default.
 *
 * -------------------------------------------------------------------------
 * NOTE FOR PLUGIN AUTHORS
 * -------------------------------------------------------------------------
 * If you want to take ownership of this extension, copy it to:
 *
 *   plugins/weather/mcp/McpExtension.php
 *
 * Your version will automatically take precedence over this embedded one.
 * -------------------------------------------------------------------------
 */

class weatherMcpExtension {

    public static function getTools(): array {
        return [
            [
                'name'        => 'current',
                'description' => 'Get current weather conditions at the configured home location or a given place. Returns temperature, feels-like, humidity, wind, UV index, precipitation, and a human-readable condition.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'location' => [
                            'type'        => 'string',
                            'description' => 'City name (e.g. "Paris") or "lat,lon" coordinates (e.g. "48.85,2.35"). Defaults to the Jeedom system location.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'forecast',
                'description' => 'Get a daily weather forecast for the next 1–7 days at the configured home location or a given place. Returns min/max temperature, precipitation, rain probability, wind, and condition per day.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'location' => [
                            'type'        => 'string',
                            'description' => 'City name (e.g. "Paris") or "lat,lon" coordinates (e.g. "48.85,2.35"). Defaults to the Jeedom system location.',
                        ],
                        'days' => [
                            'type'        => 'integer',
                            'description' => 'Number of forecast days (1–7). Defaults to 4.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public static function callTool(string $name, array $args): array {
        switch ($name) {
            case 'current':  return static::current($args);
            case 'forecast': return static::forecast($args);
            default:         throw new Exception("Unknown tool: {$name}");
        }
    }

    // -------------------------------------------------------------------------

    private static function current(array $args): array {
        $loc = static::resolveLocation($args);

        $params = http_build_query([
            'latitude'      => $loc['lat'],
            'longitude'     => $loc['lon'],
            'current'       => implode(',', [
                'temperature_2m',
                'relative_humidity_2m',
                'apparent_temperature',
                'weather_code',
                'wind_speed_10m',
                'wind_gusts_10m',
                'wind_direction_10m',
                'precipitation',
                'uv_index',
                'surface_pressure',
                'is_day',
            ]),
            'daily'         => 'sunrise,sunset',
            'timezone'      => 'auto',
            'forecast_days' => 1,
        ]);

        $data = static::httpGet('https://api.open-meteo.com/v1/forecast?' . $params);
        $c    = $data['current'];
        $d    = $data['daily'];
        $u    = $data['current_units'];

        return [
            'location'    => $loc,
            'time'        => $c['time'],
            'condition'   => static::wmoDescription((int)$c['weather_code'], (bool)$c['is_day']),
            'weather_code'  => (int)$c['weather_code'],
            'temperature'   => $c['temperature_2m'],
            'feels_like'    => $c['apparent_temperature'],
            'humidity'      => $c['relative_humidity_2m'],
            'wind_speed'    => $c['wind_speed_10m'],
            'wind_gusts'    => $c['wind_gusts_10m'],
            'wind_direction' => $c['wind_direction_10m'],
            'precipitation' => $c['precipitation'],
            'uv_index'      => $c['uv_index'],
            'pressure'      => $c['surface_pressure'],
            'sunrise'       => isset($d['sunrise'][0]) ? $d['sunrise'][0] : null,
            'sunset'        => isset($d['sunset'][0]) ? $d['sunset'][0] : null,
            'units'         => [
                'temperature'   => $u['temperature_2m'],
                'wind_speed'    => $u['wind_speed_10m'],
                'precipitation' => $u['precipitation'],
                'pressure'      => $u['surface_pressure'],
            ],
        ];
    }

    private static function forecast(array $args): array {
        $loc  = static::resolveLocation($args);
        $days = isset($args['days']) ? max(1, min(7, (int)$args['days'])) : 4;

        $params = http_build_query([
            'latitude'      => $loc['lat'],
            'longitude'     => $loc['lon'],
            'daily'         => implode(',', [
                'weather_code',
                'temperature_2m_max',
                'temperature_2m_min',
                'precipitation_sum',
                'precipitation_probability_max',
                'wind_speed_10m_max',
                'wind_gusts_10m_max',
                'uv_index_max',
                'sunrise',
                'sunset',
            ]),
            'timezone'      => 'auto',
            'forecast_days' => $days,
        ]);

        $data = static::httpGet('https://api.open-meteo.com/v1/forecast?' . $params);
        $d    = $data['daily'];
        $u    = $data['daily_units'];

        $forecast = [];
        $count    = count($d['time']);
        for ($i = 0; $i < $count; $i++) {
            $forecast[] = [
                'date'                      => $d['time'][$i],
                'condition'                 => static::wmoDescription((int)$d['weather_code'][$i], true),
                'weather_code'              => (int)$d['weather_code'][$i],
                'temperature_max'           => $d['temperature_2m_max'][$i],
                'temperature_min'           => $d['temperature_2m_min'][$i],
                'precipitation'             => $d['precipitation_sum'][$i],
                'precipitation_probability' => $d['precipitation_probability_max'][$i],
                'wind_speed_max'            => $d['wind_speed_10m_max'][$i],
                'wind_gusts_max'            => $d['wind_gusts_10m_max'][$i],
                'uv_index_max'              => $d['uv_index_max'][$i],
                'sunrise'                   => $d['sunrise'][$i],
                'sunset'                    => $d['sunset'][$i],
            ];
        }

        return [
            'location' => $loc,
            'units'    => [
                'temperature'   => $u['temperature_2m_max'],
                'precipitation' => $u['precipitation_sum'],
                'wind_speed'    => $u['wind_speed_10m_max'],
            ],
            'forecast' => $forecast,
        ];
    }

    // -------------------------------------------------------------------------

    private static function resolveLocation(array $args): array {
        if (!empty($args['location'])) {
            $input = trim($args['location']);

            // "lat,lon" format
            if (preg_match('/^(-?\d+\.?\d*),\s*(-?\d+\.?\d*)$/', $input, $m)) {
                return ['lat' => (float)$m[1], 'lon' => (float)$m[2], 'name' => $input];
            }

            // City name — geocode via Open-Meteo
            $params = http_build_query(['name' => $input, 'count' => 1, 'language' => 'fr', 'format' => 'json']);
            $geo    = static::httpGet('https://geocoding-api.open-meteo.com/v1/search?' . $params);

            if (empty($geo['results'])) {
                throw new Exception("Location not found: \"{$input}\".");
            }

            $r = $geo['results'][0];
            return [
                'lat'  => (float)$r['latitude'],
                'lon'  => (float)$r['longitude'],
                'name' => $r['name'] . (isset($r['country']) ? ', ' . $r['country'] : ''),
            ];
        }

        // Fall back to Jeedom system location.
        // Jeedom stores location under 'info::latitude' (4.x) or 'latitude' (older).
        $lat  = config::byKey('info::latitude');
        $lon  = config::byKey('info::longitude');
        $city = config::byKey('info::name');

        if (($lat === '' || $lat === null) && ($lon === '' || $lon === null)) {
            $lat  = config::byKey('latitude');
            $lon  = config::byKey('longitude');
            $city = config::byKey('name');
        }

        if ($lat === '' || $lon === '' || $lat === null || $lon === null) {
            throw new Exception(
                'No location provided and no home location configured in Jeedom ' .
                '(Administration \u2192 Configuration \u2192 Localisation).'
            );
        }

        return [
            'lat'  => (float)$lat,
            'lon'  => (float)$lon,
            'name' => ($city !== '' && $city !== null) ? $city : ((string)$lat . ',' . (string)$lon),
        ];
    }

    private static function httpGet(string $url): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '') {
            throw new Exception("Weather API request failed: {$err}");
        }
        if ($status !== 200) {
            throw new Exception("Weather API returned HTTP {$status}.");
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new Exception("Weather API returned invalid JSON.");
        }

        return $json;
    }

    private static function wmoDescription(int $code, bool $is_day = true): string {
        $map = [
            0  => ['Clear sky',              'Clear sky'],
            1  => ['Mainly clear',           'Mainly clear'],
            2  => ['Partly cloudy',          'Partly cloudy'],
            3  => ['Overcast',               'Overcast'],
            45 => ['Fog',                    'Fog'],
            48 => ['Icy fog',                'Icy fog'],
            51 => ['Light drizzle',          'Light drizzle'],
            53 => ['Moderate drizzle',       'Moderate drizzle'],
            55 => ['Dense drizzle',          'Dense drizzle'],
            56 => ['Light freezing drizzle', 'Light freezing drizzle'],
            57 => ['Heavy freezing drizzle', 'Heavy freezing drizzle'],
            61 => ['Slight rain',            'Slight rain'],
            63 => ['Moderate rain',          'Moderate rain'],
            65 => ['Heavy rain',             'Heavy rain'],
            66 => ['Light freezing rain',    'Light freezing rain'],
            67 => ['Heavy freezing rain',    'Heavy freezing rain'],
            71 => ['Slight snowfall',        'Slight snowfall'],
            73 => ['Moderate snowfall',      'Moderate snowfall'],
            75 => ['Heavy snowfall',         'Heavy snowfall'],
            77 => ['Snow grains',            'Snow grains'],
            80 => ['Slight rain showers',    'Slight rain showers'],
            81 => ['Moderate rain showers',  'Moderate rain showers'],
            82 => ['Violent rain showers',   'Violent rain showers'],
            85 => ['Slight snow showers',    'Slight snow showers'],
            86 => ['Heavy snow showers',     'Heavy snow showers'],
            95 => ['Thunderstorm',           'Thunderstorm'],
            96 => ['Thunderstorm with slight hail', 'Thunderstorm with slight hail'],
            99 => ['Thunderstorm with heavy hail',  'Thunderstorm with heavy hail'],
        ];

        if (!isset($map[$code])) {
            return "Unknown condition (code {$code})";
        }

        // index 0 = day, index 1 = night (currently identical, kept for future differentiation)
        return $map[$code][$is_day ? 0 : 1];
    }
}
