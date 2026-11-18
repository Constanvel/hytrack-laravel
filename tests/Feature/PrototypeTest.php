<?php

namespace Tests\Feature;

use Tests\TestCase;

class PrototypeTest extends TestCase
{
    public function test_home_opens_the_prototype(): void
    {
        $this->get('/')->assertRedirect('/prototipe/masuk');
    }

    public function test_prototype_is_served_without_session_or_database(): void
    {
        config(['database.default' => 'unreachable']); // any query would throw

        $this->get('/prototipe/kunjungan')
            ->assertOk()
            ->assertSee('Mode prototype')
            ->assertCookieMissing(config('session.cookie'));
    }
}
