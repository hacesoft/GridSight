<?php

/**
 * Hacesoft GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-21 – bezpečné sjednocení interních lokálních proměnných.
 * Historie: 2026-11-22 – ověření typů, významových komentářů a shody názvů vlastností.
 * s – textový řetězec / identifikátor nebo cesta.
 * n – číslo (celé, desetinné, čas nebo jednotky).
 * b – logická hodnota.
 * a – pole / seznam.
 * o – instance objektu nebo výjimka.
 * v – smíšená nebo zatím neurčená hodnota.
 * Výjimka: názvy parametrů PHP metod a veřejných vlastností se nemění,
 * protože jsou součástí rozhraní Nextcloud, DI a pojmenovaných argumentů.
 */

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

use OCA\HcGridSight\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;

/** Household billing inputs and the distributor's independent 15-minute measurements. */
final class EnergyAnalysisService {
    private const XML_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const ZONE = 'Europe/Prague';

    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: `$db` (IDBConnection) – databázové spojení Nextcloudu; `$config` (IConfig) – konfigurace aplikace Nextcloud; `$history` (HistoryService) – přístup k historii a sběrači; `$appData` (\OCP\Files\IAppData) – neveřejné aplikační úložiště Nextcloud.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param IDBConnection $db databázové spojení Nextcloudu
     * @param IConfig $config konfigurace aplikace Nextcloud
     * @param HistoryService $history přístup k historii a sběrači
     * @param \OCP\Files\IAppData $appData neveřejné aplikační úložiště Nextcloud
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
        private HistoryService $history,
        private \OCP\Files\IAppData $appData,
    ) {}

    /**
     * Načte uložený profil odběrného místa a historii cen.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `array` – konfigurační pole odběrného místa, dodavatelů, cen a HDO.
     * @return array konfigurační pole odběrného místa, dodavatelů, cen a HDO
     */
    public function profile(): array {
        $vRaw = json_decode($this->config->getAppValue(Application::APP_ID, 'analysis_profile', '{}'), true);
        return is_array($vRaw) ? $vRaw : [];
    }

    /**
     * Ověří a uloží údaje odběrného místa, smluv, cen a tarifů.
     *
     * Vstupy: `$input` (array) – vstupní pole profilu smluv, cen a HDO.
     * Výstup: `array` – normalizovaný a ověřený profil uložený do konfigurace aplikace.
     *
     * @param array $input vstupní pole profilu smluv, cen a HDO
     * @return array normalizovaný a ověřený profil uložený do konfigurace aplikace
     */
    public function saveProfile(array $input): array {
        $aStrings = [
            'ean'=>18, 'distributor'=>80, 'usage'=>80, 'meterType'=>16,
            'distributionTariff'=>16, 'mainBreaker'=>24,
        ];
        $profile = [];
        foreach ($aStrings as $sKey => $vLimit) {
            $vValue = trim((string)($input[$sKey] ?? ''));
            if (mb_strlen($vValue) > $vLimit) throw new \InvalidArgumentException('Invalid profile field: ' . $sKey);
            $profile[$sKey] = $vValue;
        }
        if ($profile['ean'] !== '' && !preg_match('/^\d{18}$/D', $profile['ean'])) {
            throw new \InvalidArgumentException('EAN must contain 18 digits.');
        }
        $oQb = $this->db->getQueryBuilder();
        $vExistingEan = $oQb->select('ean')->from('hc_gridsight_edg_reports')
            ->setMaxResults(1)->executeQuery()->fetchOne();
        if ($vExistingEan !== false && $profile['ean'] !== (string)$vExistingEan) {
            throw new \InvalidArgumentException('The connection EAN must match the already imported reports.');
        }
        $aContracts = $input['contracts'] ?? [];
        if (!is_array($aContracts) || count($aContracts) > 20) throw new \InvalidArgumentException('Invalid contract history.');
        $profile['contracts'] = [];
        foreach ($aContracts as $vCandidate) {
            if (!is_array($vCandidate)) throw new \InvalidArgumentException('Invalid contract row.');
            $vStart = trim((string)($vCandidate['start'] ?? ''));
            $sSupplier = trim((string)($vCandidate['supplier'] ?? ''));
            $sProduct = trim((string)($vCandidate['product'] ?? ''));
            $sSourceUrl = trim((string)($vCandidate['sourceUrl'] ?? ''));
            $vMonths = $vCandidate['durationMonths'] ?? null;
            if ($sSupplier === '' && $sProduct === '' && $vStart === '' && $sSourceUrl === '') continue;
            if (mb_strlen($sSupplier) > 80 || mb_strlen($sProduct) > 160 || mb_strlen($sSourceUrl) > 512
                || ($vStart !== '' && !$this->isDate($vStart))
                || !is_numeric($vMonths) || (int)$vMonths < 1 || (int)$vMonths > 120) {
                throw new \InvalidArgumentException('Invalid contract row.');
            }
            if ($vStart === '' && count($aContracts) > 1) {
                throw new \InvalidArgumentException('Enter the supply start date before adding another contract.');
            }
            $sTo = $vStart === '' ? '' : (new \DateTimeImmutable($vStart))
                ->add(new \DateInterval('P' . (int)$vMonths . 'M'))
                ->sub(new \DateInterval('P1D'))->format('Y-m-d');
            $profile['contracts'][] = ['start'=>$vStart,'to'=>$sTo,'durationMonths'=>(int)$vMonths,
                'supplier'=>$sSupplier,'product'=>$sProduct,'sourceUrl'=>$sSourceUrl];
        }
        usort($profile['contracts'], /** Seřadí smlouvy podle data zahájení od nejstarší. Vstupy: dva záznamy smluv s poli start. Výstup: `int` – číselný součet nebo porovnávací výsledek. */ static fn(array $vA,array $vB): int => strcmp($vA['start'],$vB['start']));
        foreach ($profile['contracts'] as $nI=>$vContract) {
            if ($nI > 0 && $vContract['start'] <= $profile['contracts'][$nI-1]['to']) {
                throw new \InvalidArgumentException('Contract periods overlap.');
            }
        }
        $vLatest = $profile['contracts'] === [] ? null : $profile['contracts'][count($profile['contracts'])-1];
        $profile['supplier'] = $vLatest['supplier'] ?? '';
        $profile['product'] = $vLatest['product'] ?? '';
        $profile['contractStart'] = $vLatest['start'] ?? '';
        $profile['durationMonths'] = $vLatest['durationMonths'] ?? 36;

        $periods = $input['pricePeriods'] ?? [];
        if (!is_array($periods) || count($periods) > 40) throw new \InvalidArgumentException('Invalid price history.');
        $profile['pricePeriods'] = [];
        foreach ($periods as $vCandidate) {
            if (!is_array($vCandidate)) throw new \InvalidArgumentException('Invalid price row.');
            $sFrom = trim((string)($vCandidate['from'] ?? ''));
            $sContractStart = trim((string)($vCandidate['contractStart'] ?? ''));
            if ($sContractStart !== '') {
                $aMatches = array_filter($profile['contracts'], /** Otestuje, zda smlouva začíná k vybranému datu. Vstupy: záznam smlouvy obsahující pole start. Výstup: `bool` – true/false podle uvedené podmínky. */ static fn(array $vContract): bool => $vContract['start'] === $sContractStart);
                if ($aMatches === []) throw new \InvalidArgumentException('Select an existing contract for the price row.');
            }
            if ($sFrom === '') {
                if ($sContractStart === '' && count($profile['contracts']) === 1) {
                    $sContractStart = $profile['contracts'][0]['start'];
                }
                $sFrom = $sContractStart;
            }
            if (!$this->isDate($sFrom)) throw new \InvalidArgumentException('Enter a valid price start date.');
            if ($sContractStart !== '' && $sFrom < $sContractStart) {
                throw new \InvalidArgumentException('Price cannot start before its contract.');
            }
            $aPrice = ['from'=>$sFrom,'contractStart'=>$sContractStart];
            foreach (['vtCzkKWh','ntCzkKWh','fixedCzkMonth','greenCzkKWh'] as $sKey) {
                $vRaw = $vCandidate[$sKey] ?? null;
                if ($vRaw === null || $vRaw === '' || !is_numeric($vRaw) || !is_finite((float)$vRaw)
                    || (float)$vRaw < 0 || (float)$vRaw > 100000) {
                    throw new \InvalidArgumentException('Invalid price in period ' . $sFrom . ': ' . $sKey);
                }
                $aPrice[$sKey] = round((float)$vRaw, 5);
            }
            if (!is_bool($vCandidate['greenEnabled'] ?? null)) {
                throw new \InvalidArgumentException('Invalid green electricity selection in ' . $sFrom);
            }
            $aPrice['greenEnabled'] = $vCandidate['greenEnabled'];
            $aPrice['sourceUrl'] = trim((string)($vCandidate['sourceUrl'] ?? ''));
            if (mb_strlen($aPrice['sourceUrl']) > 512) throw new \InvalidArgumentException('Price list URL is too long.');
            // A combined price includes annual regulated items. Never silently
            // carry the 2026 full tariff into 2027 without a new tariff row.
            $sYearEnd = substr($sFrom,0,4) . '-12-31';
            $sOldTo = trim((string)($vCandidate['to'] ?? ''));
            $aPrice['to'] = $sOldTo !== '' && $this->isDate($sOldTo) && $sOldTo < $sYearEnd ? $sOldTo : $sYearEnd;
            if ($aPrice['to'] < $sFrom) throw new \InvalidArgumentException('Price end precedes its start: ' . $sFrom);
            $profile['pricePeriods'][] = $aPrice;
        }
        usort($profile['pricePeriods'],/** Porovná cenové intervaly podle začátku jejich účinnosti. Vstupy: dva záznamy ceníkových intervalů s polem from. Výstup: `int` – číselný součet nebo porovnávací výsledek. */ static fn(array $vA,array $vB): int => strcmp($vA['from'],$vB['from']));
        foreach ($profile['pricePeriods'] as $nI=>&$aPrice) {
            if ($nI === 0) continue;
            $vPrior = &$profile['pricePeriods'][$nI-1];
            if ($aPrice['from'] <= $vPrior['from']) throw new \InvalidArgumentException('Duplicate price start date.');
            if ($aPrice['from'] <= $vPrior['to']) {
                $vPrior['to'] = (new \DateTimeImmutable($aPrice['from']))->modify('-1 day')->format('Y-m-d');
            }
            unset($vPrior);
        }
        unset($aPrice);
        $vCurrentPrice = $profile['pricePeriods'] === [] ? null : $profile['pricePeriods'][count($profile['pricePeriods'])-1];
        foreach (['vtCzkKWh','ntCzkKWh','fixedCzkMonth','greenCzkKWh','greenEnabled','sourceUrl'] as $sKey) {
            $profile[$sKey] = $vCurrentPrice[$sKey] ?? ($sKey === 'greenEnabled' ? false : '');
        }
        $profile['priceValidFrom'] = $vCurrentPrice['from'] ?? '';
        $profile['priceValidTo'] = $vCurrentPrice['to'] ?? '';
        $vSchedules = $input['hdoSchedules'] ?? [];
        if (!is_array($vSchedules) || count($vSchedules) > 12) {
            throw new \InvalidArgumentException('Invalid HDO schedules.');
        }
        $profile['hdoSchedules'] = [];
        foreach ($vSchedules as $schedule) {
            if (!is_array($schedule)) throw new \InvalidArgumentException('Invalid HDO schedule.');
            $sFrom = (string)($schedule['from'] ?? '');
            $sTo = (string)($schedule['to'] ?? '');
            if (!$this->isDate($sFrom) || !$this->isDate($sTo) || $sFrom > $sTo) {
                throw new \InvalidArgumentException('Invalid HDO date range.');
            }
            $vWindows = $schedule['ntWindows'] ?? [];
            if (!is_array($vWindows) || count($vWindows) > 24) throw new \InvalidArgumentException('Invalid HDO windows.');
            $aClean = [];
            foreach ($vWindows as $nWindow) {
                if (!is_array($nWindow) || count($nWindow) !== 2
                    || !is_int($nWindow[0]) || !is_int($nWindow[1])
                    || $nWindow[0] < 0 || $nWindow[1] > 1440 || $nWindow[0] >= $nWindow[1]) {
                    throw new \InvalidArgumentException('HDO windows must be minute pairs within one day.');
                }
                $aClean[] = [$nWindow[0], $nWindow[1]];
            }
            usort($aClean, /** Seřadí dvojice začátek/konec NT okna chronologicky. Vstupy: dvě dvojice hranic časových oken. Výstup: `int` – číselný součet nebo porovnávací výsledek. */ static fn(array $vA, array $vB): int => $vA[0] <=> $vB[0]);
            for ($nI = 1; $nI < count($aClean); $nI++) {
                if ($aClean[$nI][0] < $aClean[$nI-1][1]) throw new \InvalidArgumentException('Overlapping HDO windows.');
            }
            foreach ($profile['hdoSchedules'] as $vOld) {
                if ($sFrom <= $vOld['to'] && $sTo >= $vOld['from']) {
                    throw new \InvalidArgumentException('Overlapping HDO validity periods.');
                }
            }
            $profile['hdoSchedules'][] = ['from'=>$sFrom,'to'=>$sTo,'ntWindows'=>$aClean];
        }
        $this->config->setAppValue(Application::APP_ID, 'analysis_profile', json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $profile;
    }

    /**
     * Ověří, že řetězec představuje skutečné datum YYYY-MM-DD.
     *
     * Vstupy: `$date` (string) – datum YYYY-MM-DD nebo datový objekt dle typu.
     * Výstup: `bool` – true jen pro platný kalendářní den ve formátu YYYY-MM-DD.
     *
     * @param string $date datum YYYY-MM-DD nebo datový objekt dle typu
     * @return bool true jen pro platný kalendářní den ve formátu YYYY-MM-DD
     */
    private function isDate(string $date): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $vM)) return false;
        return checkdate((int)$vM[2], (int)$vM[3], (int)$vM[1]);
    }

    /** Parse the specific ED.G ICC1/ICQ2/ISC1/ISQ2 export, not arbitrary spreadsheets. */
    /**
     * Bezpečně načte předepsaný XLSX výkaz EG.D, zkontroluje strukturu a uloží 15minutová měření.
     *
     * Vstupy: `$path` (string) – relativní cesta API nebo úplná cesta k XLSX souboru podle metody; `$filename` (string) – původní název nahraného XLSX souboru.
     * Výstup: `array` – souhrn importu měsíčního XLSX výkazu EG.D včetně měření a EAN.
     *
     * @param string $path relativní cesta API nebo úplná cesta k XLSX souboru podle metody
     * @param string $filename původní název nahraného XLSX souboru
     * @return array souhrn importu měsíčního XLSX výkazu EG.D včetně měření a EAN
     */
    public function importReport(string $path, string $filename): array {
        if (!preg_match('/\.xlsx$/iD', $filename) || !is_file($path) || filesize($path) > 5_000_000) {
            throw new \InvalidArgumentException('Select an ED.G .xlsx report of at most 5 MB.');
        }
        $oZip = new \ZipArchive();
        if ($oZip->open($path) !== true) throw new \InvalidArgumentException('Invalid XLSX archive.');
        try {
            if ($oZip->numFiles > 64) throw new \InvalidArgumentException('Unexpected XLSX structure.');
            foreach (['xl/sharedStrings.xml'=>2_000_000, 'xl/worksheets/sheet1.xml'=>8_000_000] as $sName=>$vLimit) {
                $vStat = $oZip->statName($sName);
                if (!is_array($vStat) || $vStat['size'] > $vLimit) {
                    throw new \InvalidArgumentException('Unsupported or oversized ED.G worksheet.');
                }
            }
            // Explicit namespace-aware XPath avoids relying on SimpleXML's
            // implicit default-namespace traversal for role/header cells.
            $oStringDocument = new \DOMDocument();
            $oSheetDocument = new \DOMDocument();
            $vSharedXml = $oZip->getFromName('xl/sharedStrings.xml');
            $vSheetXml = $oZip->getFromName('xl/worksheets/sheet1.xml');
            if (!is_string($vSharedXml) || $vSharedXml === '' || !is_string($vSheetXml) || $vSheetXml === ''
                || !@$oStringDocument->loadXML($vSharedXml, LIBXML_NONET)
                || !@$oSheetDocument->loadXML($vSheetXml, LIBXML_NONET)) {
                throw new \InvalidArgumentException('Unreadable ED.G worksheet.');
            }
            $oStringXPath = new \DOMXPath($oStringDocument);
            $oSheetXPath = new \DOMXPath($oSheetDocument);
            $oStringXPath->registerNamespace('x', self::XML_NS);
            $oSheetXPath->registerNamespace('x', self::XML_NS);
            $aStrings = [];
            foreach ($oStringXPath->query('/x:sst/x:si') as $oEntry) {
                $vValue = '';
                foreach ($oStringXPath->query('.//x:t', $oEntry) as $oText) $vValue .= $oText->textContent;
                $aStrings[] = $vValue;
            }
            $ean = '';
            $aRoleColumns = [];
            $aDescriptionColumns = [];
            $aObservedHeader = [];
            $aUnitHeaders = [];
            $vImportColumn = null;
            $vExportColumn = null;
            $period = '';
            $aIntervals = [];
            $nFactor = 1_000_000;
            $nFirstEpoch = 0;
            $nEndEpoch = 0;
            $oZone = new \DateTimeZone(self::ZONE);
            foreach ($oSheetXPath->query('/x:worksheet/x:sheetData/x:row') as $oRow) {
                $nRowNumber = (int)$oRow->getAttribute('r');
                $aCells = [];
                foreach ($oSheetXPath->query('./x:c', $oRow) as $oCell) {
                    $sName = $oCell->getAttribute('r');
                    if (!preg_match('/^([A-Z]{1,2})\d+$/D', $sName, $vM)) continue;
                    $vValue = $oSheetXPath->query('./x:v', $oCell)->item(0)?->textContent ?? '';
                    $aCells[$vM[1]] = $oCell->getAttribute('t') === 's' ? ($aStrings[(int)$vValue] ?? '') : $vValue;
                }
                if ($nRowNumber === 1) {
                    if (($aCells['B'] ?? '') !== 'EAN' || !preg_match('/^\d{18}$/D', $aCells['C'] ?? '')) {
                        throw new \InvalidArgumentException('Missing or invalid EAN in the ED.G report.');
                    }
                    $ean = $aCells['C'];
                }
                if ($nRowNumber <= 4) {
                    if ($nRowNumber === 3) $aObservedHeader = $aCells;
                    foreach ($aCells as $vColumn => $sRawLabel) {
                        $sLabel = trim((string)$sRawLabel);
                        if (in_array($sLabel, ['ICQ2','ISQ2','DCC1','DSC1'], true)) {
                            if (isset($aRoleColumns[$sLabel]) && $aRoleColumns[$sLabel] !== $vColumn) {
                                throw new \InvalidArgumentException('Duplicate ED.G energy role: ' . $sLabel);
                            }
                            $aRoleColumns[$sLabel] = $vColumn;
                        }
                        if ($nRowNumber !== 4) continue;
                        $aUnitHeaders[$vColumn] = mb_strtolower($sLabel);
                        $sDescription = mb_strtolower((string)preg_replace('/\s+/u', ' ', $sLabel));
                        if (str_contains($sDescription, 'energie spotřeby ze sítě') && str_contains($sDescription, 'kwh')) {
                            $aDescriptionColumns['ICQ2'] = $vColumn;
                        }
                        if (str_contains($sDescription, 'energie dodávky do sítě') && str_contains($sDescription, 'kwh')) {
                            $aDescriptionColumns['ISQ2'] = $vColumn;
                        }
                    }
                }
                if ($nRowNumber < 5) continue;
                if (count($aIntervals) > 4000) throw new \InvalidArgumentException('Too many ED.G intervals.');
                if ($vImportColumn === null || $vExportColumn === null) {
                    if (isset($aRoleColumns['DCC1'], $aRoleColumns['DSC1'])) {
                        foreach (['DCC1','DSC1'] as $vRole) {
                            if (!str_contains($aUnitHeaders[$aRoleColumns[$vRole]] ?? '', '(kw)') || !str_contains($aUnitHeaders[$aRoleColumns[$vRole]] ?? '', 'fakturaci')) {
                                throw new \InvalidArgumentException('Unsupported billed power unit.');
                            }
                        }
                        $aDescriptionColumns = [];
                        $aRoleColumns = ['ICQ2'=>$aRoleColumns['DCC1'], 'ISQ2'=>$aRoleColumns['DSC1']];
                        $nFactor = 250_000; // billed kW averaged over a quarter hour -> milliWh
                    }
                    $aColumns = isset($aRoleColumns['ICQ2'], $aRoleColumns['ISQ2']) ? $aRoleColumns : $aDescriptionColumns;
                    $vImportColumn = $aColumns['ICQ2'] ?? null;
                    $vExportColumn = $aColumns['ISQ2'] ?? null;
                    if ($vImportColumn === null || $vExportColumn === null || $vImportColumn === $vExportColumn) {
                        $aLabels = [];
                        foreach (['B','C','D','E'] as $sKey) {
                            $aLabels[] = $sKey . '=' . substr(trim((string)($aObservedHeader[$sKey] ?? '')), 0, 30);
                        }
                        throw new \InvalidArgumentException('ED.G energy roles ICQ2/ISQ2 not found. Row 3: ' . implode(', ', $aLabels));
                    }
                    if (isset($aDescriptionColumns['ICQ2'], $aDescriptionColumns['ISQ2'])
                        && ($aDescriptionColumns['ICQ2'] !== $vImportColumn || $aDescriptionColumns['ISQ2'] !== $vExportColumn)) {
                        throw new \InvalidArgumentException('ED.G energy roles conflict with column descriptions.');
                    }
                }
                $vLocal = $aCells['A'] ?? '';
                if (!preg_match('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}$/D', $vLocal)) {
                    throw new \InvalidArgumentException('Invalid date at row ' . $nRowNumber);
                }
                if ($period === '') {
                    $period = substr($vLocal, 6, 4) . '-' . substr($vLocal, 3, 2);
                    if (!$this->isDate($period . '-01')) throw new \InvalidArgumentException('Invalid report month.');
                    $nFirstEpoch = (new \DateTimeImmutable($period . '-01 00:00:00', $oZone))->getTimestamp();
                    $nEndEpoch = (new \DateTimeImmutable($period . '-01 00:00:00', $oZone))->modify('+1 month')->getTimestamp();
                }
                $vExpected = $nFirstEpoch + count($aIntervals) * 900;
                if ($vExpected >= $nEndEpoch || (new \DateTimeImmutable('@' . $vExpected))->setTimezone($oZone)->format('d.m.Y H:i:s') !== $vLocal) {
                    throw new \InvalidArgumentException('Missing, repeated or out-of-order quarter hour at row ' . $nRowNumber);
                }
                foreach ([$vImportColumn,$vExportColumn] as $sKey) {
                    $vNumberValue = $aCells[$sKey] ?? '';
                    if (!is_numeric($vNumberValue) || !is_finite((float)$vNumberValue)
                        || (float)$vNumberValue < 0 || (float)$vNumberValue > 100000) {
                        throw new \InvalidArgumentException('Invalid energy in column ' . $sKey . ' at row ' . $nRowNumber);
                    }
                }
                $aIntervals[] = ['ts'=>$vExpected,
                    'import_mwh'=>(int)round((float)$aCells[$vImportColumn] * $nFactor),
                    'export_mwh'=>(int)round((float)$aCells[$vExportColumn] * 1_000_000)];
            }
            if ($ean === '' || $period === '' || $nFirstEpoch + count($aIntervals) * 900 !== $nEndEpoch) {
                throw new \InvalidArgumentException('The ED.G report does not contain a complete calendar month.');
            }
        } finally {
            $oZip->close();
        }
        $configuredEan = $this->profile()['ean'] ?? '';
        if ($configuredEan !== '' && $configuredEan !== $ean) {
            throw new \InvalidArgumentException('The report EAN does not match this connection.');
        }
        $oQb = $this->db->getQueryBuilder();
        $vStoredEan = $oQb->select('ean')->from('hc_gridsight_edg_reports')
            ->setMaxResults(1)->executeQuery()->fetchOne();
        if ($vStoredEan !== false && (string)$vStoredEan !== $ean) {
            throw new \InvalidArgumentException('The report EAN differs from previously imported months.');
        }
        // ED.G usually puts the billing month at the end of REP_DATA_..._YYYY-MM.xlsx.
        // Rows remain authoritative; the filename also guards against a
        // misnamed file being imported into the wrong month.
        if (preg_match('/(?:^|_)(\d{4})-(0[1-9]|1[0-2])(?:\(\d+\))?\.xlsx$/iD', $filename, $sFilenameDate)
            && $period !== $sFilenameDate[1] . '-' . $sFilenameDate[2]) {
            throw new \InvalidArgumentException('The report filename month does not match its worksheet.');
        }
        $sHash = hash_file('sha256', $path);
        $vPrevious = $this->report($period);
        $sSourceName = $period . '-' . $sHash . '.xlsx';
        $vFolder = $this->reportFolder();
        if (!$vFolder->fileExists($sSourceName)) $vFolder->newFile($sSourceName)->putContent(file_get_contents($path));
        if ($vPrevious && $vPrevious['sha256'] === $sHash) {
            $this->rememberReportEan($ean, $configuredEan);
            return ['period'=>$period,'rows'=>count($aIntervals),'unchanged'=>true];
        }
        $nTotalImport = array_sum(array_column($aIntervals, 'import_mwh'));
        $nTotalExport = array_sum(array_column($aIntervals, 'export_mwh'));
        $this->db->beginTransaction();
        try {
            if ($vPrevious) {
                $oQb = $this->db->getQueryBuilder();
                $oQb->delete('hc_gridsight_edg_intervals')
                    ->where($oQb->expr()->eq('period',$oQb->createNamedParameter($period)))->executeStatement();
                $oQb = $this->db->getQueryBuilder();
                $oQb->delete('hc_gridsight_edg_reports')
                    ->where($oQb->expr()->eq('period',$oQb->createNamedParameter($period)))->executeStatement();
            }
            $oQb = $this->db->getQueryBuilder();
            $oQb->insert('hc_gridsight_edg_reports');
            foreach (['period'=>$period,'ean'=>$ean,'sha256'=>$sHash,'row_count'=>count($aIntervals),
                      'import_mwh'=>$nTotalImport,'export_mwh'=>$nTotalExport,'imported_at'=>time()] as $vColumn=>$vValue) {
                $oQb->setValue($vColumn,$oQb->createNamedParameter($vValue));
            }
            $oQb->executeStatement();
            foreach ($aIntervals as $vInterval) {
                $oQb = $this->db->getQueryBuilder();
                $oQb->insert('hc_gridsight_edg_intervals')
                    ->setValue('period',$oQb->createNamedParameter($period))
                    ->setValue('ts',$oQb->createNamedParameter($vInterval['ts']))
                    ->setValue('import_mwh',$oQb->createNamedParameter($vInterval['import_mwh']))
                    ->setValue('export_mwh',$oQb->createNamedParameter($vInterval['export_mwh']))
                    ->executeStatement();
            }
            $this->db->commit();
        } catch (\Throwable $sError) {
            $this->db->rollBack();
            throw $sError;
        }
        if ($vPrevious && $vPrevious['sha256'] !== $sHash) {
            $sOldName=$period.'-'.$vPrevious['sha256'].'.xlsx';
            try { if ($vFolder->fileExists($sOldName)) $vFolder->getFile($sOldName)->delete(); }
            catch (\OCP\Files\NotFoundException $oE) { /* Original already absent. */ }
        }
        $this->rememberReportEan($ean, $configuredEan);
        return ['period'=>$period,'rows'=>count($aIntervals),'importKWh'=>$nTotalImport/1_000_000,
            'exportKWh'=>$nTotalExport/1_000_000,'unchanged'=>false];
    }


    /**
     * Najde nebo vytvoří neveřejnou složku AppData pro originály měření EG.D.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `\OCP\Files\SimpleFS\ISimpleFolder` – soukromá složka AppData pro původní výkazy EG.D.
     * @return \OCP\Files\SimpleFS\ISimpleFolder soukromá složka AppData pro původní výkazy EG.D
     */
    private function reportFolder(): \OCP\Files\SimpleFS\ISimpleFolder {
        try { return $this->appData->getFolder('edg-reports'); }
        catch (\OCP\Files\NotFoundException $oE) { return $this->appData->newFolder('edg-reports'); }
    }

    /**
     * Odstraní vybraný importovaný report EG.D a jeho intervaly bez zásahu do historie LINEA.
     *
     * Vstupy: `$period` (string) – měsíc reportu ve tvaru YYYY-MM; prázdné znamená výchozí výběr.
     * Výstup: `void` – smaže zvolený import EG.D; vrátí JSON potvrzení nebo chybu.
     *
     * @param string $period měsíc reportu ve tvaru YYYY-MM; prázdné znamená výchozí výběr
     * @return void smaže zvolený import EG.D; vrátí JSON potvrzení nebo chybu
     */
    public function deleteReport(string $period): void {
        $vRecord = $this->report($period);
        if (!$vRecord) throw new \InvalidArgumentException('Unknown report.');
        $this->db->beginTransaction();
        try {
            foreach (['hc_gridsight_edg_intervals','hc_gridsight_edg_reports'] as $oTable) {
                $oQb=$this->db->getQueryBuilder();
                $oQb->delete($oTable)->where($oQb->expr()->eq('period',$oQb->createNamedParameter($period)))->executeStatement();
            }
            $this->db->commit();
        } catch (\Throwable $oE) { $this->db->rollBack(); throw $oE; }
        $vFolder=$this->reportFolder(); $sName=$period.'-'.$vRecord['sha256'].'.xlsx';
        if ($vFolder->fileExists($sName)) $vFolder->getFile($sName)->delete();
    }

    /**
     * Připraví export vybraných měsíčních reportů EG.D do ZIP archivu.
     *
     * Vstupy: `$periods` (array) – seznam měsíců YYYY-MM určených k exportu.
     * Výstup: `string` – vrátí ZIP odpověď s vybranými reporty EG.D.
     *
     * @param array $periods seznam měsíců YYYY-MM určených k exportu
     * @return string vrátí ZIP odpověď s vybranými reporty EG.D
     */
    public function exportReports(array $periods): string {
        $periods=array_values(array_unique($periods));
        if (count($periods)<1 || count($periods)>24) throw new \InvalidArgumentException('Select 1–24 reports.');
        $path=tempnam(sys_get_temp_dir(),'gridsight-'); $oZip=new \ZipArchive();
        try {
            if ($oZip->open($path,\ZipArchive::OVERWRITE)!==true) throw new \RuntimeException('Cannot create ZIP.');
            $aManifest=[]; $vBytes=0;
            foreach ($periods as $period) {
                if (!is_string($period) || !preg_match('/^\d{4}-\d{2}$/D',$period)) throw new \InvalidArgumentException('Invalid month.');
                $vRecord=$this->report($period);
                if (!$vRecord) throw new \InvalidArgumentException('Unknown report: '.$period);
                $oQb=$this->db->getQueryBuilder();
                $aRows=$oQb->select('ts','import_mwh','export_mwh')->from('hc_gridsight_edg_intervals')
                    ->where($oQb->expr()->eq('period',$oQb->createNamedParameter($period)))->orderBy('ts','ASC')->executeQuery()->fetchAllAssociative();
                $vCsv="timestamp_utc,local_time,import_kwh,export_kwh\n";
                foreach ($aRows as $oRow) {
                    $date=new \DateTimeImmutable('@'.$oRow['ts']);
                    $vCsv.=$date->format('c').','.$date->setTimezone(new \DateTimeZone(self::ZONE))->format('c').','.
                        number_format((int)$oRow['import_mwh']/1_000_000,6,'.','').','.number_format((int)$oRow['export_mwh']/1_000_000,6,'.','')."\n";
                }
                $oZip->addFromString($period.'/intervals.csv',$vCsv); $vBytes+=strlen($vCsv);
                $vFolder=$this->reportFolder(); $sName=$period.'-'.$vRecord['sha256'].'.xlsx';
                $vRecord['originalIncluded']=$vFolder->fileExists($sName);
                if ($vBytes>25_000_000) throw new \InvalidArgumentException('Export exceeds 25 MB. Select fewer months.');
                if ($vRecord['originalIncluded']) {
                    $vContent=$vFolder->getFile($sName)->getContent(); $vBytes+=strlen($vContent);
                    if ($vBytes>25_000_000) throw new \InvalidArgumentException('Export exceeds 25 MB. Select fewer months.');
                    $oZip->addFromString($period.'/original.xlsx',$vContent);
                }
                $aManifest[]=$vRecord;
            }
            $oZip->addFromString('manifest.json',json_encode(['version'=>1,'timezone'=>self::ZONE,'databaseEnergyUnit'=>'milliWh','reports'=>$aManifest],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            $oZip->close(); return (string)file_get_contents($path);
        } finally { if (is_file($path)) unlink($path); }
    }

    /**
     * Ověří a uloží ruční vyúčtování prodeje Delta Green.
     *
     * Vstupy: `$statement` (array) – položky ručního vyúčtování Delta Green.
     * Výstup: `array` – ověřené a uložené ruční vyúčtování prodeje energie.
     *
     * @param array $statement položky ručního vyúčtování Delta Green
     * @return array ověřené a uložené ruční vyúčtování prodeje energie
     */
    public function saveStatement(array $statement): array {
        $sFrom=(string)($statement['from']??''); $sTo=(string)($statement['to']??'');
        $vAmount=$statement['amountCzk']??null;
        if (!$this->isDate($sFrom)||!$this->isDate($sTo)||$sFrom>$sTo || !is_numeric($vAmount)||!is_finite((float)$vAmount)||abs((float)$vAmount)>10000000 || (new \DateTimeImmutable($sFrom))->diff(new \DateTimeImmutable($sTo))->days>366)
            throw new \InvalidArgumentException('Enter valid dates and total payment in CZK.');
        $ean=trim((string)($statement['saleEan']??''));
        if ($ean!==''&&!preg_match('/^\d{18}$/D',$ean)) throw new \InvalidArgumentException('Sale EAN must have 18 digits.');
        $vDocument=trim((string)($statement['document']??''));
        if (mb_strlen($vDocument)>80) throw new \InvalidArgumentException('Document number is too long.');
        $aRows=$this->statementRows(); $id=(string)($statement['id']??'');
        if ($id==='') $id=bin2hex(random_bytes(12));
        if (!preg_match('/^[a-f0-9]{24}$/D',$id)) throw new \InvalidArgumentException('Invalid statement ID.');
        foreach ($aRows as $sOldId=>$vOld) if ($sOldId!==$id && $vOld['from']===$sFrom && $vOld['to']===$sTo && $vOld['saleEan']===$ean)
            throw new \InvalidArgumentException('This period already exists. Edit its existing row.');
        if (count($aRows)>=600&&!isset($aRows[$id])) throw new \InvalidArgumentException('Maximum 600 statements.');
        $aRows[$id]=['id'=>$id,'from'=>$sFrom,'to'=>$sTo,'amountCzk'=>round((float)$vAmount,2),'saleEan'=>$ean,'document'=>$vDocument];
        $this->config->setAppValue(Application::APP_ID,'sale_statements',json_encode($aRows,JSON_THROW_ON_ERROR));
        return $aRows[$id];
    }

    /**
     * Načte uložené řádky ručních vyúčtování.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `array` – mapa jednotlivých ručních vyúčtování uložených v konfiguraci.
     * @return array mapa jednotlivých ručních vyúčtování uložených v konfiguraci
     */
    private function statementRows(): array {
        $aRows=json_decode($this->config->getAppValue(Application::APP_ID,'sale_statements','{}'),true);
        return is_array($aRows)?$aRows:[];
    }

    /**
     * Odstraní vybrané vyúčtování prodeje Delta Green.
     *
     * Vstupy: `$id` (string) – význam parametru podle rozhraní metody.
     * Výstup: `void` – odstraní záznam vyúčtování podle identifikátoru; nevrací hodnotu.
     *
     * @param string $id význam parametru podle rozhraní metody
     * @return void odstraní záznam vyúčtování podle identifikátoru; nevrací hodnotu
     */
    public function deleteStatement(string $id): void {
        $aRows=$this->statementRows();
        if (!isset($aRows[$id])) throw new \InvalidArgumentException('Unknown statement.');
        unset($aRows[$id]); $this->config->setAppValue(Application::APP_ID,'sale_statements',json_encode($aRows,JSON_THROW_ON_ERROR));
    }

    /**
     * Vrátí přehled uložených vyúčtování prodeje.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `array` – seznam vyúčtování s doplněným pokrytím historie a porovnáním prodeje.
     * @return array seznam vyúčtování s doplněným pokrytím historie a porovnáním prodeje
     */
    private function statements(): array {
        $aRows=array_values($this->statementRows());
        foreach ($aRows as &$oRow) {
            $oQb=$this->db->getQueryBuilder();
            $vDaily=$oQb->select('*')->from('hc_gridsight_daily')->where($oQb->expr()->gte('day',$oQb->createNamedParameter($oRow['from'])))
                ->andWhere($oQb->expr()->lte('day',$oQb->createNamedParameter($oRow['to'])))->executeQuery()->fetchAllAssociative();
            $oZone=new \DateTimeZone(self::ZONE); $vStart=new \DateTimeImmutable($oRow['from'],$oZone);
            $oEnd=(new \DateTimeImmutable($oRow['to'],$oZone))->modify('+1 day');
            $nExpectedDays=(int)$vStart->diff($oEnd)->days; $bComplete=count($vDaily)===$nExpectedDays; $nSale=0; $nCoverage=0;
            foreach ($vDaily as $sDay) {
                $date=new \DateTimeImmutable($sDay['day'],$oZone); $nSeconds=$date->modify('+1 day')->getTimestamp()-$date->getTimestamp();
                if ((int)$sDay['coverage_sec']<$nSeconds*.995 || (int)$sDay['unpriced_export_mwh']>0) $bComplete=false;
                $nCoverage+=(int)$sDay['coverage_sec']; $nSale+=(int)$sDay['sale_microczk'];
            }
             $oQb=$this->db->getQueryBuilder();
            $aIntervals=$oQb->select('ts','export_mwh')->from('hc_gridsight_edg_intervals')->where($oQb->expr()->gte('ts',$oQb->createNamedParameter($vStart->getTimestamp())))
                ->andWhere($oQb->expr()->lt('ts',$oQb->createNamedParameter($oEnd->getTimestamp())))->executeQuery()->fetchAllAssociative();
            $oRow['distributorExportKWh']=count($aIntervals)===(int)(($oEnd->getTimestamp()-$vStart->getTimestamp())/900) ? array_sum(array_column($aIntervals,'export_mwh'))/1_000_000 : null;
            $oRow['spotCzk']=$bComplete?$nSale/1_000_000:null;
            $oRow['differenceCzk']=$bComplete?$oRow['amountCzk']-$oRow['spotCzk']:null;
            $oRow['coveragePct']=round(min(100,$nCoverage/max(1,$oEnd->getTimestamp()-$vStart->getTimestamp())*100),2);
        }
        unset($oRow); usort($aRows,/** Seřadí vyúčtování sestupně podle data začátku. Vstupy: dva záznamy vyúčtování. Výstup: `int` – číselný součet nebo porovnávací výsledek. */ static fn(array $vA,array $vB):int=>strcmp($vB['from'],$vA['from'])); return $aRows;
    }

    /**
     * Při prvním importu zapamatuje EAN pro další ověřování identity odběrného místa.
     *
     * Vstupy: `$ean` (string) – EAN odběrného místa uvedený v importu; `$configuredEan` (string) – dosavadní nastavený EAN, pokud již existuje.
     * Výstup: `void` – uloží EAN z prvního importu do profilu, pokud dosud nebyl nastaven; nevrací hodnotu.
     *
     * @param string $ean EAN odběrného místa uvedený v importu
     * @param string $configuredEan dosavadní nastavený EAN, pokud již existuje
     * @return void uloží EAN z prvního importu do profilu, pokud dosud nebyl nastaven; nevrací hodnotu
     */
    private function rememberReportEan(string $ean, string $configuredEan): void {
        if ($configuredEan !== '') return;
        $profile = $this->profile();
        $profile['ean'] = $ean;
        $this->config->setAppValue(Application::APP_ID, 'analysis_profile', json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Načte detail vybraného měsíčního reportu EG.D.
     *
     * Vstupy: `$period` (string) – měsíc reportu ve tvaru YYYY-MM; prázdné znamená výchozí výběr.
     * Výstup: `?array` – záznam a metadata měsíčního výkazu EG.D, nebo null při absenci.
     *
     * @param string $period měsíc reportu ve tvaru YYYY-MM; prázdné znamená výchozí výběr
     * @return ?array záznam a metadata měsíčního výkazu EG.D, nebo null při absenci
     */
    private function report(string $period): ?array {
        $oQb = $this->db->getQueryBuilder();
        $oQb->select('*')->from('hc_gridsight_edg_reports')->where($oQb->expr()->eq('period',$oQb->createNamedParameter($period)));
        return $oQb->executeQuery()->fetchAssociative() ?: null;
    }

    /** A near-complete local day identifies which electrical level occupies ~20 hours. */
    /**
     * Odvodí polaritu vstupu Shelly HDO z většiny čtvrthodinových pozorování během dne.
     *
     * Vstupy: `$evidence` (array) – čtvrthodinové důkazy stavu Shelly HDO.
     * Výstup: `array` – důkazní pole mapování hodnoty Shelly na tarif NT/VT.
     *
     * @param array $evidence čtvrthodinové důkazy stavu Shelly HDO
     * @return array důkazní pole mapování hodnoty Shelly na tarif NT/VT
     */
    private function shellyPolarity(array $evidence): array {
        $oZone = new \DateTimeZone(self::ZONE);
        $aDays = [];
        foreach ($evidence as $nTs=>$vReading) {
            $sDay = (new \DateTimeImmutable('@'.$nTs))->setTimezone($oZone)->format('Y-m-d');
            $aDays[$sDay][$vReading['state'] ? 'on' : 'off'] = ($aDays[$sDay][$vReading['state'] ? 'on' : 'off'] ?? 0) + 900;
        }
        $aVotes = [];
        foreach ($aDays as $sDay=>$vDurations) {
            $vStart = new \DateTimeImmutable($sDay.' 00:00:00',$oZone);
            $nLength = $vStart->modify('+1 day')->getTimestamp()-$vStart->getTimestamp();
            $vOn = $vDurations['on'] ?? 0; $vOff = $vDurations['off'] ?? 0;
            if ($vOn+$vOff < $nLength * .985) continue;
            // Offset by one hour on the two daylight-saving transition days.
            $vShort = 4*3600 + ($nLength-86400)/2;
            $vLong = 20*3600 + ($nLength-86400)/2;
            if (abs($vOn-$vLong) <= 2700 && abs($vOff-$vShort) <= 2700) $aVotes[] = true;
            elseif (abs($vOff-$vLong) <= 2700 && abs($vOn-$vShort) <= 2700) $aVotes[] = false;
        }
        if ($aVotes === [] || count(array_unique($aVotes, SORT_REGULAR)) !== 1) {
            return ['onMeansNt'=>null,'days'=>count($aVotes)];
        }
        return ['onMeansNt'=>$aVotes[0],'days'=>count($aVotes)];
    }

    /**
     * Určí tarif VT/NT z platného rozpisu nebo ověřené historie Shelly.
     *
     * Vstupy: `$timestamp` (int) – unixový čas analyzovaného měření (s); `$profile` (array) – aktuální profil odběrného místa, ceníků a HDO; `$evidence` (array) – čtvrthodinové důkazy stavu Shelly HDO; `$onMeansNt` (?bool) – dříve odvozené mapování signálu Shelly na NT; null pokud neurčeno.
     * Výstup: `array` – tarif NT/VT a způsob jeho určení, případně stav bez spolehlivého důkazu.
     *
     * @param int $timestamp unixový čas analyzovaného měření (s)
     * @param array $profile aktuální profil odběrného místa, ceníků a HDO
     * @param array $evidence čtvrthodinové důkazy stavu Shelly HDO
     * @param ?bool $onMeansNt dříve odvozené mapování signálu Shelly na NT; null pokud neurčeno
     * @return array tarif NT/VT a způsob jeho určení, případně stav bez spolehlivého důkazu
     */
    private function tariff(int $timestamp, array $profile, array $evidence, ?bool $onMeansNt): array {
        $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone(self::ZONE));
        $sDay = $date->format('Y-m-d');
        foreach ($profile['hdoSchedules'] ?? [] as $schedule) {
            if ($sDay < $schedule['from'] || $sDay > $schedule['to']) continue;
            if (!$this->scheduleComplete($schedule, $profile)) continue;
            $vMinute = (int)$date->format('H') * 60 + (int)$date->format('i');
            foreach ($schedule['ntWindows'] as [$sFrom,$sTo]) {
                if ($vMinute >= $sFrom && $vMinute < $sTo) return ['tariff'=>'NT','source'=>'schedule'];
            }
            return ['tariff'=>'VT','source'=>'schedule'];
        }
        $vReading = $evidence[$timestamp] ?? null;
        if ($onMeansNt === null || $vReading === null) return ['tariff'=>null,'source'=>null];
        return ['tariff'=>$vReading['state'] === $onMeansNt ? 'NT' : 'VT','source'=>$vReading['source']];
    }

    /** D57d must not treat an incomplete list of NT windows as VT for the rest of the day. */
    /**
     * Ověří úplnost nízkotarifních oken HDO; nezaměňuje chybějící interval za vysoký tarif.
     *
     * Vstupy: `$schedule` (array) – časová okna spínání nízkého tarifu; `$profile` (array) – aktuální profil odběrného místa, ceníků a HDO.
     * Výstup: `bool` – true, pokud rozpis pokrývá požadované nízkotarifní intervaly.
     *
     * @param array $schedule časová okna spínání nízkého tarifu
     * @param array $profile aktuální profil odběrného místa, ceníků a HDO
     * @return bool true, pokud rozpis pokrývá požadované nízkotarifní intervaly
     */
    private function scheduleComplete(array $schedule, array $profile): bool {
        if (strcasecmp((string)($profile['distributionTariff'] ?? ''),'D57d') !== 0) return true;
        $nMinutes = 0;
        foreach ($schedule['ntWindows'] ?? [] as [$sFrom,$sTo]) $nMinutes += $sTo-$sFrom;
        return $nMinutes === 1200;
    }

    /**
     * Vrátí přehled účtování za vybrané období včetně energetických a cenových součtů.
     *
     * Vstupy: `$period` (string) – měsíc reportu ve tvaru YYYY-MM; prázdné znamená výchozí výběr.
     * Výstup: `array` – přehled fakturačních dat, cen, intervalů a porovnání LINEA s EG.D za měsíc.
     *
     * @param string $period měsíc reportu ve tvaru YYYY-MM; prázdné znamená výchozí výběr
     * @return array přehled fakturačních dat, cen, intervalů a porovnání LINEA s EG.D za měsíc
     */
    public function overview(string $period = ''): array {
        $profile = $this->profile();
        $oQb = $this->db->getQueryBuilder();
        $oQb->select('*')
            ->from('hc_gridsight_edg_reports')->orderBy('period','DESC')->setMaxResults(120);
        $aReports = $oQb->executeQuery()->fetchAllAssociative();
        if ($period === '' && $aReports !== []) $period = (string)$aReports[0]['period'];
        if ($period !== '' && (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$period) || !$this->report($period))) {
            throw new \InvalidArgumentException('Unknown ED.G report month.');
        }
        $vRecentEvidence = $this->history->hdoEvidence(time()-3*86400, time(), false);
        $vRecentShelly = $this->shellyPolarity($vRecentEvidence);
        $vRecentShelly['observedQuarters'] = count($vRecentEvidence);
        $vResult = ['statements'=>$this->statements(), 'profile'=>$profile,'shellyStatus'=>$vRecentShelly,'reports'=>array_map(/** Sestaví veřejný souhrn importovaného měsíčního výkazu EG.D. Vstupy: databázový záznam výkazu EG.D. Výstup: `array` – odvozený datový záznam. */ static fn(array $vR): array => [
            'period'=>$vR['period'],'ean'=>$vR['ean'],'rows'=>(int)$vR['row_count'],
            'importKWh'=>(int)$vR['import_mwh']/1_000_000,
            'exportKWh'=>(int)$vR['export_mwh']/1_000_000,
            'importedAt'=>(int)$vR['imported_at'],
        ],$aReports),'selectedPeriod'=>$period,'month'=>null];
        if ($period === '') return $vResult;
        $oQb = $this->db->getQueryBuilder();
        $oQb->select('ts','import_mwh','export_mwh')->from('hc_gridsight_edg_intervals')
            ->where($oQb->expr()->eq('period',$oQb->createNamedParameter($period)))->orderBy('ts','ASC');
        $vRecords = $oQb->executeQuery()->fetchAllAssociative();
        if ($vRecords === []) throw new \RuntimeException('Imported report intervals are missing.');
        $sLastDay = (new \DateTimeImmutable($period . '-01'))->modify('last day of this month')->format('Y-m-d');
        $aPriceByDay = [];
        $nDaysInMonth = (int)(new \DateTimeImmutable($sLastDay))->format('j');
        $nFixed = 0.0;
        $bPricesValid = true;
        $sFirstPricedDay = null; $sLastPricedDay = null; $nPricedDays = 0;
        for ($nDayNumber = 1; $nDayNumber <= $nDaysInMonth; $nDayNumber++) {
            $sDayKey = $period . '-' . str_pad((string)$nDayNumber, 2, '0', STR_PAD_LEFT);
            $aPriceByDay[$sDayKey] = null;
            foreach ($profile['pricePeriods'] ?? [] as $vCandidate) {
                if ($vCandidate['from'] <= $sDayKey && $vCandidate['to'] >= $sDayKey) {
                    $aPriceByDay[$sDayKey] = $vCandidate;
                    $nFixed += (float)$vCandidate['fixedCzkMonth'] / $nDaysInMonth;
                    $sFirstPricedDay ??= $sDayKey;
                    $sLastPricedDay = $sDayKey;
                    $nPricedDays++;
                    break;
                }
            }
            if ($aPriceByDay[$sDayKey] === null) $bPricesValid = false;
        }
        $oZone = new \DateTimeZone(self::ZONE);
        $nFirst = (int)$vRecords[0]['ts'];
        $nLast = (int)$vRecords[count($vRecords)-1]['ts'];
        $evidence = $this->history->hdoEvidence($nFirst-7*86400,$nLast+7*86400+900);
        $vCalibration = $this->shellyPolarity($evidence);
        $onMeansNt = $vCalibration['onMeansNt'];
        $vIncompleteSchedules = array_values(array_map(/** Formátuje rozpis HDO do textu od–do. Vstupy: záznam HDO s časovými poli from a to. Výstup: `string` – formátovaný text. */ static fn(array $vS): string => $vS['from'].'–'.$vS['to'],
            array_filter($profile['hdoSchedules'] ?? [], /** Vybere pouze časová okna HDO, jejichž rozpis není úplný. Vstupy: časové okno HDO a pravidla ověření úplnosti. Výstup: `bool` – true/false podle uvedené podmínky. */ fn(array $vS): bool => !$this->scheduleComplete($vS,$profile))));
        $vDaily = [];
        $aIntervals = [];
        $nVtKWh = $nNtKWh = $nUnknownKWh = $vVariable = 0.0;
        $vSources = ['schedule'=>0,'shelly'=>0,'snapshot'=>0,'unknown'=>0];
        foreach ($vRecords as $vRecord) {
            $nTs = (int)$vRecord['ts'];
            $date = (new \DateTimeImmutable('@'.$nTs))->setTimezone($oZone);
            $sDay = $date->format('Y-m-d');
            $vTariffResult = $this->tariff($nTs,$profile,$evidence,$onMeansNt);
            $vTariff = $vTariffResult['tariff'];
            $sSource = $vTariffResult['source'] ?? 'unknown';
            $vSources[$sSource ?? 'unknown']++;
            $aPrice = $aPriceByDay[$sDay];
            $nGreen = !empty($aPrice['greenEnabled']) ? (float)$aPrice['greenCzkKWh'] : 0.0;
            $nVtPrice = (float)($aPrice['vtCzkKWh'] ?? 0) + $nGreen;
            $nNtPrice = (float)($aPrice['ntCzkKWh'] ?? 0) + $nGreen;
            $nImport = (int)$vRecord['import_mwh']/1_000_000;
            $nExport = (int)$vRecord['export_mwh']/1_000_000;
            $vCost = $aPrice !== null && $vTariff !== null ? $nImport * ($vTariff === 'NT' ? $nNtPrice : $nVtPrice) : null;
            $vDaily[$sDay] ??= ['day'=>$sDay,'importKWh'=>0.0,'exportKWh'=>0.0,'vtKWh'=>0.0,'ntKWh'=>0.0,'unknownKWh'=>0.0,'unknownIntervals'=>0,'variableCzk'=>0.0,'priceAvailable'=>$aPrice !== null];
            $vDaily[$sDay]['importKWh'] += $nImport;
            $vDaily[$sDay]['exportKWh'] += $nExport;
            if ($vTariff === 'NT') { $nNtKWh += $nImport; $vDaily[$sDay]['ntKWh'] += $nImport; }
            elseif ($vTariff === 'VT') { $nVtKWh += $nImport; $vDaily[$sDay]['vtKWh'] += $nImport; }
            else { $nUnknownKWh += $nImport; $vDaily[$sDay]['unknownKWh'] += $nImport; $vDaily[$sDay]['unknownIntervals']++; }
            if ($vCost !== null) { $vVariable += $vCost; $vDaily[$sDay]['variableCzk'] += $vCost; }
            $aIntervals[] = ['ts'=>$nTs,'localTime'=>$date->format('Y-m-d H:i'),'day'=>$sDay,'tariff'=>$vTariff,'tariffSource'=>$sSource,
                'importKWh'=>$nImport,'exportKWh'=>$nExport,'variableCzk'=>$vCost,
                'priceVt'=>$aPrice !== null ? $nVtPrice : null,'priceNt'=>$aPrice !== null ? $nNtPrice : null];
        }
        $bTariffsComplete = count(array_filter($aIntervals,/** Označí interval, pro který nebylo určeno NT ani VT. Vstupy: čtvrthodinový interval EG.D. Výstup: `bool` – true/false podle uvedené podmínky. */ static fn(array $oRow): bool => $oRow['tariff'] === null)) === 0;
        $vPriced = $bPricesValid && $bTariffsComplete;
        $bEligibleTariffsComplete = $nPricedDays > 0 && count(array_filter($aIntervals, /** Označí ocenitelný interval s chybějícím tarifem. Vstupy: čtvrthodinový interval a dostupnost ceníku. Výstup: `bool` – true/false podle uvedené podmínky. */ static fn(array $oRow): bool =>
            $oRow['priceVt'] !== null && $oRow['tariff'] === null)) === 0;
        $vPricedFixed = round($nFixed,2);
        $vPartial = !$bPricesValid && $nPricedDays > 0 ? [
            'from'=>$sFirstPricedDay,'to'=>$sLastPricedDay,'days'=>$nPricedDays,
            'complete'=>$bEligibleTariffsComplete,
            'variableCzk'=>$bEligibleTariffsComplete ? round($vVariable,2) : null,
            'fixedCzk'=>$vPricedFixed,
            'estimatedTotalCzk'=>$bEligibleTariffsComplete ? round($vVariable+$nFixed,2) : null,
        ] : null;
        $nFixed = $bPricesValid ? round($nFixed,2) : null;
        $vReport = $this->report($period);
        $vSummary = ['importKWh'=>(int)$vReport['import_mwh']/1_000_000,
            'exportKWh'=>(int)$vReport['export_mwh']/1_000_000,
            'vtKWh'=>$nVtKWh,'ntKWh'=>$nNtKWh,'unknownKWh'=>$nUnknownKWh,
            'variableCzk'=>$vPriced ? round($vVariable,2) : null,
            'fixedCzk'=>$nFixed,'estimatedTotalCzk'=>$vPriced ? round($vVariable+$nFixed,2) : null,
            'priceValid'=>$bPricesValid,'tariffComplete'=>$bTariffsComplete,
            'tariffSources'=>$vSources,'shellyCalibration'=>$vCalibration,
            'partialPrice'=>$vPartial,'incompleteSchedules'=>$vIncompleteSchedules];

        // The hypothetical uses LINEA house load in the same quarter hours.
        // It assumes the entire house load would otherwise be bought from the grid.
        $sFrom = (int)$vRecords[0]['ts'];
        $sTo = (int)$vRecords[count($vRecords)-1]['ts'] + 900;
        $history = $this->history->getHistory($sFrom,$sTo-1);
        $vResolution = (int)$history['resolutionSec'];
        $vSamples = [];
        if (in_array($vResolution,[300,900],true)) {
            foreach ($history['points'] as $vPoint) {
                $nTs = (int)$vPoint['ts'];
                $vQuarter = intdiv($nTs,900)*900;
                if ($vQuarter < $sFrom || $vQuarter >= $sTo) continue;
                // A collected bucket without a usable house reading is not
                // evidence that the house consumed zero energy.
                if (($vPoint['houseW'] ?? null) === null) continue;
                $vSamples[$vQuarter] ??= ['coverage'=>0,'houseKWh'=>0.0,'pvKWh'=>0.0,'pvCoverage'=>0];
                $vSamples[$vQuarter]['coverage'] += min($vResolution,max(0,(int)$vPoint['coverageSec']));
                $vSamples[$vQuarter]['houseKWh'] += (float)($vPoint['houseKWh'] ?? 0);
                if (($vPoint['pvW'] ?? null) !== null) {
                    $vSamples[$vQuarter]['pvCoverage'] += min($vResolution,max(0,(int)$vPoint['coverageSec']));
                    $vSamples[$vQuarter]['pvKWh'] += (float)($vPoint['pvKWh'] ?? 0);
                }
            }
        }
        $nCoveredSeconds = 0; $vHouse = 0.0; $vPv = 0.0; $vHouseVariable = 0.0;
        foreach ($aIntervals as &$vInterval) {
            $vSample = $vSamples[$vInterval['ts']] ?? null;
            $vInterval['houseKWh'] = $vSample && $vSample['coverage'] > 0 ? round($vSample['houseKWh'],4) : null;
            $vInterval['pvKWh'] = $vSample && $vSample['pvCoverage'] > 0 ? round($vSample['pvKWh'],4) : null;
            $vInterval['lineaCoveragePct'] = $vSample ? round(min(900,$vSample['coverage'])/9,1) : 0;
            $vUnitPrice = $vInterval['tariff'] === 'NT' ? $vInterval['priceNt'] : $vInterval['priceVt'];
            $vInterval['hypotheticalVariableCzk'] = $vUnitPrice !== null && $vInterval['tariff'] !== null
                && $vSample && $vSample['coverage'] >= 896
                ? round($vSample['houseKWh'] * $vUnitPrice,2) : null;
            if (!$vSample || $vSample['coverage'] <= 0) continue;
            $nCoveredSeconds += min(900,$vSample['coverage']);
            $vHouse += $vSample['houseKWh'];
            $vPv += $vSample['pvKWh'];
            if ($vPriced) $vHouseVariable += $vSample['houseKWh'] * $vUnitPrice;
        }
        unset($vInterval);
        $nCoverage = $vRecords !== [] ? $nCoveredSeconds/(count($vRecords)*900) : 0;
        $vSummary['houseCoveredKWh'] = round($vHouse,3);
        $vSummary['pvCoveredKWh'] = round($vPv,3);
        $vSummary['lineaCoveragePct'] = round($nCoverage*100,2);
        $vSummary['hypotheticalVariableCzk'] = $vPriced && $nCoverage >= .995 ? round($vHouseVariable,2) : null;
        $vSummary['differenceCzk'] = $vSummary['hypotheticalVariableCzk'] === null ? null
            : round($vSummary['hypotheticalVariableCzk'] - $vVariable,2);
        $vResult['month'] = ['summary'=>$vSummary,'daily'=>array_values($vDaily),'intervals'=>$aIntervals];
        return $vResult;
    }
}
