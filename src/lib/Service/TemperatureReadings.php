<?php

/**
 * Hacesoft GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-21 – bezpečné sjednocení interních lokálních proměnných.
 * Historie: 2026-11-22 – ověření typů, významových komentářů a shody názvů vlastností.
 * s – textový řetězec / identifikátor nebo cesta.
 * a – pole / seznam.
 * o – instance objektu nebo výjimka.
 * v – smíšená nebo zatím neurčená hodnota.
 * Výjimka: názvy parametrů PHP metod a veřejných vlastností se nemění,
 * protože jsou součástí rozhraní Nextcloud, DI a pojmenovaných argumentů.
 */

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

/** One interpretation of LINEA temperatures for both LIVE and the collector. */
final class TemperatureReadings {
    /**
     * Sestaví dostupné teploty baterie, racku a měničů ze stavové odpovědi LINEA.
     *
     * Vstupy: `$status` (array) – strukturovaná stavová odpověď LINEA.
     * Výstup: `array` – souhrnná mapa teplot baterie, racku, měničů a dostupných čidel LINEA.
     *
     * @param array $status strukturovaná stavová odpověď LINEA
     * @return array souhrnná mapa teplot baterie, racku, měničů a dostupných čidel LINEA
     */
    public static function fromStatus(array $status): array {
        $vBlock = $status['temperatures'] ?? [];
        $vData = is_array($vBlock) && ($vBlock['available'] ?? true) !== false
            ? (is_array($vBlock['data'] ?? null) ? $vBlock['data'] : $vBlock) : [];

        $aGroups = ['racks' => [], 'inverters' => [], 'other' => [], 'batteries' => []];
        foreach ($aGroups as $sGroup => $_) {
            $vSource = $vData[$sGroup] ?? [];
            if (!is_array($vSource)) continue;
            if (self::temperature($vSource) !== null) $vSource = [$vSource];
            foreach ($vSource as $sKey => $reading) {
                $vTemperature = self::temperature($reading);
                if ($vTemperature === null) continue;
                $sName = is_array($reading)
                    ? (string)($reading['name'] ?? $reading['label'] ?? $reading['id'] ?? $reading['phase'] ?? $sKey)
                    : (string)$sKey;
                $aGroups[$sGroup][] = ['name' => $sName, 'temperatureC' => $vTemperature];
            }
        }
        // Some API variants expose a single object instead of an array of sensors.
        foreach (['rack' => 'racks', 'inverter' => 'inverters', 'battery' => 'batteries'] as $sKey => $sGroup) {
            $reading = $vData[$sKey] ?? null;
            $vTemperature = self::temperature($reading);
            if ($vTemperature !== null) {
                $aGroups[$sGroup][] = ['name' => $sKey, 'temperatureC' => $vTemperature];
            }
        }

        $vBattery = self::temperature($status['energy']['battery'] ?? null)
            ?? self::temperature($vData['battery'] ?? null)
            ?? self::number($vData['batteryTemperatureC'] ?? $vData['batteryTempC'] ?? null);
        foreach (array_merge($aGroups['batteries'], $aGroups['racks'], $aGroups['other']) as $vSensor) {
            if ($vBattery === null && preg_match('/bater|battery|accu/iu', $vSensor['name']) === 1) {
                $vBattery = $vSensor['temperatureC'];
            }
        }
        if ($vBattery === null && count($aGroups['batteries']) === 1) {
            $vBattery = $aGroups['batteries'][0]['temperatureC'];
        }

        $vRack = self::number($vData['rackTemperatureC'] ?? $vData['rackTempC'] ?? null)
            ?? self::temperature($vData['rack'] ?? null);
        foreach (array_merge($aGroups['racks'], $aGroups['other']) as $vSensor) {
            if ($vRack === null && preg_match('/rack|rozvad[eě]č/iu', $vSensor['name']) === 1) {
                $vRack = $vSensor['temperatureC'];
            }
        }
        foreach ($aGroups['racks'] as $vSensor) {
            if ($vRack === null && preg_match('/bater|battery|accu/iu', $vSensor['name']) !== 1) {
                $vRack = $vSensor['temperatureC'];
            }
        }

        $aInverters = [null, null, null];
        foreach ([1, 2, 3] as $vNumber) {
            foreach (["inverter{$vNumber}TemperatureC", "inverter{$vNumber}TempC", "inv{$vNumber}TempC"] as $sKey) {
                if (($vTemperature = self::number($vData[$sKey] ?? null)) !== null) {
                    $aInverters[$vNumber - 1] = $vTemperature;
                    break;
                }
            }
        }
        $aUnassigned = [];
        foreach ($aGroups['inverters'] as $vSensor) {
            $sName = $vSensor['name'];
            if (preg_match('/(?:^|\D)([123])(?:\D|$)/u', $sName, $aMatches) === 1
                && $aInverters[(int)$aMatches[1] - 1] === null) {
                $aInverters[(int)$aMatches[1] - 1] = $vSensor['temperatureC'];
            } else {
                $aUnassigned[] = $vSensor['temperatureC'];
            }
        }
        foreach ($aInverters as &$vTemperature) {
            if ($vTemperature === null && $aUnassigned !== []) $vTemperature = array_shift($aUnassigned);
        }
        unset($vTemperature);

        $aSensors = [];
        foreach (['inverters' => 'inverter', 'racks' => 'rack', 'other' => 'sensor', 'batteries' => 'battery'] as $sGroup => $sKind) {
            foreach ($aGroups[$sGroup] as $reading) {
                $reading['kind'] = $sKind;
                $aSensors[] = $reading;
            }
        }
        return [
            'rackTempC' => $vRack,
            'batteryTempC' => $vBattery,
            'inverter1TempC' => $aInverters[0],
            'inverter2TempC' => $aInverters[1],
            'inverter3TempC' => $aInverters[2],
            'sensors' => $aSensors,
        ];
    }

    /**
     * Načte teplotu vybraného čidla z dostupné odpovědi LINEA.
     *
     * Vstupy: `$reading` (mixed) – jednotlivé měření/objekt teplotního čidla.
     * Výstup: `?float` – teplota čidla v °C nebo null při neplatném či nedostupném údaji.
     *
     * @param mixed $reading jednotlivé měření/objekt teplotního čidla
     * @return ?float teplota čidla v °C nebo null při neplatném či nedostupném údaji
     */
    private static function temperature(mixed $reading): ?float {
        if (is_array($reading)) {
            if (($reading['available'] ?? true) === false) return null;
            foreach (['temperatureC', 'tempC', 'valueC', 'celsius', 'temperature', 'value'] as $sKey) {
                if (($vNumber = self::number($reading[$sKey] ?? null)) !== null) return $vNumber;
            }
            return null;
        }
        return self::number($reading);
    }

    /**
     * Ověří číselnou teplotu a vrátí NULL pro neplatný údaj.
     *
     * Vstupy: `$value` (mixed) – aktuální hodnota metriky nebo atributu.
     * Výstup: `?float` – platná číselná teplota v °C nebo null při nedostupnosti.
     *
     * @param mixed $value aktuální hodnota metriky nebo atributu
     * @return ?float platná číselná teplota v °C nebo null při nedostupnosti
     */
    private static function number(mixed $value): ?float {
        return is_numeric($value) && !is_bool($value) && is_finite((float)$value) ? (float)$value : null;
    }
}
