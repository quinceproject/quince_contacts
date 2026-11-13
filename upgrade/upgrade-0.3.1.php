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

use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyType;

/**
 * 0.3.1: telefon faktycznie w Admin API.
 *
 * `associatedApis` miało od instalacji wartość `['contact']`, czyli nazwę encji,
 * nie szablon trasy. Natywny most z extra properties do API rozpoznaje tylko
 * prawdziwe `uriTemplate` (`/contacts`, `/contacts/{contactId}`), więc pole
 * nigdy nie wychodziło w odpowiedzi, mimo że miało. `registerExtraProperty()`
 * aktualizuje istniejącą definicję, więc ponowne wywołanie z poprawioną
 * wartością naprawia sklepy, które moduł mają już zainstalowany.
 *
 * @param Module $module
 */
function upgrade_module_0_3_1($module): bool
{
    return $module->registerExtraProperty(new ExtraPropertyDefinition(
        entityName: 'contact',
        propertyName: Quince_Contacts::FIELD_PHONE,
        type: ExtraPropertyType::STRING,
        scope: ExtraPropertyScope::COMMON,
        size: 64,
        displayFront: true,
        associatedForms: ['contact'],
        associatedGrids: ['contact'],
        associatedApis: ['/contacts', '/contacts/{contactId}'],
        labelWording: 'Phone',
        labelDomain: 'Modules.Quincecontacts.Admin'
    ));
}
