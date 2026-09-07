<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Mapin\Query\Query;
use Mapin\Store\SqliteStore;

final class UnresolvedCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:unresolved {--file=} {--member=} {--limit=50} {--json}';

    /** @var string */
    protected $description = 'List unresolved references, for improving code or the resolver';

    public function handle(): int
    {
        $storagePath = (string) config('mapin.storage');
        if (! file_exists($storagePath)) {
            $this->error('Mapin: no graph found. Run mapin:build first.');

            return 1;
        }

        $file = $this->option('file');
        $member = $this->option('member');
        $limit = (int) $this->option('limit');
        $query = new Query(new SqliteStore($storagePath), base_path());
        $result = $query->unresolved($file, $member, $limit);
        $envelope = $query->envelope('unresolved', ['file' => $file, 'member' => $member, 'limit' => $limit], $result);

        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT));

            return 0;
        }

        if ($result->result === []) {
            $this->line('No unresolved references.');

            return 0;
        }

        $this->table(['File', 'Line', 'Kind', 'Expression', 'Member'], array_map(
            static fn (array $r) => [$r['file'] ?? '(unknown)', $r['line'], $r['kind'], $r['expression'], $r['member'] ?? '(none)'],
            $result->result,
        ));

        return 0;
    }
}
