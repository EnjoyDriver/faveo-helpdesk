<?php

namespace App\Notifications\Channels;

use App\Notifications\Messages\WhatsAppMessage;
use App\Services\WhatsAppService;
use Illuminate\Notifications\Notification;

class WhatsAppChannel
{
    public function __construct(
        protected WhatsAppService $whatsAppService
    ) {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        $message = $notification->toWhatsApp($notifiable);

        if (is_string($message)) {
            $message = new WhatsAppMessage($message);
        }

        $phone = $notifiable->mobile
            ? $this->formatPhone($notifiable->mobile, $notifiable->country_code)
            : $notifiable->routeNotificationForWhatsApp();

        if (! $phone) {
            return;
        }

        $this->whatsAppService->send(
            $phone,
            $message->content,
            $message->deviceId
        );
    }

    protected function formatPhone(string $mobile, ?string $countryCode = null): string
    {
        $phone = preg_replace('/[^0-9]/', '', $mobile);

        if ($countryCode && ! str_starts_with($phone, $countryCode)) {
            $countryCode = preg_replace('/[^0-9]/', '', $countryCode);
            if (! str_starts_with($phone, $countryCode)) {
                $phone = $countryCode.$phone;
            }
        }

        return $phone;
    }
}
