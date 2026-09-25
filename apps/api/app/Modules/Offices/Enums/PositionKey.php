<?php

declare(strict_types=1);

namespace App\Modules\Offices\Enums;

/**
 * The ten local-level positions (docs/02 §3.2, §4.1, docs/05 §5.1).
 *
 * Urban and rural local levels use different names for the same two offices —
 * mayor/deputy mayor against chairperson/vice-chairperson — so they are separate
 * keys with separate applicable local level types rather than one key with a
 * label that changes. Ward positions are shared by all four types.
 *
 * Appointed civil servants (the chief administrative officer, ward secretaries)
 * are deliberately absent: they are not positions anyone is elected to, and the
 * platform must never attribute an elected representative's words to them or
 * the reverse (docs/02 §3.2).
 */
enum PositionKey: string
{
    case Mayor = 'mayor';
    case DeputyMayor = 'deputy_mayor';
    case Chairperson = 'chairperson';
    case ViceChairperson = 'vice_chairperson';
    case WardChair = 'ward_chair';
    case WardMemberWoman = 'ward_member_woman';
    case WardMemberDalitWoman = 'ward_member_dalit_woman';
    case WardMemberOpen = 'ward_member_open';
    case ExecutiveMemberWoman = 'executive_member_woman';
    case ExecutiveMemberDalitMinority = 'executive_member_dalit_minority';

    /** The seven positions a voter fills directly (docs/02 §4.1). */
    public function isDirectlyElected(): bool
    {
        return ! in_array($this, [
            self::ExecutiveMemberWoman,
            self::ExecutiveMemberDalitMinority,
        ], true);
    }

    public function isWardLevel(): bool
    {
        return in_array($this, [
            self::WardChair,
            self::WardMemberWoman,
            self::WardMemberDalitWoman,
            self::WardMemberOpen,
        ], true);
    }
}
