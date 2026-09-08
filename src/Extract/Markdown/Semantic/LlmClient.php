<?php

declare(strict_types=1);

namespace Mapin\Extract\Markdown\Semantic;

/**
 * SPEC.md section 7's semantic layer: one call per changed Markdown section, sending only that
 * section's own prose (never PHP source - see section 13's "never send code to an LLM without an
 * explicit flag" rule, enforced structurally by LlmConceptExtractor only ever reading `.md` files
 * in the first place, not just by convention here). A provider driver lives in its own namespace
 * under Semantic/Drivers and implements this against that provider's current API reference at the
 * time of coding, per SPEC.md section 7.
 */
interface LlmClient
{
    public function extractConcepts(string $sectionText): ConceptExtractionResult;

    /** Stamped onto every `mentions`/`relates_concept` edge this client produces, so a caller can tell which model answered - SPEC.md section 7: "stored with source: llm and a model identifier". */
    public function modelIdentifier(): string;
}
