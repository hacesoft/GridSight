<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

/** Only the LINEA Shelly input explicitly named "Noční proud" is HDO evidence. */
final class ShellyHdo {
    public static function inputState(array $status, ?int $observedAt = null): ?bool {
        if (($status['system']['stale'] ?? false) === true || ($status['shelly']['available'] ?? false) !== true) return null;
        $data = $status['shelly']['data'] ?? null;
        if (!is_array($data) || !is_array($data['devices'] ?? null)) return null;
        if (isset($data['updatedAt'])) {
            $updated = is_string($data['updatedAt']) ? strtotime($data['updatedAt']) : false;
            if ($updated === false || abs(($observedAt ?? time()) - $updated) > 120) return null;
        }
        $matches = [];
        foreach ($data['devices'] as $device) {
            if (!is_array($device) || ($device['available'] ?? true) === false
                || strtolower((string)($device['kind'] ?? '')) !== 'input'
                || (string)($device['channel'] ?? '') !== '0') continue;
            $name = mb_strtolower(trim((string)($device['name'] ?? '')));
            $name = strtr($name, ['č'=>'c','í'=>'i','š'=>'s','ř'=>'r','ě'=>'e','ý'=>'y','á'=>'a','é'=>'e','ů'=>'u','ú'=>'u','ž'=>'z','ň'=>'n']);
            $name = preg_replace('/[\s_-]+/u', ' ', $name);
            if ($name !== 'nocni proud') continue;
            $state = $device['state'] ?? null;
            if (is_bool($state)) $matches[] = $state;
            elseif ($state === 1 || $state === 0) $matches[] = $state === 1;
        }
        return count($matches) === 1 ? $matches[0] : null;
    }
}
