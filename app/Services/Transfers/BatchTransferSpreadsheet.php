<?php

namespace App\Services\Transfers;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class BatchTransferSpreadsheet
{
    /** @return array<int, array{ddi: string, phone: string, amount: float}> */
    public function parse(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'mpmm-batch-');

        if ($path === false || file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Não foi possível preparar a planilha para leitura.');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                throw new RuntimeException('O arquivo enviado não é uma planilha XLSX válida.');
            }

            try {
                $this->assertRecipientsTable($zip);
                $sharedStrings = $this->sharedStrings($zip);
                $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');

                if ($sheet === false) {
                    throw new RuntimeException('A planilha não contém a aba de destinatários.');
                }

                return $this->rows($sheet, $sharedStrings);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }

    private function assertRecipientsTable(ZipArchive $zip): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (! is_string($name) || ! preg_match('#^xl/tables/table\d+\.xml$#', $name)) {
                continue;
            }

            $xml = $zip->getFromName($name);

            if (is_string($xml) && preg_match('/\b(?:name|displayName)="destinatarios"/i', $xml)) {
                return;
            }
        }

        throw new RuntimeException('A planilha precisa conter a tabela chamada "destinatarios".');
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $xpath = $this->xpath($xml);
        $strings = [];

        foreach ($xpath->query('/*[local-name()="sst"]/*[local-name()="si"]') ?: [] as $item) {
            $parts = [];
            foreach ((new DOMXPath($item->ownerDocument))->query('.//*[local-name()="t"]', $item) ?: [] as $text) {
                $parts[] = $text->textContent;
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    /** @return array<int, array{ddi: string, phone: string, amount: float}> */
    private function rows(string $xml, array $sharedStrings): array
    {
        $xpath = $this->xpath($xml);
        $rows = [];
        $headers = [];

        foreach ($xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $rowIndex => $row) {
            $values = [];

            foreach ($xpath->query('./*[local-name()="c"]', $row) ?: [] as $cell) {
                $reference = (string) $cell->attributes?->getNamedItem('r')?->nodeValue;
                preg_match('/^[A-Z]+/', $reference, $match);
                $column = $match[0] ?? '';
                $type = (string) $cell->attributes?->getNamedItem('t')?->nodeValue;
                $valueNode = $xpath->query('./*[local-name()="v"]', $cell)?->item(0);
                $value = $valueNode?->textContent ?? '';

                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = $xpath->query('.//*[local-name()="t"]', $cell)?->item(0)?->textContent ?? '';
                }

                $values[$column] = trim($value);
            }

            if ($rowIndex === 0) {
                foreach ($values as $column => $header) {
                    $headers[mb_strtolower(trim($header))] = $column;
                }

                foreach (['ddi', 'telefone', 'valor'] as $required) {
                    if (! isset($headers[$required])) {
                        throw new RuntimeException('A planilha precisa ter as colunas DDI, Telefone e Valor.');
                    }
                }

                continue;
            }

            $ddi = preg_replace('/\D+/', '', $values[$headers['ddi']] ?? '') ?: '';
            $phone = preg_replace('/\D+/', '', $values[$headers['telefone']] ?? '') ?: '';
            $amount = $this->amount($values[$headers['valor']] ?? '');

            if ($ddi === '' && $phone === '' && $amount === null) {
                continue;
            }

            if ($ddi === '' || $phone === '' || $amount === null || $amount <= 0) {
                throw new RuntimeException('Há uma linha com DDI, telefone ou valor inválido.');
            }

            $rows[] = ['ddi' => $ddi, 'phone' => $phone, 'amount' => $amount];
        }

        if ($rows === []) {
            throw new RuntimeException('A planilha não possui destinatários.');
        }

        if (count($rows) > 25) {
            throw new RuntimeException('Você só pode transferir para até 25 destinatários por lote.');
        }

        return $rows;
    }

    private function amount(string $value): ?float
    {
        $value = trim(str_replace(['R$', ' '], '', $value));

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('A estrutura interna da planilha é inválida.');
        }

        return new DOMXPath($document);
    }
}
