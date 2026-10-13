<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Salinan BACA-SAJA data bisnis dalam satu file Excel, untuk arsip dan dilihat pemilik.
 * BUKAN bahan restore (itu tugas database.sql).
 *
 * Desain:
 *  - Hanya tabel di config('backup.excel_tables') (allowlist). Kolom sensitif (password,
 *    remember_token) tidak ada di daftar kolom `users`.
 *  - Streaming (openspout + lazyById): memori tetap kecil walau ratusan ribu baris;
 *    server hanya punya RAM 2–4GB.
 *  - Teks SELALU ditulis sebagai StringCell eksplisit. Cell::fromValue() bawaan openspout
 *    mengubah teks berawalan "=" menjadi RUMUS aktif — nama produk "=HYPERLINK(...)" akan
 *    jadi injeksi rumus saat arsip dibuka di Excel. Jangan ganti kembali ke Row::fromValues()
 *    untuk data dari database.
 *  - Lembar yang melebihi batas baris Excel dipecah otomatis: "Transaksi", "Transaksi (2)", ...
 */
class ExcelArchive
{
    /** Batas Excel 1.048.576 baris, termasuk 1 baris header. */
    public const MAX_ROWS_PER_SHEET = 1_048_000;

    private const NUMERIC_TYPES = [
        'int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint',
        'decimal', 'numeric', 'float', 'double', 'real',
    ];

    public function __construct(private int $maxRowsPerSheet = self::MAX_ROWS_PER_SHEET)
    {
    }

    /**
     * @return list<string> peringatan (mis. tabel yang dilewati); kosong bila semua lancar
     */
    public function write(string $path, \DateTimeInterface $createdAt): array
    {
        $warnings = [];
        $writer = new Writer();
        $writer->openToFile($path);

        try {
            $header = (new Style())->setFontBold();

            $writer->getCurrentSheet()->setName('Info');
            $writer->addRow(Row::fromValues(['Arsip data Retail Management'], $header));
            $writer->addRow(Row::fromValues(['Dibuat', $createdAt->format('Y-m-d H:i:s T')]));
            $writer->addRow(Row::fromValues([
                'Catatan',
                'Salinan BACA-SAJA untuk arsip. File ini TIDAK bisa dipakai untuk restore; gunakan file database.sql di dalam paket backup.',
            ]));

            foreach ((array) config('backup.excel_tables') as $table => $def) {
                try {
                    $this->writeTable($writer, $header, (string) $table, $def);
                } catch (\Throwable $e) {
                    $warnings[] = "Tabel {$table} dilewati di Excel: ".mb_substr($e->getMessage(), 0, 150);
                }
            }
        } finally {
            $writer->close();
        }

        return $warnings;
    }

    /** @param  array{label: string, columns: ?array<int, string>}  $def */
    private function writeTable(Writer $writer, Style $header, string $table, array $def): void
    {
        if (! Schema::hasTable($table)) {
            throw new \RuntimeException('tabel tidak ada');
        }

        $types = [];
        foreach (Schema::getColumns($table) as $col) {
            $types[$col['name']] = strtolower((string) ($col['type_name'] ?? ''));
        }

        $columns = $def['columns'] ?? array_keys($types);
        $columns = array_values(array_filter($columns, fn ($c) => isset($types[$c])));
        if ($columns === []) {
            throw new \RuntimeException('tidak ada kolom yang bisa diekspor');
        }

        $label = $def['label'];
        $part = 1;
        $rows = 0;

        $writer->addNewSheetAndMakeItCurrent()->setName($this->sheetName($label, $part));
        $writer->addRow(Row::fromValues($columns, $header));
        $rows++;

        $query = DB::table($table)->select($columns);

        foreach ($query->lazyById(1000, 'id') as $record) {
            if ($rows >= $this->maxRowsPerSheet) {
                $part++;
                $writer->addNewSheetAndMakeItCurrent()->setName($this->sheetName($label, $part));
                $writer->addRow(Row::fromValues($columns, $header));
                $rows = 1;
            }

            $values = [];
            foreach ($columns as $c) {
                $values[] = $this->cast($record->{$c} ?? null, $types[$c]);
            }

            $writer->addRow($this->row($values));
            $rows++;
        }
    }

    /** @param  list<mixed>  $values */
    private function row(array $values): Row
    {
        return new Row(array_map(
            fn ($v) => is_string($v) && $v !== '' ? new StringCell($v, null) : Cell::fromValue($v),
            $values
        ));
    }

    private function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        if (in_array($type, self::NUMERIC_TYPES, true) && is_numeric($value)) {
            return $value + 0; // "12.500" -> 12.5, "3" -> 3
        }

        return (string) $value;
    }

    /** Nama lembar Excel: maks 31 karakter, tanpa [ ] : * ? / \ */
    private function sheetName(string $label, int $part): string
    {
        $suffix = $part > 1 ? " ({$part})" : '';
        $clean = preg_replace('/[\[\]:*?\/\\\\]/', '', $label);

        return mb_substr($clean, 0, 31 - mb_strlen($suffix)).$suffix;
    }
}