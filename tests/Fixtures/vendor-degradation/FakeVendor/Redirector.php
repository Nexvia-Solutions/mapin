<?php

declare(strict_types=1);

namespace FakeVendor;

/**
 * Stands in for a real vendor class like Illuminate\Routing\Redirector: back() has no native PHP
 * return type in its signature, only a docblock @return - the exact shape that hid the bug this
 * fixture regression-tests (SPEC.md section 1.17).
 */
class Redirector
{
    /**
     * @return RedirectResponse
     */
    public function back()
    {
        return new RedirectResponse;
    }
}
