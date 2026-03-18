<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class BusinessDirectorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvPath = storage_path('BD.csv');

        if (! is_file($csvPath)) {
            throw new RuntimeException("No se encontro el archivo CSV en [$csvPath].");
        }

        $handle = fopen($csvPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir el archivo CSV en [$csvPath].");
        }

        try {
            $headers = fgetcsv($handle);

            if ($headers === false) {
                throw new RuntimeException('El archivo CSV esta vacio o no tiene encabezados.');
            }

            $normalizeHeader = static fn (string $header): string => Str::of((string) preg_replace('/^\xEF\xBB\xBF/', '', $header))
                ->trim()
                ->lower()
                ->ascii()
                ->replaceMatches('/\s+/', ' ')
                ->toString();

            $headers = array_map(
                static fn ($header): string => $normalizeHeader((string) $header),
                $headers,
            );

            $columnsMap = [
                'giro' => 'giro',
                'nombre comercial' => 'nombre_comercial',
                'razon social' => 'razon_social',
                'codigo scian' => 'codigo_scian',
                'actividad' => 'actividad',
                'tamano' => 'tamano',
                'calle' => 'calle',
                'numero exterior' => 'numero_exterior',
                'letra exterior' => 'letra_exterior',
                'numero interior' => 'numero_interior',
                'letra interior' => 'letra_interior',
                'colonia' => 'colonia',
                'codigo postal' => 'codigo_postal',
                'estado' => 'estado',
                'ciudad' => 'ciudad',
                'telefono' => 'telefono',
                'email' => 'email',
                'pagina web' => 'pagina_web',
            ];

            $columnIndexes = [];

            foreach ($columnsMap as $csvColumn => $dbColumn) {
                $index = array_search($csvColumn, $headers, true);

                if ($index === false) {
                    throw new RuntimeException("No se encontro la columna [$csvColumn] en el CSV.");
                }

                $columnIndexes[$dbColumn] = $index;
            }

            DB::disableQueryLog();
            DB::table('business_directories')->truncate();

            $batchSize = 1000;
            $batch = [];
            $timestamp = now()->format('Y-m-d H:i:s');
            $inserted = 0;

            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null]) {
                    continue;
                }

                $record = [];

                foreach ($columnIndexes as $dbColumn => $index) {
                    $value = isset($row[$index]) ? trim($row[$index]) : null;
                    $record[$dbColumn] = $value === '' ? null : $value;
                }

                $record['created_at'] = $timestamp;
                $record['updated_at'] = $timestamp;
                $batch[] = $record;

                if (count($batch) < $batchSize) {
                    continue;
                }

                DB::table('business_directories')->insert($batch);
                $inserted += count($batch);
                $batch = [];
            }

            if ($batch !== []) {
                DB::table('business_directories')->insert($batch);
                $inserted += count($batch);
            }

            $this->command?->info("Registros importados en business_directories: $inserted");
        } finally {
            fclose($handle);
        }
    }
}
