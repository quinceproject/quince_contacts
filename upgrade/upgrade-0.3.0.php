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
 * 0.3.0: „Zapytaj specjalistę" na karcie produktu.
 *
 * Moduł pokazywał ludzi wyłącznie w panelu; teraz dokłada przy produkcie kartę
 * osoby z jego działu i formularz zapytania. Sklep, który ma moduł już
 * zainstalowany, dostaje tu brakującą rejestrację hooka, bo instalator zapisuje
 * ją tylko przy pierwszej instalacji.
 *
 * @param Module $module
 */
function upgrade_module_0_3_0($module): bool
{
    // Hook własny musi istnieć w spisie, zanim moduł się do niego zapisze.
    if (!Hook::getIdByName('displayQuinceExpert')) {
        $hook = new Hook();
        $hook->name = 'displayQuinceExpert';
        $hook->title = 'Quince: ask an expert';
        $hook->description = 'Product page: the specialist card and the enquiry drawer.';
        $hook->position = true;

        if (!$hook->add()) {
            return false;
        }
    }

    // Podczas aktualizacji egzemplarz modułu bywa bez identyfikatora, a wtedy
    // `registerHook()` cicho nic nie robi i zwraca prawdę.
    if ((int) $module->id === 0) {
        $module->id = (int) Module::getModuleIdByName('quince_contacts');
    }

    return (bool) $module->registerHook('displayQuinceExpert');
}
