<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;

/**
 * GridSight · Parser.php
 * ══════════════════════════════════════════════════════════
 * Parsuje XLSX exporty ze zákaznického portálu distributora.
 * Formát: REP_DATA_<EAN>_<RRRR-MM>.xlsx
 *
 * Hlavička na řádcích 1–4, data od řádku 5.
 * Sloupce: A=čas, B=DCC0, C=DCC1, ... M=DSC1
 */
class Parser
{
    /**
     * @return array{rows:list<array>, from:string, to:string, count:int}
     * @throws RuntimeException
     */
    public static function parseXlsx(string $path): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Soubor nenalezen: {$path}");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getActiveSheet();

        $rows = [];

        foreach ($sheet->getRowIterator(5) as $row) {
            $cells = [];
            $iter  = $row->getCellIterator('A', 'M');
            $iter->setIterateOnlyExistingCells(false);
            foreach ($iter as $cell) {
                $cells[] = $cell->getValue();
            }

            if (empty($cells[0])) continue;

            $ts = self::parseTs($cells[0]);
            if ($ts === null) continue;

            $dbRow = [$ts];
            for ($i = 1; $i <= 12; $i++) {
                $v       = $cells[$i] ?? 0;
                $dbRow[] = is_numeric($v) ? (float)$v : 0.0;
            }
            $rows[] = $dbRow;
        }

        if (empty($rows)) {
            throw new \RuntimeException('Žádná platná data. Zkontroluj formát souboru (REP_DATA_*.xlsx).');
        }

        $tsList = array_column($rows, 0);
        return [
            'rows'  => $rows,
            'from'  => min($tsList),
            'to'    => max($tsList),
            'count' => count($rows),
        ];
    }

    private static function parseTs(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;

        // Excel date serial (float)
        if (is_numeric($v) && (float)$v > 1000) {
            try {
                return XlDate::excelToDateTimeObject((float)$v)->format('Y-m-d\TH:i:s');
            } catch (\Throwable) {}
        }

        $s = trim((string)$v);

        // "01.04.2026 00:15:00"
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}:\d{2}:\d{2})$/', $s, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}T{$m[4]}";
        }
        // "2026-04-01 00:15:00" nebo ISO
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})$/', $s, $m)) {
            return "{$m[1]}T{$m[2]}";
        }
        return null;
    }
}
