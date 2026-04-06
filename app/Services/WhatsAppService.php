<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsAppService
{
    public function send(string $phone, string $message, ?string $deviceId = null): bool
    {
        $baseUrl = rtrim(config('whatsapp.base_url'), '/');
        $deviceId = $deviceId ?: config('whatsapp.device_id');

        try {
            $response = Http::timeout(10)
                ->post("{$baseUrl}/whatsapp/send", [
                    'phone' => $phone,
                    'message' => $message,
                    'deviceId' => $deviceId,
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            \Log::error('WhatsApp send failed: ' . $e->getMessage());
            return false;
        }
    }
}