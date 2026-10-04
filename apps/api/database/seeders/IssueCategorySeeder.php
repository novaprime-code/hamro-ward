<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Issues\Models\IssueCategory;
use Illuminate\Database\Seeder;

/**
 * What a citizen can report about (docs/05 §6.1). Icons are lucide-react
 * names. Nepali labels need native review.
 */
final class IssueCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['roads', 'सडक', 'Roads', 'construction'],
            ['drainage', 'ढल तथा निकास', 'Drainage', 'waves'],
            ['drinking_water', 'खानेपानी', 'Drinking water', 'droplets'],
            ['waste', 'फोहोरमैला', 'Waste', 'trash-2'],
            ['streetlights', 'सडक बत्ती', 'Streetlights', 'lamp'],
            ['electricity', 'बिजुली', 'Electricity', 'zap'],
            ['schools', 'विद्यालय', 'Schools', 'school'],
            ['health', 'स्वास्थ्य', 'Health', 'heart-pulse'],
            ['transport', 'यातायात', 'Transport', 'bus'],
            ['public_safety', 'सार्वजनिक सुरक्षा', 'Public safety', 'shield'],
            ['environment', 'वातावरण', 'Environment', 'trees'],
            ['agriculture', 'कृषि', 'Agriculture', 'wheat'],
            ['public_services', 'सार्वजनिक सेवा', 'Public services', 'landmark'],
            ['other', 'अन्य', 'Other', 'circle-help'],
        ];

        foreach ($categories as $index => [$key, $labelNe, $labelEn, $icon]) {
            IssueCategory::query()->updateOrCreate(
                ['key' => $key],
                [
                    'label_ne' => $labelNe,
                    'label_en' => $labelEn,
                    'icon' => $icon,
                    'sort' => ($index + 1) * 10,
                    'is_active' => true,
                ],
            );
        }
    }
}
