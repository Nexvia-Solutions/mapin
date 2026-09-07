<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Mapin\Query\GitStatus;
use Mapin\Query\Query;
use Mapin\Store\SqliteStore;

final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:doctor {--json}';

    /** @var string */
    protected $description = 'Check dependencies, boot capability, graph staleness and the unresolved ratio';

    public function handle(): int
    {
        $checks = [
            'php_version' => ['ok' => version_compare(PHP_VERSION, '8.2.0', '>='), 'detail' => PHP_VERSION],
            'ext_pdo_sqlite' => ['ok' => extension_loaded('pdo_sqlite'), 'detail' => extension_loaded('pdo_sqlite') ? 'loaded' : 'missing'],
            'router_resolves' => ['ok' => $this->laravel->bound('router'), 'detail' => $this->laravel->bound('router') ? 'bound' : 'not bound'],
        ];

        $storagePath = (string) config('mapin.storage');
        $checks['graph_exists'] = ['ok' => file_exists($storagePath), 'detail' => $storagePath];

        if (file_exists($storagePath)) {
            $query = new Query(new SqliteStore($storagePath), base_path(), new GitStatus);
            $result = $query->stats();
            /** @var array{counts: array{files: int, nodes: int, edges: int, unresolved: int}, last_build: array<string,mixed>|null} $stats */
            $stats = $result->result;
            $counts = $stats['counts'];
            $lastBuild = $stats['last_build'];
            $total = $counts['edges'] + $counts['unresolved'];
            $unresolvedRatio = $total > 0 ? round($counts['unresolved'] / $total, 3) : 0.0;

            $checks['unresolved_ratio'] = ['ok' => $unresolvedRatio < 0.5, 'detail' => $unresolvedRatio];

            $envelope = $query->envelope('stats', [], $result);
            $graph = $envelope['graph'];
            $stale = $graph['built_commit'] !== null
                && $graph['head_commit'] !== null
                && $graph['built_commit'] !== $graph['head_commit'];
            $checks['graph_staleness'] = [
                'ok' => ! $stale,
                'detail' => $stale
                    ? "built at {$graph['built_commit']}, HEAD is {$graph['head_commit']} ({$graph['files_changed_since_build']} files changed)"
                    : 'current',
            ];
            $checks['last_build'] = ['ok' => $lastBuild !== null, 'detail' => $lastBuild['mode'] ?? 'never built'];
        }

        $allOk = ! in_array(false, array_column($checks, 'ok'), true);

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => $allOk, 'checks' => $checks], JSON_PRETTY_PRINT));

            return $allOk ? 0 : 1;
        }

        foreach ($checks as $name => $check) {
            $icon = $check['ok'] ? '<info>✓</info>' : '<error>✗</error>';
            $this->line("{$icon} {$name}: {$check['detail']}");
        }

        return $allOk ? 0 : 1;
    }
}
