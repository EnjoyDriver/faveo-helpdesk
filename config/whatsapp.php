<?php

return [
    'base_url' => env('WHATSAPP_BASE_URL', 'https://socket.enjoy-driving.com.sa'),
    'device_id' => env('WHATSAPP_DEVICE_ID', 'enjoydriving'),

    /*
     * Fixed business/admin number that receives the "ticket created"
     * notification, in full international format without "+" (e.g. a Saudi
     * number is 9665XXXXXXXX). The ticket is NOT sent to the customer's own
     * number.
     *
     * NOTE: 966571060010 is currently the gateway's own sender number, so a
     * message to it may land silently in the "message yourself" chat. Change
     * WHATSAPP_NOTIFY_NUMBER to a staff number (or group JID) that someone
     * actually watches once testing is done.
     */
    'notify_number' => env('WHATSAPP_NOTIFY_NUMBER', '966571060010'),
];