<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log of events received from WordPress. The unique event_id is what makes
 * a redelivered event a duplicate. Rows are pruned after 90 days.
 */
class CreateSpatbridgeEventsTable extends Migration
{
    public function up()
    {
        Schema::create('spatbridge_events', function (Blueprint $table) {
            $table->increments('id');
            $table->string('event_id', 36)->unique();
            $table->string('type', 64);
            $table->string('email', 255);
            $table->text('payload');
            $table->string('outcome', 64);
            $table->string('conversation_ids', 255);
            $table->string('detail', 255);
            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('spatbridge_events');
    }
}
