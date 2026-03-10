<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MunicipalKnowledgeService
{
    private static array $datasetCache = [];

    public function buildContext(string $message, ?string $datasetPath = null, array $settings = []): array
    {
        $resolvedPath = $this->resolveDatasetPath($datasetPath);
        $dataset = $this->loadDataset($resolvedPath);

        if (!$dataset) {
            return [
                'error' => "No pude cargar el dataset municipal en: {$resolvedPath}",
                'dataset_path' => $resolvedPath,
                'matched_records' => [],
            ];
        }

        $tokens = $this->extractSearchTokens($message);
        $maxRecords = (int) ($settings['max_records_context']
            ?? config('agents.municipal.max_records_context', 4));
        $maxSources = (int) ($settings['max_sources_context']
            ?? config('agents.municipal.max_sources_context', 6));
        $maxRequirements = (int) ($settings['max_requirements_per_record']
            ?? config('agents.municipal.max_requirements_per_record', 8));

        $records = array_merge(
            Arr::wrap($dataset['tramites_y_servicios'] ?? []),
            Arr::wrap($dataset['programas_y_apoyos'] ?? [])
        );

        $scoredRecords = $this->scoreRecords($records, $message, $tokens);
        $matchedRecords = array_map(
            fn (array $record): array => $this->compactRecord($record, $maxRequirements),
            array_slice($scoredRecords, 0, $maxRecords)
        );

        $matchedDependencies = $this->matchDependencies(
            Arr::wrap($dataset['dependencias'] ?? []),
            $tokens
        );

        return [
            'dataset_path' => $resolvedPath,
            'dataset_name' => $dataset['dataset_name'] ?? null,
            'generated_on' => $dataset['generated_on'] ?? null,
            'scope' => $dataset['scope'] ?? [],
            'municipio' => $dataset['municipio'] ?? [],
            'matched_records' => $matchedRecords,
            'matched_dependencies' => array_slice($matchedDependencies, 0, 5),
            'official_sources' => array_slice(Arr::wrap($dataset['source_notes']['official_sources'] ?? []), 0, $maxSources),
            'faq_sugeridas' => array_slice(Arr::wrap($dataset['faq_sugeridas_para_demo'] ?? []), 0, 8),
            'notas_para_produccion' => Arr::wrap($dataset['notas_para_produccion'] ?? []),
            'total_records' => count($records),
            'token_count' => count($tokens),
        ];
    }

    public function buildFallbackAnswer(string $message, array $context): string
    {
        if (isset($context['error'])) {
            return "No pude consultar la base municipal en este momento. {$context['error']}";
        }

        $records = Arr::wrap($context['matched_records'] ?? []);
        $municipio = Arr::get($context, 'scope.municipio', 'el municipio');
        $estado = Arr::get($context, 'scope.estado', '');
        $ubicacion = trim($municipio . ($estado ? ", {$estado}" : ''));

        if (empty($records)) {
            $sources = Arr::wrap($context['official_sources'] ?? []);
            $sourceLine = '';

            if (!empty($sources)) {
                $urls = collect($sources)
                    ->pluck('url')
                    ->filter()
                    ->take(2)
                    ->values()
                    ->all();

                if (!empty($urls)) {
                    $sourceLine = "\nFuentes oficiales sugeridas:\n- " . implode("\n- ", $urls);
                }
            }

            return "No encontré una coincidencia clara para tu consulta en la base municipal de {$ubicacion}. "
                . "Si me dices el nombre exacto del trámite, programa o dependencia, te doy los requisitos puntuales."
                . $sourceLine;
        }

        $lines = [
            "Encontré información para {$ubicacion}:",
        ];

        foreach (array_slice($records, 0, 3) as $index => $record) {
            $name = $record['nombre'] ?? 'Trámite/servicio';
            $dep = $record['dependencia'] ?? 'Dependencia no especificada';
            $modalidad = $record['modalidad'] ?? 'Modalidad no visible';
            $costo = $this->formatCost($record['costo'] ?? null);
            $respuesta = $record['respuesta_maxima'] ?? 'Tiempo de respuesta no visible';

            $line = ($index + 1) . ". {$name} ({$dep})\n";
            $line .= "Modalidad: {$modalidad}. {$costo}. Respuesta: {$respuesta}.";

            if (!empty($record['donde']['oficina'])) {
                $line .= " Oficina: {$record['donde']['oficina']}.";
            }

            if (!empty($record['donde']['telefono'])) {
                $line .= " Tel: {$record['donde']['telefono']}.";
            }

            $lines[] = $line;
        }

        $sources = collect(Arr::wrap($context['official_sources'] ?? []))
            ->pluck('url')
            ->filter()
            ->take(2)
            ->values()
            ->all();

        if (!empty($sources)) {
            $lines[] = "Fuentes: " . implode(' | ', $sources);
        }

        return implode("\n\n", $lines);
    }

    private function resolveDatasetPath(?string $datasetPath): string
    {
        $path = $datasetPath ?: config('agents.municipal.default_dataset_path', 'storage/progreso.json');

        if (Str::startsWith($path, ['/', '\\']) || preg_match('/^[A-Za-z]:[\/\\\\]/', $path)) {
            return $path;
        }

        return base_path($path);
    }

    private function loadDataset(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $cacheKey = $path . '|' . filemtime($path);

        if (isset(self::$datasetCache[$cacheKey])) {
            return self::$datasetCache[$cacheKey];
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            return null;
        }

        self::$datasetCache = [$cacheKey => $decoded];

        return $decoded;
    }

    private function extractSearchTokens(string $message): array
    {
        $normalized = $this->normalizeText($message);
        $rawTokens = preg_split('/[^a-z0-9]+/', $normalized) ?: [];
        $stopWords = [
            'de', 'la', 'el', 'los', 'las', 'en', 'y', 'o', 'a', 'un', 'una',
            'para', 'por', 'con', 'que', 'como', 'del', 'al', 'mi', 'tu', 'su',
            'se', 'me', 'te', 'es', 'son', 'hay', 'donde', 'cuando', 'cual',
            'necesito', 'quiero', 'tramite', 'trámite', 'servicio',
        ];

        $tokens = array_values(array_unique(array_filter($rawTokens, function (string $token) use ($stopWords): bool {
            return strlen($token) >= 3 && !in_array($token, $stopWords, true);
        })));

        return array_slice($tokens, 0, 16);
    }

    private function scoreRecords(array $records, string $originalMessage, array $tokens): array
    {
        $query = $this->normalizeText($originalMessage);
        $scored = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $haystack = $this->normalizeText(implode(' ', [
                $record['id'] ?? '',
                $record['nombre'] ?? '',
                $record['tipo'] ?? '',
                $record['dependencia'] ?? '',
                $record['descripcion'] ?? '',
                implode(' ', Arr::wrap($record['requisitos'] ?? [])),
                Arr::get($record, 'donde.oficina', ''),
                Arr::get($record, 'donde.direccion', ''),
                Arr::get($record, 'donde.horario', ''),
                Arr::get($record, 'homoclave', ''),
            ]));

            if ($haystack === '') {
                continue;
            }

            $score = 0;

            if ($query !== '' && str_contains($haystack, $query)) {
                $score += 12;
            }

            foreach ($tokens as $token) {
                if (str_contains($haystack, $token)) {
                    $score += strlen($token) >= 6 ? 3 : 2;
                }
            }

            if ($score > 0) {
                $record['_score'] = $score;
                $scored[] = $record;
            }
        }

        usort($scored, fn (array $a, array $b): int => ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0));

        if (!empty($scored)) {
            return $scored;
        }

        return array_slice($records, 0, 3);
    }

    private function compactRecord(array $record, int $maxRequirements): array
    {
        return [
            'id' => $record['id'] ?? null,
            'nombre' => $record['nombre'] ?? null,
            'tipo' => $record['tipo'] ?? null,
            'homoclave' => $record['homoclave'] ?? null,
            'dependencia' => $record['dependencia'] ?? null,
            'descripcion' => $record['descripcion'] ?? null,
            'modalidad' => $record['modalidad'] ?? null,
            'requisitos' => array_slice(Arr::wrap($record['requisitos'] ?? []), 0, $maxRequirements),
            'costo' => $record['costo'] ?? null,
            'respuesta_maxima' => $record['respuesta_maxima'] ?? null,
            'vigencia' => $record['vigencia'] ?? null,
            'donde' => $record['donde'] ?? [],
            'whatsapp_flow' => $record['whatsapp_flow'] ?? [],
            'score' => $record['_score'] ?? null,
        ];
    }

    private function matchDependencies(array $dependencies, array $tokens): array
    {
        if (empty($tokens)) {
            return [];
        }

        $matches = [];

        foreach ($dependencies as $dependency) {
            if (!is_string($dependency) || trim($dependency) === '') {
                continue;
            }

            $normalized = $this->normalizeText($dependency);
            $score = 0;

            foreach ($tokens as $token) {
                if (str_contains($normalized, $token)) {
                    $score += 1;
                }
            }

            if ($score > 0) {
                $matches[] = [
                    'nombre' => $dependency,
                    'score' => $score,
                ];
            }
        }

        usort($matches, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $matches;
    }

    private function normalizeText(string $text): string
    {
        $text = Str::lower($text);
        $text = Str::ascii($text);

        return preg_replace('/\s+/', ' ', trim($text)) ?? '';
    }

    private function formatCost(mixed $cost): string
    {
        if (!is_array($cost)) {
            return 'Costo no visible';
        }

        $amount = $cost['monto_mxn'] ?? null;
        $type = $cost['tipo'] ?? null;

        if (is_numeric($amount)) {
            return 'Costo: $' . number_format((float) $amount, 2) . ' MXN';
        }

        if ($type) {
            return 'Costo: ' . $type;
        }

        return 'Costo no visible';
    }
}
