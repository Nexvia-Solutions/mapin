<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Storage path
    |--------------------------------------------------------------------------
    |
    | Where the SQLite graph lives. Mapin never writes anywhere else in the host
    | project - see SPEC.md section 13 ("never write outside storage/").
    |
    */
    'storage' => env('MAPIN_STORAGE', storage_path('mapin/graph.sqlite')),

    /*
    |--------------------------------------------------------------------------
    | Project paths
    |--------------------------------------------------------------------------
    |
    | Paths, relative to the application base path, that make up "your code" -
    | these get full Node entries (classes, methods, functions) and are what
    | queries like impact/callers report against.
    |
    */
    'paths' => ['app', 'routes', 'config', 'resources/views', 'docs'],

    /*
    |--------------------------------------------------------------------------
    | Vendor paths indexed for type resolution only
    |--------------------------------------------------------------------------
    |
    | Indexed so calls into the framework (Eloquent, facades, Carbon) resolve
    | correctly, but never treated as "your code": no per-method nodes, never
    | reported by find/callers/impact. Leave null to auto-detect the framework
    | source under vendor/ at build time (see BuildCommand::vendorPaths()).
    |
    */
    'vendor_paths' => null,

    /*
    |--------------------------------------------------------------------------
    | Excluded paths
    |--------------------------------------------------------------------------
    |
    | Glob patterns, matched against the path relative to the project root, for
    | files to skip even if they fall under one of the paths above.
    |
    */
    'exclude' => [],

    /*
    |--------------------------------------------------------------------------
    | Heuristic resolution
    |--------------------------------------------------------------------------
    |
    | Off by default per SPEC.md section 4.1: a method name defined by exactly
    | one class in the project is not, on its own, strong enough evidence to
    | link a call to it with confidence.
    |
    */
    'heuristics' => false,

    /*
    |--------------------------------------------------------------------------
    | Semantic layer (phase 6, opt-in via --with-llm)
    |--------------------------------------------------------------------------
    |
    | Never consulted unless `mapin:docs --with-llm` is run - see SPEC.md section
    | 13's "never send code to an LLM without an explicit flag" rule. 'driver' is
    | 'null' (the default: no network calls, ever) or 'ollama'.
    |
    */
    'llm' => [
        'driver' => env('MAPIN_LLM_DRIVER', 'null'),

        'ollama' => [
            'base_url' => env('MAPIN_OLLAMA_URL', 'http://127.0.0.1:11434'),
            'model' => env('MAPIN_OLLAMA_MODEL', 'phi3:mini'),
            'timeout' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis (phase 5, mapin:communities)
    |--------------------------------------------------------------------------
    |
    | 'hub_percentile': nodes at or above this degree percentile are excluded
    | from Louvain and reattached afterward to their strongest neighbour's
    | community - SPEC.md section 16's own open question, closed here at the
    | proposed default (top 1%) rather than left unset.
    |
    */
    'communities' => [
        'hub_percentile' => 0.99,
    ],

];
