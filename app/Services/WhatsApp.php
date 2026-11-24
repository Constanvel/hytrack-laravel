<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends WhatsApp text messages through the Wangsaf gateway (docs.wangsaf.com). The API key
 * lives only in the server environment (WANGSAF_API_KEY); it also picks the sending device.
 */
class WhatsApp
{
    public function enabled(): bool
    {
        return $this->key() !== '';
    }

    /** @param  string  $phone  normalized number, e.g. "6281234567890" */
    public function send(string $phone, string $message): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            $response = Http::acceptJson()
                ->withHeader('Api-Key', $this->key())
                ->connectTimeout(3)
                ->timeout(10)
                ->post(rtrim((string) config('services.wangsaf.url'), '/').'/chat/send-text', [
                    'target' => $phone.'@s.whatsapp.net',
                    'message' => $message,
                ]);
        } catch (ConnectionException) {
            Log::warning('Wangsaf could not be reached.');

            return false;
        }

        // A wrong key comes back as HTTP 200 with status 401 in the body, so check both.
        if ($response->successful() && (int) $response->json('status') === 200) {
            return true;
        }

        // No phone number or message text in the log.
        Log::warning('Wangsaf did not send the message.', [
            'http' => $response->status(),
            'status' => $response->json('status'),
        ]);

        return false;
    }

    private function key(): string
    {
        return trim((string) config('services.wangsaf.key'));
    }
}
