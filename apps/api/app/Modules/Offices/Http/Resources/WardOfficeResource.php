<?php

declare(strict_types=1);

namespace App\Modules\Offices\Http\Resources;

use App\Modules\Offices\Models\WardOffice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The ward office's contact details (FR-GEO-08).
 *
 * Fields are nullable all the way down on purpose: a partially known office is
 * the normal case, and an address without a phone number is still worth
 * showing. The publication rule that hides individually unsourced fields
 * arrives in HW-E04-F02-T01 and will filter this payload rather than change its
 * shape.
 *
 * @property WardOffice $resource
 */
final class WardOfficeResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'address' => ['ne' => $this->resource->address_ne, 'en' => $this->resource->address_en],
            'phone' => $this->resource->phone,
            'alternate_phone' => $this->resource->alternate_phone,
            'email' => $this->resource->email,
            'office_hours' => [
                'ne' => $this->resource->office_hours_ne,
                'en' => $this->resource->office_hours_en,
            ],
        ];
    }
}
