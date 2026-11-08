<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-41 agent D1: what the business schema left out.
 * - entities get a moderation state (the page text is public) and a log of the claims the business makes about itself
 * - needs get soft deletes
 * - profiles get the age confirmation, an avatar media id and a moderation state for their public talent text
 * - pulse_media_files holds re-encoded images (the old pulse_media table is polymorphic, defaults to s3 and has no
 *   purpose, owner entity or conversation, so nothing could be authorised from it)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pulse_entities', function (Blueprint $table) {
            if (! Schema::hasColumn('pulse_entities', 'moderation')) {
                $table->string('moderation', 10)->default('pending'); // pending, approved, held, removed
            }
            if (! Schema::hasColumn('pulse_entities', 'infodot_verification')) {
                $table->json('infodot_verification')->nullable(); // dormant: what a future redeem teams[].verification object carried, with when Pulse read it
            }
            if (! Schema::hasColumn('pulse_entities', 'claims')) {
                $table->json('claims')->nullable(); // {registration: {value, at}, website: {value, at}, phone: {...}}: what the business says, never proof
            }
        });

        Schema::table('pulse_needs', function (Blueprint $table) {
            if (! Schema::hasColumn('pulse_needs', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('pulse_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('pulse_profiles', 'age_confirmed_at')) {
                $table->timestamp('age_confirmed_at')->nullable(); // the person said they are 18 or over; nobody under 18 is ever discoverable
            }
            if (! Schema::hasColumn('pulse_profiles', 'avatar_media_id')) {
                $table->unsignedBigInteger('avatar_media_id')->nullable();
            }
            if (! Schema::hasColumn('pulse_profiles', 'moderation')) {
                $table->string('moderation', 10)->default('approved'); // approved, held: the public talent text
            }
        });

        if (! Schema::hasTable('pulse_media_files')) {
            Schema::create('pulse_media_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('purpose', 10); // logo, cover, offer, gallery, avatar, message
                $table->foreignId('entity_id')->nullable()->constrained('pulse_entities')->cascadeOnDelete();
                $table->unsignedBigInteger('conversation_id')->nullable()->index(); // message media is readable by the participants only
                $table->string('disk', 20)->default('local');
                $table->string('mime', 40);
                $table->unsignedInteger('width');
                $table->unsignedInteger('height');
                $table->unsignedInteger('bytes'); // of the largest stored size
                $table->json('variants'); // {thumb: {path, width, height, bytes}, medium: {...}, large: {...}}
                $table->string('sha256', 64)->nullable(); // of the upload, for abuse tracing only
                $table->timestamps();
                $table->index(['entity_id', 'purpose']);
                $table->index(['user_id', 'purpose']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pulse_media_files');

        Schema::table('pulse_profiles', function (Blueprint $table) {
            foreach (['moderation', 'avatar_media_id', 'age_confirmed_at'] as $column) {
                if (Schema::hasColumn('pulse_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('pulse_needs', function (Blueprint $table) {
            if (Schema::hasColumn('pulse_needs', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });

        Schema::table('pulse_entities', function (Blueprint $table) {
            foreach (['claims', 'infodot_verification', 'moderation'] as $column) {
                if (Schema::hasColumn('pulse_entities', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
