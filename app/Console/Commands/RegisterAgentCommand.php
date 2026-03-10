<?php

namespace App\Console\Commands;

use App\Models\AIAgent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RegisterAgentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:register
        {name : Nombre visible del agente}
        {email : Email del usuario agente}
        {type=municipal : Tipo de agente [municipal|finance|real_estate|intelligent]}
        {--key= : Clave unica del agente}
        {--dataset= : Ruta del dataset JSON (municipal)}
        {--password= : Password inicial del usuario}
        {--enabled=1 : 1 habilitado, 0 deshabilitado}
        {--force-password : Sobrescribe password de usuario existente}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or update an AI agent linked to a Chatify user';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!Schema::hasTable('ai_agents')) {
            $this->error('La tabla ai_agents no existe. Ejecuta primero: php artisan migrate');
            return self::FAILURE;
        }

        $name = trim((string) $this->argument('name'));
        $email = trim((string) $this->argument('email'));
        $type = trim((string) $this->argument('type'));
        $enabled = (bool) ((int) $this->option('enabled'));

        $allowedTypes = ['municipal', 'finance', 'real_estate', 'intelligent'];

        if (!in_array($type, $allowedTypes, true)) {
            $this->error('Tipo invalido. Usa: municipal, finance, real_estate, intelligent');
            return self::FAILURE;
        }

        $agentKey = trim((string) ($this->option('key') ?: Str::slug($name, '_')));

        if ($agentKey === '') {
            $agentKey = 'agent_' . Str::lower(Str::random(8));
        }

        $datasetPath = $this->resolveDatasetPath(
            type: $type,
            datasetOption: $this->option('dataset')
        );

        if ($type === 'municipal' && (!is_file($datasetPath) || !is_readable($datasetPath))) {
            $this->error("No se encontro dataset municipal en: {$datasetPath}");
            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        $isNewUser = !$user;

        if (!$user) {
            $user = new User();
            $user->email = $email;
        }

        $user->name = $name;

        $passwordOption = $this->option('password');
        $mustSetPassword = $isNewUser || $this->option('force-password') || $passwordOption;

        if ($mustSetPassword) {
            $rawPassword = $passwordOption ?: Str::random(16);
            $user->password = Hash::make($rawPassword);

            if ($isNewUser || $passwordOption) {
                $this->line("Password aplicado para {$email}: {$rawPassword}");
            } else {
                $this->line("Password regenerado para {$email}");
            }
        }

        $user->save();

        if (AIAgent::query()->where('agent_key', $agentKey)->where('user_id', '!=', $user->id)->exists()) {
            $this->error("La clave de agente '{$agentKey}' ya esta en uso por otro usuario.");
            return self::FAILURE;
        }

        $settings = [];
        if ($type === 'municipal') {
            $settings = [
                'max_records_context' => (int) config('agents.municipal.max_records_context', 4),
                'max_sources_context' => (int) config('agents.municipal.max_sources_context', 6),
                'max_requirements_per_record' => (int) config('agents.municipal.max_requirements_per_record', 8),
            ];
        }

        $agent = AIAgent::query()->firstOrNew(['user_id' => $user->id]);
        $agent->agent_key = $agentKey;
        $agent->agent_type = $type;
        $agent->enabled = $enabled;
        $agent->dataset_path = $type === 'municipal'
            ? $this->toProjectRelativePath($datasetPath)
            : null;
        $agent->settings = $settings;
        $agent->save();

        $this->info('Agente registrado correctamente.');
        $this->table(
            ['user_id', 'name', 'email', 'agent_key', 'agent_type', 'enabled', 'dataset_path'],
            [[
                $user->id,
                $user->name,
                $user->email,
                $agent->agent_key,
                $agent->agent_type,
                $agent->enabled ? '1' : '0',
                $agent->dataset_path ?: '-',
            ]]
        );

        return self::SUCCESS;
    }

    private function resolveDatasetPath(string $type, ?string $datasetOption): ?string
    {
        if ($type !== 'municipal') {
            return null;
        }

        $rawPath = $datasetOption ?: config('agents.municipal.default_dataset_path', 'storage/progreso.json');

        if ($rawPath === null || $rawPath === '') {
            return null;
        }

        if ($this->isAbsolutePath($rawPath)) {
            return $rawPath;
        }

        return base_path($rawPath);
    }

    private function toProjectRelativePath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $basePath = base_path();
        $normalizedBase = str_replace('\\', '/', $basePath);
        $normalizedPath = str_replace('\\', '/', $path);

        if (Str::startsWith($normalizedPath, $normalizedBase . '/')) {
            return ltrim(Str::after($normalizedPath, $normalizedBase), '/');
        }

        return $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        return Str::startsWith($path, ['/', '\\']) || (bool) preg_match('/^[A-Za-z]:[\/\\\\]/', $path);
    }
}

