<?php

declare(strict_types=1);

namespace App\Modules\Staff\Support;

use App\Modules\Staff\Models\StaffUser;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time codes for staff (docs/12 §11.5, HW-E13-F01-T03).
 *
 * A code is accepted once: a code read over someone's shoulder, or replayed
 * from a captured request, fails inside its own 30-second window. Recovery
 * codes are stored as SHA-256 hashes inside the encrypted column, shown in
 * plain text exactly once, and each works once.
 */
final class TwoFactor
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function newSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUrl(StaffUser $staff, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl('Hamro Ward staff', $staff->email, $secret);
    }

    public function qrSvg(string $otpauthUrl): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return $writer->writeString($otpauthUrl);
    }

    public function verify(StaffUser $staff, string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1 || ! $this->google2fa->verifyKey($secret, $code, 1)) {
            return false;
        }

        // Cache::add is atomic: the first use stores the key, any replay finds it.
        return Cache::add("staff-totp-used:{$staff->id}:{$code}", true, now()->addSeconds(120));
    }

    /**
     * Fresh recovery codes: the plain codes to show once, and what to store.
     *
     * @return array{plain: list<string>, stored: string}
     */
    public function newRecoveryCodes(): array
    {
        $plain = [];

        for ($i = 0; $i < (int) config('staff.recovery_codes'); $i++) {
            $plain[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        return [
            'plain' => $plain,
            'stored' => json_encode(array_map(fn (string $code): string => hash('sha256', $code), $plain), JSON_THROW_ON_ERROR),
        ];
    }

    /** Uses up a recovery code; false when it is not one of this person's unused codes. */
    public function consumeRecoveryCode(StaffUser $staff, string $code): bool
    {
        /** @var list<string> $hashes */
        $hashes = json_decode((string) $staff->getAttribute('two_factor_recovery_codes'), true) ?: [];
        $hash = hash('sha256', Str::lower(trim($code)));
        $index = array_search($hash, $hashes, true);

        if ($index === false) {
            return false;
        }

        unset($hashes[$index]);
        $staff->forceFill(['two_factor_recovery_codes' => json_encode(array_values($hashes), JSON_THROW_ON_ERROR)])->save();

        return true;
    }
}
