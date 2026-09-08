<?php

declare(strict_types=1);

namespace Mapin\Extract\Markdown\Semantic\Drivers;

use Mapin\Extract\Markdown\Semantic\ConceptExtractionResult;
use Mapin\Extract\Markdown\Semantic\LlmClient;

/**
 * Ollama's `/api/generate` endpoint (https://github.com/ollama/ollama/blob/main/docs/api.md),
 * checked against a live, locally running v0.33.3 server while writing this - not the newer
 * OpenAI-compatible `/v1/chat/completions` path, since `/api/generate`'s `format: "json"` mode
 * constrains the response to valid JSON directly, which a small local model needs to reliably
 * follow the `{"concepts": [...], "relations": [[...]]}` shape this driver parses back. Uses
 * `curl` directly rather than adding an HTTP client dependency - a provider driver is optional,
 * pluggable surface (SPEC.md section 7: "their own namespace"), not core Mapin, so it should not
 * force a new Composer dependency onto every install just to exist.
 */
final class OllamaClient implements LlmClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function modelIdentifier(): string
    {
        return 'ollama:'.$this->model;
    }

    public function extractConcepts(string $sectionText): ConceptExtractionResult
    {
        $prompt = <<<PROMPT
            Extract the key domain concepts from this documentation section and how they relate to
            each other. Concepts are short noun phrases (2-4 words), lowercase, no punctuation.
            Respond with JSON only, exactly this shape, no other text:
            {"concepts": ["concept one", "concept two"], "relations": [["concept one", "concept two"]]}
            If there are no clear concepts, respond with {"concepts": [], "relations": []}.

            Section text:
            {$sectionText}
            PROMPT;

        $response = $this->request($prompt);

        $content = $response['response'] ?? null;
        if (! is_string($content)) {
            throw new \RuntimeException('Mapin: Ollama response had no "response" field.');
        }

        $parsed = json_decode($content, true);
        if (! is_array($parsed)) {
            return ConceptExtractionResult::empty();
        }

        $concepts = array_values(array_filter(array_map(
            static fn ($c) => is_string($c) ? trim($c) : null,
            is_array($parsed['concepts'] ?? null) ? $parsed['concepts'] : [],
        )));

        $relations = [];
        foreach (is_array($parsed['relations'] ?? null) ? $parsed['relations'] : [] as $pair) {
            if (is_array($pair) && count($pair) === 2 && is_string($pair[0] ?? null) && is_string($pair[1] ?? null)) {
                $relations[] = [trim($pair[0]), trim($pair[1])];
            }
        }

        return new ConceptExtractionResult($concepts, $relations);
    }

    /** @return array<string,mixed> */
    private function request(string $prompt): array
    {
        // No 'options' => ['temperature' => 0]: checked against a real running phi3:mini while
        // writing this, greedy decoding (temperature 0) combined with format: "json" made the
        // model loop emitting trailing whitespace after a syntactically complete answer instead of
        // stopping ("done": false, valid JSON followed by hundreds of extra newlines) - a real
        // generation degeneracy with this specific model/setting combination, not a parsing bug on
        // this driver's side. Ollama's own default sampling produced a clean, complete response
        // every time in the same check. Determinism is a nice-to-have here, not a promise this
        // layer makes elsewhere (SPEC.md's own reproducibility guarantees are about the
        // deterministic extractors, never about the optional LLM layer) - it is not worth trading
        // for a driver that hangs on valid input.
        $payload = json_encode([
            'model' => $this->model,
            'prompt' => $prompt,
            'format' => 'json',
            'stream' => false,
        ]);

        $ch = curl_init(rtrim($this->baseUrl, '/').'/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException("Mapin: Ollama request to {$this->baseUrl} failed: {$error}");
        }
        if ($status !== 200) {
            throw new \RuntimeException("Mapin: Ollama returned HTTP {$status}: {$raw}");
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
