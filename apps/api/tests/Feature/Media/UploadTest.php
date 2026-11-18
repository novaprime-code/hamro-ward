<?php

declare(strict_types=1);

use App\Modules\Accounts\Models\User;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Jobs\ProcessImage;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Photographs for a report (HW-E12-F01; FR-MED-01..03). Acceptance criteria:
|   - JPEG/PNG/WebP/HEIC by magic bytes, <=10 MB, <=8000 px
|   - a Turnstile failure is a 422
|   - the processed image has no EXIF or GPS (proved with a fixture that has)
|   - re-running the job is safe
|   - a failure leaves the reason on the row
|   - keys always sit under the owning municipality's prefix
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['turnstile.secret_key' => '']);
    Storage::fake('local');
});

/**
 * A municipality that can actually be reached by URL.
 *
 * ResolveTenant only finds a local level that is publicly visible, and that
 * means every ancestor published too — tenantWithPublishedWards publishes the
 * municipality and its wards but leaves the district and province above them
 * unpublished, which is a 404 before any of this code runs.
 *
 * @param  Closure(Tenant): void  $test
 */
function uploadableTenant(Closure $test, ?Tenant $tenant = null): void
{
    withTenantDatabase(function (Tenant $ready) use ($test): void {
        publishAncestorsOf($ready);
        $test($ready);
    }, $tenant ?? tenantWithPublishedWards());
}

/** Where a municipality's uploads go. */
function uploadUrl(Tenant $tenant): string
{
    return '/api/v1/uploads/'.tenantPath($tenant);
}

/** A real JPEG, carrying real GPS coordinates in its EXIF. */
function photoWithGps(string $name = 'pothole.jpg', int $width = 1200, int $height = 800): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'hw-photo').'.jpg';

    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 40, 80, 60));
    imagejpeg($image, $path, 90);
    imagedestroy($image);

    // Little-endian TIFF whose IFD0 points at a GPS IFD: a point in Kathmandu.
    $tiff = "II\x2A\x00\x08\x00\x00\x00".pack('v', 1).pack('vvVV', 0x8825, 4, 1, 26).pack('V', 0);
    $valuesAt = 26 + 2 + (4 * 12) + 4;
    $gps = pack('v', 4)
        .pack('vvVa4', 1, 2, 2, "N\x00\x00\x00")
        .pack('vvVV', 2, 5, 3, $valuesAt)
        .pack('vvVa4', 3, 2, 2, "E\x00\x00\x00")
        .pack('vvVV', 4, 5, 3, $valuesAt + 24)
        .pack('V', 0)
        .pack('VVVVVV', 27, 1, 42, 1, 25, 1)
        .pack('VVVVVV', 85, 1, 18, 1, 55, 1);

    $app1 = "Exif\x00\x00".$tiff.$gps;
    $jpeg = (string) file_get_contents($path);
    file_put_contents($path, substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2));

    return new UploadedFile($path, $name, 'image/jpeg', null, true);
}

/** A file of the given bytes, whatever it claims to be. */
function fileOf(string $contents, string $name, string $claimedType = 'image/jpeg'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'hw-upload');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, $claimedType, null, true);
}

