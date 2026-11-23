<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use ZipArchive;

/**
 * Minimal, dependency-free reader for CSV and XLSX (first worksheet) used by
 * imports. Returns raw cell text; callers validate everything.
 *
 * Safety: row and size limits, no formulas evaluated (cached values only),
 * no external entities (SimpleXML with LIBXML_NONET; PHP 8 never loads
 * external entities), and the XLSX parts are size-checked before reading
 * (zip-bomb guard).
 */
class SpreadsheetReader
{
    public const MAX_ROWS = 5001;          // header + 5000 data rows

    private const MAX_PART_BYTES = 30_000_000;

    /** @return list<list<string>> */
    public static function read(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($ext) {
            'csv', 'txt' => self::csv($path),
            'xlsx' => self::xlsx($path),
            default => throw BusinessRuleException::make('file', __('Upload a .csv or .xlsx file.')),
        };
    }

    /** @return list<list<string>> */
    private static function csv(string $path): array
    {
        $handle = fopen($path, 'r');
        $first = (string) fgets($handle);
        rewind($handle);
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                throw BusinessRuleException::make('file', __('The file has more than :max rows.', ['max' => self::MAX_ROWS - 1]));
            }
            $rows[] = array_map(fn ($v) => self::clean((string) $v), $row);
        }
        fclose($handle);

        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);   // UTF-8 BOM (Excel)
        }

        return self::dropEmpty($rows);
    }

    /** @return list<list<string>> */
    private static function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw BusinessRuleException::make('file', __('This Excel file could not be opened.'));
        }

        try {
            $part = function (string $name) use ($zip): ?\SimpleXMLElement {
                $stat = $zip->statName($name);
                if ($stat === false) {
                    return null;
                }
                if ($stat['size'] > self::MAX_PART_BYTES) {
                    throw BusinessRuleException::make('file', __('The Excel file is too large.'));
                }
                $xml = simplexml_load_string((string) $zip->getFromName($name), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);

                return $xml === false ? null : $xml;
            };

            // first worksheet via workbook relationships
            $sheetPath = 'xl/worksheets/sheet1.xml';
            $workbook = $part('xl/workbook.xml');
            $rels = $part('xl/_rels/workbook.xml.rels');
            if ($workbook && $rels) {
                $rid = (string) ($workbook->sheets->sheet[0]?->attributes('r', true)['id'] ?? '');
                foreach ($rels->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = ltrim((string) $rel['Target'], '/');
                        $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                    }
                }
            }

            $shared = [];
            if ($sst = $part('xl/sharedStrings.xml')) {
                foreach ($sst->si as $si) {
                    $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', iterator_to_array($si->xpath('.//*[local-name()="t"]'), false)));
                }
            }

            $sheet = $part($sheetPath) ?? throw BusinessRuleException::make('file', __('The Excel file has no worksheet.'));
            $rows = [];
            foreach ($sheet->sheetData->row as $row) {
                if (count($rows) >= self::MAX_ROWS) {
                    throw BusinessRuleException::make('file', __('The file has more than :max rows.', ['max' => self::MAX_ROWS - 1]));
                }
                $cells = [];
                foreach ($row->c as $c) {
                    $index = self::columnIndex((string) $c['r']) ?? count($cells);
                    $type = (string) $c['t'];
                    $value = match ($type) {
                        's' => $shared[(int) $c->v] ?? '',
                        'inlineStr' => (string) ($c->is->t ?? ''),
                        'b' => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                        default => (string) $c->v,
                    };
                    $cells[$index] = self::clean($value);
                }
                if ($cells !== []) {
                    $max = max(array_keys($cells));
                    $rows[] = array_map(fn ($i) => $cells[$i] ?? '', range(0, $max));
                }
            }

            return self::dropEmpty($rows);
        } finally {
            $zip->close();
        }
    }

    private static function columnIndex(string $ref): ?int
    {
        if (! preg_match('/^([A-Z]+)\d+$/', $ref, $m)) {
            return null;
        }
        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    private static function clean(string $value): string
    {
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '');
    }

    /** @param list<list<string>> $rows */
    private static function dropEmpty(array $rows): array
    {
        return array_values(array_filter($rows, fn ($r) => implode('', $r) !== ''));
    }

    /** Excel serial date (e.g. 45627) or common text formats -> Y-m-d; '' when empty; null when invalid. */
    public static function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $value)) {
            return date('Y-m-d', (int) round(((float) $value - 25569) * 86400));
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $value);
            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
