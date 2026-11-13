<?php

namespace App\Services\InitialDataImport;

use DOMDocument;
use RuntimeException;
use ZipArchive;

class XlsxWorkbookReader
{
    public function read(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to read Excel workbooks.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The uploaded file is not a valid Excel workbook.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $workbook = $this->xml($zip->getFromName('xl/workbook.xml'));
            $relationships = $this->xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
            $relMap = [];

            foreach ($relationships->getElementsByTagName('Relationship') as $relationship) {
                $relMap[$relationship->getAttribute('Id')] = $relationship->getAttribute('Target');
            }

            $sheets = [];
            foreach ($workbook->getElementsByTagName('sheet') as $sheet) {
                $name = $sheet->getAttribute('name');
                $rid = $sheet->getAttribute('r:id');
                $target = $relMap[$rid] ?? null;
                if (! $target) {
                    continue;
                }

                $target = ltrim(str_replace('../', '', $target), '/');
                $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                $xml = $zip->getFromName($sheetPath);
                if ($xml === false) {
                    continue;
                }

                $sheets[$name] = $this->sheetRows($xml, $sharedStrings);
            }

            return $sheets;
        } finally {
            $zip->close();
        }
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $document = $this->xml($xml);
        $strings = [];
        foreach ($document->getElementsByTagName('si') as $item) {
            $value = '';
            foreach ($item->getElementsByTagName('t') as $text) {
                $value .= $text->textContent;
            }
            $strings[] = $value;
        }

        return $strings;
    }

    private function sheetRows(string $xml, array $sharedStrings): array
    {
        $document = $this->xml($xml);
        $rows = [];

        foreach ($document->getElementsByTagName('row') as $rowNode) {
            $row = [];
            foreach ($rowNode->getElementsByTagName('c') as $cell) {
                $reference = $cell->getAttribute('r');
                preg_match('/^[A-Z]+/', $reference, $matches);
                if (! isset($matches[0])) {
                    continue;
                }

                $column = $this->columnNumber($matches[0]);
                $type = $cell->getAttribute('t');
                $value = '';
                if ($type === 'inlineStr') {
                    foreach ($cell->getElementsByTagName('t') as $text) {
                        $value .= $text->textContent;
                    }
                } else {
                    $valueNode = $cell->getElementsByTagName('v')->item(0);
                    $value = $valueNode?->textContent ?? '';
                    if ($type === 's' && $value !== '') {
                        $value = $sharedStrings[(int) $value] ?? '';
                    } elseif ($type === 'b') {
                        $value = $value === '1' ? 'TRUE' : 'FALSE';
                    }
                }

                $row[$column] = trim((string) $value);
            }

            if ($row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = ($number * 26) + (ord($letter) - 64);
        }
        return $number;
    }

    private function xml(string|false $xml): DOMDocument
    {
        if ($xml === false) {
            throw new RuntimeException('The Excel workbook is missing a required worksheet component.');
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('The Excel workbook contains invalid XML.');
        }

        return $document;
    }
}
