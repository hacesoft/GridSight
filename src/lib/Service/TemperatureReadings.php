<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

/** One interpretation of LINEA temperatures for both LIVE and the collector. */
final class TemperatureReadings {
    public static function fromStatus(array $status): array {
        $block = $status['temperatures'] ?? [];
        $data = is_array($block) && ($block['available'] ?? true) !== false
            ? (is_array($block['data'] ?? null) ? $block['data'] : $block) : [];

        $groups = ['racks' => [], 'inverters' => [], 'other' => [], 'batteries' => []];
        foreach ($groups as $group => $_) {
            $source = $data[$group] ?? [];
            if (!is_array($source)) continue;
            if (self::temperature($source) !== null) $source = [$source];
            foreach ($source as $key => $reading) {
                $temperature = self::temperature($reading);
                if ($temperature === null) continue;
                $name = is_array($reading)
                    ? (string)($reading['name'] ?? $reading['label'] ?? $reading['id'] ?? $reading['phase'] ?? $key)
                    : (string)$key;
                $groups[$group][] = ['name' => $name, 'temperatureC' => $temperature];
            }
        }
        // Some API variants expose a single object instead of an array of sensors.
        foreach (['rack' => 'racks', 'inverter' => 'inverters', 'battery' => 'batteries'] as $key => $group) {
            $reading = $data[$key] ?? null;
            $temperature = self::temperature($reading);
            if ($temperature !== null) {
                $groups[$group][] = ['name' => $key, 'temperatureC' => $temperature];
            }
        }

        $battery = self::temperature($status['energy']['battery'] ?? null)
            ?? self::temperature($data['battery'] ?? null)
            ?? self::number($data['batteryTemperatureC'] ?? $data['batteryTempC'] ?? null);
        foreach (array_merge($groups['batteries'], $groups['racks'], $groups['other']) as $sensor) {
            if ($battery === null && preg_match('/bater|battery|accu/iu', $sensor['name']) === 1) {
                $battery = $sensor['temperatureC'];
            }
        }
        if ($battery === null && count($groups['batteries']) === 1) {
            $battery = $groups['batteries'][0]['temperatureC'];
        }

        $rack = self::number($data['rackTemperatureC'] ?? $data['rackTempC'] ?? null)
            ?? self::temperature($data['rack'] ?? null);
        foreach (array_merge($groups['racks'], $groups['other']) as $sensor) {
            if ($rack === null && preg_match('/rack|rozvad[eě]č/iu', $sensor['name']) === 1) {
                $rack = $sensor['temperatureC'];
            }
        }
        foreach ($groups['racks'] as $sensor) {
            if ($rack === null && preg_match('/bater|battery|accu/iu', $sensor['name']) !== 1) {
                $rack = $sensor['temperatureC'];
            }
        }

        $inverters = [null, null, null];
        foreach ([1, 2, 3] as $number) {
            foreach (["inverter{$number}TemperatureC", "inverter{$number}TempC", "inv{$number}TempC"] as $key) {
                if (($temperature = self::number($data[$key] ?? null)) !== null) {
                    $inverters[$number - 1] = $temperature;
                    break;
                }
            }
        }
        $unassigned = [];
        foreach ($groups['inverters'] as $sensor) {
            $name = $sensor['name'];
            if (preg_match('/(?:^|\D)([123])(?:\D|$)/u', $name, $matches) === 1
                && $inverters[(int)$matches[1] - 1] === null) {
                $inverters[(int)$matches[1] - 1] = $sensor['temperatureC'];
            } else {
                $unassigned[] = $sensor['temperatureC'];
            }
        }
        foreach ($inverters as &$temperature) {
            if ($temperature === null && $unassigned !== []) $temperature = array_shift($unassigned);
        }
        unset($temperature);

        $sensors = [];
        foreach (['inverters' => 'inverter', 'racks' => 'rack', 'other' => 'sensor', 'batteries' => 'battery'] as $group => $kind) {
            foreach ($groups[$group] as $reading) {
                $reading['kind'] = $kind;
                $sensors[] = $reading;
            }
        }
        return [
            'rackTempC' => $rack,
            'batteryTempC' => $battery,
            'inverter1TempC' => $inverters[0],
            'inverter2TempC' => $inverters[1],
            'inverter3TempC' => $inverters[2],
            'sensors' => $sensors,
        ];
    }

    private static function temperature(mixed $reading): ?float {
        if (is_array($reading)) {
            if (($reading['available'] ?? true) === false) return null;
            foreach (['temperatureC', 'tempC', 'valueC', 'celsius', 'temperature', 'value'] as $key) {
                if (($number = self::number($reading[$key] ?? null)) !== null) return $number;
            }
            return null;
        }
        return self::number($reading);
    }

    private static function number(mixed $value): ?float {
        return is_numeric($value) && !is_bool($value) && is_finite((float)$value) ? (float)$value : null;
    }
}
