<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiGuide
{
    /** Accept only a fixed HyTrack help text, without any question or visit data. */
    public function rewrite(string $guide): string
    {
        $key = trim((string) config('services.gemini.key'));
        $model = (string) config('services.gemini.model');

        if ($key === '' || ! preg_match('/^gemini-[a-z0-9.-]+$/D', $model)) {
            return $guide;
        }

        try {
            $response = Http::acceptJson()
                ->withHeader('x-goog-api-key', $key)
                ->connectTimeout(3)
                ->timeout(8)
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                    'systemInstruction' => ['parts' => [[
                        'text' => 'Susun ulang panduan HyTrack dalam bahasa Indonesia yang mudah dibaca. Pertahankan setiap langkah. Jangan tambahkan klaim, langkah, lokasi, waktu, saran medis, atau informasi yang tidak diberikan. Jawab langsung dalam maksimal empat kalimat.',
                    ]]],
                    'contents' => [['parts' => [['text' => $guide]]]],
                    'generationConfig' => ['maxOutputTokens' => 512],
                ]);
        } catch (ConnectionException) {
            Log::warning('Hya could not connect to Gemini.');

            return $guide;
        }

        if (! $response->successful()) {
            Log::warning('Gemini request for Hya failed.', ['status' => $response->status()]);

            return $guide;
        }

        if ($response->json('candidates.0.finishReason') !== 'STOP') {
            return $guide;
        }

        $parts = $response->json('candidates.0.content.parts');
        $reply = collect(is_array($parts) ? $parts : [])
            ->filter(fn ($part) => is_array($part) && empty($part['thought']) && is_string($part['text'] ?? null))
            ->pluck('text')->implode(' ');
        $reply = trim($reply);

        return $reply !== '' && mb_strlen($reply) <= 750 ? $reply : $guide;
    }
}
