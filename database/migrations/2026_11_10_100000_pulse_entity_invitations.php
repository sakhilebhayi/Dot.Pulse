<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team invitations. An owner or admin invites an email address; Pulse never says whether the address has an account.
 * The address is stored only as a keyed hash (plus a masked hint for the inviter's own list), and the person sees the
 * invitation on their own hub when they sign in with that address.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pulse_entity_invitations')) {
            return;
        }

        Schema::create('pulse_entity_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('pulse_entities')->cascadeOnDelete();
            $table->string('email_hash', 64);
            $table->string('email_hint', 80)->nullable(); // "t***@example.test", for the inviter's list; never the address
            $table->string('role', 16);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['email_hash', 'accepted_at', 'declined_at'], 'pulse_invitations_recipient_index');
            $table->index(['entity_id', 'email_hash'], 'pulse_invitations_entity_email_index');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pulse_entity_invitations');
    }
};
