<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GeocodeKiroMasterPlacesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kiro:geocode-master-places
        {--limit=1200 : Numero maximo de filas por corrida}
        {--sleep-ms=1100 : Pausa entre requests al geocoder (ms)}
        {--timeout=8 : Timeout HTTP en segundos}
        {--force=0 : 1 vuelve a geocodificar aunque ya tenga coordenadas}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Geocodifica negocios de kiro_master_places para mejorar precision por cercania';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!Schema::hasTable('kiro_master_places')) {
            $this->error('No existe la tabla kiro_master_places. Ejecuta primero php artisan migrate');
            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $timeout = max(3, (int) $this->option('timeout'));
        $force = (bool) ((int) $this->option('force'));

        $query = DB::table('kiro_master_places')
            ->select([
                'id',
                'name',
                'address',
                'neighborhood',
                'city',
                'state',
                'postal_code',
                'latitude',
                'longitude',
            ]);

        if (!$force) {
            $query->where(function ($where): void {
                $where->whereNull('latitude')->orWhereNull('longitude');
            });
        }

        $rows = $query->orderBy('id')->limit($limit)->get();

        if ($rows->isEmpty()) {
            $this->info('No hay filas pendientes de geocodificar para la configuracion actual.');
            return self::SUCCESS;
        }

        $this->info('Iniciando geocodificacion de ' . $rows->count() . ' filas...');

        $updated = 0;
        $failed = 0;
        $cacheHits = 0;
        $apiHits = 0;

        foreach ($rows as $index => $row) {
            $candidate = (array) $row;
            $geoQueries = $this->buildGeoQueries($candidate);

            if ($geoQueries === []) {
                $failed++;
                continue;
            }

            $geo = null;
            $precision = null;
            foreach ($geoQueries as $queryConfig) {
                $geo = $this->resolveCoordinatesByQuery((string) ($queryConfig['query'] ?? ''), $timeout);
                if ($geo !== null) {
                    $precision = (string) ($queryConfig['precision'] ?? 'nominatim_address');
                    break;
                }
            }

            if ($geo === null) {
                $failed++;
                continue;
            }

            if (($geo['source'] ?? null) === 'cache') {
                $cacheHits++;
            } else {
                $apiHits++;
            }

            DB::table('kiro_master_places')
                ->where('id', (int) $row->id)
                ->update([
                    'latitude' => $geo['latitude'],
                    'longitude' => $geo['longitude'],
                    'geo_precision' => $precision ?? 'nominatim_address',
                    'updated_at' => now(),
                ]);

            $updated++;

            if ((($index + 1) % 50) === 0) {
                $this->line('Procesadas: ' . ($index + 1) . ' | Actualizadas: ' . $updated . ' | Cache: ' . $cacheHits . ' | API: ' . $apiHits);
            }

            if (($geo['source'] ?? null) === 'api' && $sleepMs > 0 && $index < ($rows->count() - 1)) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info('Geocodificacion finalizada.');
        $this->line('Actualizadas: ' . $updated);
        $this->line('Fallidas/sin datos: ' . $failed);
        $this->line('Resueltas por cache: ' . $cacheHits);
        $this->line('Resueltas por API: ' . $apiHits);

        return self::SUCCESS;
    }

    private function buildGeoQueries(array $row): array
    {
        $postal = trim((string) ($row['postal_code'] ?? ''));
        $city = trim((string) ($row['city'] ?? ''));
        $state = trim((string) ($row['state'] ?? ''));
        $neighborhood = trim((string) ($row['neighborhood'] ?? ''));
        $address = trim((string) ($row['address'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));

        $queries = [];

        if ($address !== '' && $city !== '' && $state !== '') {
            $queries[] = [
                'query' => "{$address}, {$city}, {$state}, Mexico",
                'precision' => 'nominatim_address',
            ];
        }

        if ($neighborhood !== '' && $city !== '' && $state !== '') {
            $queries[] = [
                'query' => "{$neighborhood}, {$city}, {$state}, Mexico",
                'precision' => 'nominatim_neighborhood',
            ];
        }

        if ($postal !== '' && $city !== '' && $state !== '') {
            $queries[] = [
                'query' => "{$postal}, {$city}, {$state}, Mexico",
                'precision' => 'nominatim_postal',
            ];
        }

        if ($name !== '' && $city !== '' && $state !== '') {
            $queries[] = [
                'query' => "{$name}, {$city}, {$state}, Mexico",
                'precision' => 'nominatim_name',
            ];
        }

        $unique = [];
        $filtered = [];

        foreach ($queries as $queryConfig) {
            $queryText = trim((string) ($queryConfig['query'] ?? ''));

            if ($queryText === '' || isset($unique[$queryText])) {
                continue;
            }

            $unique[$queryText] = true;
            $filtered[] = $queryConfig;
        }

        return $filtered;
    }

    private function resolveCoordinatesByQuery(string $queryText, int $timeoutSeconds): ?array
    {
        $queryText = trim($queryText);
        if ($queryText === '') {
            return null;
        }

        $lookupKey = hash('sha256', $this->normalizeText($queryText));

        if (Schema::hasTable('kiro_location_cache')) {
            $cached = DB::table('kiro_location_cache')->where('lookup_key', $lookupKey)->first();
            if ($cached !== null && $cached->latitude !== null && $cached->longitude !== null) {
                DB::table('kiro_location_cache')
                    ->where('lookup_key', $lookupKey)
                    ->update([
                        'hits' => (int) ($cached->hits ?? 0) + 1,
                        'last_used_at' => now(),
                        'updated_at' => now(),
                    ]);

                return [
                    'latitude' => (float) $cached->latitude,
                    'longitude' => (float) $cached->longitude,
                    'source' => 'cache',
                ];
            }
        }

        $response = Http::timeout($timeoutSeconds)
            ->withHeaders(['User-Agent' => 'Chatify-Kiro-Local/1.0'])
            ->get('https://nominatim.openstreetmap.org/search', [
                'format' => 'jsonv2',
                'limit' => 1,
                'countrycodes' => 'mx',
                'q' => $queryText,
            ]);

        if (!$response->successful()) {
            return null;
        }

        $first = Arr::first($response->json() ?? []);
        if (!is_array($first) || !isset($first['lat'], $first['lon'])) {
            return null;
        }

        $latitude = (float) $first['lat'];
        $longitude = (float) $first['lon'];

        if (Schema::hasTable('kiro_location_cache')) {
            try {
                DB::table('kiro_location_cache')->updateOrInsert(
                    ['lookup_key' => $lookupKey],
                    [
                        'query_text' => $queryText,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'provider' => 'nominatim',
                        'confidence' => 'geocoded',
                        'metadata' => json_encode($first, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'hits' => 1,
                        'last_used_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            } catch (\Throwable) {
                // Ignore duplicate-key races during concurrent runs.
            }
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'source' => 'api',
        ];
    }

    private function normalizeText(string $value): string
    {
        return trim(Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->value());
    }
}
