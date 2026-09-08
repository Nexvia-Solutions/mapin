<?php

declare(strict_types=1);

namespace App;

use FakeVendor\Redirector;

class RedirectController
{
    public function test()
    {
        return (new Redirector)->back()->withInput();
    }
}
