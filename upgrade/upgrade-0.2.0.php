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

use Quince\Contacts\Settings\People;

/**
 * 0.2.0 — stanowisko schodzi z pola PrestaShopa do naszej tabeli.
 *
 * PrestaShop 9.2 beta nie potrafi zapisać pola językowego na encji, której
 * tabela językowa nie ma `id_shop`; kontakt takiej kolumny nie ma, więc próba
 * zapisania stanowiska w karcie kontaktu kończyła się stroną błędu. Danych nie
 * przenosimy, bo żadne się nie zapisały.
 *
 * @param Module $module
 */
function upgrade_module_0_2_0($module): bool
{
    $module->removeJobTitleField();

    return (new People())->installTables();
}
