<?php

declare(strict_types=1);

namespace App\Modules\Demo\Data;

use App\Modules\Geography\Enums\LocalLevelType;
use Ramsey\Uuid\Uuid;

/**
 * The demonstration dataset, as data (docs/13).
 *
 * Four invented local levels, one per type, each inside a real district. The
 * whole set is defined here so that the mockups, the seeder and any screenshot
 * tell the same story, and so that retiring the demonstration means deleting
 * this module rather than hunting for fixtures.
 *
 * Everything is deterministic. Identifiers come from UUIDv5 over a fixed
 * namespace, and names are chosen by index rather than at random, so running
 * the seeder twice produces byte-identical data and yesterday's screenshots
 * still match today's database.
 */
final class DemoDataset
{
    /** The local level every ward screen and screenshot uses. */
    public const PRIMARY = 'koshara';

    /** Namespace for every deterministic identifier in the demonstration. */
    private const UUID_NAMESPACE = 'a1f0c2e4-5b6d-4e8a-9c3f-7d2b8e1a4c60';

    /** A deterministic id: same key, same uuid, every run and every machine. */
    public static function id(string ...$parts): string
    {
        return Uuid::uuid5(self::UUID_NAMESPACE, implode(':', $parts))->toString();
    }

    /** @return list<DemoLocalLevel> */
    public static function localLevels(): array
    {
        return [
            new DemoLocalLevel(
                key: 'himtara',
                nameNe: 'हिमतारा महानगरपालिका',
                nameEn: 'Himtara Metropolitan City',
                type: LocalLevelType::MetropolitanCity,
                districtSlug: 'kathmandu',
                districtNameNe: 'काठमाडौं',
                districtNameEn: 'Kathmandu',
                provinceSlug: 'bagmati',
                provinceNameNe: 'बागमती प्रदेश',
                provinceNameEn: 'Bagmati Province',
                wards: 25,
                population: 620_000,
                taglineNe: 'प्रविधि, व्यापार र संस्कृति जोडिने आधुनिक सहर।',
                taglineEn: 'A modern Himalayan hub where technology, commerce and culture converge.',
                surnames: ['अधिकारी', 'घिमिरे', 'महर्जन', 'श्रेष्ठ', 'तामाङ', 'बरैली'],
            ),
            new DemoLocalLevel(
                key: 'koshara',
                nameNe: 'कोशारा उपमहानगरपालिका',
                nameEn: 'Koshara Sub-Metropolitan City',
                type: LocalLevelType::SubMetropolitanCity,
                districtSlug: 'sunsari',
                districtNameNe: 'सुनसरी',
                districtNameEn: 'Sunsari',
                provinceSlug: 'koshi',
                provinceNameNe: 'कोशी प्रदेश',
                provinceNameEn: 'Koshi Province',
                wards: 20,
                population: 265_000,
                taglineNe: 'कृषि र उद्यमलाई जोड्ने पूर्वी व्यापारिक केन्द्र।',
                taglineEn: 'An eastern trade and industrial centre connecting agriculture with enterprise.',
                surnames: ['राई', 'लिम्बू', 'तामाङ', 'श्रेष्ठ', 'बरैली', 'मण्डल'],
            ),
            new DemoLocalLevel(
                key: 'sonapur',
                nameNe: 'सोनापुर नगरपालिका',
                nameEn: 'Sonapur Municipality',
                type: LocalLevelType::Municipality,
                districtSlug: 'rautahat',
                districtNameNe: 'रौतहट',
                districtNameEn: 'Rautahat',
                provinceSlug: 'madhesh',
                provinceNameNe: 'मधेश प्रदेश',
                provinceNameEn: 'Madhesh Province',
                wards: 11,
                population: 82_000,
                taglineNe: 'ग्रामीण उत्पादन र स्थानीय व्यापार जोड्ने बजार सहर।',
                taglineEn: 'A market town connecting rural production with local commerce.',
                surnames: ['यादव', 'साह', 'मण्डल', 'ठाकुर', 'चौधरी', 'राउत'],
            ),
            new DemoLocalLevel(
                key: 'sainli',
                nameNe: 'साइँली गाउँपालिका',
                nameEn: 'Sainli Rural Municipality',
                type: LocalLevelType::RuralMunicipality,
                districtSlug: 'baitadi',
                districtNameNe: 'बैतडी',
                districtNameEn: 'Baitadi',
                provinceSlug: 'sudurpashchim',
                provinceNameNe: 'सुदूरपश्चिम प्रदेश',
                provinceNameEn: 'Sudurpashchim Province',
                wards: 7,
                population: 21_500,
                taglineNe: 'कृषि, वन र स्थानीय संस्कृतिमा आधारित पहाडी समुदाय।',
                taglineEn: 'A mountain community built around agriculture, forests and local culture.',
                surnames: ['बोहरा', 'जोशी', 'भट्ट', 'धामी', 'अवस्थी', 'बरैली'],
            ),
        ];
    }

