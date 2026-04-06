<?php

namespace App\Notifications;

use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TicketCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected array $ticketData
    ) {
    }

    public function via(object $notifiable): array
    {
        return [WhatsAppChannel::class];
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $ticket = $this->ticketData;

        return WhatsAppMessage::create()
            ->line('🎫 New Ticket Created')
            ->line('')
            ->line("Ticket #{$ticket['ticket_number']}")
            ->line("Subject: {$ticket['subject']}")
            ->line("Priority: {$ticket['priority']}")
            ->line("Status: {$ticket['status']}")
            ->line('');
    }

    public function toArray(object $notifiable): array
    {
        return $this->ticketData;
    }
}
