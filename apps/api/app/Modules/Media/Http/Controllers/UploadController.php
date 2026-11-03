<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Controllers;

use App\Modules\Accounts\Models\User;
use App\Modules\Accounts\Support\Turnstile;
use App\Modules\Media\Actions\StoreUpload;
use App\Modules\Media\Exceptions\UploadRefused;
use App\Modules\Media\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/uploads/{province}/{district}/{local_level}
 * (HW-E12-F01-T01; docs/06 §7).
 *
 * The municipality is in the URL rather than in the body, so ResolveTenant
 * opens the right database before this runs and the object key is under the
 * right prefix by construction. The architecture note sketched a bare
 * `/uploads`; a photograph has to belong to exactly one municipality from the
 * moment it exists, and putting that in the path is how every other
 * tenant-scoped route in this API already says so.
 *
 * Who may upload: a signed-in citizen whose address is confirmed — the same
 * bar as reporting (`User::canReport`), because an upload exists only to be
 * attached to a report. An endpoint that stores files for anyone who asks is
 * an endpoint that stores anything anyone asks it to.
 */
final class UploadController
{
    public function __invoke(Request $request, StoreUpload $store, Turnstile $turnstile): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 404);

        if (! $user->canReport()) {
            /*
             * 403 and not 422: nothing about the request is wrong. The
             * account is not ready, and the screen's job is to say which part
             * — which it can, because it already knows from /api/v1/me.
             */
            return response()->json([
                'message' => 'Confirm your email address before adding photos.',
                'email_verification_required' => true,
            ], 403)->header('Cache-Control', 'no-store');
        }

        $request->validate(['photo' => ['required', 'file']]);

        if (! $turnstile->verify($request->string('turnstile_token')->toString(), $request->ip())) {
            throw ValidationException::withMessages([
                'turnstile_token' => 'That check did not pass. Please try again.',
            ]);
        }

        $photo = $request->file('photo');

        if (! $photo instanceof UploadedFile || ! $photo->isValid()) {
            throw ValidationException::withMessages([
                'photo' => UploadRefused::unreadable()->getMessage(),
            ]);
        }

        try {
            $media = $store->handle($photo, $user->id);
        } catch (UploadRefused $refusal) {
            // The action's own words: they were written for the person who
            // has to do something differently.
            throw ValidationException::withMessages(['photo' => $refusal->getMessage()]);
        }

        return response()->json(['data' => $this->summarise($media)], 201)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Where the upload got to, so the screen can show a placeholder and poll.
     *
     * No URL: the photograph is private and stays private until the report is
     * approved, so there is nothing to link to yet (FR-MED-03).
     *
     * @return array<string, mixed>
     */
    private function summarise(Media $media): array
    {
        return [
            'id' => $media->id,
            'status' => $media->status->value,
            'width' => $media->width,
            'height' => $media->height,
            'byte_size' => $media->byte_size,
        ];
    }
}
