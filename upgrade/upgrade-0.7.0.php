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
 * 0.7.0: zapytanie bez produktu, z przycisku w bloku na dowolnej stronie.
 *
 * Hook okna na końcu strony i kolumny tematu i strony w logu zapytań, który
 * czyta Admin API. Kolumny zapytaniem wprost, bez metod klas modułu: w trakcie
 * aktualizacji w pamięci bywa jeszcze ich stara wersja.
 *
 * @param Module $module
 */
function upgrade_module_0_7_0($module): bool
{
    $tabela = _DB_PREFIX_ . 'quince_contact_message';
    $ok = true;

    foreach ([
        'topic' => 'VARCHAR(255) NOT NULL DEFAULT "" AFTER `product_name`',
        'page_url' => 'VARCHAR(512) NOT NULL DEFAULT "" AFTER `topic`',
    ] as $kolumna => $definicja) {
        $jest = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $tabela . '` LIKE "' . $kolumna . '"');

        if (empty($jest)) {
            $ok = $ok && (bool) Db::getInstance()->execute(
                'ALTER TABLE `' . $tabela . '` ADD COLUMN `' . $kolumna . '` ' . $definicja
            );
        }
    }

    return $ok && $module->registerHook('displayBeforeBodyClosingTag');
}
