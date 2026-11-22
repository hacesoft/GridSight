<?php

/**
 * Hacesoft GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-21 – bezpečné sjednocení interních lokálních proměnných.
 * Historie: 2026-11-22 – ověření typů, významových komentářů a shody názvů vlastností.
 * s – textový řetězec / identifikátor nebo cesta.
 * a – pole / seznam.
 * v – smíšená nebo zatím neurčená hodnota.
 * Výjimka: názvy parametrů PHP metod a veřejných vlastností se nemění,
 * protože jsou součástí rozhraní Nextcloud, DI a pojmenovaných argumentů.
 */

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

/** Only the LINEA Shelly input explicitly named "Noční proud" is HDO evidence. */
final class ShellyHdo {
    /**
     * Určí stav pojmenovaného Shelly vstupu „Noční proud“ jen z aktuálních jednoznačných dat.
     *
     * Vstupy: `$status` (array) – strukturovaná stavová odpověď LINEA; `$observedAt` (?int) – čas, ke kterému má být Shelly informace považována za aktuální (unixové sekundy).
     * Výstup: `?bool` – stav jediného aktuálního vstupu Shelly „Noční proud“; null při nejednoznačnosti či nedostupnosti.
     *
     * @param array $status strukturovaná stavová odpověď LINEA
     * @param ?int $observedAt čas, ke kterému má být Shelly informace považována za aktuální (unixové sekundy)
     * @return ?bool stav jediného aktuálního vstupu Shelly „Noční proud“; null při nejednoznačnosti či nedostupnosti
     */
    public static function inputState(array $status, ?int $observedAt = null): ?bool {
        if (($status['system']['stale'] ?? false) === true || ($status['shelly']['available'] ?? false) !== true) return null;
        $vData = $status['shelly']['data'] ?? null;
        if (!is_array($vData) || !is_array($vData['devices'] ?? null)) return null;
        if (isset($vData['updatedAt'])) {
            $vUpdated = is_string($vData['updatedAt']) ? strtotime($vData['updatedAt']) : false;
            if ($vUpdated === false || abs(($observedAt ?? time()) - $vUpdated) > 120) return null;
        }
        $aMatches = [];
        foreach ($vData['devices'] as $vDevice) {
            if (!is_array($vDevice) || ($vDevice['available'] ?? true) === false
                || strtolower((string)($vDevice['kind'] ?? '')) !== 'input'
                || (string)($vDevice['channel'] ?? '') !== '0') continue;
            $sName = mb_strtolower(trim((string)($vDevice['name'] ?? '')));
            $sName = strtr($sName, ['č'=>'c','í'=>'i','š'=>'s','ř'=>'r','ě'=>'e','ý'=>'y','á'=>'a','é'=>'e','ů'=>'u','ú'=>'u','ž'=>'z','ň'=>'n']);
            $sName = preg_replace('/[\s_-]+/u', ' ', $sName);
            if ($sName !== 'nocni proud') continue;
            $vState = $vDevice['state'] ?? null;
            if (is_bool($vState)) $aMatches[] = $vState;
            elseif ($vState === 1 || $vState === 0) $aMatches[] = $vState === 1;
        }
        return count($aMatches) === 1 ? $aMatches[0] : null;
    }
}
