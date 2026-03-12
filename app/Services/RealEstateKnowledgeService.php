<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class RealEstateKnowledgeService
{
    private static array $datasetCache = [];

    private array $resourceSynonyms = [
        'brochure' => ['brochure', 'folleto', 'catalogo'],
        'pdv' => ['pdv', 'precio', 'precios', 'price list', 'lista de precios'],
        'availability' => ['disponibilidad', 'inventario', 'unidades', 'disponible'],
        'technical_sheet' => ['ficha tecnica', 'especificaciones'],
        'renders' => ['render', 'renders', 'imagenes'],
        'videos' => ['video', 'videos', 'recorrido', 'recorridos'],
        'location' => ['ubicacion', 'mapa', 'maps', 'google maps'],
        'descriptive_memory' => ['memoria descriptiva', 'descripcion'],
    ];

    public function buildContext(string $message, ?string $datasetPath = null, array $settings = []): array
    {
        $resolvedPath = $this->resolveDatasetPath($datasetPath);
        $dataset = $this->loadDataset($resolvedPath);

        if (!$dataset) {
            return [
                'error' => "No pude cargar el dataset inmobiliario en: {$resolvedPath}",
                'dataset_path' => $resolvedPath,
                'matched_projects' => [],
            ];
        }

        $tokens = $this->extractSearchTokens($message);
        $resourceIntent = $this->detectResourceIntent($message);
        $priceIntent = $this->isPriceIntent($message);

        $maxProjects = (int) ($settings['max_projects_context']
            ?? config('agents.real_estate.max_projects_context', 3));
        $maxResources = (int) ($settings['max_resources_context']
            ?? config('agents.real_estate.max_resources_context', 8));
        $maxFeatures = (int) ($settings['max_features_context']
            ?? config('agents.real_estate.max_features_context', 10));

        $projects = Arr::wrap($dataset['projects'] ?? []);
        $scoredProjects = $this->scoreProjects($projects, $message, $tokens);

        $matchedProjects = array_map(
            fn (array $project): array => $this->compactProject(
                project: $project,
                maxResources: $maxResources,
                maxFeatures: $maxFeatures,
                resourceIntent: $resourceIntent,
            ),
            array_slice($scoredProjects, 0, $maxProjects)
        );

        return [
            'dataset_path' => $resolvedPath,
            'dataset_name' => $dataset['dataset_name'] ?? null,
            'updated_at' => $dataset['updated_at'] ?? null,
            'agent_key' => $dataset['agent_key'] ?? null,
            'resource_intent' => $resourceIntent,
            'price_intent' => $priceIntent,
            'matched_projects' => $matchedProjects,
            'project_count' => count($projects),
            'token_count' => count($tokens),
            'sales_contact' => Arr::wrap($dataset['sales_contact'] ?? []),
            'global_resources' => $this->normalizeResources(Arr::wrap($dataset['global_resources'] ?? []), $maxResources),
        ];
    }

    public function buildFallbackAnswer(string $message, array $context): string
    {
        if (isset($context['error'])) {
            return "No pude consultar la base inmobiliaria en este momento. {$context['error']}";
        }

        $projects = Arr::wrap($context['matched_projects'] ?? []);
        $resourceIntent = $context['resource_intent'] ?? null;
        $priceIntent = (bool) ($context['price_intent'] ?? false);

        if (empty($projects)) {
            return 'No encontre una coincidencia clara del desarrollo. '
                . 'Dime el nombre exacto (por ejemplo: Okte, Adia, Mystika o Thula) y te paso precio, disponibilidad o documentos.';
        }

        $project = $projects[0];
        $projectName = $project['name'] ?? 'Proyecto';
        $lines = ["Proyecto: {$projectName}"];

        if ($priceIntent) {
            $priceFrom = $this->formatMoney($project['price_from_mxn'] ?? null, $project['currency'] ?? 'MXN');
            $delivery = $project['delivery_date'] ?? null;

            if ($priceFrom !== null) {
                $lines[] = "Precio desde: {$priceFrom}.";
            } else {
                $lines[] = 'Precio desde: dato no visible en base.';
            }

            if ($delivery) {
                $lines[] = "Fecha de entrega: {$delivery}.";
            }
        }

        $resources = Arr::wrap($project['resources'] ?? []);

        if ($resourceIntent) {
            $filtered = array_values(array_filter($resources, fn (array $resource): bool => ($resource['resource_type'] ?? '') === $resourceIntent));

            if (empty($filtered)) {
                $label = $this->resourceTypeLabel($resourceIntent);
                $lines[] = "No tengo link de {$label} cargado para {$projectName}.";
            } else {
                $lines[] = 'Te comparto lo solicitado:';
                foreach (array_slice($filtered, 0, 4) as $resource) {
                    $lines[] = $this->formatResourceLine($resource);
                }
            }
        } else {
            $lines[] = $project['summary'] ?? 'Asistente inmobiliario listo.';

            $topResources = array_slice($resources, 0, 3);
            if (!empty($topResources)) {
                $lines[] = 'Links utiles:';
                foreach ($topResources as $resource) {
                    $lines[] = $this->formatResourceLine($resource);
                }
            }
        }

        $salesContact = Arr::wrap($context['sales_contact'] ?? []);
        if (!empty($salesContact['whatsapp'])) {
            $lines[] = "WhatsApp comercial: {$salesContact['whatsapp']}";
        }

        return implode("\n", array_filter($lines));
    }

    private function resolveDatasetPath(?string $datasetPath): string
    {
        $path = $datasetPath ?: config('agents.real_estate.default_dataset_path', 'storage/kiro_real_estate.json');

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
            'de', 'la', 'el', 'los', 'las', 'en', 'y', 'o', 'a', 'un', 'una', 'para', 'por',
            'con', 'que', 'como', 'del', 'al', 'me', 'pasame', 'pasame', 'cuanto', 'desde',
            'precio', 'brochure', 'quiero', 'necesito', 'mandame', 'manda',
        ];

        return array_slice(array_values(array_unique(array_filter($rawTokens, function (string $token) use ($stopWords): bool {
            return strlen($token) >= 3 && !in_array($token, $stopWords, true);
        }))), 0, 18);
    }

    private function detectResourceIntent(string $message): ?string
    {
        $normalized = $this->normalizeText($message);

        foreach ($this->resourceSynonyms as $resourceType => $words) {
            foreach ($words as $word) {
                if (str_contains($normalized, $this->normalizeText($word))) {
                    return $resourceType;
                }
            }
        }

        return null;
    }

    private function isPriceIntent(string $message): bool
    {
        $normalized = $this->normalizeText($message);
        $keywords = ['precio', 'costo', 'valor', 'desde cuanto', 'desde cuanto', 'apartado', 'enganche', 'mensualidad'];

        foreach ($keywords as $word) {
            if (str_contains($normalized, $this->normalizeText($word))) {
                return true;
            }
        }

        return false;
    }

    private function scoreProjects(array $projects, string $message, array $tokens): array
    {
        $query = $this->normalizeText($message);
        $scored = [];

        foreach ($projects as $project) {
            if (!is_array($project)) {
                continue;
            }

            $aliases = Arr::wrap($project['aliases'] ?? []);
            $haystack = $this->normalizeText(implode(' ', [
                $project['project_key'] ?? '',
                $project['name'] ?? '',
                $project['slug'] ?? '',
                implode(' ', $aliases),
                $project['summary'] ?? '',
                Arr::get($project, 'location.zone', ''),
                Arr::get($project, 'location.city', ''),
                Arr::get($project, 'location.address', ''),
                implode(' ', Arr::wrap($project['features'] ?? [])),
                $this->resourcesText(Arr::wrap($project['resources'] ?? [])),
            ]));

            if ($haystack === '') {
                continue;
            }

            $score = 0;

            if ($query !== '' && str_contains($haystack, $query)) {
                $score += 15;
            }

            foreach ($tokens as $token) {
                if (str_contains($haystack, $token)) {
                    $score += strlen($token) >= 5 ? 3 : 2;
                }
            }

            $projectName = $this->normalizeText((string) ($project['name'] ?? ''));
            if ($projectName !== '' && str_contains($query, $projectName)) {
                $score += 10;
            }

            $projectSlug = $this->normalizeText((string) ($project['slug'] ?? ''));
            if ($projectSlug !== '' && str_contains($query, $projectSlug)) {
                $score += 10;
            }

            foreach ($aliases as $alias) {
                $aliasNormalized = $this->normalizeText((string) $alias);
                if ($aliasNormalized !== '' && str_contains($query, $aliasNormalized)) {
                    $score += 8;
                }
            }

            if ($score > 0) {
                $project['_score'] = $score;
                $scored[] = $project;
            }
        }

        usort($scored, fn (array $a, array $b): int => ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0));

        if (!empty($scored)) {
            return $scored;
        }

        return array_slice($projects, 0, 2);
    }

    private function resourcesText(array $resources): string
    {
        $lines = [];

        foreach ($resources as $resource) {
            if (!is_array($resource)) {
                continue;
            }

            $lines[] = implode(' ', [
                (string) ($resource['resource_type'] ?? ''),
                (string) ($resource['resource_label'] ?? ''),
                (string) ($resource['notes'] ?? ''),
                (string) ($resource['content'] ?? ''),
                implode(' ', Arr::wrap($resource['keywords'] ?? [])),
            ]);
        }

        return implode(' ', $lines);
    }

    private function compactProject(array $project, int $maxResources, int $maxFeatures, ?string $resourceIntent): array
    {
        $resources = $this->normalizeResources(Arr::wrap($project['resources'] ?? []), $maxResources);

        if ($resourceIntent !== null) {
            $resources = array_values(array_filter($resources, fn (array $resource): bool => ($resource['resource_type'] ?? '') === $resourceIntent));
            $resources = array_slice($resources, 0, $maxResources);
        }

        return [
            'project_key' => $project['project_key'] ?? null,
            'name' => $project['name'] ?? null,
            'slug' => $project['slug'] ?? null,
            'website_url' => $project['website_url'] ?? null,
            'status' => $project['status'] ?? null,
            'summary' => $project['summary'] ?? null,
            'price_from_mxn' => $project['price_from_mxn'] ?? null,
            'currency' => $project['currency'] ?? 'MXN',
            'delivery_date' => $project['delivery_date'] ?? null,
            'location' => Arr::wrap($project['location'] ?? []),
            'payment_options' => array_slice(Arr::wrap($project['payment_options'] ?? []), 0, 6),
            'features' => array_slice(Arr::wrap($project['features'] ?? []), 0, $maxFeatures),
            'resources' => $resources,
            'score' => $project['_score'] ?? null,
        ];
    }

    private function normalizeResources(array $resources, int $maxResources): array
    {
        $normalized = [];

        foreach ($resources as $resource) {
            if (!is_array($resource)) {
                continue;
            }

            $resourceType = (string) ($resource['resource_type'] ?? '');
            if ($resourceType === '') {
                continue;
            }

            $sourceUrl = (string) ($resource['source_url'] ?? '');
            $driveInfo = $this->extractDriveInfo($sourceUrl);

            $normalized[] = [
                'resource_type' => $resourceType,
                'resource_label' => $resource['resource_label'] ?? $this->resourceTypeLabel($resourceType),
                'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
                'source_type' => $resource['source_type'] ?? ($driveInfo ? 'drive' : 'url'),
                'preview_url' => $resource['preview_url'] ?? ($driveInfo['preview_url'] ?? null),
                'download_url' => $resource['download_url'] ?? ($driveInfo['download_url'] ?? null),
                'notes' => $resource['notes'] ?? null,
                'tags' => Arr::wrap($resource['tags'] ?? []),
            ];
        }

        return array_slice($normalized, 0, $maxResources);
    }

    private function extractDriveInfo(string $url): ?array
    {
        if (!str_contains($url, 'drive.google.com') && !str_contains($url, 'docs.google.com')) {
            return null;
        }

        $patterns = [
            '/\/file\/d\/([a-zA-Z0-9_-]+)/',
            '/id=([a-zA-Z0-9_-]+)/',
            '/\/document\/d\/([a-zA-Z0-9_-]+)/',
            '/\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/',
            '/\/presentation\/d\/([a-zA-Z0-9_-]+)/',
        ];

        $fileId = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches) === 1) {
                $fileId = $matches[1];
                break;
            }
        }

        if (!$fileId) {
            return null;
        }

        return [
            'file_id' => $fileId,
            'preview_url' => "https://drive.google.com/file/d/{$fileId}/view",
            'download_url' => "https://drive.google.com/uc?export=download&id={$fileId}",
        ];
    }

    private function resourceTypeLabel(string $resourceType): string
    {
        return match ($resourceType) {
            'brochure' => 'Brochure',
            'pdv' => 'PDV',
            'availability' => 'Disponibilidad',
            'technical_sheet' => 'Ficha tecnica',
            'renders' => 'Renders',
            'videos' => 'Videos',
            'location' => 'Ubicacion',
            'descriptive_memory' => 'Memoria descriptiva',
            default => Str::headline(str_replace('_', ' ', $resourceType)),
        };
    }

    private function formatResourceLine(array $resource): string
    {
        $label = $resource['resource_label'] ?? 'Documento';
        $primary = $resource['source_url'] ?? null;
        $preview = $resource['preview_url'] ?? null;
        $download = $resource['download_url'] ?? null;

        $parts = [];

        if ($primary) {
            $parts[] = "link {$primary}";
        }

        if ($preview && $preview !== $primary) {
            $parts[] = "ver {$preview}";
        }

        if ($download) {
            $parts[] = "descargar {$download}";
        }

        if (empty($parts)) {
            return "- {$label}";
        }

        return "- {$label}: " . implode(' | ', $parts);
    }

    private function formatMoney(mixed $amount, string $currency = 'MXN'): ?string
    {
        if (!is_numeric($amount)) {
            return null;
        }

        return '$' . number_format((float) $amount, 2) . " {$currency}";
    }

    private function normalizeText(string $text): string
    {
        $text = Str::lower($text);
        $text = Str::ascii($text);

        return preg_replace('/\s+/', ' ', trim($text)) ?? '';
    }
}
