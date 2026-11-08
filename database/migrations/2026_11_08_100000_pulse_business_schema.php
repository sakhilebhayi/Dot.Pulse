<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-41: Dot.Pulse becomes where business meets opportunity. Business pages (entities) with sellers and employers,
 * offers, roles, needs, a unified discovery index (signals), explainable matches, contextual conversations with
 * quotes and outcomes, privacy controls, notification preferences and an append only audit log.
 * Strings, not enums, so values can grow without a table rebuild. The old extended tables (polls, marketplace,
 * knowledge graph, badges) stay as they are; nothing new depends on them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pulse_places', function (Blueprint $table) {
            $table->id();
            $table->string('level', 8); // country, region, city
            $table->char('country_code', 2);
            $table->string('region', 80)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->decimal('lat', 8, 5)->nullable(); // an approximate centroid of the place, never a person's position
            $table->decimal('lng', 8, 5)->nullable();
            $table->index(['country_code', 'level']);
        });

        Schema::table('pulse_profiles', function (Blueprint $table) {
            $table->string('kind', 16)->default('individual'); // individual, professional
            $table->string('discoverability', 12)->default('off'); // off, anonymous, open (professionals found by employers)
            $table->json('open_to')->nullable(); // work, freelance, collab, mentoring, investing
            $table->foreignId('place_id')->nullable()->constrained('pulse_places')->nullOnDelete();
            $table->string('reach', 10)->default('region'); // city, region, country, remote: how far the person looks
            $table->boolean('show_place')->default(true);
            $table->unsignedTinyInteger('experience_years')->nullable();
            $table->json('languages')->nullable();
            $table->json('interests')->nullable();
            $table->json('achievements')->nullable();
            $table->json('work_history')->nullable();
            $table->string('contact_policy', 12)->default('context'); // anyone, context, requests, nobody
            $table->timestamp('onboarded_at')->nullable();
        });

        Schema::create('pulse_entities', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 140);
            $table->string('tagline', 160)->nullable();
            $table->text('about')->nullable();
            $table->boolean('is_seller')->default(false);
            $table->boolean('is_employer')->default(false);
            $table->string('industry', 80)->nullable();
            $table->foreignId('place_id')->nullable()->constrained('pulse_places')->nullOnDelete();
            $table->json('service_area')->nullable(); // place slugs, "national", "remote"
            $table->string('website')->nullable();
            $table->string('public_email')->nullable();
            $table->string('public_phone', 40)->nullable();
            $table->unsignedBigInteger('logo_media_id')->nullable();
            $table->unsignedBigInteger('cover_media_id')->nullable();
            $table->json('culture')->nullable(); // values, perks, work_mode
            $table->string('team_size', 12)->nullable();
            $table->unsignedSmallInteger('founded_year')->nullable();
            $table->string('registration_number', 40)->nullable(); // a claim the business makes; never shown as verified
            $table->string('verification_status', 12)->default('unverified'); // unverified, pending, verified, rejected, revoked
            $table->string('verification_source', 24)->nullable(); // infodot
            $table->string('verification_ref', 80)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('infodot_business_ref', 80)->nullable()->unique();
            $table->string('status', 12)->default('draft'); // draft, live, suspended
            $table->string('contact_policy', 12)->default('anyone');
            $table->string('hiring_signal', 10)->default('none'); // none, building, active: an employer open to meeting talent before a vacancy exists
            $table->json('hiring_skills')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('followers_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'industry']);
        });

        Schema::create('pulse_entity_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('pulse_entities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->default('editor'); // owner, admin, editor, responder
            $table->timestamps();
            $table->unique(['entity_id', 'user_id']);
        });

        Schema::create('pulse_entity_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('pulse_entities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['entity_id', 'user_id']);
        });

        Schema::create('pulse_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('pulse_entities')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('slug', 140);
            $table->string('kind', 8)->default('product'); // product, service
            $table->string('title', 140);
            $table->string('summary', 240)->nullable();
            $table->text('description')->nullable();
            $table->string('category', 80)->nullable();
            $table->json('tags')->nullable();
            $table->string('price_mode', 8)->default('quote'); // fixed, from, quote, free, contact
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->char('currency', 3)->default('ZAR');
            $table->string('unit', 24)->nullable();
            $table->foreignId('place_id')->nullable()->constrained('pulse_places')->nullOnDelete();
            $table->json('service_area')->nullable();
            $table->string('availability', 10)->default('available'); // available, limited, sold_out
            $table->boolean('accepts_quotes')->default(true);
            $table->string('status', 10)->default('draft'); // draft, live, paused, archived, removed
            $table->string('moderation', 10)->default('pending'); // pending, approved, held, removed
            $table->json('media_ids')->nullable();
            $table->unsignedInteger('enquiries_count')->default(0);
            $table->unsignedInteger('saves_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['entity_id', 'slug']);
            $table->index(['status', 'moderation', 'published_at']);
        });

        Schema::create('pulse_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('pulse_entities')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('slug', 140);
            $table->string('title', 140);
            $table->string('summary', 240)->nullable();
            $table->text('description')->nullable();
            $table->string('employment_type', 12)->default('full_time'); // full_time, part_time, contract, internship, freelance
            $table->string('work_mode', 8)->default('onsite'); // onsite, hybrid, remote
            $table->string('seniority', 12)->nullable();
            $table->foreignId('place_id')->nullable()->constrained('pulse_places')->nullOnDelete();
            $table->json('skills')->nullable();
            $table->string('pay_text', 80)->nullable(); // as the employer declares it
            $table->string('apply_mode', 12)->default('on_pulse'); // on_pulse, external_url
            $table->string('apply_url')->nullable();
            $table->string('status', 10)->default('draft'); // draft, live, paused, filled, closed, removed
            $table->string('moderation', 10)->default('pending');
            $table->unsignedInteger('applications_count')->default(0);
            $table->unsignedInteger('saves_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['entity_id', 'slug']);
            $table->index(['status', 'moderation', 'published_at']);
        });

        Schema::create('pulse_needs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('pulse_entities')->nullOnDelete();
            $table->string('kind', 10)->default('need'); // need, supplier, collab, investor, mentor, intro
            $table->string('title', 140);
            $table->text('details')->nullable();
            $table->json('tags')->nullable();
            $table->string('budget_text', 60)->nullable();
            $table->date('needed_by')->nullable();
            $table->foreignId('place_id')->nullable()->constrained('pulse_places')->nullOnDelete();
            $table->string('reach', 10)->default('region');
            $table->string('visibility', 10)->default('open'); // open (anyone on Pulse), matched (only people whose offers match)
            $table->string('status', 10)->default('open'); // open, fulfilled, closed, removed
            $table->string('moderation', 10)->default('pending');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'moderation', 'published_at']);
        });

        Schema::create('pulse_signals', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10); // offer, need, role, talent, news, ask, event, collab, business
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('pulse_entities')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('excerpt', 280)->nullable();
            $table->json('topics')->nullable(); // normalised slugs
            $table->json('skills')->nullable();
            $table->foreignId('place_id')->nullable()->constrained('pulse_places')->nullOnDelete();
            $table->string('reach', 10)->default('region');
            $table->boolean('is_remote')->default(false);
            $table->boolean('anonymous')->default(false); // a talent signal that hides the name until the person says yes
            $table->float('quality')->default(0.5);
            $table->string('status', 8)->default('live'); // live, hidden, expired
            $table->timestamp('published_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id']);
            $table->index(['status', 'kind', 'published_at']);
            $table->index(['place_id', 'status']);
        });

        Schema::create('pulse_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signal_id')->constrained('pulse_signals')->cascadeOnDelete();
            $table->float('score');
            $table->json('reasons'); // [{code, text}] shown to the person as "why you are seeing this"
            $table->timestamp('computed_at');
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->string('dismissed_reason', 20)->nullable();
            $table->unique(['user_id', 'signal_id']);
            $table->index(['user_id', 'dismissed_at', 'score']);
        });

        Schema::table('pulse_conversations', function (Blueprint $table) {
            $table->string('kind', 12)->default('dm'); // dm, enquiry, quote, application, intro, collab, invite
            $table->string('status', 10)->default('open'); // requested, open, declined, closed, spam
            $table->foreignId('initiator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('pulse_entities')->nullOnDelete(); // the page that is being contacted
            $table->string('context_type', 24)->nullable(); // offer, role, need, signal, profile, entity
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('context_title', 160)->nullable(); // a snapshot, so the thread keeps its meaning if the listing changes
            $table->boolean('identity_hidden')->default(false); // the initiator is anonymous until they reveal themselves
            $table->string('outcome', 12)->default('none'); // none, won, lost, hired, partnered
            $table->timestamp('outcome_initiator_at')->nullable();
            $table->timestamp('outcome_recipient_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->index(['status', 'last_message_at']);
            $table->index(['context_type', 'context_id']);
        });

        Schema::table('pulse_conversation_user', function (Blueprint $table) {
            $table->string('side', 10)->default('member'); // initiator, recipient, member
            $table->foreignId('entity_id')->nullable()->constrained('pulse_entities')->nullOnDelete(); // speaking for a page
            $table->boolean('muted')->default(false);
            $table->timestamp('archived_at')->nullable();
        });

        Schema::table('pulse_messages', function (Blueprint $table) {
            $table->string('kind', 12)->default('text'); // text, quote_request, quote, system
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('media_id')->nullable();
        });

        Schema::create('pulse_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pulse_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('pulse_messages')->nullOnDelete();
            $table->foreignId('entity_id')->constrained('pulse_entities')->cascadeOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained('pulse_offers')->nullOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('ZAR');
            $table->string('unit', 24)->nullable();
            $table->text('notes')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status', 10)->default('sent'); // sent, accepted, declined, expired
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pulse_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['blocker_id', 'blocked_id']);
        });

        Schema::create('pulse_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8); // topic, entity, user
            $table->string('target', 120);
            $table->timestamps();
            $table->unique(['user_id', 'kind', 'target']);
        });

        Schema::create('pulse_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('saveable_type', 40);
            $table->unsignedBigInteger('saveable_id');
            $table->timestamps();
            $table->unique(['user_id', 'saveable_type', 'saveable_id']);
        });

        Schema::create('pulse_notification_prefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->json('channels')->nullable(); // {in_app: true, email: true}
            $table->json('categories')->nullable(); // {enquiry: true, response: true, match: true, role: true, invite: true, follow: true, community: true}
            $table->string('frequency', 8)->default('instant'); // instant, daily, weekly, off (email only; in app is always instant)
            $table->boolean('digest')->default(true);
            $table->string('digest_hour', 5)->default('07:30');
            $table->string('timezone', 40)->default('Africa/Johannesburg');
            $table->timestamp('last_digest_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pulse_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 48);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['action', 'created_at']);
        });

        Schema::table('pulse_posts', function (Blueprint $table) {
            $table->foreignId('entity_id')->nullable()->constrained('pulse_entities')->nullOnDelete(); // posted as a business page
        });

        Schema::table('pulse_reviews', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->constrained('pulse_conversations')->nullOnDelete(); // a review needs a concluded conversation
        });
    }

    public function down(): void
    {
        Schema::table('pulse_reviews', fn (Blueprint $t) => $t->dropConstrainedForeignId('conversation_id'));
        Schema::table('pulse_posts', fn (Blueprint $t) => $t->dropConstrainedForeignId('entity_id'));
        foreach (['pulse_audit_log', 'pulse_notification_prefs', 'pulse_saves', 'pulse_mutes', 'pulse_blocks', 'pulse_quotes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('pulse_messages', fn (Blueprint $t) => $t->dropColumn(['kind', 'meta', 'media_id']));
        Schema::table('pulse_conversation_user', function (Blueprint $t) {
            $t->dropConstrainedForeignId('entity_id');
            $t->dropColumn(['side', 'muted', 'archived_at']);
        });
        Schema::table('pulse_conversations', function (Blueprint $t) {
            // SQLite cannot drop a column that an index still uses: drop the indexes first.
            $t->dropIndex(['status', 'last_message_at']);
            $t->dropIndex(['context_type', 'context_id']);
            $t->dropConstrainedForeignId('initiator_id');
            $t->dropConstrainedForeignId('entity_id');
            $t->dropColumn(['kind', 'status', 'context_type', 'context_id', 'context_title', 'identity_hidden', 'outcome', 'outcome_initiator_at', 'outcome_recipient_at', 'first_response_at', 'last_message_at', 'closed_at']);
        });
        foreach (['pulse_matches', 'pulse_signals', 'pulse_needs', 'pulse_roles', 'pulse_offers', 'pulse_entity_followers', 'pulse_entity_members', 'pulse_entities'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('pulse_profiles', function (Blueprint $t) {
            $t->dropConstrainedForeignId('place_id');
            $t->dropColumn(['kind', 'discoverability', 'open_to', 'reach', 'show_place', 'experience_years', 'languages', 'interests', 'achievements', 'work_history', 'contact_policy', 'onboarded_at']);
        });
        Schema::dropIfExists('pulse_places');
    }
};
