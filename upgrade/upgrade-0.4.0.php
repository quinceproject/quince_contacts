<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Contacts\Settings\MessageLog;

/**
 * 0.4.0: log zapytań do specjalisty, pod odpytywanie Admin API.
 *
 * Instalator zakłada tabelę tylko przy pierwszej instalacji; sklep, który
 * moduł ma już zainstalowany, dostaje ją tutaj.
 *
 * @param Module $module
 */
function upgrade_module_0_4_0($module): bool
{
    return (new MessageLog())->installTable();
}
