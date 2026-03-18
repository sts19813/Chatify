<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

class ImportKiroMasterDbCommand extends Command
{
    private const XLSX_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const XLSX_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const XLSX_DOC_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kiro:import-master-db
        {--file=storage/KIRO_Yucatan_Master_DB.xlsx : Ruta al archivo XLSX}
        {--sheet=Master_DB : Nombre de la hoja a importar}
        {--truncate=1 : 1 limpia la tabla antes de importar, 0 hace upsert}
        {--chunk=500 : Tamano de lote para inserts/upserts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importa el XLSX maestro de KIRO a la tabla kiro_master_places';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!Schema::hasTable('kiro_master_places')) {
            $this->error('La tabla kiro_master_places no existe. Ejecuta primero: php artisan migrate');
            return self::FAILURE;
        }

        $xlsxPath = $this->resolveFilePath((string) $this->option('file'));
        $sheetName = trim((string) $this->option('sheet'));
        $truncate = (bool) ((int) $this->option('truncate'));
        $chunkSize = max(100, (int) $this->option('chunk'));

        if (!is_file($xlsxPath) || !is_readable($xlsxPath)) {
            $this->error("No se puede leer el archivo XLSX: {$xlsxPath}");
            return self::FAILURE;
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($xlsxPath) !== true) {
                throw new RuntimeException("No pude abrir el archivo XLSX: {$xlsxPath}");
            }

            $sharedStrings = $this->loadSharedStrings($zip);
            $sheetPath = $this->resolveSheetPath($zip, $sheetName);

            if ($sheetPath === null) {
                throw new RuntimeException("No se encontro la hoja [{$sheetName}] dentro del XLSX.");
            }

            $this->info("Importando hoja [{$sheetName}] desde: {$xlsxPath}");
            $this->line("Sheet path: {$sheetPath}");

            DB::disableQueryLog();

            if ($truncate) {
                DB::table('kiro_master_places')->truncate();
                $this->line('Tabla kiro_master_places limpiada.');
            }

            $insertBatch = [];
            $processed = 0;
            $stored = 0;
            $upsertColumns = [];

            foreach ($this->iterateSheetRows($xlsxPath, $sheetPath, $sharedStrings) as $row) {
                $payload = $this->mapRowToDatabasePayload($row);

                if ($payload === null) {
                    continue;
                }

                if ($upsertColumns === []) {
                    $upsertColumns = array_values(array_filter(array_keys($payload), static fn (string $column): bool => $column !== 'record_id'));
                }

                $insertBatch[] = $payload;
                $processed++;

                if (count($insertBatch) < $chunkSize) {
                    continue;
                }

                $stored += $this->storeBatch($insertBatch, $truncate, $upsertColumns);
                $insertBatch = [];

                if ($processed % 1000 === 0) {
                    $this->line("Procesadas: {$processed}");
                }
            }

            if ($insertBatch !== []) {
                $stored += $this->storeBatch($insertBatch, $truncate, $upsertColumns);
            }

            $zip->close();

