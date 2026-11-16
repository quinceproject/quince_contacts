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

/**
 * 0.5.0: telefon w formularzu „Napisz do nas”.
 *
 * Kolumna telefonu w logu zapytań, który czyta Admin API. Zapytaniem wprost,
 * bez metod klas modułu: w trakcie aktualizacji w pamięci bywa jeszcze ich
 * stara wersja. Ustawienia pól i potwierdzenia nie potrzebują nic: dopóki sklep
 * ich nie zapisze, obowiązują wartości domyślne (oba pola widoczne,
 * potwierdzenie wyłączone).
 *
 * @param Module $module
 */
function upgrade_module_0_5_0($module): bool
{
    $tabela = _DB_PREFIX_ . 'quince_contact_message';

    $jest = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $tabela . '` LIKE "customer_phone"');

    if (!empty($jest)) {
        return true;
    }

    return (bool) Db::getInstance()->execute(
        'ALTER TABLE `' . $tabela . '`
         ADD COLUMN `customer_phone` VARCHAR(64) NOT NULL DEFAULT "" AFTER `customer_email`'
    );
}
