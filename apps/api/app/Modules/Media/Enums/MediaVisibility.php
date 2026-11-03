<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

/**
 * Who may fetch a photograph (FR-MED-03).
 *
 * Private is the only state anything can create. A photograph becomes public
 * when a moderator approves the report it belongs to, and that act does not
 * exist yet (HW-E14) — so for now every photograph in every municipality is
 * private, which is the right default to have been stuck with.
 */
enum MediaVisibility: string
{
    case Private = 'private';
    case Public = 'public';
}
