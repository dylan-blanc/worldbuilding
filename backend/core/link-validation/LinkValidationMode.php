<?php

declare(strict_types=1);

/**
 * Selects the shared safe-link validation depth.
 * CmsContentValidator uses LOCAL for draft saves and REMOTE for publication, while LinkController uses REMOTE.
 */
enum LinkValidationMode: string
{
    case LOCAL = "local";
    case REMOTE = "remote";
}
