<?php

namespace Tests\Feature\Entities;

use App\Models\PulseMedia;
use App\Models\PulseOffer;
use App\Models\PulseProfile;
use App\Models\User;
use App\Services\Pulse\Entities\EntityService;
use App\Services\Pulse\Entities\MediaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MediaServiceTest extends EntitiesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): MediaService
    {
        return app(MediaService::class);
    }

    private function jpeg(int $w = 800, int $h = 600, array $segments = []): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 40, 40));
        ob_start();
        imagejpeg($im);
        $bytes = (string) ob_get_clean();

        $inject = '';

        foreach ($segments as $payload) {
            $inject .= "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
        }

        return substr($bytes, 0, 2).$inject.substr($bytes, 2);
    }

    private function png(int $w = 100, int $h = 100): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function upload(string $bytes, string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    public function test_an_image_is_stored_at_three_sizes_on_the_private_disk(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);

        $id = $this->service()->store($owner, $this->upload($this->jpeg(2400, 1200)), 'cover', $entity);
        $media = PulseMedia::findOrFail($id);

        $this->assertSame(['thumb', 'medium', 'large'], array_keys($media->variants));
        $this->assertSame(160, $media->variant('thumb')['width']);
        $this->assertSame(640, $media->variant('medium')['width']);
        $this->assertSame(1600, $media->variant('large')['width']);
        $this->assertSame(800, $media->variant('large')['height']);

        foreach ($media->variants as $variant) {
            Storage::disk('local')->assertExists($variant['path']);
            $this->assertStringStartsWith('pulse/media/', $variant['path']);
        }

        $this->assertSame('image/webp', $media->mime);
        $this->assertSame($entity->id, $media->entity_id);
        $this->assertSame(strlen(Storage::disk('local')->get($media->variant('large')['path'])), $media->bytes);
    }

    public function test_small_images_are_never_enlarged(): void
    {
        $owner = $this->person();

        $media = PulseMedia::findOrFail($this->service()->store($owner, $this->upload($this->png(100, 80), 'a.png'), 'avatar'));

        $this->assertSame(100, $media->variant('large')['width']);
        $this->assertSame(80, $media->variant('large')['height']);
    }

    public function test_exif_is_stripped_and_orientation_is_applied_first(): void
    {
        $owner = $this->person();
        $tiff = "MM\x00\x2a\x00\x00\x00\x08".pack('n', 1).pack('nnNn', 0x0112, 3, 1, 6)."\x00\x00".pack('N', 0);
        $bytes = $this->jpeg(800, 600, ["Exif\x00\x00".$tiff, 'SECRETGPS 26.2041S 28.0473E camera-serial-1234']);
        $this->assertStringContainsString('SECRETGPS', $bytes);

        $media = PulseMedia::findOrFail($this->service()->store($owner, $this->upload($bytes), 'avatar'));

        foreach ($media->variants as $variant) {
            $stored = Storage::disk('local')->get($variant['path']);
            $this->assertStringNotContainsString('SECRETGPS', $stored);
            $this->assertStringNotContainsString('Exif', $stored);
            $this->assertStringNotContainsString('camera-serial', $stored);
        }

        $this->assertSame(600, $media->variant('large')['width'], 'orientation 6 turns a landscape upload upright');
        $this->assertSame(800, $media->variant('large')['height']);
    }

    public function test_the_wrong_kind_of_file_is_refused_whatever_it_is_called(): void
    {
        $owner = $this->person();
        $attempts = [
            'a php script named like a photo' => $this->upload("<?php system(\$_GET['c']); ?>", 'photo.jpg'),
            'a gif' => $this->upload("GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;", 'photo.jpg'),
            'a gif with a script after the header' => $this->upload("GIF89a<?php echo 'x'; ?>", 'photo.png'),
            'an svg with script' => $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'photo.png'),
            'a pdf' => $this->upload("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 'photo.jpg'),
            'a truncated jpeg header' => $this->upload("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00<?php evil(); ?>", 'photo.jpg'),
            'an empty file' => $this->upload('', 'photo.jpg'),
        ];

        foreach ($attempts as $what => $file) {
            try {
                $this->service()->store($owner, $file, 'avatar');
                $this->fail("$what was accepted");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, PulseMedia::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_valid_image_with_a_payload_appended_is_cleaned_not_stored_as_is(): void
    {
        $owner = $this->person();
        $payload = "<?php system(\$_GET['c']); ?>";
        $bytes = $this->png().$payload;

        $media = PulseMedia::findOrFail($this->service()->store($owner, $this->upload($bytes, 'avatar.php.png'), 'avatar'));

        foreach ($media->variants as $variant) {
            $this->assertStringNotContainsString('<?php', Storage::disk('local')->get($variant['path']));
            $this->assertStringEndsWith('.webp', $variant['path']);
        }
    }

    public function test_oversize_and_over_dimension_images_are_refused(): void
    {
        $owner = $this->person();
        config(['pulse.entities.media.max_bytes' => 2000]);

        try {
            $this->service()->store($owner, $this->upload($this->jpeg(900, 900)), 'avatar');
            $this->fail('over the byte limit');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('MB', $e->errors()['file'][0]);
        }

        config(['pulse.entities.media.max_bytes' => 5 * 1024 * 1024, 'pulse.entities.media.max_pixels' => 10_000]);

        $this->expectException(ValidationException::class);
        $this->service()->store($owner, $this->upload($this->jpeg(200, 200)), 'avatar');
    }

    public function test_a_file_over_five_megabytes_is_refused_by_default(): void
    {
        $this->assertSame(5 * 1024 * 1024, config('pulse.entities.media.max_bytes'));

        $this->expectException(ValidationException::class);
        $this->service()->store($this->person(), $this->upload($this->jpeg().str_repeat("\0", 5 * 1024 * 1024)), 'avatar');
    }

    public function test_who_may_upload_for_a_page(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $editor = $this->member($entity, 'editor');
        $responder = $this->member($entity, 'responder');

        $this->assertIsInt($this->service()->store($editor, $this->upload($this->jpeg()), 'offer', $entity));
        $this->assertIsInt($this->service()->store($owner, $this->upload($this->jpeg()), 'logo', $entity));

        foreach ([[$editor, 'logo'], [$responder, 'offer'], [$this->person(), 'gallery'], [$this->person(), 'cover']] as [$who, $purpose]) {
            try {
                $this->service()->store($who, $this->upload($this->jpeg()), $purpose, $entity);
                $this->fail('expected an authorisation failure');
            } catch (AuthorizationException) {
            }
        }
    }

    public function test_message_media_needs_a_conversation_the_sender_is_in(): void
    {
        $a = $this->person();
        $b = $this->person();
        $outsider = $this->person();
        $conversation = $this->conversation([$a, $b]);

        $this->assertIsInt($this->service()->store($a, $this->upload($this->jpeg()), 'message', null, $conversation));

        $this->expectException(HttpException::class);
        $this->service()->store($outsider, $this->upload($this->jpeg()), 'message', null, $conversation);
    }

    public function test_upload_rate_limit(): void
    {
        config(['pulse.entities.media.uploads_per_hour' => 2]);
        $owner = $this->person();

        $this->service()->store($owner, $this->upload($this->jpeg()), 'avatar');
        $this->service()->store($owner, $this->upload($this->jpeg()), 'avatar');

        $this->expectException(ValidationException::class);
        $this->service()->store($owner, $this->upload($this->jpeg()), 'avatar');
    }

    public function test_delete_removes_files_rows_and_references(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $id = $this->service()->store($owner, $this->upload($this->jpeg()), 'logo', $entity);
        $entity->forceFill(['logo_media_id' => $id])->save();
        $paths = array_column(PulseMedia::find($id)->variants, 'path');

        try {
            $this->service()->delete($this->person(), $id);
            $this->fail('a stranger must not delete');
        } catch (HttpException) {
        }

        $this->service()->delete($owner, $id);

        $this->assertNull(PulseMedia::find($id));
        $this->assertNull($entity->fresh()->logo_media_id);
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    public function test_entities_only_accept_their_own_logo(): void
    {
        $owner = $this->person();
        $entity = app(EntityService::class)->create($owner, ['name' => 'Karoo Books', 'is_seller' => true]);
        $other = $this->page($owner);
        $mine = $this->service()->store($owner, $this->upload($this->jpeg()), 'logo', $entity);
        $theirs = $this->service()->store($owner, $this->upload($this->jpeg()), 'logo', $other);
        $cover = $this->service()->store($owner, $this->upload($this->jpeg()), 'cover', $entity);

        $this->assertSame($mine, app(EntityService::class)->update($owner, $entity, ['logo_media_id' => $mine])->logo_media_id);

        foreach ([$theirs, $cover] as $wrong) {
            try {
                app(EntityService::class)->update($owner, $entity, ['logo_media_id' => $wrong]);
                $this->fail('that image is not this page logo');
            } catch (ValidationException) {
            }
        }
    }

    public function test_url_is_the_authorised_route_not_a_storage_path(): void
    {
        $url = $this->service()->url(42, 'thumb');

        $this->assertSame('/media/42/thumb', $url);
        $this->assertSame('/media/42/medium', $this->service()->url(42, 'bogus'));
    }

    // ---- the route ---------------------------------------------------------------------------------------------

    public function test_a_live_pages_logo_is_public_and_served_with_safe_headers(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $id = $this->service()->store($owner, $this->upload($this->jpeg()), 'logo', $entity);
        $entity->forceFill(['logo_media_id' => $id])->save();

        $response = $this->get($this->service()->url($id, 'thumb'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
    }

    public function test_a_public_sized_image_is_cached_for_a_week_with_an_etag_and_revalidates_to_304_after_the_same_checks(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $id = $this->service()->store($owner, $this->upload($this->jpeg()), 'logo', $entity);
        $entity->forceFill(['logo_media_id' => $id])->save();

        $first = $this->get($this->service()->url($id, 'medium'));
        $first->assertOk();
        $cache = $first->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=604800', $cache);
        $this->assertStringContainsString('immutable', $cache);
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        // The tag is per size, so a thumb is never answered with a medium's 304.
        $this->assertNotSame($etag, $this->get($this->service()->url($id, 'thumb'))->headers->get('ETag'));

        $again = $this->withHeaders(['If-None-Match' => $etag])->get($this->service()->url($id, 'medium'));
        $again->assertStatus(304);
        $this->assertSame('', (string) $again->getContent());

        // A 304 is still behind the authorisation check: once the page is not public the answer is a 404, not a 304.
        $entity->forceFill(['status' => 'draft'])->save();
        $this->withHeaders(['If-None-Match' => $etag])->get($this->service()->url($id, 'medium'))->assertNotFound();
    }

    public function test_a_signed_in_person_gets_the_same_week_but_never_a_shared_cache(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $id = $this->service()->store($owner, $this->upload($this->jpeg()), 'logo', $entity);
        $entity->forceFill(['logo_media_id' => $id])->save();

        $cache = $this->actingAs($this->person())->get($this->service()->url($id, 'thumb'))->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cache);
        $this->assertStringNotContainsString('public', $cache);
        $this->assertStringContainsString('max-age=604800', $cache);
    }

    public function test_an_image_that_is_not_used_yet_or_whose_page_is_not_live_is_private(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $unused = $this->service()->store($owner, $this->upload($this->jpeg()), 'logo', $entity);
        $stranger = $this->person();

        $this->get($this->service()->url($unused))->assertNotFound();
        $this->actingAs($stranger)->get($this->service()->url($unused))->assertNotFound();
        $this->actingAs($owner)->get($this->service()->url($unused))->assertOk();
        $this->actingAs($this->member($entity, 'responder'))->get($this->service()->url($unused))->assertOk();

        $entity->forceFill(['logo_media_id' => $unused, 'status' => 'draft'])->save();
        auth()->logout();
        $this->get($this->service()->url($unused))->assertNotFound();
    }

    public function test_offer_images_are_public_only_while_an_offer_using_them_is_live(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $id = $this->service()->store($owner, $this->upload($this->jpeg()), 'offer', $entity);
        $offer = PulseOffer::factory()->create(['entity_id' => $entity->id, 'media_ids' => [$id]]);

        $this->get($this->service()->url($id))->assertOk();

        $offer->update(['status' => 'paused']);
        $this->get($this->service()->url($id))->assertNotFound();
    }

    public function test_message_media_is_for_the_participants_only(): void
    {
        $a = $this->person();
        $b = $this->person();
        $conversation = $this->conversation([$a, $b]);
        $id = $this->service()->store($a, $this->upload($this->jpeg()), 'message', null, $conversation);

        $this->get($this->service()->url($id))->assertNotFound();
        $this->actingAs($this->person())->get($this->service()->url($id))->assertNotFound();
        $this->actingAs($this->moderator())->get($this->service()->url($id))->assertNotFound();
        $this->actingAs($b)->get($this->service()->url($id))->assertOk()->assertHeader('Cache-Control', 'max-age=300, private');
        $this->actingAs($a)->get($this->service()->url($id))->assertOk();
    }

    public function test_an_anonymous_profiles_avatar_is_the_owners_alone(): void
    {
        $owner = $this->person();
        $id = $this->service()->store($owner, $this->upload($this->jpeg()), 'avatar');
        $profile = PulseProfile::factory()->anonymous()->create(['user_id' => $owner->id, 'avatar_media_id' => $id]);

        $this->actingAs($this->person())->get($this->service()->url($id))->assertNotFound();
        $this->actingAs($owner)->get($this->service()->url($id))->assertOk();

        $profile->update(['discoverability' => 'open']);
        $this->actingAs($this->person())->get($this->service()->url($id))->assertOk();
        auth()->logout();
        $this->get($this->service()->url($id))->assertNotFound();
    }

    public function test_an_unknown_size_or_id_is_not_found(): void
    {
        $owner = $this->person();
        $id = $this->service()->store($owner, $this->upload($this->jpeg()), 'avatar');

        $this->actingAs($owner)->get("/media/$id/original")->assertNotFound();
        $this->actingAs($owner)->get('/media/999999/thumb')->assertNotFound();
    }

    /** @param list<User> $people */
    private function conversation(array $people): int
    {
        $id = DB::table('pulse_conversations')->insertGetId(['type' => 'direct', 'created_at' => now(), 'updated_at' => now()]);

        foreach ($people as $person) {
            DB::table('pulse_conversation_user')->insert(['pulse_conversation_id' => $id, 'user_id' => $person->id]);
        }

        return $id;
    }
}
