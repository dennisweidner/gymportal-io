<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror mode of the Agent API: a gym whose data arrives as a studio export
 * from another gymportal installation. The mirror is read-only by nature, so
 * confirmed writes become pending actions a human carries out in the portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_mirror_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gym_id')->constrained()->cascadeOnDelete();
            $table->string('source')->nullable();
            $table->string('content_hash', 64);
            // When the export was taken in the source portal: the age of the data.
            $table->timestamp('exported_at')->nullable();
            $table->json('stats');
            // member_number => member id in the source portal, for deep links
            $table->json('portal_member_ids')->nullable();
            $table->unsignedInteger('actions_closed')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['gym_id', 'created_at']);
        });

        Schema::create('agent_pending_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gym_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gym_api_client_id')->nullable()->constrained('gym_api_clients')->nullOnDelete();
            $table->string('type', 40);
            // Members get new ids with every import, the member number stays.
            $table->string('member_number')->nullable();
            $table->string('member_name')->nullable();
            $table->string('portal_member_id')->nullable();
            $table->json('payload');
            $table->json('effect');
            $table->string('status', 20)->default('open');
            // manual: a human ticked it off. sync: the next export showed the effect.
            $table->string('closed_by', 20)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['gym_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_pending_actions');
        Schema::dropIfExists('agent_mirror_syncs');
    }
};
