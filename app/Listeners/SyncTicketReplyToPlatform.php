<?php

namespace App\Listeners;

use App\Events\FaveoAfterReply;
use App\Model\helpdesk\Ticket\Ticket_Thread;
use App\Model\helpdesk\Ticket\Tickets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Copies a reply on a helpdesk ticket into the main platform's ticket_replies
 * table, so the customer sees the conversation in the driving app.
 *
 * Deliberately NOT ShouldQueue, unlike SyncTicketToPlatform. Queueing a
 * listener makes Laravel serialize the event first, even on the sync driver,
 * and FaveoAfterReply carries the live Request object -- which holds closures
 * and throws "Serialization of 'Closure' is not allowed" before the listener
 * body ever runs. That failure happens in the queue layer, so the try/catch
 * below cannot contain it and the reply silently never syncs.
 *
 * Running inline is safe here: the work is a single insert on an already-open
 * connection, and every failure is caught and logged rather than surfaced.
 */
class SyncTicketReplyToPlatform
{
    /** Mirrors main.ticket_replies.author. */
    protected const AUTHOR_CUSTOMER = 'customer';

    protected const AUTHOR_SUPPORT = 'support';

    public function handleTicketReply(FaveoAfterReply $event): void
    {
        /*
         * FaveoAfterReply carries positional parameters, and the two places
         * that fire it disagree on the order: the agent controller passes
         * (content, mobile, country_code, request, ticket, thread) while the v1
         * API passes (content, phone, request, ticket). Reading para4 or para5
         * would work for one caller and silently pick up the wrong object for
         * the other, so each argument is resolved by type instead.
         */
        $ticket = $this->firstOfType($event, Tickets::class);
        $thread = $this->firstOfType($event, Ticket_Thread::class);

        if (! $ticket) {
            return;
        }

        try {
            /*
             * The API path fires the event without the thread, so fall back to
             * the newest one on the ticket -- the reply that was just saved.
             */
            $thread ??= Ticket_Thread::where('ticket_id', $ticket->id)
                ->orderByDesc('id')
                ->first();

            if (! $thread || ! $thread->id) {
                Log::warning('platform reply sync skipped: no thread', [
                    'ticket_number' => $ticket->ticket_number,
                ]);

                return;
            }

            /*
             * Internal notes are agent-only: assignment records, private
             * comments. Faveo writes one of these for every assignment, and
             * showing "This Ticket have been assigned to ..." to the customer
             * would leak staff workflow into their app.
             */
            if ((int) $thread->is_internal === 1) {
                return;
            }

            $body = $this->plainText($thread->body);

            if ($body === '') {
                return;
            }

            /*
             * Replies live under the platform's own ticket row, which the
             * create sync wrote. ticket_number is the only key shared between
             * the two databases -- Faveo's ids are unrelated to the platform's.
             */
            $platformTicket = DB::connection('main_platform')
                ->table('tickets')
                ->select('id')
                ->where('ticket_number', '=', $ticket->ticket_number)
                ->first();

            if (! $platformTicket) {
                Log::warning('platform reply sync skipped: ticket not synced yet', [
                    'ticket_number'   => $ticket->ticket_number,
                    'faveo_thread_id' => $thread->id,
                ]);

                return;
            }

            /*
             * Idempotency guard: the reply event can fire more than once for a
             * single reply, and a duplicate would show the customer the same
             * message twice. faveo_thread_id is unique on the platform side, so
             * this check and that constraint together make a re-fire a no-op.
             */
            $already = DB::connection('main_platform')
                ->table('ticket_replies')
                ->where('faveo_thread_id', '=', $thread->id)
                ->exists();

            if ($already) {
                return;
            }

            DB::connection('main_platform')
                ->table('ticket_replies')
                ->insert([
                    'ticket_id'       => $platformTicket->id,
                    'faveo_thread_id' => $thread->id,
                    'author'          => $this->authorFor($thread),
                    'body'            => $body,
                    'replied_at'      => $thread->created_at ?? now(),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

            Log::info('platform reply sync: inserted', [
                'ticket_number'      => $ticket->ticket_number,
                'faveo_thread_id'    => $thread->id,
                'platform_ticket_id' => $platformTicket->id,
            ]);
        } catch (\Exception $e) {
            /*
             * Never let a sync failure break replying: the reply already exists
             * here, and this is a copy.
             */
            Log::error('platform reply sync: FAILED', [
                'ticket_number' => $ticket->ticket_number ?? '?',
                'error'         => $e->getMessage(),
                'class'         => get_class($e),
                'file'          => $e->getFile().':'.$e->getLine(),
            ]);
        }
    }

    /**
     * Faveo records 'client' for a customer and 'support' for an agent. A
     * missing poster means an agent-side write, which is what produces the
     * internal notes filtered out above.
     */
    protected function authorFor(Ticket_Thread $thread): string
    {
        return $thread->poster === 'client'
            ? self::AUTHOR_CUSTOMER
            : self::AUTHOR_SUPPORT;
    }

    /**
     * Thread bodies are the rich text Faveo's editor produced, stored as a
     * blob. The app renders plain text, so strip the markup and collapse the
     * whitespace that leaves behind.
     */
    protected function plainText($html): string
    {
        $html = (string) $html;

        if ($html === '') {
            return '';
        }

        $text = preg_replace('/<(br|\/p|\/div|\/li)[^>]*>/i', ' ', $html);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // &nbsp; decodes to a non-breaking space, which trim() leaves behind.
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text);

        return trim((string) $text);
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
}
