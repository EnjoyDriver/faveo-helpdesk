<?php

namespace App\Observers;

use App\Model\helpdesk\Ticket\Tickets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors a helpdesk ticket's status onto the main platform's tickets row, so
 * the customer sees the same state in the driving app that an agent sees here.
 *
 * An observer rather than a listener on Faveo's 'change-status' event: that
 * event is fired at the end of close()/resolve()/open(), after a mail send that
 * returns early on failure, so a status change whose notification email bounced
 * would never reach the platform. Every status write goes through a model save,
 * so observing the model catches them all -- including bulk changes and code
 * paths that fire no event at all.
 */
class TicketStatusObserver
{
    /**
     * Faveo's ticket_status ids mapped onto the platform's own vocabulary
     * ('open', 'inprogress', 'finished', 'canceled').
     *
     * 2 Resolved and 3 Closed both mean the work is done, and the platform
     * draws no distinction between them. Faveo's remaining statuses -- 4
     * Archived, 5 Deleted, 6 Unverified, 7 Request Approval -- have no platform
     * equivalent and are deliberately absent: leaving the customer's view
     * unchanged is better than inventing a state for it.
     */
    protected const STATUS_MAP = [
        1 => 'open',
        2 => 'finished',
        3 => 'finished',
    ];

    /**
     * A ticket the customer cancelled in the app is final. Faveo has no
     * equivalent status, so without this a later change here would overwrite
     * 'canceled' and resurrect a ticket they deliberately closed.
     */
    protected const TERMINAL_PLATFORM_STATUSES = ['canceled'];

    public function updated(Tickets $ticket): void
    {
        // Only a status change matters here; every other save is ignored.
        if (! $ticket->wasChanged('status')) {
            return;
        }

        $status = self::STATUS_MAP[(int) $ticket->status] ?? null;

        if ($status === null) {
            return;
        }

        try {
            $platformTicket = DB::connection('main_platform')
                ->table('tickets')
                ->select('id', 'status')
                ->where('ticket_number', '=', $ticket->ticket_number)
                ->first();

            if (! $platformTicket) {
                return;
            }

            if (in_array($platformTicket->status, self::TERMINAL_PLATFORM_STATUSES, true)) {
                Log::info('platform status sync skipped: terminal state', [
                    'ticket_number'   => $ticket->ticket_number,
                    'platform_status' => $platformTicket->status,
                ]);

                return;
            }

            if ($platformTicket->status === $status) {
                return;
            }

            DB::connection('main_platform')
                ->table('tickets')
                ->where('id', '=', $platformTicket->id)
                ->update([
                    'status'     => $status,
                    'updated_at' => now(),
                ]);

            Log::info('platform status sync: updated', [
                'ticket_number' => $ticket->ticket_number,
                'from'          => $platformTicket->status,
                'to'            => $status,
            ]);
        } catch (\Exception $e) {
            /*
             * Never let a sync failure break a status change: it has already
             * been applied here, and this is a copy.
             */
            Log::error('platform status sync: FAILED', [
                'ticket_number' => $ticket->ticket_number ?? '?',
                'error'         => $e->getMessage(),
                'file'          => $e->getFile().':'.$e->getLine(),
            ]);
        }
    }
}
