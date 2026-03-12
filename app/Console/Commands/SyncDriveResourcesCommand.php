<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncDriveResourcesCommand extends Command
{
    protected $signature = 'dataset:sync-drive
        {--dataset=storage/kiro_real_estate.json : Ruta del dataset JSON}
        {--limit=12000 : Maximo de caracteres por recurso}
        {--timeout=30 : Timeout de descarga en segundos}
        {--dry-run : No escribe archivo, solo muestra resultados}';

    protected $description = 'Descarga texto de Google Docs/Sheets publicos y actualiza el dataset inmobiliario';

    public function handle(): int
    {
        $datasetPath = $this->resolvePath((string) $this->option('dataset'));
        $limit = max(1000, (int) $this->option('limit'));
        $timeout = max(5, (int) $this->option('timeout'));
        $dryRun = (bool) $this->option('dry-run');

        if (!is_file($datasetPath) || !is_readable($datasetPath)) {
            $this->error("No se pudo leer el dataset: {$datasetPath}");
            return self::FAILURE;
        }

        $json = file_get_contents($datasetPath);
        if ($json === false) {
            $this->error("No se pudo abrir el dataset: {$datasetPath}");
            return self::FAILURE;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->error('JSON invalido en dataset.');
            return self::FAILURE;
        }

        $projects = &$data['projects'];
        if (!is_array($projects)) {
            $this->error('El dataset no contiene un arreglo projects.');
            return self::FAILURE;
        }

        $updatedResources = 0;

        foreach ($projects as $projectIndex => &$project) {
            if (!is_array($project) || !isset($project['resources']) || !is_array($project['resources'])) {
                continue;
            }

            $projectName = (string) ($project['name'] ?? "Proyecto {$projectIndex}");

            foreach ($project['resources'] as $resourceIndex => &$resource) {
                if (!is_array($resource)) {
                    continue;
                }

                $sourceUrl = trim((string) ($resource['source_url'] ?? ''));
                if ($sourceUrl === '') {
                    continue;
                }

                $resourceLabel = (string) ($resource['resource_label'] ?? "Recurso {$resourceIndex}");
                $fileId = $this->extractDriveFileId($sourceUrl);

                if ($fileId) {
                    $resource['preview_url'] = $resource['preview_url'] ?? "https://drive.google.com/file/d/{$fileId}/view";
                    $resource['download_url'] = $resource['download_url'] ?? "https://drive.google.com/uc?export=download&id={$fileId}";
                }

                $exportUrl = $this->buildExportUrl($sourceUrl, $fileId);
                if (!$exportUrl) {
                    $this->line("SKIP {$projectName} / {$resourceLabel} (sin export de texto automatico)");
                    continue;
                }

                try {
                    $response = Http::timeout($timeout)->get($exportUrl);
                } catch (\Throwable $e) {
                    $this->warn("ERROR {$projectName} / {$resourceLabel}: {$e->getMessage()}");
                    continue;
                }

                if (!$response->successful()) {
                    $this->warn("ERROR {$projectName} / {$resourceLabel}: HTTP {$response->status()}");
                    continue;
                }

                $raw = trim((string) $response->body());
                if ($raw === '') {
                    $this->warn("VACIO {$projectName} / {$resourceLabel}");
                    continue;
                }

                $content = preg_replace('/\s+/', ' ', $raw) ?? $raw;
                $content = trim($content);
                $content = Str::limit($content, $limit, '...');

                $resource['content'] = $content;
                $resource['synced_at'] = now()->toIso8601String();
                $updatedResources++;

                $this->info("OK {$projectName} / {$resourceLabel}");
            }
        }

        if ($updatedResources === 0) {
            $this->warn('No se actualizaron recursos.');
            return self::SUCCESS;
        }

        $data['updated_at'] = now()->toDateString();

        if ($dryRun) {
            $this->line("Dry run: {$updatedResources} recurso(s) actualizado(s).");
            return self::SUCCESS;
        }

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!$encoded) {
            $this->error('No se pudo serializar el dataset actualizado.');
            return self::FAILURE;
        }

        file_put_contents($datasetPath, $encoded . PHP_EOL);
        $this->info("Dataset actualizado en {$datasetPath}. Recursos sincronizados: {$updatedResources}");

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        if (Str::startsWith($path, ['/', '\\']) || preg_match('/^[A-Za-z]:[\/\\\\]/', $path)) {
            return $path;
        }

        return base_path($path);
    }

    private function extractDriveFileId(string $url): ?string
    {
        $patterns = [
            '/\/file\/d\/([a-zA-Z0-9_-]+)/',
            '/id=([a-zA-Z0-9_-]+)/',
            '/\/document\/d\/([a-zA-Z0-9_-]+)/',
            '/\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/',
            '/\/presentation\/d\/([a-zA-Z0-9_-]+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    private function buildExportUrl(string $sourceUrl, ?string $fileId): ?string
    {
        if (str_contains($sourceUrl, 'docs.google.com/document/d/') && $fileId) {
            return "https://docs.google.com/document/d/{$fileId}/export?format=txt";
        }

        if (str_contains($sourceUrl, 'docs.google.com/spreadsheets/d/') && $fileId) {
            return "https://docs.google.com/spreadsheets/d/{$fileId}/export?format=csv";
        }

        return null;
    }
}

