<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which platform role a ticket was raised under.
 *
 * On the main platform one account can act as more than one role: the same
 * person signs in as a user and, later, as a captain. Tickets need to remember
 * which of those they were acting as at the time, so the app can list "my
 * tickets as a captain" separately from "my tickets as a user".
 *
 * The value mirrors main.users.last_login_as on the platform database
 * ('user', 'captain', 'delegate'). Existing tickets predate the column and
 * were all raised through the client form, so they are backfilled to 'user'.
 */
class AddLoginAsToTicketsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('login_as', 20)->nullable()->default('user')->after('user_id');
            $table->index('login_as');
        });

        // Existing tickets were all raised by customers through the client
        // form, before captains could reach the helpdesk at all.
        \DB::table('tickets')->whereNull('login_as')->update(['login_as' => 'user']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['login_as']);
            $table->dropColumn('login_as');
        });
    }
}
