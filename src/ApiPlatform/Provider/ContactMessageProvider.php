<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Contacts\ApiPlatform\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Quince\Contacts\ApiPlatform\Resources\ContactMessage;
use Quince\Contacts\Settings\MessageLog;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<ContactMessage>
 */
final class ContactMessageProvider implements ProviderInterface
{
    private const PER_PAGE = 50;
    private const MAX_PER_PAGE = 200;

    public function __construct(
        private readonly MessageLog $messages,
        private readonly RequestStack $requestStack
    ) {
    }

    /**
     * @param array<string,mixed> $uriVariables
     * @param array<string,mixed> $context
     *
     * @return array<int,ContactMessage>|ContactMessage|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|ContactMessage|null
    {
        $idShop = $this->queryShopId();

        if (isset($uriVariables['id'])) {
            $row = $idShop !== null ? $this->messages->get((int) $uriVariables['id'], $idShop) : null;

            return $row !== null ? $this->map($row) : null;
        }

        if ($idShop === null) {
            return [];
        }

        $query = $this->requestStack->getCurrentRequest()?->query->all() ?? [];

        $sinceId = max(0, (int) ($query['sinceId'] ?? 0));
        $limit = min(self::MAX_PER_PAGE, max(1, (int) ($query['limit'] ?? self::PER_PAGE)));
        $page = max(1, (int) ($query['page'] ?? 1));

        $rows = $this->messages->listSinceId($idShop, $sinceId, $limit, ($page - 1) * $limit);

        return array_map(fn (array $row): ContactMessage => $this->map($row), $rows);
    }

    private function queryShopId(): ?int
    {
        $shopId = $this->requestStack->getCurrentRequest()?->query->get('shopId');

        return $shopId !== null && $shopId !== '' ? (int) $shopId : null;
    }

    /** @param array<string,mixed> $row */
    private function map(array $row): ContactMessage
    {
        $wynik = new ContactMessage();
        $wynik->id = (int) $row['id_quince_contact_message'];
        $wynik->shopId = (int) $row['id_shop'];
        $wynik->contactId = (int) $row['id_contact'];
        $wynik->expertName = (string) $row['expert_name'];
        $wynik->expertEmail = (string) $row['expert_email'];
        $wynik->productId = (int) $row['id_product'];
        $wynik->productName = (string) $row['product_name'];
        $wynik->customerId = (int) $row['id_customer'];
        $wynik->customerName = (string) $row['customer_name'];
        $wynik->customerEmail = (string) $row['customer_email'];
        $wynik->message = (string) $row['message'];
        $wynik->mailSent = (bool) $row['mail_sent'];
        $wynik->createdAt = str_replace(' ', 'T', (string) $row['date_add']);

        return $wynik;
    }
}
