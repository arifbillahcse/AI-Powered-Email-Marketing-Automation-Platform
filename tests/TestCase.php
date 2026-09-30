<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Panel themes are compiled by Vite; tests shouldn't need a build.
        $this->withoutVite();
    }
}
