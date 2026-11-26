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

    return $ok && quince_contacts_0_7_0_hook($module, 'displayBeforeBodyClosingTag');
}

/**
 * Podpięcie hooka wpisem do `hook_module`, bez `registerHook()`.
 *
 * `registerHook()` sprawdza, czy klasa modułu ma metodę hooka. W trakcie
 * aktualizacji PrestaShop trzyma w pamięci jeszcze STARĄ klasę modułu, bez
 * nowej metody, więc w trybie deweloperskim rzuca wyjątek i aktualizacja
 * staje (0.7.0 na sklepie z włączonym trybem deweloperskim). Wpis robimy więc
 * tak jak rdzeń w `Hook::registerHook()`: każdy sklep, pozycja na końcu,
 * bez dubla, gdy wpis już jest.
 *
 * @param Module $module
 */
function quince_contacts_0_7_0_hook($module, string $nazwa): bool
{
    $idHook = (int) Hook::getIdByName($nazwa, false);

    if ($idHook <= 0 || (int) $module->id <= 0) {
        return false;
    }

    $ok = true;

    foreach (Shop::getCompleteListOfShopsID() as $idShop) {
        $jest = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'hook_module`
             WHERE id_module = ' . (int) $module->id . ' AND id_hook = ' . $idHook . ' AND id_shop = ' . (int) $idShop
        );

        if ($jest > 0) {
            continue;
        }

        $pozycja = (int) Db::getInstance()->getValue(
            'SELECT MAX(position) FROM `' . _DB_PREFIX_ . 'hook_module`
             WHERE id_hook = ' . $idHook . ' AND id_shop = ' . (int) $idShop
        );

        $ok = $ok && Db::getInstance()->insert('hook_module', [
            'id_module' => (int) $module->id,
            'id_hook' => $idHook,
            'id_shop' => (int) $idShop,
            'position' => $pozycja + 1,
        ]);
    }

    return $ok;
}
