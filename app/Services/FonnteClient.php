<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Fonnte WhatsApp gateway. The sending device/number is
 * determined by the account token, so only the target and message are sent.
 */
class FonnteClient
{
    public function send(string $target, string $message): bool
    {
        $token = config('services.fonnte.token');

        if (blank($token)) {
            Log::warning('FonnteClient: FONNTE_TOKEN is not set — WhatsApp message not sent.');

            return false;
        }

        try {
            $response = Http::withHeaders(['Authorization' => $token])
                ->asForm()
                ->timeout(15)
                ->post(config('services.fonnte.url'), [
                    'target' => $target,
                    'message' => $message,
                ]);
        } catch (\Throwable $e) {
            Log::warning('FonnteClient: request failed', ['target' => $target, 'error' => $e->getMessage()]);

            return false;
        }

        $ok = $response->successful() && $response->json('status') === true;

        if (! $ok) {
            Log::warning('FonnteClient: gateway rejected the message', [
                'target' => $target,
                'status_code' => $response->status(),
                'body' => $response->json(),
            ]);
        }

        return $ok;
    }
}
