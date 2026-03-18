<?php

namespace App\Services;

use App\Models\KiroUserContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class KiroBusinessDirectoryService
{
    private static ?array $sourceCache = null;

    public function buildContext(int $userId, int $agentUserId, string $message, array $settings = []): array
    {
        $currentTime = now()->setTimezone((string) config('app.timezone', 'America/Mexico_City'))->toDateTimeString();
        [$sourceTable, $sourceType] = $this->resolveActiveSource();

        if ($sourceTable === null || $sourceType === null) {
            return [
                'error' => 'No hay una base local de negocios disponible.',
                'current_time' => $currentTime,
                'matched_businesses' => [],
            ];
        }

        $maxHistory = max(6, (int) ($settings['max_history_context'] ?? config('agents.kiro.max_history_context', 20)));
        $maxCandidates = max(30, (int) ($settings['max_candidates_context'] ?? config('agents.kiro.max_candidates_context', 120)));
        $maxResults = min(5, max(3, (int) ($settings['max_results'] ?? config('agents.kiro.max_results', 3))));
        $nearKmDefault = (float) ($settings['near_km_default'] ?? config('agents.kiro.near_km_default', 8));
        $nearKmVeryClose = (float) ($settings['near_km_very_close'] ?? config('agents.kiro.near_km_very_close', 3));
        $weatherEnabled = (bool) ($settings['weather_enabled'] ?? config('agents.kiro.weather_enabled', true));
        $geocodeTimeout = max(3, (int) ($settings['geocode_timeout_seconds'] ?? config('agents.kiro.geocode_timeout_seconds', 8)));
        $weatherTimeout = max(3, (int) ($settings['weather_timeout_seconds'] ?? config('agents.kiro.weather_timeout_seconds', 8)));

        $userContext = $this->resolveUserContext($userId);
        $message = trim($message);
        $normalizedMessage = $this->normalizeText($message);

        $intent = $this->detectIntent($normalizedMessage);
        $distanceMode = $this->detectDistanceMode($normalizedMessage);
        $tokens = $this->extractSearchTokens($normalizedMessage);
        $budgetIntent = $this->extractBudgetIntent($normalizedMessage);
        $preferenceTags = $this->detectPreferenceTags($normalizedMessage, Arr::wrap($userContext?->preference_tags ?? []));
        $pricePreference = $this->resolvePricePreference($normalizedMessage, $userContext?->price_preference);

        $locationFromMessage = $this->extractLocationFromMessage($message);
        $userLocation = $locationFromMessage ?: ($userContext?->user_location ?: null);
        [$userLatitude, $userLongitude] = $this->resolveUserCoordinates(
            $userLocation,
            $userContext?->location_latitude,
            $userContext?->location_longitude,
            $locationFromMessage !== null,
            $geocodeTimeout
        );

        $chatHistory = $this->loadChatHistory($userId, $agentUserId, $maxHistory);
        $chatSummary = $this->buildInternalSummary($chatHistory, (string) ($userContext?->chat_summary ?? ''));
        $weatherContext = $weatherEnabled ? $this->resolveWeatherContext($userLatitude, $userLongitude, $normalizedMessage, $weatherTimeout) : null;

        $matchedBusinesses = [];
        $lastResultIds = array_values(array_filter(array_map(static fn ($id): int => (int) $id, Arr::wrap($userContext?->last_result_ids ?? []))));
        $directContactMode = false;

        if ($intent === 'contact' && !empty($lastResultIds)) {
            $matchedBusinesses = $this->fetchBusinessesByIds($sourceTable, $sourceType, $lastResultIds);
            $directContactMode = !empty($matchedBusinesses);
        }

        if (empty($matchedBusinesses)) {
            $locationTokens = $this->extractLocationTokens((string) $userLocation);
            $distanceSensitiveSearch = in_array($distanceMode, ['very_near', 'near', 'far'], true) && $userLatitude !== null && $userLongitude !== null;
            $candidates = $this->collectCandidates(
                $sourceTable,
                $sourceType,
                $tokens,
                $locationTokens,
                $maxCandidates,
                $distanceSensitiveSearch
            );
            $scored = $this->scoreBusinesses(
                $candidates,
                $sourceTable,
                $sourceType,
                $tokens,
                $locationTokens,
                $preferenceTags,
                $pricePreference,
                $budgetIntent,
                $intent,
                $distanceMode,
                $nearKmDefault,
                $nearKmVeryClose,
                $userLatitude,
                $userLongitude,
                $weatherContext,
                $geocodeTimeout
            );
            $matchedBusinesses = array_slice($scored, 0, $maxResults);
        }

        $mappedBusinesses = array_map(fn (array $row): array => $this->toBusinessPayload($row, $sourceType), $matchedBusinesses);
        $interestPatterns = $this->updateInterestPatterns(Arr::wrap($userContext?->interest_patterns ?? []), $tokens);
        $ambiguous = $this->isAmbiguous($intent, $tokens, $mappedBusinesses);

        if ($userContext !== null) {
            $this->persistUserContext(
                $userContext,
                $userLocation,
                $userLatitude,
                $userLongitude,
                $pricePreference,
                $preferenceTags,
                $interestPatterns,
                $chatSummary,
                $intent,
                $message,
                $mappedBusinesses
            );
        }

        return [
            'source_table' => $sourceTable,
            'source_type' => $sourceType,
            'user_location' => $userLocation,
            'user_latitude' => $userLatitude,
            'user_longitude' => $userLongitude,
            'chat_history' => $chatHistory,
            'chat_history_summary' => $chatSummary,
            'current_time' => $currentTime,
            'intent' => $intent,
            'distance_mode' => $distanceMode,
            'preference_tags' => $preferenceTags,
            'price_preference' => $pricePreference,
            'budget_intent' => $budgetIntent,
            'interest_patterns' => $interestPatterns,
            'weather' => $weatherContext,
            'ambiguous' => $ambiguous,
            'direct_contact_mode' => $directContactMode,
            'matched_businesses' => $mappedBusinesses,
        ];
    }

    public function buildFallbackAnswer(string $message, array $context): string
    {
        if (isset($context['error'])) {
            return "No pude consultar la base local de negocios. {$context['error']}";
        }

        $intent = (string) ($context['intent'] ?? 'search');
        $businesses = Arr::wrap($context['matched_businesses'] ?? []);
        $location = trim((string) ($context['user_location'] ?? ''));
        $ambiguous = (bool) ($context['ambiguous'] ?? false);

        if ($ambiguous && empty($businesses)) {
            return 'Que tipo de negocio buscas y en que zona?';
        }

        if ($intent === 'contact' && !empty($businesses)) {
            return $this->buildDirectContactAnswer($businesses[0]);
        }

        if (empty($businesses)) {
            if ($location !== '') {
                return "No encontre coincidencias exactas en {$location}. Prefieres que lo busque por giro, colonia o presupuesto?";
            }

            return 'No encontre coincidencias exactas. Dime giro, zona y presupuesto aproximado.';
        }

        $lines = [];
        foreach (array_slice($businesses, 0, 5) as $index => $business) {
            $line = ($index + 1) . '. ' . ($business['nombre'] ?? 'Negocio');
            $line .= "\nGiro/actividad: " . ($business['giro_o_actividad'] ?? 'No visible');
            $line .= "\nDireccion: " . ($business['direccion'] ?? 'No visible');

            if (!empty($business['distance_km'])) {
                $line .= "\nDistancia aprox: " . $business['distance_km'] . ' km';
            }

            if (!empty($business['price_from'])) {
                $line .= "\nPrecio desde: $" . number_format((float) $business['price_from'], 2) . ' MXN';
            } elseif (!empty($business['budget_level'])) {
                $line .= "\nNivel presupuesto: " . $business['budget_level'];
            }

            if (!empty($business['telefono'])) {
                $line .= "\nTelefono: " . $business['telefono'];
            }

            if (!empty($business['pagina_web'])) {
                $line .= "\nWeb: " . $business['pagina_web'];
            }

            $lines[] = $line;
        }

        return implode("\n\n", $lines);
    }

    private function resolveActiveSource(): array
    {
        if (self::$sourceCache !== null) {
            return self::$sourceCache;
        }

        if (Schema::hasTable('kiro_master_places') && DB::table('kiro_master_places')->limit(1)->exists()) {
            self::$sourceCache = ['kiro_master_places', 'master'];
            return self::$sourceCache;
        }

        if (Schema::hasTable('business_directories')) {
            self::$sourceCache = ['business_directories', 'legacy'];
            return self::$sourceCache;
        }

        self::$sourceCache = [null, null];
        return self::$sourceCache;
    }

    private function resolveUserContext(int $userId): ?KiroUserContext
    {
        if (!Schema::hasTable('kiro_user_contexts')) {
            return null;
        }

        return KiroUserContext::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'preference_tags' => [],
                'interest_patterns' => [],
                'last_result_ids' => [],
            ],
        );
    }

    private function persistUserContext(
        KiroUserContext $userContext,
        ?string $userLocation,
        ?float $userLatitude,
        ?float $userLongitude,
        ?string $pricePreference,
        array $preferenceTags,
        array $interestPatterns,
        string $chatSummary,
        string $intent,
        string $message,
        array $mappedBusinesses
    ): void {
        $userContext->user_location = $userLocation;
        $userContext->location_latitude = $userLatitude;
        $userContext->location_longitude = $userLongitude;
        $userContext->price_preference = $pricePreference;
        $userContext->preference_tags = $preferenceTags;
        $userContext->interest_patterns = $interestPatterns;
        $userContext->chat_summary = $chatSummary;
        $userContext->last_intent = $intent;
        $userContext->last_query = $message;
        $userContext->last_result_ids = array_values(array_map(
            static fn (array $business): int => (int) ($business['id'] ?? 0),
            $mappedBusinesses,
        ));
        $userContext->last_interaction_at = now();
        $userContext->save();
    }

    private function collectCandidates(
        string $sourceTable,
        string $sourceType,
        array $tokens,
        array $locationTokens,
        int $maxCandidates,
        bool $distanceSensitiveSearch = false
    ): array {
        $query = DB::table($sourceTable);

        if ($sourceType === 'master') {
            $query->select([
                'id',
                'record_id',
                'primary_type',
                'name',
                'secondary_type',
                'rating',
                'price_range',
                'price_from',
                'budget_level',
                'address',
                'neighborhood',
                'city',
                'state',
                'postal_code',
                'phone',
                'email',
                'website',
                'google_maps_url',
                'hours',
                'features',
                'review_snippet',
                'legal_name',
                'category',
                'size',
                'latitude',
                'longitude',
                'geo_precision',
                'searchable_text',
            ]);

            if (!empty($tokens)) {
                $this->applyTokenFilter($query, $tokens, [
                    'name',
                    'secondary_type',
                    'primary_type',
                    'category',
                    'searchable_text',
                    'neighborhood',
                    'city',
                    'state',
                    'postal_code',
                ]);
            } elseif (!empty($locationTokens)) {
                $this->applyTokenFilter($query, $locationTokens, ['neighborhood', 'city', 'state', 'postal_code']);
            }

            if ($distanceSensitiveSearch) {
                $query->orderByRaw('CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL THEN 0 ELSE 1 END')
                    ->orderByDesc('rating');
            }

            return $query->limit($maxCandidates)->get()->map(fn ($row): array => $this->normalizeMasterRow((array) $row))->all();
        }

        $query->select([
            'id',
            'giro',
            'nombre_comercial',
            'razon_social',
            'actividad',
            'tamano',
            'calle',
            'numero_exterior',
            'letra_exterior',
            'numero_interior',
            'letra_interior',
            'colonia',
            'codigo_postal',
            'estado',
            'ciudad',
            'telefono',
            'email',
            'pagina_web',
        ]);

        if (!empty($tokens)) {
            $this->applyTokenFilter($query, $tokens, [
                'giro',
                'nombre_comercial',
                'razon_social',
                'actividad',
                'colonia',
                'ciudad',
                'estado',
            ]);
        } elseif (!empty($locationTokens)) {
            $this->applyTokenFilter($query, $locationTokens, ['colonia', 'ciudad', 'estado', 'codigo_postal']);
        }

        return $query->limit($maxCandidates)->get()->map(fn ($row): array => $this->normalizeLegacyRow((array) $row))->all();
    }

    private function applyTokenFilter(Builder $query, array $tokens, array $columns): void
    {
        $query->where(function ($where) use ($tokens, $columns): void {
            foreach (array_slice($tokens, 0, 8) as $token) {
                $like = '%' . $token . '%';

                foreach ($columns as $column) {
                    $where->orWhere($column, 'like', $like);
                }
            }
        });
    }

    private function fetchBusinessesByIds(string $sourceTable, string $sourceType, array $ids): array
    {
        $validIds = array_values(array_unique(array_filter($ids, static fn ($id): bool => (int) $id > 0)));

        if (empty($validIds)) {
            return [];
        }

        $rows = DB::table($sourceTable)->whereIn('id', $validIds)->get()->keyBy('id');
        $ordered = [];

        foreach ($validIds as $id) {
            if (!$rows->has($id)) {
                continue;
            }

            $row = (array) $rows->get($id);
            $ordered[] = $sourceType === 'master'
                ? $this->normalizeMasterRow($row)
                : $this->normalizeLegacyRow($row);
        }

        return $ordered;
    }

    private function searchByLocationOnly(string $sourceTable, string $sourceType, array $locationTokens, int $limit): array
    {
        if (empty($locationTokens)) {
            return [];
        }

        return array_slice(
            $this->collectCandidates($sourceTable, $sourceType, [], $locationTokens, max($limit, 20)),
            0,
            $limit
        );
    }

    private function scoreBusinesses(
        array $candidates,
        string $sourceTable,
        string $sourceType,
        array $tokens,
        array $locationTokens,
        array $preferenceTags,
        ?string $pricePreference,
        array $budgetIntent,
        string $intent,
        string $distanceMode,
        float $nearKmDefault,
        float $nearKmVeryClose,
        ?float $userLatitude,
        ?float $userLongitude,
        ?array $weatherContext,
        int $geocodeTimeout
    ): array {
        $scored = [];
        $geoCalls = 0;

        foreach ($candidates as $candidate) {
            $score = 0;

            $name = $this->normalizeText((string) ($candidate['name'] ?? ''));
            $activity = $this->normalizeText((string) ($candidate['activity'] ?? ''));
            $category = $this->normalizeText((string) ($candidate['category'] ?? ''));
            $searchable = $this->normalizeText((string) ($candidate['searchable_text'] ?? ''));
            $locationText = $this->normalizeText(implode(' ', [
                $candidate['neighborhood'] ?? '',
                $candidate['city'] ?? '',
                $candidate['state'] ?? '',
                $candidate['postal_code'] ?? '',
            ]));

            foreach ($tokens as $token) {
                if (str_contains($name, $token)) {
                    $score += 7;
                }

                if (str_contains($activity, $token)) {
                    $score += 6;
                }

                if (str_contains($category, $token)) {
                    $score += 5;
                }

                if (str_contains($searchable, $token)) {
                    $score += 3;
                }
            }

            foreach ($locationTokens as $token) {
                if (str_contains($locationText, $token)) {
                    $score += 7;
                }
            }

            $distanceKm = null;
            $candidateLatitude = $this->nullableFloat($candidate['latitude'] ?? null);
            $candidateLongitude = $this->nullableFloat($candidate['longitude'] ?? null);

            if (($distanceMode === 'very_near' || $distanceMode === 'near' || $distanceMode === 'far')
                && $userLatitude !== null
                && $userLongitude !== null
                && ($candidateLatitude === null || $candidateLongitude === null)
            ) {
                $geoQuery = $this->buildCandidateGeoQuery($candidate);

                if ($geoQuery !== null) {
                    $geo = $this->resolveCoordinatesByQuery($geoQuery, false, $geocodeTimeout);

                    if ($geo === null && $geoCalls < 6) {
                        $geoCalls++;
                        $geo = $this->resolveCoordinatesByQuery($geoQuery, true, $geocodeTimeout);
                    }

                    if ($geo !== null) {
                        $candidateLatitude = (float) $geo['latitude'];
                        $candidateLongitude = (float) $geo['longitude'];
                        $candidate['latitude'] = $candidateLatitude;
                        $candidate['longitude'] = $candidateLongitude;

                        if ($sourceType === 'master') {
                            $this->persistMasterCoordinatesIfMissing(
                                $sourceTable,
                                (int) ($candidate['id'] ?? 0),
                                $candidateLatitude,
                                $candidateLongitude
                            );
                        }
                    }
                }
            }

            if ($userLatitude !== null && $userLongitude !== null && $candidateLatitude !== null && $candidateLongitude !== null) {
                $distanceKm = $this->haversineDistanceKm($userLatitude, $userLongitude, $candidateLatitude, $candidateLongitude);
                $candidate['_distance_km'] = $distanceKm;
            } else {
                $candidate['_distance_km'] = null;
            }

            if ($distanceMode === 'very_near') {
                if ($distanceKm !== null) {
                    if ($distanceKm > max(6.0, $nearKmVeryClose * 2.5)) {
                        continue;
                    }

                    $score += $distanceKm <= $nearKmVeryClose ? 26 : -((int) min(26, ($distanceKm - $nearKmVeryClose) * 4));
                } else {
                    $score -= 10;
                }
            } elseif ($distanceMode === 'near') {
                if ($distanceKm !== null) {
                    if ($distanceKm > max(20.0, $nearKmDefault * 2.5)) {
                        continue;
                    }

                    $score += $distanceKm <= $nearKmDefault ? 18 : -((int) min(16, ($distanceKm - $nearKmDefault) * 2.2));
                } elseif (in_array('cerca', $preferenceTags, true)) {
                    $score -= 6;
                }
            } elseif ($distanceMode === 'far' && $distanceKm !== null) {
                $score += (int) min(15, $distanceKm * 1.4);
            }

            if (($budgetIntent['max_price'] ?? null) !== null) {
                $maxPrice = (float) $budgetIntent['max_price'];
                $priceFrom = $this->nullableFloat($candidate['price_from'] ?? null);

                if ($priceFrom !== null) {
                    $score += $priceFrom <= $maxPrice ? 12 : -8;
                }
            }

            $candidateBudget = $this->normalizeBudgetLevel($candidate['budget_level'] ?? null);
            if (($budgetIntent['level'] ?? null) !== null && $candidateBudget !== null) {
                $target = $this->normalizeBudgetLevel($budgetIntent['level']);
                $score += $target === $candidateBudget ? 7 : -3;
            }

            if ($pricePreference === 'barato' && $candidateBudget === 'barato') {
                $score += 4;
            }

            if ($pricePreference === 'premium' && $candidateBudget === 'premium') {
                $score += 4;
            }

            $rating = $this->nullableFloat($candidate['rating'] ?? null);
            if ($rating !== null) {
                $score += (int) min(6, round($rating));
            }

            if ($intent === 'contact') {
                if (!empty($candidate['phone'])) {
                    $score += 3;
                }

                if (!empty($candidate['email']) || !empty($candidate['website'])) {
                    $score += 2;
                }
            }

            if ($weatherContext !== null) {
                if (($weatherContext['is_rainy'] ?? false) && $this->isOutdoorCandidate($candidate)) {
                    $score -= 8;
                }

                if (($weatherContext['is_rainy'] ?? false) && $this->isIndoorFriendlyCandidate($candidate)) {
                    $score += 4;
                }

                if (($weatherContext['is_hot'] ?? false) && $this->isIndoorFriendlyCandidate($candidate)) {
                    $score += 2;
                }
            }

            if ($score <= 0 && !empty($tokens)) {
                continue;
            }

            $candidate['_score'] = $score;
            $scored[] = $candidate;
        }

        usort($scored, function (array $a, array $b): int {
            $scoreSort = ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0);
            if ($scoreSort !== 0) {
                return $scoreSort;
            }

            $distanceA = $a['_distance_km'] ?? null;
            $distanceB = $b['_distance_km'] ?? null;
            if (is_numeric($distanceA) && is_numeric($distanceB)) {
                $distanceSort = $distanceA <=> $distanceB;
                if ($distanceSort !== 0) {
                    return $distanceSort;
                }
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $scored;
    }

    private function buildCandidateGeoQuery(array $candidate): ?string
    {
        $postal = trim((string) ($candidate['postal_code'] ?? ''));
        $city = trim((string) ($candidate['city'] ?? ''));
        $state = trim((string) ($candidate['state'] ?? ''));
        $neighborhood = trim((string) ($candidate['neighborhood'] ?? ''));
        $address = trim((string) ($candidate['address'] ?? ''));

        if ($postal !== '' && $city !== '' && $state !== '') {
            return "{$postal}, {$city}, {$state}, Mexico";
        }

        if ($neighborhood !== '' && $city !== '' && $state !== '') {
            return "{$neighborhood}, {$city}, {$state}, Mexico";
        }

        if ($address !== '' && $city !== '' && $state !== '') {
            return "{$address}, {$city}, {$state}, Mexico";
        }

        return null;
    }

    private function persistMasterCoordinatesIfMissing(string $sourceTable, int $rowId, float $latitude, float $longitude): void
    {
        if ($rowId <= 0) {
            return;
        }

        try {
            DB::table($sourceTable)
                ->where('id', $rowId)
                ->where(function ($where): void {
                    $where->whereNull('latitude')->orWhereNull('longitude');
                })
                ->update([
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'geo_precision' => 'nominatim_address',
                    'updated_at' => now(),
                ]);
        } catch (\Throwable) {
            // Ignore persistence errors to avoid breaking chat responses.
        }
    }

    private function resolveUserCoordinates(
        ?string $userLocation,
        ?float $previousLatitude,
        ?float $previousLongitude,
        bool $forceRefresh,
        int $geocodeTimeout
    ): array
    {
        if (!$forceRefresh && $previousLatitude !== null && $previousLongitude !== null) {
            return [$previousLatitude, $previousLongitude];
        }

        if ($userLocation === null || trim($userLocation) === '') {
            return [$previousLatitude, $previousLongitude];
        }

        $mustGeocode = $forceRefresh || $previousLatitude === null || $previousLongitude === null;
        $geo = $this->resolveCoordinatesByQuery($userLocation, $mustGeocode, $geocodeTimeout);

        if ($geo === null) {
            return [$previousLatitude, $previousLongitude];
        }

        return [$geo['latitude'], $geo['longitude']];
    }

    private function resolveCoordinatesByQuery(string $queryText, bool $forceRefresh = false, int $timeoutSeconds = 8): ?array
    {
        $queryText = trim($queryText);

        if ($queryText === '') {
            return null;
        }

        $lookupKey = hash('sha256', $this->normalizeText($queryText));

        if (Schema::hasTable('kiro_location_cache')) {
            $cached = DB::table('kiro_location_cache')->where('lookup_key', $lookupKey)->first();
            if ($cached !== null && $cached->latitude !== null && $cached->longitude !== null) {
                return [
                    'latitude' => (float) $cached->latitude,
                    'longitude' => (float) $cached->longitude,
                ];
            }

            if (!$forceRefresh) {
                return null;
            }
        } elseif (!$forceRefresh) {
            return null;
        }

        $response = Http::timeout(max(3, $timeoutSeconds))
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
                // Another request may have inserted the same key in parallel.
            }
        }

        return ['latitude' => $latitude, 'longitude' => $longitude];
    }

    private function resolveWeatherContext(
        ?float $latitude,
        ?float $longitude,
        string $normalizedMessage,
        int $timeoutSeconds
    ): ?array
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        if (!$this->shouldCheckWeather($normalizedMessage)) {
            return null;
        }

        $response = Http::timeout(max(3, $timeoutSeconds))->get('https://api.open-meteo.com/v1/forecast', [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'current' => 'temperature_2m,precipitation,weather_code,wind_speed_10m',
            'timezone' => 'auto',
        ]);

        if (!$response->successful()) {
            return null;
        }

        $current = data_get($response->json(), 'current');
        if (!is_array($current)) {
            return null;
        }

        $temperature = $this->nullableFloat($current['temperature_2m'] ?? null);
        $precipitation = $this->nullableFloat($current['precipitation'] ?? null) ?? 0.0;
        $weatherCode = (int) ($current['weather_code'] ?? -1);

        return [
            'temperature_c' => $temperature,
            'precipitation_mm' => $precipitation,
            'weather_code' => $weatherCode,
            'weather_label' => $this->mapWeatherCode($weatherCode),
            'is_rainy' => $precipitation > 0 || in_array($weatherCode, [51, 53, 55, 61, 63, 65, 80, 81, 82, 95], true),
            'is_hot' => $temperature !== null && $temperature >= 33,
        ];
    }

    private function shouldCheckWeather(string $normalizedMessage): bool
    {
        return $this->containsAny($normalizedMessage, ['clima', 'lluvia', 'llueve', 'calor', 'frio', 'pronostico', 'que hacer hoy']);
    }

    private function mapWeatherCode(int $weatherCode): string
    {
        return match ($weatherCode) {
            0 => 'despejado',
            1, 2, 3 => 'nublado',
            45, 48 => 'niebla',
            51, 53, 55, 61, 63, 65, 80, 81, 82 => 'lluvia',
            71, 73, 75 => 'nieve',
            95, 96, 99 => 'tormenta',
            default => 'variable',
        };
    }

    private function haversineDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    private function loadChatHistory(int $userId, int $agentUserId, int $limit): array
    {
        if (!Schema::hasTable('ch_messages')) {
            return [];
        }

        return DB::table('ch_messages')
            ->where(function ($query) use ($userId, $agentUserId): void {
                $query
                    ->where(function ($pair) use ($userId, $agentUserId): void {
                        $pair->where('from_id', $userId)->where('to_id', $agentUserId);
                    })
                    ->orWhere(function ($pair) use ($userId, $agentUserId): void {
                        $pair->where('from_id', $agentUserId)->where('to_id', $userId);
                    });
            })
            ->whereNotNull('body')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(function ($row) use ($userId): array {
                $body = html_entity_decode((string) ($row->body ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return [
                    'role' => ((int) $row->from_id === $userId) ? 'user' : 'assistant',
                    'content' => Str::limit(trim($body), 320),
                ];
            })
            ->filter(fn (array $entry): bool => $entry['content'] !== '')
            ->values()
            ->all();
    }

    private function buildInternalSummary(array $chatHistory, string $previousSummary): string
    {
        $recent = collect($chatHistory)
            ->pluck('content')
            ->filter()
            ->take(-6)
            ->implode(' | ');

        $summary = trim($previousSummary) !== ''
            ? trim($previousSummary) . ' | ' . $recent
            : $recent;

        return Str::limit(trim($summary, ' |'), 650);
    }

    private function detectIntent(string $normalizedMessage): string
    {
        if ($this->containsAny($normalizedMessage, ['telefono', 'tel', 'whatsapp', 'contacto', 'correo', 'email', 'web', 'pagina'])) {
            return 'contact';
        }

        if ($this->containsAny($normalizedMessage, ['compara', 'comparar', 'comparacion', 'versus', 'vs', 'mejor'])) {
            return 'compare';
        }

        if ($this->containsAny($normalizedMessage, ['recomienda', 'recomendar', 'sugiere', 'sugerir', 'opcion', 'opciones'])) {
            return 'recommend';
        }

        if ($this->containsAny($normalizedMessage, ['donde queda', 'ubicacion', 'ubicar', 'como llegar', 'direccion'])) {
            return 'locate';
        }

        return 'search';
    }

    private function detectDistanceMode(string $normalizedMessage): string
    {
        if ($this->containsAny($normalizedMessage, ['muy cerca', 'a pie', 'caminando', 'walking'])) {
            return 'very_near';
        }

        if ($this->containsAny($normalizedMessage, ['cerca', 'cercano', 'cercana'])) {
            return 'near';
        }

        if ($this->containsAny($normalizedMessage, ['lejos', 'retirado', 'apartado'])) {
            return 'far';
        }

        return 'none';
    }

    private function extractSearchTokens(string $normalizedMessage): array
    {
        $rawTokens = preg_split('/[^a-z0-9]+/', $normalizedMessage) ?: [];
        $stopWords = [
            'de', 'la', 'el', 'los', 'las', 'en', 'y', 'o', 'a', 'un', 'una',
            'para', 'por', 'con', 'que', 'como', 'del', 'al', 'mi', 'tu', 'su',
            'me', 'te', 'se', 'es', 'son', 'hay', 'quiero', 'necesito', 'busco',
            'buscar', 'dame', 'donde', 'queda', 'algo', 'cerca', 'lejos', 'telefono',
            'correo', 'email', 'web', 'pagina', 'contacto', 'negocio', 'negocios',
            'presupuesto', 'barato', 'economico', 'economica', 'caro', 'clima',
        ];

        $tokens = array_values(array_unique(array_filter(
            $rawTokens,
            static fn (string $token): bool => strlen($token) >= 3 && !in_array($token, $stopWords, true),
        )));

        return array_slice($tokens, 0, 14);
    }

    private function extractLocationTokens(string $location): array
    {
        if (trim($location) === '') {
            return [];
        }

        $tokens = preg_split('/[^a-z0-9]+/', $this->normalizeText($location)) ?: [];
        return array_values(array_filter($tokens, static fn (string $token): bool => strlen($token) >= 3));
    }

    private function extractBudgetIntent(string $normalizedMessage): array
    {
        $result = ['level' => null, 'max_price' => null];

        if ($this->containsAny($normalizedMessage, ['barato', 'economico', 'economica', 'accesible'])) {
            $result['level'] = 'barato';
        } elseif ($this->containsAny($normalizedMessage, ['premium', 'lujo', 'caro'])) {
            $result['level'] = 'premium';
        } elseif ($this->containsAny($normalizedMessage, ['medio', 'intermedio'])) {
            $result['level'] = 'medio';
        }

        if (preg_match('/(?:hasta|menos de|maximo|max|presupuesto(?: de)?|no mas de)\s*\$?\s*([0-9]+(?:[.,][0-9]+)?)/i', $normalizedMessage, $matches) === 1) {
            $result['max_price'] = (float) str_replace(',', '.', $matches[1]);
        }

        return $result;
    }

    private function detectPreferenceTags(string $normalizedMessage, array $existingTags): array
    {
        $tags = collect($existingTags)->map(fn ($tag): string => $this->normalizeText((string) $tag))->filter()->values()->all();

        if ($this->containsAny($normalizedMessage, ['barato', 'economico', 'economica', 'accesible'])) {
            $tags[] = 'barato';
        }

        if ($this->containsAny($normalizedMessage, ['cerca', 'cercano', 'cercana'])) {
            $tags[] = 'cerca';
        }

        if ($this->containsAny($normalizedMessage, ['lejos', 'retirado'])) {
            $tags[] = 'lejos';
        }

        if ($this->containsAny($normalizedMessage, ['abierto ahora', 'abierto', 'abren'])) {
            $tags[] = 'abierto_ahora';
        }

        if ($this->containsAny($normalizedMessage, ['clima', 'lluvia', 'calor', 'frio'])) {
            $tags[] = 'clima_sensible';
        }

        return array_values(array_unique(array_filter($tags)));
    }

    private function resolvePricePreference(string $normalizedMessage, ?string $currentPreference): ?string
    {
        if ($this->containsAny($normalizedMessage, ['barato', 'economico', 'economica', 'accesible'])) {
            return 'barato';
        }

        if ($this->containsAny($normalizedMessage, ['premium', 'lujo'])) {
            return 'premium';
        }

        return $currentPreference;
    }

    private function extractLocationFromMessage(string $message): ?string
    {
        $patterns = [
            '/(?:estoy|vivo|ubicad[oa]|me encuentro)\s+(?:en|por|cerca de)\s+([\p{L}0-9,.\-\s]{3,80})/u',
            '/(?:mi ubicacion es|mi ubicación es)\s+([\p{L}0-9,.\-\s]{3,80})/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $location = trim((string) ($matches[1] ?? ''), " \t\n\r\0\x0B.,;");
                $location = trim((string) preg_split('/\b(busco|quiero|necesito|dame|recomienda|recomiendame|con|para)\b/i', $location)[0]);
                if ($location !== '') {
                    return Str::limit($location, 120, '');
                }
            }
        }

        return null;
    }

    private function toBusinessPayload(array $row, string $sourceType): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'record_id' => $row['record_id'] ?? null,
            'nombre' => $row['name'] ?? 'Negocio sin nombre visible',
            'giro_o_actividad' => $row['activity'] ?? 'Actividad no visible',
            'direccion' => $this->formatAddress($row, $sourceType),
            'telefono' => $this->nullableValue($row['phone'] ?? null),
            'email' => $this->nullableValue($row['email'] ?? null),
            'pagina_web' => $this->nullableValue($row['website'] ?? null),
            'city' => $this->nullableValue($row['city'] ?? null),
            'state' => $this->nullableValue($row['state'] ?? null),
            'neighborhood' => $this->nullableValue($row['neighborhood'] ?? null),
            'price_range' => $this->nullableValue($row['price_range'] ?? null),
            'price_from' => $this->nullableFloat($row['price_from'] ?? null),
            'budget_level' => $this->nullableValue($row['budget_level'] ?? null),
            'rating' => $this->nullableFloat($row['rating'] ?? null),
            'distance_km' => isset($row['_distance_km']) && $row['_distance_km'] !== null
                ? number_format((float) $row['_distance_km'], 2, '.', '')
                : null,
            'hours' => $this->nullableValue($row['hours'] ?? null),
            'features' => $this->nullableValue($row['features'] ?? null),
            'primary_type' => $this->nullableValue($row['primary_type'] ?? null),
            'category' => $this->nullableValue($row['category'] ?? null),
            'google_maps_url' => $this->nullableValue($row['google_maps_url'] ?? null),
            'latitude' => $this->nullableFloat($row['latitude'] ?? null),
            'longitude' => $this->nullableFloat($row['longitude'] ?? null),
        ];
    }

    private function normalizeMasterRow(array $row): array
    {
        $name = $this->nullableValue($row['name'] ?? null) ?? 'Negocio sin nombre visible';
        $activity = $this->nullableValue($row['secondary_type'] ?? null)
            ?? $this->nullableValue($row['category'] ?? null)
            ?? 'Actividad no visible';

        return [
            'id' => (int) ($row['id'] ?? 0),
            'record_id' => $this->nullableValue($row['record_id'] ?? null),
            'primary_type' => $this->nullableValue($row['primary_type'] ?? null),
            'name' => $name,
            'activity' => $activity,
            'category' => $this->nullableValue($row['category'] ?? null),
            'rating' => $this->nullableFloat($row['rating'] ?? null),
            'price_range' => $this->nullableValue($row['price_range'] ?? null),
            'price_from' => $this->nullableFloat($row['price_from'] ?? null),
            'budget_level' => $this->normalizeBudgetLevel($row['budget_level'] ?? null),
            'address' => $this->nullableValue($row['address'] ?? null),
            'neighborhood' => $this->nullableValue($row['neighborhood'] ?? null),
            'city' => $this->nullableValue($row['city'] ?? null),
            'state' => $this->nullableValue($row['state'] ?? null),
            'postal_code' => $this->normalizePostalCode((string) ($row['postal_code'] ?? '')),
            'phone' => $this->normalizePhone($row['phone'] ?? null),
            'email' => $this->nullableValue($row['email'] ?? null),
            'website' => $this->nullableValue($row['website'] ?? null),
            'google_maps_url' => $this->nullableValue($row['google_maps_url'] ?? null),
            'hours' => $this->nullableValue($row['hours'] ?? null),
            'features' => $this->nullableValue($row['features'] ?? null),
            'size' => $this->nullableValue($row['size'] ?? null),
            'latitude' => $this->nullableFloat($row['latitude'] ?? null),
            'longitude' => $this->nullableFloat($row['longitude'] ?? null),
            'geo_precision' => $this->nullableValue($row['geo_precision'] ?? null),
            'searchable_text' => $this->nullableValue($row['searchable_text'] ?? null),
        ];
    }

    private function normalizeLegacyRow(array $row): array
    {
        $name = $this->nullableValue($row['nombre_comercial'] ?? null)
            ?? $this->nullableValue($row['razon_social'] ?? null)
            ?? 'Negocio sin nombre visible';

        $activity = $this->nullableValue($row['actividad'] ?? null)
            ?? $this->nullableValue($row['giro'] ?? null)
            ?? 'Actividad no visible';

        $address = $this->formatLegacyAddress($row);
        $budget = $this->deriveBudgetFromLegacy($row['tamano'] ?? null);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'record_id' => null,
            'primary_type' => null,
            'name' => $name,
            'activity' => $activity,
            'category' => $this->nullableValue($row['giro'] ?? null),
            'rating' => null,
            'price_range' => null,
            'price_from' => null,
            'budget_level' => $budget,
            'address' => $address,
            'neighborhood' => $this->nullableValue($row['colonia'] ?? null),
            'city' => $this->nullableValue($row['ciudad'] ?? null),
            'state' => $this->nullableValue($row['estado'] ?? null),
            'postal_code' => $this->normalizePostalCode((string) ($row['codigo_postal'] ?? '')),
            'phone' => $this->normalizePhone($row['telefono'] ?? null),
            'email' => $this->nullableValue($row['email'] ?? null),
            'website' => $this->nullableValue($row['pagina_web'] ?? null),
            'google_maps_url' => null,
            'hours' => null,
            'features' => null,
            'size' => $this->nullableValue($row['tamano'] ?? null),
            'latitude' => null,
            'longitude' => null,
            'geo_precision' => null,
            'searchable_text' => $this->buildSearchableText([
                $name,
                $activity,
                $row['giro'] ?? null,
                $address,
                $row['colonia'] ?? null,
                $row['ciudad'] ?? null,
                $row['estado'] ?? null,
                $row['codigo_postal'] ?? null,
            ]),
        ];
    }

    private function formatAddress(array $row, string $sourceType): string
    {
        if ($sourceType === 'master') {
            $parts = array_filter([
                $this->nullableValue($row['address'] ?? null),
                $this->nullableValue($row['neighborhood'] ?? null),
                $this->nullableValue($row['city'] ?? null),
                $this->nullableValue($row['state'] ?? null),
            ]);
            $postalCode = $this->normalizePostalCode((string) ($row['postal_code'] ?? ''));
            if ($postalCode !== null) {
                $parts[] = "CP {$postalCode}";
            }
            return implode(', ', $parts);
        }

        return $this->formatLegacyAddress($row);
    }

    private function formatLegacyAddress(array $row): string
    {
        $street = trim((string) ($row['calle'] ?? ''));
        $numExt = trim((string) ($row['numero_exterior'] ?? ''));
        $letExt = trim((string) ($row['letra_exterior'] ?? ''));
        $number = trim($numExt . ($letExt !== '' ? " {$letExt}" : ''));

        if ($number === '' || $number === '0') {
            $number = 'S/N';
        }

        $line1 = trim(($street !== '' ? $street : 'Calle no visible') . ' ' . $number);
        $parts = array_filter([
            $line1,
            $this->nullableValue($row['colonia'] ?? null),
            $this->nullableValue($row['ciudad'] ?? null),
            $this->nullableValue($row['estado'] ?? null),
        ]);
        $postalCode = $this->normalizePostalCode((string) ($row['codigo_postal'] ?? ''));
        if ($postalCode !== null) {
            $parts[] = "CP {$postalCode}";
        }
        return implode(', ', $parts);
    }

    private function buildDirectContactAnswer(array $business): string
    {
        $lines = [$business['nombre'] ?? 'Negocio'];
        if (!empty($business['telefono'])) {
            $lines[] = 'Telefono: ' . $business['telefono'];
        }
        if (!empty($business['email'])) {
            $lines[] = 'Email: ' . $business['email'];
        }
        if (!empty($business['pagina_web'])) {
            $lines[] = 'Web: ' . $business['pagina_web'];
        }
        if (count($lines) === 1) {
            $lines[] = 'No tengo contacto visible para este negocio en la base local.';
        }
        return implode("\n", $lines);
    }

    private function updateInterestPatterns(array $currentPatterns, array $tokens): array
    {
        foreach ($tokens as $token) {
            if (strlen($token) < 4) {
                continue;
            }
            $currentPatterns[$token] = min(200, ((int) ($currentPatterns[$token] ?? 0)) + 1);
        }

        arsort($currentPatterns);
        return array_slice($currentPatterns, 0, 20, true);
    }

    private function isAmbiguous(string $intent, array $tokens, array $matches): bool
    {
        if ($intent === 'contact') {
            return false;
        }
        if (count($tokens) === 0) {
            return true;
        }
        return count($tokens) === 1 && empty($matches);
    }

    private function normalizeText(string $text): string
    {
        $text = Str::lower($text);
        $text = Str::ascii($text);
        return preg_replace('/\s+/', ' ', trim($text)) ?? '';
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $this->normalizeText((string) $needle))) {
                return true;
            }
        }
        return false;
    }

    private function normalizePostalCode(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[0-9]+\.[0-9]+$/', $value) === 1) {
            $value = (string) ((int) round((float) $value));
        }
        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) < 5) {
            $digits = str_pad($digits, 5, '0', STR_PAD_LEFT);
        }
        return Str::limit($digits, 10, '');
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

    private function normalizeBudgetLevel(mixed $value): ?string
    {
        $normalized = $this->normalizeText((string) $value);
        if ($normalized === '') {
            return null;
        }
        if (str_contains($normalized, 'premium') || str_contains($normalized, '$$$') || str_contains($normalized, 'alto')) {
            return 'premium';
        }
        if (str_contains($normalized, 'medio') || str_contains($normalized, '$$')) {
            return 'medio';
        }
        if (str_contains($normalized, 'barato') || str_contains($normalized, '$') || str_contains($normalized, 'bajo')) {
            return 'barato';
        }
        return null;
    }

    private function deriveBudgetFromLegacy(mixed $size): ?string
    {
        $normalized = $this->normalizeText((string) $size);
        if ($normalized === '') {
            return null;
        }
        if (str_contains($normalized, '0 a 5') || str_contains($normalized, '6 a 10')) {
            return 'barato';
        }
        if (str_contains($normalized, '11 a 30') || str_contains($normalized, '31 a 50')) {
            return 'medio';
        }
        if (str_contains($normalized, 'mas de 50')) {
            return 'premium';
        }
        return null;
    }

    private function nullableValue(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $normalized = str_replace(',', '.', trim((string) $value));
        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function buildSearchableText(array $parts): string
    {
        return collect($parts)->map(fn ($part): string => trim((string) $part))->filter()->implode(' ');
    }

    private function isOutdoorCandidate(array $candidate): bool
    {
        $haystack = $this->normalizeText(implode(' ', [
            $candidate['primary_type'] ?? '',
            $candidate['category'] ?? '',
            $candidate['activity'] ?? '',
            $candidate['features'] ?? '',
        ]));

        return $this->containsAny($haystack, ['tour', 'playa', 'outdoor', 'excursion', 'recorrido', 'al aire libre']);
    }

    private function isIndoorFriendlyCandidate(array $candidate): bool
    {
        $haystack = $this->normalizeText(implode(' ', [
            $candidate['primary_type'] ?? '',
            $candidate['category'] ?? '',
            $candidate['activity'] ?? '',
            $candidate['features'] ?? '',
        ]));

        return $this->containsAny($haystack, ['hotel', 'restaurante', 'cafeteria', 'centro comercial', 'indoor', 'aire acondicionado']);
    }
}
