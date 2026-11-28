<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    private ?string $sentCode = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Pages are registered in resources/js/pages.ts, not as one file per component.
        config(['inertia.testing.ensure_pages_exist' => false]);
        $this->withoutVite();
        Event::listen(MessageLogged::class, function (MessageLogged $log) {
            if ($log->message === 'Kode OTP pendaftaran') {
                $this->sentCode = $log->context['code'];
            }
        });
    }

    /** The OTP sent by the last sign-up step (outside demo mode it only reaches the log). */
    protected function sentCode(): string
    {
        return $this->sentCode ?? $this->fail('No sign-up code was sent.');
    }

    /** Sign-up step 1 then step 2 with the code that was sent: what a person does. */
    protected function signUp(array $form): TestResponse
    {
        $this->post('/daftar', $form)->assertRedirect('/daftar/verifikasi');

        return $this->post('/daftar/verifikasi', ['code' => $this->sentCode()]);
    }
}
