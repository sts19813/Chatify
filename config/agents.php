<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Legacy Agent Map
    |--------------------------------------------------------------------------
    | Backward compatibility for hardcoded user IDs previously used in the
    | chat controller. New agents should be registered in the `ai_agents` table.
    */
    'legacy_user_map' => [
        2 => [
            'agent_key' => 'finance_bot',
            'agent_type' => 'finance',
            'enabled' => true,
            'dataset_path' => null,
            'settings' => [],
        ],
        6 => [
            'agent_key' => 'real_estate_agent',
            'agent_type' => 'real_estate',
            'enabled' => true,
            'dataset_path' => null,
            'settings' => [],
        ],
        7 => [
            'agent_key' => 'intelligent_agent',
            'agent_type' => 'intelligent',
            'enabled' => true,
            'dataset_path' => null,
            'settings' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Municipal Agent Defaults
    |--------------------------------------------------------------------------
    */
    'municipal' => [
        'default_dataset_path' => 'storage/progreso.json',
        'max_records_context' => 4,
        'max_sources_context' => 6,
        'max_requirements_per_record' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Real Estate Agent Defaults
    |--------------------------------------------------------------------------
    */
    'real_estate' => [
        'default_dataset_path' => 'storage/kiro_real_estate.json',
        'max_projects_context' => 3,
        'max_resources_context' => 8,
        'max_features_context' => 10,
    ],
];
