<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Mapin\Analysis\CommunityDetector;
use Mapin\Store\SqliteStore;

final class CommunitiesCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:communities
        {--resolution=1.0 : Louvain resolution - higher finds more, smaller communities}
        {--json : Print the report as JSON}';

    /** @var string */
    protected $description = 'Detect communities (Louvain) and hub nodes over the stored graph';

    public function handle(): int
    {
        $storagePath = (string) config('mapin.storage');
        if (! file_exists($storagePath)) {
            $this->error('Mapin: no graph found. Run mapin:build first.');

            return 1;
        }

        $store = new SqliteStore($storagePath);
        $hubPercentile = (float) config('mapin.communities.hub_percentile', 0.99);
        $resolution = (float) $this->option('resolution');

        $report = (new CommunityDetector($resolution, $hubPercentile))->run($store)->toArray();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));

            return 0;
        }

        $this->info(sprintf(
            'Mapin communities: %d communities, %d nodes assigned, %d hubs excluded and reattached.',
            $report['community_count'],
            $report['node_count'],
            $report['hub_count'],
        ));

        return 0;
    }
}
