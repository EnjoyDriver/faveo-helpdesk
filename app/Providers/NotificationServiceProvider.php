<?php

namespace App\Providers;

use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WhatsAppChannel::class, function ($app) {
            return new WhatsAppChannel(
                $app->make(\App\Services\WhatsAppService::class)
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
