<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        'App\Events\WorkFlowEvent' => [
            'App\Listeners\WorkFlowListen',
        ],
//        'after.ticket.created' => [
//            'App\Listeners\SendWhatsAppNotifications@handleTicketCreated',
//        ],
        'after.ticket.created' => [
            'App\Listeners\SyncTicketToPlatform@handleTicketCreated',
        ],
        /*
         * The platform sync runs first: a listener that throws stops the ones
         * after it, and SendWhatsAppNotifications reads this event's arguments
         * by position even though the two places that fire it disagree on the
         * order -- on the agent reply path it takes country_code for the user
         * and fatals. Ordering alone is not enough, so each listener also
         * swallows its own failures.
         */
        'App\Events\FaveoAfterReply' => [
            'App\Listeners\SyncTicketReplyToPlatform@handleTicketReply',
            'App\Listeners\SendWhatsAppNotifications@handleTicketReply',
        ],
    ];

    /**
     * Register any other events for your application.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
