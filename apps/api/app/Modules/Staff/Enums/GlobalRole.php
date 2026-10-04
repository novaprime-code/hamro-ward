<?php

declare(strict_types=1);

namespace App\Modules\Staff\Enums;

/**
 * Authority that is not tied to a municipality (docs/12 §11.5). There is one,
 * and it implies every tenant (D-032).
 */
enum GlobalRole: string
{
    case OperatorAdmin = 'operator_admin';
}
