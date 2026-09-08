<?php

declare(strict_types=1);

namespace Mapin\Tests\Support;

use Mapin\Extract\Markdown\Semantic\ConceptExtractionResult;
use Mapin\Extract\Markdown\Semantic\LlmClient;

/**
 * In-process test double, never touches a network - Phase6Test drives LlmConceptExtractor's own
 * logic (caching, edge creation, resilience to a failing call) against canned, deterministic
 * responses. A real Ollama server is verified separately by hand (SPEC.md's own established
 * pattern: fixture-driven automated tests, a real backend checked once outside the suite), never
 * as part of the automated suite - a CI run or another machine has no reason to expect Ollama
 * running locally.
 */
final class FakeLlmClient implements LlmClient
{
    private int $calls = 0;

    /**
     * @param  array<string, ConceptExtractionResult>  $responses  keyed by exact section text, '*' as a catch-all default
     * @param  \Closure(string): void|null  $onCall  invoked with the section text on every call - use to assert what was sent, or to throw and exercise the failure path
     */
    public function __construct(
        private readonly array $responses = [],
        private readonly ?\Closure $onCall = null,
        private readonly string $model = 'fake:test-model',
    ) {}

    public function extractConcepts(string $sectionText): ConceptExtractionResult
    {
        $this->calls++;
        if ($this->onCall !== null) {
            ($this->onCall)($sectionText);
        }

        return $this->responses[$sectionText] ?? $this->responses['*'] ?? ConceptExtractionResult::empty();
    }

    public function modelIdentifier(): string
    {
        return $this->model;
    }

    public function callCount(): int
    {
        return $this->calls;
    }
}
