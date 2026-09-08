<?php

declare(strict_types=1);

namespace Mapin\Extract\Markdown\Semantic;

/**
 * config('mapin.llm.driver') default (SPEC.md section 7). Never makes a network call, always
 * reports zero concepts - the safe fallback if a host app forgets to configure a real driver
 * before passing --with-llm, and the belt to LlmConceptExtractor only ever running with that flag
 * being the suspenders: two independent reasons nothing is ever sent anywhere without both.
 */
final class NullClient implements LlmClient
{
    public function extractConcepts(string $sectionText): ConceptExtractionResult
    {
        return ConceptExtractionResult::empty();
    }

    public function modelIdentifier(): string
    {
        return 'null';
    }
}
