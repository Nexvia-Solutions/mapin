<?php

declare(strict_types=1);

namespace FakeVendor;

class RedirectResponse
{
    /**
     * @return RedirectResponse
     */
    public function withInput()
    {
        return $this;
    }
}
