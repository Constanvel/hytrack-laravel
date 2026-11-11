<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pages are registered in resources/js/pages.ts, not as one file per component.
        config(['inertia.testing.ensure_pages_exist' => false]);
        $this->withoutVite();
    }
}
