<?php

declare(strict_types=1);

namespace Mapin\Extract\Markdown\Semantic;

use Mapin\Extract\Markdown\Semantic\Drivers\OllamaClient;

/**
 * Resolves config('mapin.llm') into a real LlmClient. Not container-bound like Query/SqliteStore -
 * MapinServiceProvider only binds those because laravel/mcp's ToolInvoker needs the container to
 * auto-wire tool classes; nothing here does. DocsCommand constructs this the same direct way it
 * already constructs Query and SqliteStore.
 */
final class LlmClientFactory
{
    /** @param array<string,mixed> $config */
    public static function fromConfig(array $config): LlmClient
    {
        $driver = $config['driver'] ?? 'null';

        return match ($driver) {
            'ollama' => new OllamaClient(
                (string) ($config['ollama']['base_url'] ?? 'http://127.0.0.1:11434'),
                (string) ($config['ollama']['model'] ?? 'phi3:mini'),
                (int) ($config['ollama']['timeout'] ?? 30),
            ),
            default => new NullClient,
        };
    }
}
