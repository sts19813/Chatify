<?php

namespace App\Services;

use App\Models\KiroUserContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class KiroBusinessDirectoryService
{
    public function buildContext(int $userId, int $agentUserId, string $message, array $settings = []): array
    {
        $currentTime = now()->setTimezone((string) config('app.timezone', 'America/Mexico_City'))->toDateTimeString();

        if (!Schema::hasTable('business_directories')) {
            return [
                'error' => 'La tabla business_directories no existe.',
                'user_location' => null,
                'chat_history' => [],
                'current_time' => $currentTime,
                'matched_businesses' => [],
            ];
        }

        $maxHistory = max(4, (int) ($settings['max_history_context'] ?? config('agents.kiro.max_history_context', 8)));
        $maxCandidates = max(20, (int) ($settings['max_candidates_context'] ?? config('agents.kiro.max_candidates_context', 120)));
        $maxResults = min(5, max(3, (int) ($settings['max_results'] ?? config('agents.kiro.max_results', 5))));

        $userContext = $this->resolveUserContext($userId);
        $message = trim($message);

        $locationFromMessage = $this->extractLocationFromMessage($message);
        $userLocation = $locationFromMessage ?: ($userContext?->user_location ?: null);

        $intent = $this->detectIntent($message);
        $tokens = $this->extractSearchTokens($message);
        $locationTokens = $this->extractLocationTokens((string) $userLocation);
        $chatHistory = $this->loadChatHistory($userId, $agentUserId, $maxHistory);
        $chatSummary = $this->buildInternalSummary($chatHistory, (string) ($userContext?->chat_summary ?? ''));
        $preferenceTags = $this->detectPreferenceTags($message, Arr::wrap($userContext?->preference_tags ?? []));
        $pricePreference = $this->resolvePricePreference($message, $userContext?->price_preference);

        $directContactMode = false;
        $usedSimilarResults = false;

        $lastResultIds = array_map(
            static fn ($id): int => (int) $id,
            Arr::wrap($userContext?->last_result_ids ?? []),
        );

        $matchedBusinesses = [];

        if ($intent === 'contact' && !empty($lastResultIds)) {
            $matchedBusinesses = $this->fetchBusinessesByIds($lastResultIds);
            $directContactMode = !empty($matchedBusinesses);
        }

        if (empty($matchedBusinesses)) {
            $candidates = $this->collectCandidates(
                tokens: $tokens,
                locationTokens: $locationTokens,
                maxCandidates: $maxCandidates,
            );

            $scored = $this->scoreBusinesses(
                candidates: $candidates,
                tokens: $tokens,
                locationTokens: $locationTokens,
                preferenceTags: $preferenceTags,
                pricePreference: $pricePreference,
                intent: $intent,
            );

            $matchedBusinesses = array_slice($scored, 0, $maxResults);

            if (empty($matchedBusinesses) && !empty($locationTokens)) {
                $matchedBusinesses = $this->searchByLocationOnly($locationTokens, $maxResults);
                $usedSimilarResults = !empty($matchedBusinesses);
            }
        }

        $mappedBusinesses = array_map(fn (array $row): array => $this->toBusinessPayload($row), $matchedBusinesses);
        $interestPatterns = $this->updateInterestPatterns(Arr::wrap($userContext?->interest_patterns ?? []), $tokens);
        $ambiguous = $this->isAmbiguous($intent, $tokens, $mappedBusinesses);

        if ($userContext !== null) {
            $userContext->user_location = $userLocation;
            $userContext->price_preference = $pricePreference;
            $userContext->preference_tags = $preferenceTags;
            $userContext->interest_patterns = $interestPatterns;
            $userContext->chat_summary = $chatSummary;
            $userContext->last_intent = $intent;
            $userContext->last_query = $message;
            $userContext->last_result_ids = array_values(array_map(
                static fn (array $business): int => (int) $business['id'],
                $mappedBusinesses,
            ));
            $userContext->last_interaction_at = now();
            $userContext->save();
        }

        return [
            'user_location' => $userLocation,
            'chat_history' => $chatHistory,
            'chat_history_summary' => $chatSummary,
            'current_time' => $currentTime,
            'intent' => $intent,
            'preference_tags' => $preferenceTags,
            'price_preference' => $pricePreference,
            'interest_patterns' => $interestPatterns,
            'ambiguous' => $ambiguous,
            'direct_contact_mode' => $directContactMode,
            'used_similar_results' => $usedSimilarResults,
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
            return '¿Qué tipo de negocio buscas y en qué zona?';
        }

        if ($intent === 'contact' && !empty($businesses)) {
            return $this->buildDirectContactAnswer($businesses[0]);
        }

        if (empty($businesses)) {
            if ($location !== '') {
                return "No encontré coincidencias exactas en {$location}. ¿Prefieres que lo busque por giro o por colonia?";
            }

            return 'No encontré coincidencias exactas. ¿Qué negocio necesitas y en qué zona te queda mejor?';
        }

        $lines = [];

        foreach (array_slice($businesses, 0, 5) as $index => $business) {
            $line = ($index + 1) . '. ' . $business['nombre'];
            $line .= "\nGiro/actividad: " . $business['giro_o_actividad'];
            $line .= "\nDirección: " . $business['direccion'];

            if (!empty($business['telefono'])) {
                $line .= "\nTeléfono: " . $business['telefono'];
            }

            if (!empty($business['pagina_web'])) {
                $line .= "\nWeb: " . $business['pagina_web'];
            }

            $lines[] = $line;
        }

        return implode("\n\n", $lines);
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
            ->take(-5)
            ->implode(' | ');

        $summary = trim($previousSummary) !== ''
            ? trim($previousSummary) . ' | ' . $recent
            : $recent;

        return Str::limit(trim($summary, ' |'), 650);
    }

    private function detectIntent(string $message): string
    {
        $normalized = $this->normalizeText($message);

        if ($this->containsAny($normalized, ['telefono', 'tel', 'whatsapp', 'contacto', 'correo', 'email', 'web', 'pagina'])) {
            return 'contact';
        }

        if ($this->containsAny($normalized, ['compara', 'comparar', 'comparacion', 'versus', 'vs', 'mejor'])) {
            return 'compare';
        }

        if ($this->containsAny($normalized, ['recomienda', 'recomendar', 'sugiere', 'sugerir', 'opcion', 'opciones'])) {
            return 'recommend';
        }

        if ($this->containsAny($normalized, ['donde queda', 'ubicacion', 'ubicar', 'cerca', 'como llegar', 'direccion'])) {
            return 'locate';
        }

        return 'search';
    }

    private function extractSearchTokens(string $message): array
    {
        $normalized = $this->normalizeText($message);
        $rawTokens = preg_split('/[^a-z0-9]+/', $normalized) ?: [];
        $stopWords = [
            'de', 'la', 'el', 'los', 'las', 'en', 'y', 'o', 'a', 'un', 'una',
            'para', 'por', 'con', 'que', 'como', 'del', 'al', 'mi', 'tu', 'su',
            'me', 'te', 'se', 'es', 'son', 'hay', 'quiero', 'necesito', 'busco',
            'buscar', 'dame', 'donde', 'queda', 'algo', 'cerca', 'telefono',
            'correo', 'email', 'web', 'pagina', 'contacto', 'negocio', 'negocios',
        ];

        $tokens = array_values(array_unique(array_filter(
            $rawTokens,
            static fn (string $token): bool => strlen($token) >= 3 && !in_array($token, $stopWords, true),
        )));

        return array_slice($tokens, 0, 12);
    }

    private function extractLocationTokens(string $location): array
    {
        if (trim($location) === '') {
            return [];
        }

        $tokens = preg_split('/[^a-z0-9]+/', $this->normalizeText($location)) ?: [];

        return array_values(array_filter($tokens, static fn (string $token): bool => strlen($token) >= 3));
    }

    private function collectCandidates(array $tokens, array $locationTokens, int $maxCandidates): array
    {
        $query = DB::table('business_directories')->select([
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

        $rows = $query->limit($maxCandidates)->get()->map(static fn ($row): array => (array) $row)->all();

        if (!empty($rows)) {
            return $rows;
        }

        if (!empty($locationTokens)) {
            return $this->searchByLocationOnly($locationTokens, $maxCandidates);
        }

        return [];
    }

    private function applyTokenFilter(Builder $query, array $tokens, array $columns): void
    {
        $query->where(function ($where) use ($tokens, $columns): void {
            foreach (array_slice($tokens, 0, 6) as $token) {
                $like = '%' . $token . '%';

                foreach ($columns as $column) {
                    $where->orWhere($column, 'like', $like);
                }
            }
        });
    }

    private function scoreBusinesses(
        array $candidates,
        array $tokens,
        array $locationTokens,
        array $preferenceTags,
        ?string $pricePreference,
        string $intent
    ): array {
        $scored = [];

        foreach ($candidates as $candidate) {
            $score = 0;

            $name = $this->normalizeText((string) ($candidate['nombre_comercial'] ?? ''));
            $social = $this->normalizeText((string) ($candidate['razon_social'] ?? ''));
            $giro = $this->normalizeText((string) ($candidate['giro'] ?? ''));
            $activity = $this->normalizeText((string) ($candidate['actividad'] ?? ''));
            $location = $this->normalizeText(implode(' ', [
                $candidate['colonia'] ?? '',
                $candidate['ciudad'] ?? '',
                $candidate['estado'] ?? '',
                $candidate['codigo_postal'] ?? '',
            ]));

            foreach ($tokens as $token) {
                if (str_contains($name, $token) || str_contains($social, $token)) {
                    $score += 6;
                }

                if (str_contains($activity, $token)) {
                    $score += 5;
                }

                if (str_contains($giro, $token)) {
                    $score += 4;
                }

                if (str_contains($location, $token)) {
                    $score += 2;
                }
            }

            foreach ($locationTokens as $token) {
                if (str_contains($location, $token)) {
                    $score += 6;
                }
            }

            if (in_array('cerca', $preferenceTags, true) && !empty($locationTokens)) {
                $score += 2;
            }

            if ($pricePreference === 'barato' && $this->looksSmallBusiness((string) ($candidate['tamano'] ?? ''))) {
                $score += 2;
            }

            if ($intent === 'contact') {
                if (!empty($candidate['telefono'])) {
                    $score += 2;
                }

                if (!empty($candidate['email']) || !empty($candidate['pagina_web'])) {
                    $score += 1;
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

            return strcmp(
                (string) ($a['nombre_comercial'] ?? $a['razon_social'] ?? ''),
                (string) ($b['nombre_comercial'] ?? $b['razon_social'] ?? ''),
            );
        });

        return $scored;
    }

    private function fetchBusinessesByIds(array $ids): array
    {
        $validIds = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if (empty($validIds)) {
            return [];
        }

        $rows = DB::table('business_directories')
            ->whereIn('id', $validIds)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->keyBy('id');

        $ordered = [];

        foreach ($validIds as $id) {
            if ($rows->has($id)) {
                $ordered[] = $rows->get($id);
            }
        }

        return $ordered;
    }

    private function searchByLocationOnly(array $locationTokens, int $limit): array
    {
        if (empty($locationTokens)) {
            return [];
        }

        $query = DB::table('business_directories');
        $this->applyTokenFilter($query, $locationTokens, ['colonia', 'ciudad', 'estado', 'codigo_postal']);

        return $query
            ->limit($limit)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    private function toBusinessPayload(array $row): array
    {
        $name = trim((string) ($row['nombre_comercial'] ?? ''));

        if ($name === '') {
            $name = trim((string) ($row['razon_social'] ?? ''));
        }

        if ($name === '') {
            $name = 'Negocio sin nombre visible';
        }

        $activity = trim((string) ($row['actividad'] ?? ''));
        $giro = trim((string) ($row['giro'] ?? ''));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'nombre' => $name,
            'giro' => $giro !== '' ? $giro : null,
            'actividad' => $activity !== '' ? $activity : null,
            'giro_o_actividad' => $activity !== '' ? $activity : ($giro !== '' ? $giro : 'Actividad no visible'),
            'direccion' => $this->formatAddress($row),
            'telefono' => $this->nullableValue($row['telefono'] ?? null),
            'email' => $this->nullableValue($row['email'] ?? null),
            'pagina_web' => $this->nullableValue($row['pagina_web'] ?? null),
            'ciudad' => $this->nullableValue($row['ciudad'] ?? null),
            'estado' => $this->nullableValue($row['estado'] ?? null),
            'colonia' => $this->nullableValue($row['colonia'] ?? null),
        ];
    }

    private function buildDirectContactAnswer(array $business): string
    {
        $lines = [
            $business['nombre'] ?? 'Negocio',
        ];

        if (!empty($business['telefono'])) {
            $lines[] = 'Teléfono: ' . $business['telefono'];
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

    private function detectPreferenceTags(string $message, array $existingTags): array
    {
        $tags = collect($existingTags)->map(fn ($tag): string => $this->normalizeText((string) $tag))->filter()->values()->all();
        $normalized = $this->normalizeText($message);

        if ($this->containsAny($normalized, ['barato', 'economico', 'economica', 'accesible'])) {
            $tags[] = 'barato';
        }

        if ($this->containsAny($normalized, ['cerca', 'cercano', 'cercana', 'por aqui', 'por aqui'])) {
            $tags[] = 'cerca';
        }

        if ($this->containsAny($normalized, ['abierto ahora', 'abierto', 'abren'])) {
            $tags[] = 'abierto_ahora';
        }

        return array_values(array_unique(array_filter($tags)));
    }

    private function resolvePricePreference(string $message, ?string $currentPreference): ?string
    {
        $normalized = $this->normalizeText($message);

        if ($this->containsAny($normalized, ['barato', 'economico', 'economica', 'accesible'])) {
            return 'barato';
        }

        if ($this->containsAny($normalized, ['premium', 'lujo'])) {
            return 'premium';
        }

        return $currentPreference;
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

    private function extractLocationFromMessage(string $message): ?string
    {
        $patterns = [
            '/(?:estoy|vivo|ubicad[oa]|me encuentro)\s+(?:en|por|cerca de)\s+([a-zA-Z0-9áéíóúñÁÉÍÓÚÑ,.\-\s]{3,80})/u',
            '/(?:mi ubicacion es|mi ubicación es)\s+([a-zA-Z0-9áéíóúñÁÉÍÓÚÑ,.\-\s]{3,80})/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $location = trim((string) ($matches[1] ?? ''), " \t\n\r\0\x0B.,;");

                if ($location !== '') {
                    return Str::limit($location, 120, '');
                }
            }
        }

        return null;
    }

    private function formatAddress(array $row): string
    {
        $calle = trim((string) ($row['calle'] ?? ''));
        $numExt = trim((string) ($row['numero_exterior'] ?? ''));
        $letExt = trim((string) ($row['letra_exterior'] ?? ''));
        $numero = trim($numExt . ($letExt !== '' ? " {$letExt}" : ''));

        if ($numero === '' || $numero === '0') {
            $numero = 'S/N';
        }

        $line1 = trim(($calle !== '' ? $calle : 'Calle no visible') . ' ' . $numero);
        $colonia = $this->nullableValue($row['colonia'] ?? null);
        $ciudad = $this->nullableValue($row['ciudad'] ?? null);
        $estado = $this->nullableValue($row['estado'] ?? null);
        $cp = $this->nullableValue($row['codigo_postal'] ?? null);

        $parts = array_filter([$line1, $colonia, $ciudad, $estado]);

        if ($cp !== null) {
            $parts[] = "CP {$cp}";
        }

        return implode(', ', $parts);
    }

    private function looksSmallBusiness(string $size): bool
    {
        $normalized = $this->normalizeText($size);

        return str_contains($normalized, '0 a 5') || str_contains($normalized, '6 a 10');
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

    private function normalizeText(string $text): string
    {
        $text = Str::lower($text);
        $text = Str::ascii($text);

        return preg_replace('/\s+/', ' ', trim($text)) ?? '';
    }

    private function nullableValue(mixed $value): ?string
    {
        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }
}