    public static function primary(): DemoLocalLevel
    {
        foreach (self::localLevels() as $localLevel) {
            if ($localLevel->key === self::PRIMARY) {
                return $localLevel;
            }
        }

        throw new \LogicException('The primary demonstration local level is missing from the dataset.');
    }

    /**
     * Given names, paired with a local level's regional surnames by index.
     *
     * None of these combinations is the name of a Nepali public figure — that
     * is the whole requirement (docs/13 §3.1). Given names that belong to
     * well-known politicians are deliberately absent, and the first version of
     * the mockups shipped with one before it was caught.
     *
     * @return list<array{0: string, 1: string}> Devanagari, romanised
     */
    public static function givenNames(): array
    {
        return [
            ['हेमन्त', 'Hemanta'],
            ['सुनिता', 'Sunita'],
            ['दीपल', 'Dipal'],
            ['मञ्जु', 'Manju'],
            ['चेतन', 'Chetan'],
            ['निर्मला', 'Nirmala'],
            ['एकराज', 'Ekraj'],
            ['फूलमाया', 'Fulmaya'],
            ['तिलक', 'Tilak'],
            ['सरिता', 'Sarita'],
            ['बसन्त', 'Basanta'],
            ['अनुपा', 'Anupa'],
            ['जीवन', 'Jeevan'],
            ['सन्ध्या', 'Sandhya'],
            ['प्रेमकुमार', 'Premkumar'],
            ['शोभा', 'Shobha'],
            ['दिलिप', 'Dilip'],
            ['हरिमाया', 'Harimaya'],
            ['नरेश', 'Naresh'],
            ['पवित्रा', 'Pavitra'],
        ];
    }

    /**
     * Romanisations for the regional surnames, so a person has a usable slug
     * and an English name as well as a Nepali one.
     *
     * @return array<string, string>
     */
    public static function surnameRomanisations(): array
    {
        return [
            'अधिकारी' => 'Adhikari',
            'घिमिरे' => 'Ghimire',
            'महर्जन' => 'Maharjan',
            'श्रेष्ठ' => 'Shrestha',
            'तामाङ' => 'Tamang',
            'बरैली' => 'Baraili',
            'राई' => 'Rai',
            'लिम्बू' => 'Limbu',
            'मण्डल' => 'Mandal',
            'यादव' => 'Yadav',
            'साह' => 'Sah',
            'ठाकुर' => 'Thakur',
            'चौधरी' => 'Chaudhary',
            'राउत' => 'Raut',
            'बोहरा' => 'Bohara',
            'जोशी' => 'Joshi',
            'भट्ट' => 'Bhatta',
            'धामी' => 'Dhami',
            'अवस्थी' => 'Awasthi',
        ];
    }

    /**
     * Invented parties. No real party name appears anywhere in the platform's
     * fixtures or demonstration data (docs/13 §3.2): a real party's name beside
     * an invented promise is the same problem as a real politician's name
     * beside an invented record.
     *
     * There are five so that a ward's seats can be spread across them, plus
     * independents, without any one looking dominant.
     *
     * @return list<array{key: string, ne: string, en: string, abbr: string}>
     */
    public static function parties(): array
    {
        return [
            ['key' => 'udaharan-dal', 'ne' => 'उदाहरण दल', 'en' => 'Example Party', 'abbr' => 'EP'],
            ['key' => 'namuna-party', 'ne' => 'नमूना पार्टी', 'en' => 'Sample Party', 'abbr' => 'SP'],
            ['key' => 'parikshan-morcha', 'ne' => 'परीक्षण मोर्चा', 'en' => 'Test Front', 'abbr' => 'TF'],
            ['key' => 'kalpanik-gathbandhan', 'ne' => 'काल्पनिक गठबन्धन', 'en' => 'Fictional Alliance', 'abbr' => 'FA'],
            ['key' => 'drishtanta-samuha', 'ne' => 'दृष्टान्त समूह', 'en' => 'Illustration Group', 'abbr' => 'IG'],
        ];
    }
}
