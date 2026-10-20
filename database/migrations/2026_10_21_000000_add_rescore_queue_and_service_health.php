<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things that make an outage survivable:
 *
 *  - a recording saved while the engine was down is scored again by the
 *    scheduler once it is back (assessments:rescore). The attempt count stops
 *    a recording that fails for its own reasons from being retried forever.
 *  - every change in the engine's health is written down, so the admin can see
 *    when it went down, how long for, and whether anyone was told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->unsignedTinyInteger('rescore_attempts')->default(0)->after('status');
            $table->timestamp('last_rescore_at')->nullable()->after('rescore_attempts');
        });

        Schema::create('service_health_events', function (Blueprint $table) {
            $table->id();
            $table->string('state', 24)->comment('ready | stale | model_missing | stt_missing | unreachable | error | disabled');
            $table->string('level', 12)->comment('healthy | degraded | down');
            $table->text('message');
            $table->timestamp('alerted_at')->nullable()->comment('when the admin was told it is down/degraded');
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_health_events');

        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn(['rescore_attempts', 'last_rescore_at']);
        });
    }
};
