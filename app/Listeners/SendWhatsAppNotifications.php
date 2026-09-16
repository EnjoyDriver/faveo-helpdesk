<?php

namespace App\Listeners;

use App\Events\FaveoAfterReply;
use App\Model\helpdesk\Ticket\Ticket_Thread;
use App\Model\helpdesk\Ticket\Tickets;
use App\Notifications\Messages\WhatsAppMessage;
use App\Services\WhatsAppService;

/**
 * Deliberately NOT ShouldQueue: queueing a listener makes Laravel serialize the
 * event before running it, even on the sync driver, and FaveoAfterReply carries
 * the live Request object -- which holds closures and throws "Serialization of
 * 'Closure' is not allowed". That breaks the whole listener chain for a reply,
 * including the platform sync, and no try/catch inside a handler can catch it.
 */
class SendWhatsAppNotifications
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
        /*
         * The two places that fire this event disagree on the argument order:
         * the agent controller passes (content, mobile, country_code, request,
         * ticket, thread) while the v1 API passes (content, phone, request,
         * ticket). Reading fixed positions picked up country_code as the user
         * on the agent path and fataled on it, which also stopped every
         * listener registered after this one -- so resolve each by type.
         */
        try {
            $ticket = $this->firstOfType($event, Tickets::class);
            $user = $ticket ? $this->getTicketOwner($ticket) : null;

            if (! $ticket || ! $user) {
                return;
            }

            $thread = $this->firstOfType($event, Ticket_Thread::class) ?? $event->para1;

            $phone = $user->routeNotificationForWhatsApp();
            if (! $phone) {
                return;
            }

            $message = $this->buildTicketRepliedMessage($ticket, $thread);
            $this->whatsAppService->send($phone, $message);
        } catch (\Exception $e) {
            /*
             * A notification is not worth losing a reply over: listeners run in
             * a chain, so anything thrown here would stop the ones after it.
             */
            \Log::error('whatsapp reply notification failed: '.$e->getMessage());
        }
    }

    /** Resolve an event argument by type rather than by position. */
    protected function firstOfType(FaveoAfterReply $event, string $class)
    {
        foreach ([$event->para1, $event->para2, $event->para3, $event->para4, $event->para5, $event->para6] as $para) {
            if ($para instanceof $class) {
                return $para;
            }
        }

        return null;
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
