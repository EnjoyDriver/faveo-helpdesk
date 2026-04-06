<?php

namespace App\Notifications;

use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TicketRepliedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected array $replyData
    ) {
    }

    public function via(object $notifiable): array
    {
        return [WhatsAppChannel::class];
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $reply = $this->replyData;

        return WhatsAppMessage::create()
            ->line("💬 New Reply on Ticket #{$reply['ticket_number']}")
            ->line('')
            ->line("From: {$reply['replier_name']}")
            ->line('Message: '.substr($reply['message'], 0, 100).(strlen($reply['message']) > 100 ? '...' : ''))
            ->line('')
            ->action('View Ticket', $reply['ticket_url']);
    }

    public function toArray(object $notifiable): array
    {
        return $this->replyData;
    }
}
