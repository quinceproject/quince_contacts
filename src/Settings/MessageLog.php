<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Contacts\Settings;

use Db;
use DbQuery;

/**
 * Log zapytań do specjalisty, do odczytu przez Admin API.
 *
 * Wiadomość idzie mailem i zdarzeniem `contact.enquiry` od razu. To jest
 * push, nikną po doręczeniu albo po wyczerpaniu prób. Ten log jest osobnym,
 * trwałym zapisem pod odpytywanie: CEP woła go cyklicznie i pyta o wiersze
 * nowsze niż ostatnio widziany `id`, więc nic nie trzeba tu oznaczać jako
 * „odebrane". To CEP pamięta, gdzie skończył.
 *
 * Nazwa eksperta i adres idą zapisane w chwili wysyłki, nie dociągane z
 * kontaktu na żywo: kontakt mógł się od tamtej pory zmienić albo zniknąć,
 * a log ma pokazywać, do kogo wiadomość trafiła naprawdę.
 */
final class MessageLog
{
    public const TABLE = 'quince_contact_message';

    public function installTable(): bool
    {
        return (bool) Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
                `id_quince_contact_message` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `id_contact` INT(11) UNSIGNED NOT NULL,
                `expert_name` VARCHAR(128) NOT NULL DEFAULT "",
                `expert_email` VARCHAR(255) NOT NULL DEFAULT "",
                `id_product` INT(11) UNSIGNED NOT NULL,
                `product_name` VARCHAR(255) NOT NULL DEFAULT "",
                `id_customer` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `customer_name` VARCHAR(128) NOT NULL DEFAULT "",
                `customer_email` VARCHAR(255) NOT NULL DEFAULT "",
                `message` TEXT NOT NULL,
                `mail_sent` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_quince_contact_message`),
                INDEX `idx_do_odpytania` (`id_shop`, `id_quince_contact_message`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    public function dropTable(): bool
    {
        return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    /** @param array<string,mixed> $osoba karta eksperta z People::expertForCategory() */
    public function record(
        int $idShop,
        array $osoba,
        int $idProduct,
        string $productName,
        int $idCustomer,
        string $customerName,
        string $customerEmail,
        string $message,
        bool $mailSent
    ): bool {
        return Db::getInstance()->insert(self::TABLE, [
            'id_shop' => $idShop,
            'id_contact' => (int) $osoba['id_contact'],
            'expert_name' => pSQL((string) $osoba['name']),
            'expert_email' => pSQL((string) $osoba['email']),
            'id_product' => $idProduct,
            'product_name' => pSQL($productName),
            'id_customer' => $idCustomer,
            'customer_name' => pSQL($customerName),
            'customer_email' => pSQL($customerEmail),
            'message' => pSQL($message, true),
            'mail_sent' => $mailSent ? 1 : 0,
            'date_add' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function get(int $id, int $idShop): ?array
    {
        $query = (new DbQuery())
            ->select('*')
            ->from(self::TABLE)
            ->where('id_quince_contact_message = ' . $id)
            ->where('id_shop = ' . $idShop);

        $row = Db::getInstance()->getRow($query);

        return is_array($row) ? $row : null;
    }

    /**
     * Wiadomości nowsze niż `$sinceId`, od najstarszej do najnowszej, tak,
     * żeby `id` ostatniego wiersza na stronie było `sinceId` następnego wołania.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listSinceId(int $idShop, int $sinceId, int $limit, int $offset): array
    {
        $query = (new DbQuery())
            ->select('*')
            ->from(self::TABLE)
            ->where('id_shop = ' . $idShop)
            ->where('id_quince_contact_message > ' . $sinceId)
            ->orderBy('id_quince_contact_message ASC')
            ->limit($limit, $offset);

        $rows = Db::getInstance()->executeS($query);

        return is_array($rows) ? $rows : [];
    }
}
