<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

use OCA\HcGridSight\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;

/** Household billing inputs and the distributor's independent 15-minute measurements. */
final class EnergyAnalysisService {
    private const XML_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const ZONE = 'Europe/Prague';

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
        private HistoryService $history,
        private \OCP\Files\IAppData $appData,
    ) {}

    public function profile(): array {
        $raw = json_decode($this->config->getAppValue(Application::APP_ID, 'analysis_profile', '{}'), true);
        return is_array($raw) ? $raw : [];
    }

    public function saveProfile(array $input): array {
        $strings = [
            'ean'=>18, 'distributor'=>80, 'usage'=>80, 'meterType'=>16,
            'distributionTariff'=>16, 'mainBreaker'=>24,
        ];
        $profile = [];
        foreach ($strings as $key => $limit) {
            $value = trim((string)($input[$key] ?? ''));
            if (mb_strlen($value) > $limit) throw new \InvalidArgumentException('Invalid profile field: ' . $key);
            $profile[$key] = $value;
        }
        if ($profile['ean'] !== '' && !preg_match('/^\d{18}$/D', $profile['ean'])) {
            throw new \InvalidArgumentException('EAN must contain 18 digits.');
        }
        $qb = $this->db->getQueryBuilder();
        $existingEan = $qb->select('ean')->from('hc_gridsight_edg_reports')
            ->setMaxResults(1)->executeQuery()->fetchOne();
        if ($existingEan !== false && $profile['ean'] !== (string)$existingEan) {
            throw new \InvalidArgumentException('The connection EAN must match the already imported reports.');
        }
        $contracts = $input['contracts'] ?? [];
        if (!is_array($contracts) || count($contracts) > 20) throw new \InvalidArgumentException('Invalid contract history.');
        $profile['contracts'] = [];
        foreach ($contracts as $candidate) {
            if (!is_array($candidate)) throw new \InvalidArgumentException('Invalid contract row.');
            $start = trim((string)($candidate['start'] ?? ''));
            $supplier = trim((string)($candidate['supplier'] ?? ''));
            $product = trim((string)($candidate['product'] ?? ''));
            $sourceUrl = trim((string)($candidate['sourceUrl'] ?? ''));
            $months = $candidate['durationMonths'] ?? null;
            if ($supplier === '' && $product === '' && $start === '' && $sourceUrl === '') continue;
            if (mb_strlen($supplier) > 80 || mb_strlen($product) > 160 || mb_strlen($sourceUrl) > 512
                || ($start !== '' && !$this->isDate($start))
                || !is_numeric($months) || (int)$months < 1 || (int)$months > 120) {
                throw new \InvalidArgumentException('Invalid contract row.');
            }
            if ($start === '' && count($contracts) > 1) {
                throw new \InvalidArgumentException('Enter the supply start date before adding another contract.');
            }
            $to = $start === '' ? '' : (new \DateTimeImmutable($start))
                ->add(new \DateInterval('P' . (int)$months . 'M'))
                ->sub(new \DateInterval('P1D'))->format('Y-m-d');
            $profile['contracts'][] = ['start'=>$start,'to'=>$to,'durationMonths'=>(int)$months,
                'supplier'=>$supplier,'product'=>$product,'sourceUrl'=>$sourceUrl];
        }
        usort($profile['contracts'], static fn(array $a,array $b): int => strcmp($a['start'],$b['start']));
        foreach ($profile['contracts'] as $i=>$contract) {
            if ($i > 0 && $contract['start'] <= $profile['contracts'][$i-1]['to']) {
                throw new \InvalidArgumentException('Contract periods overlap.');
            }
        }
        $latest = $profile['contracts'] === [] ? null : $profile['contracts'][count($profile['contracts'])-1];
        $profile['supplier'] = $latest['supplier'] ?? '';
        $profile['product'] = $latest['product'] ?? '';
        $profile['contractStart'] = $latest['start'] ?? '';
        $profile['durationMonths'] = $latest['durationMonths'] ?? 36;

        $periods = $input['pricePeriods'] ?? [];
        if (!is_array($periods) || count($periods) > 40) throw new \InvalidArgumentException('Invalid price history.');
        $profile['pricePeriods'] = [];
        foreach ($periods as $candidate) {
            if (!is_array($candidate)) throw new \InvalidArgumentException('Invalid price row.');
            $from = trim((string)($candidate['from'] ?? ''));
            $contractStart = trim((string)($candidate['contractStart'] ?? ''));
            if ($contractStart !== '') {
                $matches = array_filter($profile['contracts'], static fn(array $contract): bool => $contract['start'] === $contractStart);
                if ($matches === []) throw new \InvalidArgumentException('Select an existing contract for the price row.');
            }
            if ($from === '') {
                if ($contractStart === '' && count($profile['contracts']) === 1) {
                    $contractStart = $profile['contracts'][0]['start'];
                }
                $from = $contractStart;
            }
            if (!$this->isDate($from)) throw new \InvalidArgumentException('Enter a valid price start date.');
            if ($contractStart !== '' && $from < $contractStart) {
                throw new \InvalidArgumentException('Price cannot start before its contract.');
            }
            $price = ['from'=>$from,'contractStart'=>$contractStart];
            foreach (['vtCzkKWh','ntCzkKWh','fixedCzkMonth','greenCzkKWh'] as $key) {
                $raw = $candidate[$key] ?? null;
                if ($raw === null || $raw === '' || !is_numeric($raw) || !is_finite((float)$raw)
                    || (float)$raw < 0 || (float)$raw > 100000) {
                    throw new \InvalidArgumentException('Invalid price in period ' . $from . ': ' . $key);
                }
                $price[$key] = round((float)$raw, 5);
            }
            if (!is_bool($candidate['greenEnabled'] ?? null)) {
                throw new \InvalidArgumentException('Invalid green electricity selection in ' . $from);
            }
            $price['greenEnabled'] = $candidate['greenEnabled'];
            $price['sourceUrl'] = trim((string)($candidate['sourceUrl'] ?? ''));
            if (mb_strlen($price['sourceUrl']) > 512) throw new \InvalidArgumentException('Price list URL is too long.');
            // A combined price includes annual regulated items. Never silently
            // carry the 2026 full tariff into 2027 without a new tariff row.
            $yearEnd = substr($from,0,4) . '-12-31';
            $oldTo = trim((string)($candidate['to'] ?? ''));
            $price['to'] = $oldTo !== '' && $this->isDate($oldTo) && $oldTo < $yearEnd ? $oldTo : $yearEnd;
            if ($price['to'] < $from) throw new \InvalidArgumentException('Price end precedes its start: ' . $from);
            $profile['pricePeriods'][] = $price;
        }
        usort($profile['pricePeriods'],static fn(array $a,array $b): int => strcmp($a['from'],$b['from']));
        foreach ($profile['pricePeriods'] as $i=>&$price) {
            if ($i === 0) continue;
            $prior = &$profile['pricePeriods'][$i-1];
            if ($price['from'] <= $prior['from']) throw new \InvalidArgumentException('Duplicate price start date.');
            if ($price['from'] <= $prior['to']) {
                $prior['to'] = (new \DateTimeImmutable($price['from']))->modify('-1 day')->format('Y-m-d');
            }
            unset($prior);
        }
        unset($price);
        $currentPrice = $profile['pricePeriods'] === [] ? null : $profile['pricePeriods'][count($profile['pricePeriods'])-1];
        foreach (['vtCzkKWh','ntCzkKWh','fixedCzkMonth','greenCzkKWh','greenEnabled','sourceUrl'] as $key) {
            $profile[$key] = $currentPrice[$key] ?? ($key === 'greenEnabled' ? false : '');
        }
        $profile['priceValidFrom'] = $currentPrice['from'] ?? '';
        $profile['priceValidTo'] = $currentPrice['to'] ?? '';
        $schedules = $input['hdoSchedules'] ?? [];
        if (!is_array($schedules) || count($schedules) > 12) {
            throw new \InvalidArgumentException('Invalid HDO schedules.');
        }
        $profile['hdoSchedules'] = [];
        foreach ($schedules as $schedule) {
            if (!is_array($schedule)) throw new \InvalidArgumentException('Invalid HDO schedule.');
            $from = (string)($schedule['from'] ?? '');
            $to = (string)($schedule['to'] ?? '');
            if (!$this->isDate($from) || !$this->isDate($to) || $from > $to) {
                throw new \InvalidArgumentException('Invalid HDO date range.');
            }
            $windows = $schedule['ntWindows'] ?? [];
            if (!is_array($windows) || count($windows) > 24) throw new \InvalidArgumentException('Invalid HDO windows.');
            $clean = [];
            foreach ($windows as $window) {
                if (!is_array($window) || count($window) !== 2
                    || !is_int($window[0]) || !is_int($window[1])
                    || $window[0] < 0 || $window[1] > 1440 || $window[0] >= $window[1]) {
                    throw new \InvalidArgumentException('HDO windows must be minute pairs within one day.');
                }
                $clean[] = [$window[0], $window[1]];
            }
            usort($clean, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            for ($i = 1; $i < count($clean); $i++) {
                if ($clean[$i][0] < $clean[$i-1][1]) throw new \InvalidArgumentException('Overlapping HDO windows.');
            }
            foreach ($profile['hdoSchedules'] as $old) {
                if ($from <= $old['to'] && $to >= $old['from']) {
                    throw new \InvalidArgumentException('Overlapping HDO validity periods.');
                }
            }
            $profile['hdoSchedules'][] = ['from'=>$from,'to'=>$to,'ntWindows'=>$clean];
        }
        $this->config->setAppValue(Application::APP_ID, 'analysis_profile', json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $profile;
    }

    private function isDate(string $date): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m)) return false;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /** Parse the specific ED.G ICC1/ICQ2/ISC1/ISQ2 export, not arbitrary spreadsheets. */
    public function importReport(string $path, string $filename): array {
        if (!preg_match('/\.xlsx$/iD', $filename) || !is_file($path) || filesize($path) > 5_000_000) {
            throw new \InvalidArgumentException('Select an ED.G .xlsx report of at most 5 MB.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new \InvalidArgumentException('Invalid XLSX archive.');
        try {
            if ($zip->numFiles > 64) throw new \InvalidArgumentException('Unexpected XLSX structure.');
            foreach (['xl/sharedStrings.xml'=>2_000_000, 'xl/worksheets/sheet1.xml'=>8_000_000] as $name=>$limit) {
                $stat = $zip->statName($name);
                if (!is_array($stat) || $stat['size'] > $limit) {
                    throw new \InvalidArgumentException('Unsupported or oversized ED.G worksheet.');
                }
            }
            // Explicit namespace-aware XPath avoids relying on SimpleXML's
            // implicit default-namespace traversal for role/header cells.
            $stringDocument = new \DOMDocument();
            $sheetDocument = new \DOMDocument();
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if (!is_string($sharedXml) || $sharedXml === '' || !is_string($sheetXml) || $sheetXml === ''
                || !@$stringDocument->loadXML($sharedXml, LIBXML_NONET)
                || !@$sheetDocument->loadXML($sheetXml, LIBXML_NONET)) {
                throw new \InvalidArgumentException('Unreadable ED.G worksheet.');
            }
            $stringXPath = new \DOMXPath($stringDocument);
            $sheetXPath = new \DOMXPath($sheetDocument);
            $stringXPath->registerNamespace('x', self::XML_NS);
            $sheetXPath->registerNamespace('x', self::XML_NS);
            $strings = [];
            foreach ($stringXPath->query('/x:sst/x:si') as $entry) {
                $value = '';
                foreach ($stringXPath->query('.//x:t', $entry) as $text) $value .= $text->textContent;
                $strings[] = $value;
            }
            $ean = '';
            $roleColumns = [];
            $descriptionColumns = [];
            $observedHeader = [];
            $unitHeaders = [];
            $importColumn = null;
            $exportColumn = null;
            $period = '';
            $intervals = [];
            $factor = 1_000_000;
            $firstEpoch = 0;
            $endEpoch = 0;
            $zone = new \DateTimeZone(self::ZONE);
            foreach ($sheetXPath->query('/x:worksheet/x:sheetData/x:row') as $row) {
                $number = (int)$row->getAttribute('r');
                $cells = [];
                foreach ($sheetXPath->query('./x:c', $row) as $cell) {
                    $name = $cell->getAttribute('r');
                    if (!preg_match('/^([A-Z]{1,2})\d+$/D', $name, $m)) continue;
                    $value = $sheetXPath->query('./x:v', $cell)->item(0)?->textContent ?? '';
                    $cells[$m[1]] = $cell->getAttribute('t') === 's' ? ($strings[(int)$value] ?? '') : $value;
                }
                if ($number === 1) {
                    if (($cells['B'] ?? '') !== 'EAN' || !preg_match('/^\d{18}$/D', $cells['C'] ?? '')) {
                        throw new \InvalidArgumentException('Missing or invalid EAN in the ED.G report.');
                    }
                    $ean = $cells['C'];
                }
                if ($number <= 4) {
                    if ($number === 3) $observedHeader = $cells;
                    foreach ($cells as $column => $rawLabel) {
                        $label = trim((string)$rawLabel);
                        if (in_array($label, ['ICQ2','ISQ2','DCC1','DSC1'], true)) {
                            if (isset($roleColumns[$label]) && $roleColumns[$label] !== $column) {
                                throw new \InvalidArgumentException('Duplicate ED.G energy role: ' . $label);
                            }
                            $roleColumns[$label] = $column;
                        }
                        if ($number !== 4) continue;
                        $unitHeaders[$column] = mb_strtolower($label);
                        $description = mb_strtolower((string)preg_replace('/\s+/u', ' ', $label));
                        if (str_contains($description, 'energie spotřeby ze sítě') && str_contains($description, 'kwh')) {
                            $descriptionColumns['ICQ2'] = $column;
                        }
                        if (str_contains($description, 'energie dodávky do sítě') && str_contains($description, 'kwh')) {
                            $descriptionColumns['ISQ2'] = $column;
                        }
                    }
                }
                if ($number < 5) continue;
                if (count($intervals) > 4000) throw new \InvalidArgumentException('Too many ED.G intervals.');
                if ($importColumn === null || $exportColumn === null) {
                    if (isset($roleColumns['DCC1'], $roleColumns['DSC1'])) {
                        foreach (['DCC1','DSC1'] as $role) {
                            if (!str_contains($unitHeaders[$roleColumns[$role]] ?? '', '(kw)') || !str_contains($unitHeaders[$roleColumns[$role]] ?? '', 'fakturaci')) {
                                throw new \InvalidArgumentException('Unsupported billed power unit.');
                            }
                        }
                        $descriptionColumns = [];
                        $roleColumns = ['ICQ2'=>$roleColumns['DCC1'], 'ISQ2'=>$roleColumns['DSC1']];
                        $factor = 250_000; // billed kW averaged over a quarter hour -> milliWh
                    }
                    $columns = isset($roleColumns['ICQ2'], $roleColumns['ISQ2']) ? $roleColumns : $descriptionColumns;
                    $importColumn = $columns['ICQ2'] ?? null;
                    $exportColumn = $columns['ISQ2'] ?? null;
                    if ($importColumn === null || $exportColumn === null || $importColumn === $exportColumn) {
                        $labels = [];
                        foreach (['B','C','D','E'] as $key) {
                            $labels[] = $key . '=' . substr(trim((string)($observedHeader[$key] ?? '')), 0, 30);
                        }
                        throw new \InvalidArgumentException('ED.G energy roles ICQ2/ISQ2 not found. Row 3: ' . implode(', ', $labels));
                    }
                    if (isset($descriptionColumns['ICQ2'], $descriptionColumns['ISQ2'])
                        && ($descriptionColumns['ICQ2'] !== $importColumn || $descriptionColumns['ISQ2'] !== $exportColumn)) {
                        throw new \InvalidArgumentException('ED.G energy roles conflict with column descriptions.');
                    }
                }
                $local = $cells['A'] ?? '';
                if (!preg_match('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}$/D', $local)) {
                    throw new \InvalidArgumentException('Invalid date at row ' . $number);
                }
                if ($period === '') {
                    $period = substr($local, 6, 4) . '-' . substr($local, 3, 2);
                    if (!$this->isDate($period . '-01')) throw new \InvalidArgumentException('Invalid report month.');
                    $firstEpoch = (new \DateTimeImmutable($period . '-01 00:00:00', $zone))->getTimestamp();
                    $endEpoch = (new \DateTimeImmutable($period . '-01 00:00:00', $zone))->modify('+1 month')->getTimestamp();
                }
                $expected = $firstEpoch + count($intervals) * 900;
                if ($expected >= $endEpoch || (new \DateTimeImmutable('@' . $expected))->setTimezone($zone)->format('d.m.Y H:i:s') !== $local) {
                    throw new \InvalidArgumentException('Missing, repeated or out-of-order quarter hour at row ' . $number);
                }
                foreach ([$importColumn,$exportColumn] as $key) {
                    $numberValue = $cells[$key] ?? '';
                    if (!is_numeric($numberValue) || !is_finite((float)$numberValue)
                        || (float)$numberValue < 0 || (float)$numberValue > 100000) {
                        throw new \InvalidArgumentException('Invalid energy in column ' . $key . ' at row ' . $number);
                    }
                }
                $intervals[] = ['ts'=>$expected,
                    'import_mwh'=>(int)round((float)$cells[$importColumn] * $factor),
                    'export_mwh'=>(int)round((float)$cells[$exportColumn] * 1_000_000)];
            }
            if ($ean === '' || $period === '' || $firstEpoch + count($intervals) * 900 !== $endEpoch) {
                throw new \InvalidArgumentException('The ED.G report does not contain a complete calendar month.');
            }
        } finally {
            $zip->close();
        }
        $configuredEan = $this->profile()['ean'] ?? '';
        if ($configuredEan !== '' && $configuredEan !== $ean) {
            throw new \InvalidArgumentException('The report EAN does not match this connection.');
        }
        $qb = $this->db->getQueryBuilder();
        $storedEan = $qb->select('ean')->from('hc_gridsight_edg_reports')
            ->setMaxResults(1)->executeQuery()->fetchOne();
        if ($storedEan !== false && (string)$storedEan !== $ean) {
            throw new \InvalidArgumentException('The report EAN differs from previously imported months.');
        }
        // ED.G usually puts the billing month at the end of REP_DATA_..._YYYY-MM.xlsx.
        // Rows remain authoritative; the filename also guards against a
        // misnamed file being imported into the wrong month.
        if (preg_match('/(?:^|_)(\d{4})-(0[1-9]|1[0-2])(?:\(\d+\))?\.xlsx$/iD', $filename, $filenameDate)
            && $period !== $filenameDate[1] . '-' . $filenameDate[2]) {
            throw new \InvalidArgumentException('The report filename month does not match its worksheet.');
        }
        $hash = hash_file('sha256', $path);
        $previous = $this->report($period);
        $sourceName = $period . '-' . $hash . '.xlsx';
        $folder = $this->reportFolder();
        if (!$folder->fileExists($sourceName)) $folder->newFile($sourceName)->putContent(file_get_contents($path));
        if ($previous && $previous['sha256'] === $hash) {
            $this->rememberReportEan($ean, $configuredEan);
            return ['period'=>$period,'rows'=>count($intervals),'unchanged'=>true];
        }
        $totalImport = array_sum(array_column($intervals, 'import_mwh'));
        $totalExport = array_sum(array_column($intervals, 'export_mwh'));
        $this->db->beginTransaction();
        try {
            if ($previous) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('hc_gridsight_edg_intervals')
                    ->where($qb->expr()->eq('period',$qb->createNamedParameter($period)))->executeStatement();
                $qb = $this->db->getQueryBuilder();
                $qb->delete('hc_gridsight_edg_reports')
                    ->where($qb->expr()->eq('period',$qb->createNamedParameter($period)))->executeStatement();
            }
            $qb = $this->db->getQueryBuilder();
            $qb->insert('hc_gridsight_edg_reports');
            foreach (['period'=>$period,'ean'=>$ean,'sha256'=>$hash,'row_count'=>count($intervals),
                      'import_mwh'=>$totalImport,'export_mwh'=>$totalExport,'imported_at'=>time()] as $column=>$value) {
                $qb->setValue($column,$qb->createNamedParameter($value));
            }
            $qb->executeStatement();
            foreach ($intervals as $interval) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('hc_gridsight_edg_intervals')
                    ->setValue('period',$qb->createNamedParameter($period))
                    ->setValue('ts',$qb->createNamedParameter($interval['ts']))
                    ->setValue('import_mwh',$qb->createNamedParameter($interval['import_mwh']))
                    ->setValue('export_mwh',$qb->createNamedParameter($interval['export_mwh']))
                    ->executeStatement();
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        if ($previous && $previous['sha256'] !== $hash) {
            $oldName=$period.'-'.$previous['sha256'].'.xlsx';
            try { if ($folder->fileExists($oldName)) $folder->getFile($oldName)->delete(); }
            catch (\OCP\Files\NotFoundException $e) { /* Original already absent. */ }
        }
        $this->rememberReportEan($ean, $configuredEan);
        return ['period'=>$period,'rows'=>count($intervals),'importKWh'=>$totalImport/1_000_000,
            'exportKWh'=>$totalExport/1_000_000,'unchanged'=>false];
    }


    private function reportFolder(): \OCP\Files\SimpleFS\ISimpleFolder {
        try { return $this->appData->getFolder('edg-reports'); }
        catch (\OCP\Files\NotFoundException $e) { return $this->appData->newFolder('edg-reports'); }
    }

    public function deleteReport(string $period): void {
        $record = $this->report($period);
        if (!$record) throw new \InvalidArgumentException('Unknown report.');
        $this->db->beginTransaction();
        try {
            foreach (['hc_gridsight_edg_intervals','hc_gridsight_edg_reports'] as $table) {
                $qb=$this->db->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->eq('period',$qb->createNamedParameter($period)))->executeStatement();
            }
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        $folder=$this->reportFolder(); $name=$period.'-'.$record['sha256'].'.xlsx';
        if ($folder->fileExists($name)) $folder->getFile($name)->delete();
    }

    public function exportReports(array $periods): string {
        $periods=array_values(array_unique($periods));
        if (count($periods)<1 || count($periods)>24) throw new \InvalidArgumentException('Select 1–24 reports.');
        $path=tempnam(sys_get_temp_dir(),'gridsight-'); $zip=new \ZipArchive();
        try {
            if ($zip->open($path,\ZipArchive::OVERWRITE)!==true) throw new \RuntimeException('Cannot create ZIP.');
            $manifest=[]; $bytes=0;
            foreach ($periods as $period) {
                if (!is_string($period) || !preg_match('/^\d{4}-\d{2}$/D',$period)) throw new \InvalidArgumentException('Invalid month.');
                $record=$this->report($period);
                if (!$record) throw new \InvalidArgumentException('Unknown report: '.$period);
                $qb=$this->db->getQueryBuilder();
                $rows=$qb->select('ts','import_mwh','export_mwh')->from('hc_gridsight_edg_intervals')
                    ->where($qb->expr()->eq('period',$qb->createNamedParameter($period)))->orderBy('ts','ASC')->executeQuery()->fetchAllAssociative();
                $csv="timestamp_utc,local_time,import_kwh,export_kwh\n";
                foreach ($rows as $row) {
                    $date=new \DateTimeImmutable('@'.$row['ts']);
                    $csv.=$date->format('c').','.$date->setTimezone(new \DateTimeZone(self::ZONE))->format('c').','.
                        number_format((int)$row['import_mwh']/1_000_000,6,'.','').','.number_format((int)$row['export_mwh']/1_000_000,6,'.','')."\n";
                }
                $zip->addFromString($period.'/intervals.csv',$csv); $bytes+=strlen($csv);
                $folder=$this->reportFolder(); $name=$period.'-'.$record['sha256'].'.xlsx';
                $record['originalIncluded']=$folder->fileExists($name);
                if ($bytes>25_000_000) throw new \InvalidArgumentException('Export exceeds 25 MB. Select fewer months.');
                if ($record['originalIncluded']) {
                    $content=$folder->getFile($name)->getContent(); $bytes+=strlen($content);
                    if ($bytes>25_000_000) throw new \InvalidArgumentException('Export exceeds 25 MB. Select fewer months.');
                    $zip->addFromString($period.'/original.xlsx',$content);
                }
                $manifest[]=$record;
            }
            $zip->addFromString('manifest.json',json_encode(['version'=>1,'timezone'=>self::ZONE,'databaseEnergyUnit'=>'milliWh','reports'=>$manifest],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            $zip->close(); return (string)file_get_contents($path);
        } finally { if (is_file($path)) unlink($path); }
    }

    public function saveStatement(array $statement): array {
        $from=(string)($statement['from']??''); $to=(string)($statement['to']??'');
        $amount=$statement['amountCzk']??null;
        if (!$this->isDate($from)||!$this->isDate($to)||$from>$to || !is_numeric($amount)||!is_finite((float)$amount)||abs((float)$amount)>10000000 || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days>366)
            throw new \InvalidArgumentException('Enter valid dates and total payment in CZK.');
        $ean=trim((string)($statement['saleEan']??''));
        if ($ean!==''&&!preg_match('/^\d{18}$/D',$ean)) throw new \InvalidArgumentException('Sale EAN must have 18 digits.');
        $document=trim((string)($statement['document']??''));
        if (mb_strlen($document)>80) throw new \InvalidArgumentException('Document number is too long.');
        $rows=$this->statementRows(); $id=(string)($statement['id']??'');
        if ($id==='') $id=bin2hex(random_bytes(12));
        if (!preg_match('/^[a-f0-9]{24}$/D',$id)) throw new \InvalidArgumentException('Invalid statement ID.');
        foreach ($rows as $oldId=>$old) if ($oldId!==$id && $old['from']===$from && $old['to']===$to && $old['saleEan']===$ean)
            throw new \InvalidArgumentException('This period already exists. Edit its existing row.');
        if (count($rows)>=600&&!isset($rows[$id])) throw new \InvalidArgumentException('Maximum 600 statements.');
        $rows[$id]=['id'=>$id,'from'=>$from,'to'=>$to,'amountCzk'=>round((float)$amount,2),'saleEan'=>$ean,'document'=>$document];
        $this->config->setAppValue(Application::APP_ID,'sale_statements',json_encode($rows,JSON_THROW_ON_ERROR));
        return $rows[$id];
    }

    private function statementRows(): array {
        $rows=json_decode($this->config->getAppValue(Application::APP_ID,'sale_statements','{}'),true);
        return is_array($rows)?$rows:[];
    }

    public function deleteStatement(string $id): void {
        $rows=$this->statementRows();
        if (!isset($rows[$id])) throw new \InvalidArgumentException('Unknown statement.');
        unset($rows[$id]); $this->config->setAppValue(Application::APP_ID,'sale_statements',json_encode($rows,JSON_THROW_ON_ERROR));
    }

    private function statements(): array {
        $rows=array_values($this->statementRows());
        foreach ($rows as &$row) {
            $qb=$this->db->getQueryBuilder();
            $daily=$qb->select('*')->from('hc_gridsight_daily')->where($qb->expr()->gte('day',$qb->createNamedParameter($row['from'])))
                ->andWhere($qb->expr()->lte('day',$qb->createNamedParameter($row['to'])))->executeQuery()->fetchAllAssociative();
            $zone=new \DateTimeZone(self::ZONE); $start=new \DateTimeImmutable($row['from'],$zone);
            $end=(new \DateTimeImmutable($row['to'],$zone))->modify('+1 day');
            $expectedDays=(int)$start->diff($end)->days; $complete=count($daily)===$expectedDays; $sale=0; $coverage=0;
            foreach ($daily as $day) {
                $date=new \DateTimeImmutable($day['day'],$zone); $seconds=$date->modify('+1 day')->getTimestamp()-$date->getTimestamp();
                if ((int)$day['coverage_sec']<$seconds*.995 || (int)$day['unpriced_export_mwh']>0) $complete=false;
                $coverage+=(int)$day['coverage_sec']; $sale+=(int)$day['sale_microczk'];
            }
             $qb=$this->db->getQueryBuilder();
            $intervals=$qb->select('ts','export_mwh')->from('hc_gridsight_edg_intervals')->where($qb->expr()->gte('ts',$qb->createNamedParameter($start->getTimestamp())))
                ->andWhere($qb->expr()->lt('ts',$qb->createNamedParameter($end->getTimestamp())))->executeQuery()->fetchAllAssociative();
            $row['distributorExportKWh']=count($intervals)===(int)(($end->getTimestamp()-$start->getTimestamp())/900) ? array_sum(array_column($intervals,'export_mwh'))/1_000_000 : null;
            $row['spotCzk']=$complete?$sale/1_000_000:null;
            $row['differenceCzk']=$complete?$row['amountCzk']-$row['spotCzk']:null;
            $row['coveragePct']=round(min(100,$coverage/max(1,$end->getTimestamp()-$start->getTimestamp())*100),2);
        }
        unset($row); usort($rows,static fn(array $a,array $b):int=>strcmp($b['from'],$a['from'])); return $rows;
    }

    private function rememberReportEan(string $ean, string $configuredEan): void {
        if ($configuredEan !== '') return;
        $profile = $this->profile();
        $profile['ean'] = $ean;
        $this->config->setAppValue(Application::APP_ID, 'analysis_profile', json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function report(string $period): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('hc_gridsight_edg_reports')->where($qb->expr()->eq('period',$qb->createNamedParameter($period)));
        return $qb->executeQuery()->fetchAssociative() ?: null;
    }

    /** A near-complete local day identifies which electrical level occupies ~20 hours. */
    private function shellyPolarity(array $evidence): array {
        $zone = new \DateTimeZone(self::ZONE);
        $days = [];
        foreach ($evidence as $ts=>$reading) {
            $day = (new \DateTimeImmutable('@'.$ts))->setTimezone($zone)->format('Y-m-d');
            $days[$day][$reading['state'] ? 'on' : 'off'] = ($days[$day][$reading['state'] ? 'on' : 'off'] ?? 0) + 900;
        }
        $votes = [];
        foreach ($days as $day=>$durations) {
            $start = new \DateTimeImmutable($day.' 00:00:00',$zone);
            $length = $start->modify('+1 day')->getTimestamp()-$start->getTimestamp();
            $on = $durations['on'] ?? 0; $off = $durations['off'] ?? 0;
            if ($on+$off < $length * .985) continue;
            // Offset by one hour on the two daylight-saving transition days.
            $short = 4*3600 + ($length-86400)/2;
            $long = 20*3600 + ($length-86400)/2;
            if (abs($on-$long) <= 2700 && abs($off-$short) <= 2700) $votes[] = true;
            elseif (abs($off-$long) <= 2700 && abs($on-$short) <= 2700) $votes[] = false;
        }
        if ($votes === [] || count(array_unique($votes, SORT_REGULAR)) !== 1) {
            return ['onMeansNt'=>null,'days'=>count($votes)];
        }
        return ['onMeansNt'=>$votes[0],'days'=>count($votes)];
    }

    private function tariff(int $timestamp, array $profile, array $evidence, ?bool $onMeansNt): array {
        $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone(self::ZONE));
        $day = $date->format('Y-m-d');
        foreach ($profile['hdoSchedules'] ?? [] as $schedule) {
            if ($day < $schedule['from'] || $day > $schedule['to']) continue;
            if (!$this->scheduleComplete($schedule, $profile)) continue;
            $minute = (int)$date->format('H') * 60 + (int)$date->format('i');
            foreach ($schedule['ntWindows'] as [$from,$to]) {
                if ($minute >= $from && $minute < $to) return ['tariff'=>'NT','source'=>'schedule'];
            }
            return ['tariff'=>'VT','source'=>'schedule'];
        }
        $reading = $evidence[$timestamp] ?? null;
        if ($onMeansNt === null || $reading === null) return ['tariff'=>null,'source'=>null];
        return ['tariff'=>$reading['state'] === $onMeansNt ? 'NT' : 'VT','source'=>$reading['source']];
    }

    /** D57d must not treat an incomplete list of NT windows as VT for the rest of the day. */
    private function scheduleComplete(array $schedule, array $profile): bool {
        if (strcasecmp((string)($profile['distributionTariff'] ?? ''),'D57d') !== 0) return true;
        $minutes = 0;
        foreach ($schedule['ntWindows'] ?? [] as [$from,$to]) $minutes += $to-$from;
        return $minutes === 1200;
    }

    public function overview(string $period = ''): array {
        $profile = $this->profile();
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('hc_gridsight_edg_reports')->orderBy('period','DESC')->setMaxResults(120);
        $reports = $qb->executeQuery()->fetchAllAssociative();
        if ($period === '' && $reports !== []) $period = (string)$reports[0]['period'];
        if ($period !== '' && (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$period) || !$this->report($period))) {
            throw new \InvalidArgumentException('Unknown ED.G report month.');
        }
        $recentEvidence = $this->history->hdoEvidence(time()-3*86400, time(), false);
        $recentShelly = $this->shellyPolarity($recentEvidence);
        $recentShelly['observedQuarters'] = count($recentEvidence);
        $result = ['statements'=>$this->statements(), 'profile'=>$profile,'shellyStatus'=>$recentShelly,'reports'=>array_map(static fn(array $r): array => [
            'period'=>$r['period'],'ean'=>$r['ean'],'rows'=>(int)$r['row_count'],
            'importKWh'=>(int)$r['import_mwh']/1_000_000,
            'exportKWh'=>(int)$r['export_mwh']/1_000_000,
            'importedAt'=>(int)$r['imported_at'],
        ],$reports),'selectedPeriod'=>$period,'month'=>null];
        if ($period === '') return $result;
        $qb = $this->db->getQueryBuilder();
        $qb->select('ts','import_mwh','export_mwh')->from('hc_gridsight_edg_intervals')
            ->where($qb->expr()->eq('period',$qb->createNamedParameter($period)))->orderBy('ts','ASC');
        $records = $qb->executeQuery()->fetchAllAssociative();
        if ($records === []) throw new \RuntimeException('Imported report intervals are missing.');
        $lastDay = (new \DateTimeImmutable($period . '-01'))->modify('last day of this month')->format('Y-m-d');
        $priceByDay = [];
        $daysInMonth = (int)(new \DateTimeImmutable($lastDay))->format('j');
        $fixed = 0.0;
        $pricesValid = true;
        $firstPricedDay = null; $lastPricedDay = null; $pricedDays = 0;
        for ($dayNumber = 1; $dayNumber <= $daysInMonth; $dayNumber++) {
            $dayKey = $period . '-' . str_pad((string)$dayNumber, 2, '0', STR_PAD_LEFT);
            $priceByDay[$dayKey] = null;
            foreach ($profile['pricePeriods'] ?? [] as $candidate) {
                if ($candidate['from'] <= $dayKey && $candidate['to'] >= $dayKey) {
                    $priceByDay[$dayKey] = $candidate;
                    $fixed += (float)$candidate['fixedCzkMonth'] / $daysInMonth;
                    $firstPricedDay ??= $dayKey;
                    $lastPricedDay = $dayKey;
                    $pricedDays++;
                    break;
                }
            }
            if ($priceByDay[$dayKey] === null) $pricesValid = false;
        }
        $zone = new \DateTimeZone(self::ZONE);
        $first = (int)$records[0]['ts'];
        $last = (int)$records[count($records)-1]['ts'];
        $evidence = $this->history->hdoEvidence($first-7*86400,$last+7*86400+900);
        $calibration = $this->shellyPolarity($evidence);
        $onMeansNt = $calibration['onMeansNt'];
        $incompleteSchedules = array_values(array_map(static fn(array $s): string => $s['from'].'–'.$s['to'],
            array_filter($profile['hdoSchedules'] ?? [], fn(array $s): bool => !$this->scheduleComplete($s,$profile))));
        $daily = [];
        $intervals = [];
        $vtKWh = $ntKWh = $unknownKWh = $variable = 0.0;
        $sources = ['schedule'=>0,'shelly'=>0,'snapshot'=>0,'unknown'=>0];
        foreach ($records as $record) {
            $ts = (int)$record['ts'];
            $date = (new \DateTimeImmutable('@'.$ts))->setTimezone($zone);
            $day = $date->format('Y-m-d');
            $tariffResult = $this->tariff($ts,$profile,$evidence,$onMeansNt);
            $tariff = $tariffResult['tariff'];
            $source = $tariffResult['source'] ?? 'unknown';
            $sources[$source ?? 'unknown']++;
            $price = $priceByDay[$day];
            $green = !empty($price['greenEnabled']) ? (float)$price['greenCzkKWh'] : 0.0;
            $vtPrice = (float)($price['vtCzkKWh'] ?? 0) + $green;
            $ntPrice = (float)($price['ntCzkKWh'] ?? 0) + $green;
            $import = (int)$record['import_mwh']/1_000_000;
            $export = (int)$record['export_mwh']/1_000_000;
            $cost = $price !== null && $tariff !== null ? $import * ($tariff === 'NT' ? $ntPrice : $vtPrice) : null;
            $daily[$day] ??= ['day'=>$day,'importKWh'=>0.0,'exportKWh'=>0.0,'vtKWh'=>0.0,'ntKWh'=>0.0,'unknownKWh'=>0.0,'unknownIntervals'=>0,'variableCzk'=>0.0,'priceAvailable'=>$price !== null];
            $daily[$day]['importKWh'] += $import;
            $daily[$day]['exportKWh'] += $export;
            if ($tariff === 'NT') { $ntKWh += $import; $daily[$day]['ntKWh'] += $import; }
            elseif ($tariff === 'VT') { $vtKWh += $import; $daily[$day]['vtKWh'] += $import; }
            else { $unknownKWh += $import; $daily[$day]['unknownKWh'] += $import; $daily[$day]['unknownIntervals']++; }
            if ($cost !== null) { $variable += $cost; $daily[$day]['variableCzk'] += $cost; }
            $intervals[] = ['ts'=>$ts,'localTime'=>$date->format('Y-m-d H:i'),'day'=>$day,'tariff'=>$tariff,'tariffSource'=>$source,
                'importKWh'=>$import,'exportKWh'=>$export,'variableCzk'=>$cost,
                'priceVt'=>$price !== null ? $vtPrice : null,'priceNt'=>$price !== null ? $ntPrice : null];
        }
        $tariffsComplete = count(array_filter($intervals,static fn(array $row): bool => $row['tariff'] === null)) === 0;
        $priced = $pricesValid && $tariffsComplete;
        $eligibleTariffsComplete = $pricedDays > 0 && count(array_filter($intervals, static fn(array $row): bool =>
            $row['priceVt'] !== null && $row['tariff'] === null)) === 0;
        $pricedFixed = round($fixed,2);
        $partial = !$pricesValid && $pricedDays > 0 ? [
            'from'=>$firstPricedDay,'to'=>$lastPricedDay,'days'=>$pricedDays,
            'complete'=>$eligibleTariffsComplete,
            'variableCzk'=>$eligibleTariffsComplete ? round($variable,2) : null,
            'fixedCzk'=>$pricedFixed,
            'estimatedTotalCzk'=>$eligibleTariffsComplete ? round($variable+$fixed,2) : null,
        ] : null;
        $fixed = $pricesValid ? round($fixed,2) : null;
        $report = $this->report($period);
        $summary = ['importKWh'=>(int)$report['import_mwh']/1_000_000,
            'exportKWh'=>(int)$report['export_mwh']/1_000_000,
            'vtKWh'=>$vtKWh,'ntKWh'=>$ntKWh,'unknownKWh'=>$unknownKWh,
            'variableCzk'=>$priced ? round($variable,2) : null,
            'fixedCzk'=>$fixed,'estimatedTotalCzk'=>$priced ? round($variable+$fixed,2) : null,
            'priceValid'=>$pricesValid,'tariffComplete'=>$tariffsComplete,
            'tariffSources'=>$sources,'shellyCalibration'=>$calibration,
            'partialPrice'=>$partial,'incompleteSchedules'=>$incompleteSchedules];

        // The hypothetical uses LINEA house load in the same quarter hours.
        // It assumes the entire house load would otherwise be bought from the grid.
        $from = (int)$records[0]['ts'];
        $to = (int)$records[count($records)-1]['ts'] + 900;
        $history = $this->history->getHistory($from,$to-1);
        $resolution = (int)$history['resolutionSec'];
        $samples = [];
        if (in_array($resolution,[300,900],true)) {
            foreach ($history['points'] as $point) {
                $ts = (int)$point['ts'];
                $quarter = intdiv($ts,900)*900;
                if ($quarter < $from || $quarter >= $to) continue;
                // A collected bucket without a usable house reading is not
                // evidence that the house consumed zero energy.
                if (($point['houseW'] ?? null) === null) continue;
                $samples[$quarter] ??= ['coverage'=>0,'houseKWh'=>0.0,'pvKWh'=>0.0,'pvCoverage'=>0];
                $samples[$quarter]['coverage'] += min($resolution,max(0,(int)$point['coverageSec']));
                $samples[$quarter]['houseKWh'] += (float)($point['houseKWh'] ?? 0);
                if (($point['pvW'] ?? null) !== null) {
                    $samples[$quarter]['pvCoverage'] += min($resolution,max(0,(int)$point['coverageSec']));
                    $samples[$quarter]['pvKWh'] += (float)($point['pvKWh'] ?? 0);
                }
            }
        }
        $coveredSeconds = 0; $house = 0.0; $pv = 0.0; $houseVariable = 0.0;
        foreach ($intervals as &$interval) {
            $sample = $samples[$interval['ts']] ?? null;
            $interval['houseKWh'] = $sample && $sample['coverage'] > 0 ? round($sample['houseKWh'],4) : null;
            $interval['pvKWh'] = $sample && $sample['pvCoverage'] > 0 ? round($sample['pvKWh'],4) : null;
            $interval['lineaCoveragePct'] = $sample ? round(min(900,$sample['coverage'])/9,1) : 0;
            $unitPrice = $interval['tariff'] === 'NT' ? $interval['priceNt'] : $interval['priceVt'];
            $interval['hypotheticalVariableCzk'] = $unitPrice !== null && $interval['tariff'] !== null
                && $sample && $sample['coverage'] >= 896
                ? round($sample['houseKWh'] * $unitPrice,2) : null;
            if (!$sample || $sample['coverage'] <= 0) continue;
            $coveredSeconds += min(900,$sample['coverage']);
            $house += $sample['houseKWh'];
            $pv += $sample['pvKWh'];
            if ($priced) $houseVariable += $sample['houseKWh'] * $unitPrice;
        }
        unset($interval);
        $coverage = $records !== [] ? $coveredSeconds/(count($records)*900) : 0;
        $summary['houseCoveredKWh'] = round($house,3);
        $summary['pvCoveredKWh'] = round($pv,3);
        $summary['lineaCoveragePct'] = round($coverage*100,2);
        $summary['hypotheticalVariableCzk'] = $priced && $coverage >= .995 ? round($houseVariable,2) : null;
        $summary['differenceCzk'] = $summary['hypotheticalVariableCzk'] === null ? null
            : round($summary['hypotheticalVariableCzk'] - $variable,2);
        $result['month'] = ['summary'=>$summary,'daily'=>array_values($daily),'intervals'=>$intervals];
        return $result;
    }
}
