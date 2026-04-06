<?php

namespace App\Jobs;

use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWhatsAppNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $phone;
    public string $message;
    public ?string $deviceId;

    public function __construct(string $phone, string $message, ?string $deviceId = null)
    {
        $this->phone = $phone;
        $this->message = $message;
        $this->deviceId ??= $deviceId ?? config('whatsapp.device_id','enjoydriving');
    }

    public function handle(WhatsAppService $whatsAppService): void
    {
        $whatsAppService->send($this->phone, $this->message, $this->deviceId);
    }
}