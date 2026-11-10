<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Contacts\ApiPlatform\Resources;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Quince\Contacts\ApiPlatform\Provider\ContactMessageProvider;

/**
 * Zapytanie do specjalisty, złożone z karty produktu, w Admin API.
 *
 * Tylko do odczytu: zapis idzie wyłącznie przez formularz sklepu. Wiadomość
 * i tak wychodzi mailem i zdarzeniem `contact.enquiry` od razu po złożeniu —
 * ten zasób jest dla odbiorcy, który woli cyklicznie odpytywać, niż odbierać
 * webhook.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/quince-contacts/messages',
            provider: ContactMessageProvider::class,
            extraProperties: ['scopes' => ['quince_contacts_read']],
            openapiContext: [
                'summary' => 'Enquiries to a specialist, sent since a given one',
                'description' => 'Filter with `sinceId` and `shopId`. Page with `page` and `limit` (50 by default, '
                    . '200 at most), ordered oldest-first. Take the `id` of the last item on the page as `sinceId` '
                    . 'for the next call.',
            ],
        ),
        new Get(
            uriTemplate: '/quince-contacts/messages/{id}',
            provider: ContactMessageProvider::class,
            extraProperties: ['scopes' => ['quince_contacts_read']],
            openapiContext: ['summary' => 'One enquiry by its id'],
        ),
    ],
)]
class ContactMessage
{
    #[ApiProperty(identifier: true)]
    public int $id = 0;

    public int $shopId = 0;

    public int $contactId = 0;

    /** Imię i adres eksperta w chwili wysyłki — nie dociągane z kontaktu na żywo. */
    public string $expertName = '';

    public string $expertEmail = '';

    public int $productId = 0;

    public string $productName = '';

    /** Zero znaczy gościa. */
    public int $customerId = 0;

    public string $customerName = '';

    public string $customerEmail = '';

    public string $message = '';

    /** Czy mail do eksperta faktycznie wyszedł. */
    public bool $mailSent = false;

    public string $createdAt = '';
}
