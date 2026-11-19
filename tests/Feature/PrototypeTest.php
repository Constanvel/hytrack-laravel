<?php

namespace Tests\Feature;

use Tests\TestCase;

class PrototypeTest extends TestCase
{
    /** The backend is kept: guests go to the real sign-in; the prototype stays at /prototipe. */
    public function test_home_signs_in_and_the_prototype_stays_available(): void
    {
        $this->get('/')->assertRedirect('/masuk');
        $this->get('/prototipe/masuk')->assertOk();
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