it('accepts a photograph, stores it privately under the municipality’s prefix and queues processing', function (): void {
    Queue::fake();

    uploadableTenant(function (Tenant $tenant): void {
        $citizen = User::factory()->create();

        $response = testCase()->actingAs($citizen)
            ->post(uploadUrl($tenant), ['photo' => photoWithGps()], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', MediaStatus::Uploaded->value)
            ->assertJsonPath('data.width', 1200)
            ->assertJsonPath('data.height', 800)
            // Nothing to link to: it is private until the report is approved.
            ->assertJsonMissingPath('data.url');

        $media = Media::query()->sole();

        expect($media->status)->toBe(MediaStatus::Uploaded)
            ->and($media->visibility)->toBe(MediaVisibility::Private)
            ->and($media->mime)->toBe('image/jpeg')
            ->and($media->uploaded_by_user_id)->toBe($citizen->id)
            ->and($media->storage_prefix)->toStartWith("tenants/{$tenant->tenant_key}/media/")
            ->and($media->original_key)->toStartWith("tenants/{$tenant->tenant_key}/media/");

        Storage::disk('local')->assertExists((string) $media->original_key);

        Queue::assertPushed(ProcessImage::class, fn (ProcessImage $job): bool => $job->mediaId === $media->id
            && $job->tenantId === $tenant->id);
    });
});

it('decides what a file is from its bytes, not its name', function (array $contents, string $name): void {
    Queue::fake();

    uploadableTenant(function (Tenant $tenant) use ($contents, $name): void {
        $citizen = User::factory()->create();

        testCase()->actingAs($citizen)
            ->post(uploadUrl($tenant), ['photo' => fileOf($contents[0], $name)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        expect(Media::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });
})->with([
    'a PHP script' => [["<?php echo shell_exec(\$_GET['c']); ?>"], 'photo.jpg'],
    'an SVG with a script in it' => [['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'], 'photo.jpg'],
    'a PDF' => [["%PDF-1.7\n%\xE2\xE3\xCF\xD3"], 'photo.png'],
    'an empty file' => [[''], 'photo.webp'],
]);

it('refuses a photograph larger than the limit', function (): void {
    Queue::fake();

    uploadableTenant(function (Tenant $tenant): void {
        config(['media.max_bytes' => 1024]);

        testCase()->actingAs(User::factory()->create())
            ->post(uploadUrl($tenant), ['photo' => photoWithGps()], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        expect(Media::query()->count())->toBe(0);
    });
});

it('refuses a photograph with too many pixels', function (): void {
    Queue::fake();

    uploadableTenant(function (Tenant $tenant): void {
        config(['media.max_pixels' => 100]);

        testCase()->actingAs(User::factory()->create())
            ->post(uploadUrl($tenant), ['photo' => photoWithGps()], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        expect(Media::query()->count())->toBe(0);
    });
});

it('refuses an upload whose bot check fails', function (): void {
    Queue::fake();

    uploadableTenant(function (Tenant $tenant): void {
        config(['turnstile.secret_key' => 'a-secret']);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false]),
        ]);

        testCase()->actingAs(User::factory()->create())
            ->post(uploadUrl($tenant), ['photo' => photoWithGps(), 'turnstile_token' => 'no'], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('turnstile_token');

        expect(Media::query()->count())->toBe(0);
    });
});

it('is closed to visitors, and to accounts that have not confirmed an address', function (): void {
    Queue::fake();

    uploadableTenant(function (Tenant $tenant): void {
        testCase()->post(uploadUrl($tenant), ['photo' => photoWithGps()], ['Accept' => 'application/json'])
            ->assertUnauthorized();

        testCase()->actingAs(User::factory()->unverified()->create())
            ->post(uploadUrl($tenant), ['photo' => photoWithGps()], ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonPath('email_verification_required', true);

        expect(Media::query()->count())->toBe(0);
    });
});

it('strips every trace of the camera, writes both sizes and deletes the original', function (): void {
    uploadableTenant(function (Tenant $tenant): void {
        $citizen = User::factory()->create();

        testCase()->actingAs($citizen)
            ->post(uploadUrl($tenant), ['photo' => photoWithGps()], ['Accept' => 'application/json'])
            ->assertCreated();

        $media = Media::query()->sole();
        $originalKey = (string) $media->original_key;
        $disk = Storage::disk('local');

        // The file as uploaded really does carry coordinates.
        $uploadedPath = tempnam(sys_get_temp_dir(), 'hw-check').'.jpg';
        file_put_contents($uploadedPath, (string) $disk->get($originalKey));
        $before = @exif_read_data($uploadedPath);
        expect($before)->toBeArray()
            ->and($before['GPSLatitude'] ?? null)->not->toBeNull();

        (new ProcessImage($tenant->id, $media->id))->handle(app(TenantManager::class));

        $media->refresh();

        expect($media->status)->toBe(MediaStatus::Ready)
            ->and($media->original_key)->toBeNull()
            ->and($media->error)->toBeNull()
            ->and($media->processed_at)->not->toBeNull()
            ->and(array_keys($media->variants))->toEqualCanonicalizing(['sm', 'lg']);

        // The original is gone: it was the only copy holding the location.
        $disk->assertMissing($originalKey);

        foreach ($media->variants as $name => $variant) {
            $key = (string) $variant['key'];

            expect($key)->toStartWith("tenants/{$tenant->tenant_key}/media/");
            $disk->assertExists($key);

            $bytes = (string) $disk->get($key);

            $variantPath = tempnam(sys_get_temp_dir(), 'hw-variant').'.webp';
            file_put_contents($variantPath, $bytes);

            $after = @exif_read_data($variantPath);

            // exif_read_data gives an array or false, never null.
            expect($after === false || ! isset($after['GPSLatitude']))->toBeTrue()
                ->and($bytes)->not->toContain('Exif')
                ->and(substr($bytes, 8, 4))->toBe('WEBP');
        }

        expect($media->variants['sm']['width'])->toBe(480)
            ->and($media->variants['lg']['width'])->toBe(1200); // never enlarged
    });
});

it('can be told to process the same photograph twice', function (): void {
    uploadableTenant(function (Tenant $tenant): void {
        testCase()->actingAs(User::factory()->create())
            ->post(uploadUrl($tenant), ['photo' => photoWithGps()], ['Accept' => 'application/json'])
            ->assertCreated();

        $media = Media::query()->sole();
        $job = new ProcessImage($tenant->id, $media->id);
        $tenancy = app(TenantManager::class);

        $job->handle($tenancy);
        $first = $media->fresh();

        // The original is already gone; a second run must not take that as a
        // failure, and must not touch what the first run produced.
        $job->handle($tenancy);
        $second = $media->fresh();

        expect($second->status)->toBe(MediaStatus::Ready)
            ->and($second->variants)->toBe($first->variants)
            ->and($second->error)->toBeNull();
    });
});

it('records why processing failed, in words', function (): void {
    uploadableTenant(function (Tenant $tenant): void {
        testCase()->actingAs(User::factory()->create())
            ->post(uploadUrl($tenant), ['photo' => photoWithGps()], ['Accept' => 'application/json'])
            ->assertCreated();

        $media = Media::query()->sole();

        // The bytes vanish between upload and processing.
        Storage::disk('local')->delete((string) $media->original_key);

        (new ProcessImage($tenant->id, $media->id))->handle(app(TenantManager::class));

        $media->refresh();

        expect($media->status)->toBe(MediaStatus::Failed)
            ->and($media->error)->not->toBeNull()
            ->and($media->variants)->toBe([]);
    });
});

it('keeps one municipality’s photographs out of another’s', function (): void {
    Queue::fake();

    $first = tenantWithPublishedWards();
    $second = tenantWithPublishedWards();

    uploadableTenant(function (Tenant $tenantOne) use ($second): void {
        uploadableTenant(function (Tenant $tenantTwo) use ($tenantOne): void {
            $citizen = User::factory()->create();

            testCase()->actingAs($citizen)
                ->post(uploadUrl($tenantOne), ['photo' => photoWithGps()], ['Accept' => 'application/json'])
                ->assertCreated();

            testCase()->actingAs($citizen)
                ->post(uploadUrl($tenantTwo), ['photo' => photoWithGps()], ['Accept' => 'application/json'])
                ->assertCreated();

            // Each row is in its own database, and each key under its own
            // prefix: neither municipality can see or address the other's.
            app(TenantManager::class)->run($tenantOne, function () use ($tenantOne): void {
                $media = Media::query()->sole();
                expect($media->storage_prefix)->toStartWith("tenants/{$tenantOne->tenant_key}/");
            });

            app(TenantManager::class)->run($tenantTwo, function () use ($tenantTwo): void {
                $media = Media::query()->sole();
                expect($media->storage_prefix)->toStartWith("tenants/{$tenantTwo->tenant_key}/");
            });
        }, $second);
    }, $first);
});
