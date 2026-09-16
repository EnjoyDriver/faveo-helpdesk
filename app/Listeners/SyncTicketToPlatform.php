<?php

namespace App\Listeners;

use App\Model\helpdesk\Ticket\Tickets;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Copies a newly created helpdesk ticket into the main platform's tickets
 * table, so the driving app can list it alongside tickets raised in-app.
 *
 * Queued: the platform database is a separate server, and a ticket must still
 * be created here if that server is slow or unreachable.
 */
class SyncTicketToPlatform implements ShouldQueue
{
    /** Value written to main.tickets.status for a freshly created ticket. */
    protected const PLATFORM_STATUS_OPEN = 'open';

    public function handleTicketCreated(array|Tickets $data): void
    {
        $ticket = $data instanceof Tickets ? $data : ($data['ticket'] ?? null);

        if (! $ticket) {
            return;
        }

        try {
            $owner = \App\User::find($ticket->user_id);

            if (! $owner || ! $owner->email) {
                Log::warning('platform sync skipped: no email for ticket '.$ticket->ticket_number);

                return;
            }

            /*
             * user_id on main.tickets is NOT NULL with a foreign key to
             * main.users. Faveo's own user ids are unrelated to the platform's
             * (the same person is 175 here and 2 there), so the row must be
             * matched by email -- inserting Faveo's id would either violate
             * the constraint or silently attach the ticket to someone else.
             */
            $platformUser = DB::connection('main_platform')
                ->table('users')
                ->select('id')
                ->where('email', '=', $owner->email)
                ->first();

            if (! $platformUser) {
                Log::warning('platform sync skipped: no platform account for '.$owner->email);

                return;
            }

            /*
             * after.ticket.created fires more than once per ticket, and the
             * first fire happens before the opening thread has been written --
             * which previously produced a second platform row with an empty
             * subject and body. Wait for the content to exist.
             */
            $subject = $this->subjectFor($ticket);
            $body = $this->bodyFor($ticket);

            if ($subject === '' && $body === '') {
                Log::info('platform sync deferred: thread not written yet', [
                    'ticket_number' => $ticket->ticket_number,
                ]);

                return;
            }

            /*
             * Idempotency guard: the event firing twice must not create two
             * platform rows. ticket_number is the natural key here.
             */
            $existing = DB::connection('main_platform')
                ->table('tickets')
                ->where('ticket_number', '=', $ticket->ticket_number)
                ->first();

            if ($existing) {
                Log::info('platform sync skipped: already synced', [
                    'ticket_number'      => $ticket->ticket_number,
                    'platform_ticket_id' => $existing->id,
                ]);

                return;
            }

            $row = [
                'user_id'         => $platformUser->id,
                'ticket_number'   => $ticket->ticket_number,
                'subject'         => $subject,
                'text'            => $body,
                'status'          => self::PLATFORM_STATUS_OPEN,
                'seen'            => false,
                'login_as'        => $ticket->login_as ?? 'user',
                'topic'           => $this->topicFor($ticket),
                'created_at'      => $ticket->created_at,
                'updated_at'      => $ticket->updated_at,

                // Raised through the helpdesk, so not attached to a trip or
                // order. Both are nullable on main.tickets.
                'ticketable_id'   => null,
                'ticketable_type' => null,
            ];

            Log::info('platform sync: inserting ticket', [
                'ticket_number' => $ticket->ticket_number,
                'faveo_user_id' => $ticket->user_id,
                'email'         => $owner->email,
                'platform_user' => $platformUser->id,
                'row'           => $row,
            ]);

            $started = microtime(true);

            $platformId = DB::connection('main_platform')
                ->table('tickets')
                ->insertGetId($row);

            Log::info('platform sync: inserted', [
                'ticket_number' => $ticket->ticket_number,
                'platform_ticket_id' => $platformId,
                'ms' => round((microtime(true) - $started) * 1000),
            ]);
        } catch (\Exception $e) {
            /*
             * Never let a sync failure break ticket creation: the ticket
             * already exists here, and this is a copy.
             */
            Log::error('platform sync: FAILED', [
                'ticket_number' => $ticket->ticket_number ?? '?',
                'error'         => $e->getMessage(),
                'class'         => get_class($e),
                'file'          => $e->getFile().':'.$e->getLine(),
            ]);
        }
    }

    /** First thread of a ticket carries its title and body. */
    protected function subjectFor(Tickets $ticket): string
    {
        return (string) optional($this->firstThread($ticket))->title;
    }

    protected function bodyFor(Tickets $ticket): string
    {
        return (string) optional($this->firstThread($ticket))->body;
    }

    protected function topicFor(Tickets $ticket): ?string
    {
        $topic = \App\Model\helpdesk\Manage\Help_topic::find($ticket->help_topic_id);

        return $topic ? $topic->topic : null;
    }

    protected function firstThread(Tickets $ticket)
    {
        return \App\Model\helpdesk\Ticket\Ticket_Thread::where('ticket_id', $ticket->id)
            ->orderBy('id')
            ->first();
    }
}