            $tableCount = DB::table('kiro_master_places')->count();
            $this->info("Importacion completada. Filas procesadas: {$processed}. Filas en tabla: {$tableCount}.");
            $this->line("Filas almacenadas en esta corrida: {$stored}");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Error importando XLSX: ' . $exception->getMessage());
            return self::FAILURE;
        }
    }

    private function resolveFilePath(string $rawPath): string
    {
        $rawPath = trim($rawPath);

        if ($rawPath === '') {
            return base_path('storage/KIRO_Yucatan_Master_DB.xlsx');
        }

        if ($this->isAbsolutePath($rawPath)) {
            return $rawPath;
        }

        return base_path($rawPath);
    }

    private function isAbsolutePath(string $path): bool
    {
        return Str::startsWith($path, ['/', '\\']) || (bool) preg_match('/^[A-Za-z]:[\/\\\\]/', $path);
    }

    private function loadSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false || trim($xml) === '') {
            return [];
        }

        $sharedXml = simplexml_load_string($xml);

        if (!$sharedXml instanceof SimpleXMLElement) {
            return [];
        }

        $sharedXml->registerXPathNamespace('x', self::XLSX_NS);
        $items = $sharedXml->xpath('//x:si') ?: [];
        $values = [];

        foreach ($items as $item) {
            $item->registerXPathNamespace('x', self::XLSX_NS);
            $texts = $item->xpath('.//x:t') ?: [];
            $value = '';

            foreach ($texts as $textNode) {
                $value .= (string) $textNode;
            }

            $values[] = trim($value);
        }

        return $values;
    }

    private function resolveSheetPath(ZipArchive $zip, string $sheetName): ?string
    {
        $workbookXmlRaw = $zip->getFromName('xl/workbook.xml');
        $relsXmlRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXmlRaw === false || $relsXmlRaw === false) {
            return null;
        }

        $workbookXml = simplexml_load_string($workbookXmlRaw);
        $relsXml = simplexml_load_string($relsXmlRaw);

        if (!$workbookXml instanceof SimpleXMLElement || !$relsXml instanceof SimpleXMLElement) {
            return null;
        }

        $workbookXml->registerXPathNamespace('x', self::XLSX_NS);
        $relsXml->registerXPathNamespace('r', self::XLSX_REL_NS);

        $sheetNodes = $workbookXml->xpath('//x:sheets/x:sheet') ?: [];
        $sheetRid = null;

        foreach ($sheetNodes as $sheetNode) {
            if ((string) ($sheetNode['name'] ?? '') !== $sheetName) {
                continue;
            }

            $docRelAttributes = $sheetNode->attributes(self::XLSX_DOC_REL_NS);
            $sheetRid = (string) ($docRelAttributes['id'] ?? '');
            break;
        }

        if (!$sheetRid) {
            return null;
        }

        $relNodes = $relsXml->xpath('//r:Relationship') ?: [];

        foreach ($relNodes as $relNode) {
            if ((string) ($relNode['Id'] ?? '') !== $sheetRid) {
                continue;
            }

            $target = trim((string) ($relNode['Target'] ?? ''));

            if ($target === '') {
                return null;
            }

            if (Str::startsWith($target, 'xl/')) {
                return $target;
            }

            return 'xl/' . ltrim($target, '/');
        }

        return null;
    }

    private function iterateSheetRows(string $xlsxPath, string $sheetPath, array $sharedStrings): \Generator
    {
        $reader = new XMLReader();
        $sheetUri = 'zip://' . str_replace('\\', '/', $xlsxPath) . '#' . $sheetPath;

        if (!$reader->open($sheetUri)) {
            throw new RuntimeException("No pude abrir la hoja XML: {$sheetPath}");
        }

        $headerMap = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'row') {
                    continue;
                }

                $rowXml = $reader->readOuterXML();
                $cells = $this->parseRowXml($rowXml, $sharedStrings);

                if ($headerMap === []) {
                    $headerMap = $this->buildHeaderMap($cells);
                    continue;
                }

                $row = [];

                foreach ($headerMap as $columnIndex => $headerName) {
                    $row[$headerName] = $cells[$columnIndex] ?? '';
                }

                yield $row;
            }
        } finally {
            $reader->close();
        }
    }

    private function parseRowXml(string $rowXml, array $sharedStrings): array
    {
        $rowNode = simplexml_load_string($rowXml);

        if (!$rowNode instanceof SimpleXMLElement) {
            return [];
        }

        $rowNode->registerXPathNamespace('x', self::XLSX_NS);
        $cells = $rowNode->xpath('./x:c') ?: [];
        $values = [];

        foreach ($cells as $cell) {
            $cellRef = (string) ($cell['r'] ?? '');

            if ($cellRef === '') {
                continue;
            }

            $index = $this->columnIndexFromReference($cellRef);
            $values[$index] = $this->extractCellValue($cell, $sharedStrings);
        }

        return $values;
    }

    private function buildHeaderMap(array $headerCells): array
    {
        ksort($headerCells);

        $headerMap = [];
        $used = [];

        foreach ($headerCells as $index => $headerValue) {
            $raw = trim((string) $headerValue);
            $base = $raw !== '' ? Str::snake($raw) : "column_{$index}";
            $name = $base;
            $counter = 2;

            while (isset($used[$name])) {
                $name = "{$base}_{$counter}";
                $counter++;
            }

            $used[$name] = true;
            $headerMap[$index] = $name;
        }

        return $headerMap;
    }

    private function extractCellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');
        $children = $cell->children(self::XLSX_NS);

        if ($type === 's') {
            $sharedRaw = trim((string) ($children->v ?? ''));
            $sharedIndex = $sharedRaw === '' ? -1 : (int) $sharedRaw;

            return ($sharedIndex >= 0 && isset($sharedStrings[$sharedIndex]))
                ? trim((string) $sharedStrings[$sharedIndex])
                : '';
        }

        if ($type === 'inlineStr') {
            if (isset($children->is)) {
                $inlineChildren = $children->is->children(self::XLSX_NS);
                if (isset($inlineChildren->t)) {
                    return trim((string) $inlineChildren->t);
                }
            }
        }

        return trim((string) ($children->v ?? ''));
    }

    private function columnIndexFromReference(string $cellReference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellReference)) ?? '';
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function mapRowToDatabasePayload(array $row): ?array
    {
        $recordId = $this->nullable(trim((string) ($row['record_id'] ?? '')));

        if ($recordId === null) {
            return null;
        }

        $rating = $this->toFloat($row['rating'] ?? null);
        $priceFrom = $this->toFloat($row['price_from'] ?? null);
        $sourceCount = $this->toInt($row['source_count'] ?? null);
        $priceRange = $this->nullable($row['price_range'] ?? null);
        $size = $this->nullable($row['size'] ?? null);
        $budgetLevel = $this->deriveBudgetLevel($priceRange, $priceFrom, $size);

        [$latitude, $longitude, $geoPrecision] = $this->extractCoordinates($row['google_maps_url'] ?? null);

        $address = $this->nullable($row['address'] ?? null);
        $neighborhood = $this->nullable($row['neighborhood'] ?? null);
        $city = $this->nullable($row['city'] ?? null);
        $state = $this->nullable($row['state'] ?? null);
        $postalCode = $this->normalizePostalCode($row['postal_code'] ?? null);
        $phone = $this->normalizePhone($row['phone'] ?? null);
        $email = $this->normalizeEmail($row['email'] ?? null);
        $website = $this->normalizeWebsite($row['website'] ?? null);

        $searchableText = $this->buildSearchableText([
            $row['name'] ?? null,
            $row['primary_type'] ?? null,
            $row['secondary_type'] ?? null,
            $row['category'] ?? null,
            $address,
            $neighborhood,
            $city,
            $state,
            $postalCode,
            $row['features'] ?? null,
            $row['hours'] ?? null,
            $row['review_snippet'] ?? null,
        ]);

        $timestamp = now()->format('Y-m-d H:i:s');

        return [
            'record_id' => $recordId,
            'primary_type' => $this->nullable($row['primary_type'] ?? null),
            'name' => $this->nullable($row['name'] ?? null),
            'secondary_type' => $this->nullable($row['secondary_type'] ?? null),
            'rating' => $rating,
            'price_range' => $priceRange,
            'price_from' => $priceFrom,
            'budget_level' => $budgetLevel,
            'address' => $address,
            'neighborhood' => $neighborhood,
            'city' => $city,
            'state' => $state,
            'postal_code' => $postalCode,
            'phone' => $phone,
            'email' => $email,
            'website' => $website,
            'google_maps_url' => $this->nullable($row['google_maps_url'] ?? null),
            'hours' => $this->nullable($row['hours'] ?? null),
            'features' => $this->nullable($row['features'] ?? null),
            'review_snippet' => $this->nullable($row['review_snippet'] ?? null),
            'legal_name' => $this->nullable($row['legal_name'] ?? null),
            'category' => $this->nullable($row['category'] ?? null),
            'size' => $size,
            'merged_from_sources' => $this->nullable($row['merged_from_sources'] ?? null),
            'source_count' => $sourceCount,
            'source_files' => $this->nullable($row['source_files'] ?? null),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'geo_precision' => $geoPrecision,
            'searchable_text' => $searchableText,
            'raw_payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    private function storeBatch(array $batch, bool $truncate, array $upsertColumns): int
    {
        if ($batch === []) {
            return 0;
        }

        if ($truncate) {
            DB::table('kiro_master_places')->insert($batch);
            return count($batch);
        }

        DB::table('kiro_master_places')->upsert(
            $batch,
            ['record_id'],
            $upsertColumns,
        );

        return count($batch);
    }

    private function toFloat(mixed $value): ?float
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        $normalized = str_replace(',', '.', $raw);

        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function toInt(mixed $value): ?int
    {
        $floatValue = $this->toFloat($value);

        if ($floatValue === null) {
            return null;
        }

        return max(0, (int) round($floatValue));
    }

    private function nullable(mixed $value): ?string
    {
        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }

    private function normalizePhone(mixed $value): ?string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        if (preg_match('/^[0-9]+\.[0-9]+E\+?[0-9]+$/i', $raw) === 1) {
            $raw = number_format((float) $raw, 0, '', '');
        }

        $digits = preg_replace('/[^0-9+]/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        return Str::limit($digits, 40, '');
    }

    private function normalizePostalCode(mixed $value): ?string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        if (preg_match('/^[0-9]+\.[0-9]+$/', $raw) === 1) {
            $raw = (string) ((int) round((float) $raw));
        }

        $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) < 5) {
            $digits = str_pad($digits, 5, '0', STR_PAD_LEFT);
        }

        return Str::limit($digits, 10, '');
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $email = strtolower(trim((string) $value));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return Str::limit($email, 191, '');
    }

    private function normalizeWebsite(mixed $value): ?string
    {
        $website = trim((string) $value);

        if ($website === '') {
            return null;
        }

        if (!Str::startsWith(strtolower($website), ['http://', 'https://'])) {
            $website = 'https://' . $website;
        }

        return Str::limit($website, 255, '');
    }

    private function deriveBudgetLevel(?string $priceRange, ?float $priceFrom, ?string $size): ?string
    {
        $priceRange = strtolower(trim((string) $priceRange));
        $size = strtolower(trim((string) $size));

        if ($priceRange !== '') {
            if (str_contains($priceRange, '$$$') || str_contains($priceRange, 'alto') || str_contains($priceRange, 'high')) {
                return 'premium';
            }

            if (str_contains($priceRange, '$$') || str_contains($priceRange, 'medio') || str_contains($priceRange, 'medium')) {
                return 'medio';
            }

            if (str_contains($priceRange, '$') || str_contains($priceRange, 'bajo') || str_contains($priceRange, 'low')) {
                return 'barato';
            }
        }

        if ($priceFrom !== null) {
            if ($priceFrom <= 200) {
                return 'barato';
            }

            if ($priceFrom <= 700) {
                return 'medio';
            }

            return 'premium';
        }

        if ($size !== '') {
            if (str_contains($size, '0 a 5') || str_contains($size, '6 a 10')) {
                return 'barato';
            }

            if (str_contains($size, '11 a 30') || str_contains($size, '31 a 50')) {
                return 'medio';
            }
        }

        return null;
    }

    private function extractCoordinates(mixed $googleMapsUrl): array
    {
        $url = trim((string) $googleMapsUrl);

        if ($url === '') {
            return [null, null, null];
        }

        if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $url, $matches) === 1) {
            return [(float) $matches[1], (float) $matches[2], 'google_maps_3d4d'];
        }

        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $matches) === 1) {
            return [(float) $matches[1], (float) $matches[2], 'google_maps_at'];
        }

        if (preg_match('/q=(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $matches) === 1) {
            return [(float) $matches[1], (float) $matches[2], 'google_maps_query'];
        }

        return [null, null, null];
    }

    private function buildSearchableText(array $parts): ?string
    {
        $text = collect($parts)
            ->map(fn ($part): string => trim((string) $part))
            ->filter()
            ->implode(' ');

        $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';

        return $text === '' ? null : Str::limit($text, 8000, '');
    }
}

