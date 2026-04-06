<?php

namespace App\Listeners;

use App\Events\FaveoAfterReply;
use App\Model\helpdesk\Ticket\Tickets;
use App\Notifications\Messages\WhatsAppMessage;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendWhatsAppNotifications implements ShouldQueue
{
    public function __construct(
        protected WhatsAppService $whatsAppService
    ) {
    }

    public function handleTicketCreated(array|Tickets $data): void
    {
        $ticket = $data instanceof Tickets ? $data : $data['ticket'] ?? null;
        if (! $ticket) {
            return;
        }

        $user = $this->getTicketOwner($ticket);
        if (! $user) {
            return;
        }

        $phone = $user->routeNotificationForWhatsApp();
        if (! $phone) {
            return;
        }

        $message = $this->buildTicketCreatedMessage($ticket, $user);
        $this->whatsAppService->send($phone, $message);
    }

    public function handleTicketReply(FaveoAfterReply $event): void
    {
        $thread = $event->para1 ?? null;
        $ticket = $event->para2 ?? null;
        $user = $event->para3 ?? null;

        if (! $ticket || ! $user) {
            return;
        }

        $phone = $user->routeNotificationForWhatsApp();
        if (! $phone) {
            return;
        }

        $message = $this->buildTicketRepliedMessage($ticket, $thread);
        $this->whatsAppService->send($phone, $message);
    }

    protected function buildTicketCreatedMessage($ticket, $user): string
    {
        return WhatsAppMessage::create()
            ->line('🎫 New Ticket Created')
            ->line('')
            ->line("Ticket #{$ticket->ticket_number}")
            ->line("Subject: {$ticket->subject}")
            ->line("Priority: {$this->getPriorityLabel($ticket->priority)}")
            ->line("Status: {$this->getStatusLabel($ticket->status)}")
            ->line('')
            ->content;
    }

    protected function buildTicketRepliedMessage($ticket, $thread): string
    {
        return WhatsAppMessage::create()
            ->line("💬 New Reply on Ticket #{$ticket->ticket_number}")
            ->line('')
            ->line("From: {$this->getReplierName($thread)}")
            ->line('Message: '.substr($this->getThreadBody($thread), 0, 100).(strlen($this->getThreadBody($thread)) > 100 ? '...' : ''))
            ->line('')
            ->action('View Ticket', $this->getTicketUrl($ticket))
            ->content;
    }

    protected function getTicketOwner($ticket)
    {
        if (method_exists($ticket, 'user') && $ticket->user) {
            return $ticket->user;
        }
        if (property_exists($ticket, 'user_id') && $ticket->user_id) {
            return \App\User::find($ticket->user_id);
        }

        return null;
    }

    protected function getPriorityLabel($priority): string
    {
        $labels = [
            1 => 'Low',
            2 => 'Medium',
            3 => 'High',
            4 => 'Urgent',
        ];

        return $labels[$priority] ?? 'Normal';
    }

    protected function getStatusLabel($status): string
    {
        $labels = [
            1 => 'Open',
            2 => 'Pending',
            3 => 'Resolved',
            4 => 'Closed',
        ];

        return $labels[$status] ?? 'Open';
    }

    protected function getThreadBody($thread): string
    {
        if (is_object($thread) && property_exists($thread, 'body')) {
            return strip_tags($thread->body);
        }

        return '';
    }

    protected function getReplierName($thread): string
    {
        if (is_object($thread) && method_exists($thread, 'user')) {
            return $thread->user->name() ?? 'Agent';
        }

        return 'Agent';
    }

    protected function getTicketUrl($ticket): string
    {
        if (is_object($ticket) && property_exists($ticket, 'id')) {
            return url("/ticket/{$ticket->id}");
        }

        return url('/ticket');
    }
}
