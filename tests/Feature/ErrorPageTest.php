<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    public function test_unknown_routes_render_the_hytrack_error_page(): void
    {
        $this->get('/halaman-yang-tidak-ada')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('ErrorPage')
                ->where('status', 404));
    }
}
