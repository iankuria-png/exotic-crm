<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

class PaymentImportParserService
{
    public function parseUploadedFile(UploadedFile $file, bool $hasHeader = true): array
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));

        return match ($extension) {
            'csv', 'txt' => $this->parseCsv($file->getRealPath(), $hasHeader),
            'xlsx' => $this->parseXlsx($file->getRealPath(), $hasHeader),
            default => throw new InvalidArgumentException('Unsupported file type. Use CSV or XLSX.'),
        };
    }

    private function parseCsv(string $path, bool $hasHeader): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('Unable to read uploaded CSV file.');
        }

        $headers = [];
        $rows = [];
        $lineNumber = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber += 1;
            $cells = array_map(
                fn($value) => is_string($value) ? trim($value) : (string) $value,
                $row ?: []
            );

            if ($lineNumber === 1 && isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]) ?? $cells[0];
            }

            if ($this->rowIsEmpty($cells)) {
                continue;
            }

            if ($hasHeader && empty($headers)) {
                $headers = $this->normalizeHeaders($cells);
                continue;
            }

            if (empty($headers)) {
                for ($index = 0; $index < count($cells); $index += 1) {
                    $headers[] = 'column_' . ($index + 1);
                }
            } elseif (count($cells) > count($headers)) {
                for ($index = count($headers); $index < count($cells); $index += 1) {
                    $headers[] = 'column_' . ($index + 1);
                }
            }

            $rows[] = [
                'row_number' => $lineNumber,
                'values' => $this->mapRowValues($cells, $headers),
                'raw' => $cells,
            ];
        }

        fclose($handle);

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private function parseXlsx(string $path, bool $hasHeader): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('Unable to open uploaded XLSX file.');
        }

        $sheetPath = $this->resolveFirstWorksheetPath($zip);
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            $zip->close();
            throw new InvalidArgumentException('XLSX worksheet could not be read.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $zip->close();

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet = simplexml_load_string($sheetXml);
        if (!$sheet instanceof SimpleXMLElement) {
            throw new InvalidArgumentException('XLSX worksheet XML is invalid.');
        }

        $sheet->registerXPathNamespace('x', $namespace);
        $rowNodes = $sheet->xpath('//x:sheetData/x:row') ?: [];

        $headers = [];
        $rows = [];
        $rowIndex = 0;

        foreach ($rowNodes as $rowNode) {
            $rowIndex += 1;
            $rowAttributes = $rowNode->attributes();
            $excelRowNumber = (int) (($rowAttributes['r'] ?? null) ? (string) $rowAttributes['r'] : $rowIndex);
            $cellsByIndex = [];
            $maxColumnIndex = -1;

            foreach ($rowNode->children($namespace)->c as $cellNode) {
                $cellAttributes = $cellNode->attributes();
                $cellReference = (string) ($cellAttributes['r'] ?? '');
                $columnIndex = $this->columnIndexFromCellReference($cellReference);
                if ($columnIndex < 0) {
                    $columnIndex = $maxColumnIndex + 1;
                }

                $cellsByIndex[$columnIndex] = trim((string) $this->extractCellValue($cellNode, $sharedStrings, $namespace));
                $maxColumnIndex = max($maxColumnIndex, $columnIndex);
            }

            if ($maxColumnIndex < 0) {
                continue;
            }

            $cells = [];
            for ($index = 0; $index <= $maxColumnIndex; $index += 1) {
                $cells[] = (string) ($cellsByIndex[$index] ?? '');
            }

            if ($this->rowIsEmpty($cells)) {
                continue;
            }

            if ($hasHeader && empty($headers)) {
                $headers = $this->normalizeHeaders($cells);
                continue;
            }

            if (empty($headers)) {
                for ($index = 0; $index < count($cells); $index += 1) {
                    $headers[] = 'column_' . ($index + 1);
                }
            } elseif (count($cells) > count($headers)) {
                for ($index = count($headers); $index < count($cells); $index += 1) {
                    $headers[] = 'column_' . ($index + 1);
                }
            }

            $rows[] = [
                'row_number' => $excelRowNumber,
                'values' => $this->mapRowValues($cells, $headers),
                'raw' => $cells,
            ];
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private function resolveFirstWorksheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $defaultPath = 'xl/worksheets/sheet1.xml';

        if ($workbookXml === false || $relsXml === false) {
            return $defaultPath;
        }

        $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $pkgRelNs = 'http://schemas.openxmlformats.org/package/2006/relationships';

        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);
        if (!$workbook instanceof SimpleXMLElement || !$rels instanceof SimpleXMLElement) {
            return $defaultPath;
        }

        $workbook->registerXPathNamespace('x', $mainNs);
        $workbook->registerXPathNamespace('r', $relNs);
        $sheetNodes = $workbook->xpath('//x:sheets/x:sheet') ?: [];
        if (count($sheetNodes) === 0) {
            return $defaultPath;
        }

        $firstSheet = $sheetNodes[0];
        $relationshipId = (string) ($firstSheet->attributes($relNs)['id'] ?? '');
        if ($relationshipId === '') {
            return $defaultPath;
        }

        $rels->registerXPathNamespace('p', $pkgRelNs);
        $relationshipNodes = $rels->xpath('//p:Relationship') ?: [];

        foreach ($relationshipNodes as $relationshipNode) {
            if ((string) ($relationshipNode['Id'] ?? '') !== $relationshipId) {
                continue;
            }

            $target = (string) ($relationshipNode['Target'] ?? '');
            if ($target === '') {
                continue;
            }

            $path = 'xl/' . ltrim($target, '/');
            if ($zip->locateName($path) !== false) {
                return $path;
            }
        }

        return $defaultPath;
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml === false) {
            return [];
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $shared = simplexml_load_string($sharedXml);
        if (!$shared instanceof SimpleXMLElement) {
            return [];
        }

        $shared->registerXPathNamespace('x', $namespace);
        $stringNodes = $shared->xpath('//x:si') ?: [];

        $strings = [];
        foreach ($stringNodes as $node) {
            $strings[] = $this->extractSharedString($node, $namespace);
        }

        return $strings;
    }

    private function extractSharedString(SimpleXMLElement $node, string $namespace): string
    {
        $node->registerXPathNamespace('x', $namespace);
        $textNodes = $node->xpath('.//x:t') ?: [];
        if (count($textNodes) === 0) {
            return '';
        }

        return implode('', array_map(fn($textNode) => (string) $textNode, $textNodes));
    }

    private function extractCellValue(SimpleXMLElement $cellNode, array $sharedStrings, string $namespace): string
    {
        $attributes = $cellNode->attributes();
        $type = strtolower((string) ($attributes['t'] ?? ''));
        $children = $cellNode->children($namespace);

        if ($type === 's') {
            $index = (int) ($children->v ?? 0);
            return (string) ($sharedStrings[$index] ?? '');
        }

        if ($type === 'inlineStr') {
            $inline = $children->is;
            if (!$inline instanceof SimpleXMLElement) {
                return '';
            }

            $inline->registerXPathNamespace('x', $namespace);
            $textNodes = $inline->xpath('.//x:t') ?: [];
            if (count($textNodes) === 0) {
                return '';
            }

            return implode('', array_map(fn($textNode) => (string) $textNode, $textNodes));
        }

        return (string) ($children->v ?? '');
    }

    private function columnIndexFromCellReference(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) {
            return -1;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;
        $length = strlen($letters);

        for ($position = 0; $position < $length; $position += 1) {
            $index = ($index * 26) + (ord($letters[$position]) - 64);
        }

        return $index - 1;
    }

    private function normalizeHeaders(array $rawHeaders): array
    {
        $headers = [];
        $seen = [];

        foreach ($rawHeaders as $index => $rawHeader) {
            $base = $this->normalizeHeader((string) $rawHeader, $index + 1);
            $candidate = $base;
            $suffix = 2;

            while (isset($seen[$candidate])) {
                $candidate = "{$base}_{$suffix}";
                $suffix += 1;
            }

            $seen[$candidate] = true;
            $headers[] = $candidate;
        }

        return $headers;
    }

    private function normalizeHeader(string $header, int $position): string
    {
        $normalized = strtolower(trim($header));
        $normalized = preg_replace('/\s+/', '_', $normalized) ?? '';
        $normalized = preg_replace('/[^a-z0-9_]/', '', $normalized) ?? '';
        $normalized = trim($normalized, '_');

        return $normalized !== '' ? $normalized : "column_{$position}";
    }

    private function rowIsEmpty(array $cells): bool
    {
        foreach ($cells as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function mapRowValues(array $cells, array $headers): array
    {
        $mapped = [];

        foreach ($cells as $index => $value) {
            $header = $headers[$index] ?? ('column_' . ($index + 1));
            $mapped[$header] = trim((string) $value);
        }

        return $mapped;
    }
}
