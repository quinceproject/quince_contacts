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
use Quince\Contacts\Settings\People;

/**
 * Wiadomość do specjalisty z karty produktu.
 *
 * Formularza nie sprawdzamy tutaj poza tym, co dotyczy samej treści. Znacznik
 * strony, pułapkę, czas wypełnienia i limit powtórzeń bierze na siebie strażnik
 * silnika — odpala się na `actionFrontControllerInitAfter` dla każdego żądania
 * niosącego jego pola i przerywa, zanim ten kod się zacznie.
 *
 * Wiadomość idzie trzema drogami naraz i to jest celowe: mailem do osoby, żeby
 * dostała ją nawet wtedy, gdy sklep nie ma podpiętego niczego, zdarzeniem
 * `contact.enquiry` do odbiorcy ustawionego w silniku (push, dla tego, kto wolałby
 * webhook), oraz wierszem w logu modułu, pod odpytywanie Admin API przez tego,
 * kto wolałby cykliczne wołanie (`GET /quince-contacts/messages`).
 */
class Quince_ContactsMessageModuleFrontController extends ModuleFrontController
{
    /** @var bool nie renderujemy strony, tylko JSON */
    public $ajax = true;

    public function postProcess(): void
    {
        $this->ajax = true;
    }

    public function displayAjax(): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->wyslij());
        exit;
    }

    /** @return array<string,mixed> */
    private function wyslij(): array
    {
        $email = trim((string) Tools::getValue('email'));
        $tresc = trim((string) Tools::getValue('message'));
        $imie = trim((string) Tools::getValue('name'));
        $idProduct = (int) Tools::getValue('id_product');

        if ($email === '' || !Validate::isEmail($email) || $tresc === '') {
            return ['ok' => false, 'error' => 'form'];
        }

        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;
        $produkt = new Product($idProduct, false, $idLang, $idShop);

        if (!$produkt->id) {
            return ['ok' => false, 'error' => 'product'];
        }

        $osoba = (new People())->expertForCategory((int) $produkt->id_category_default, $idLang);

        if ($osoba === null || !Validate::isEmail($osoba['email'])) {
            return ['ok' => false, 'error' => 'expert'];
        }

        $wyslany = $this->mail($osoba, $email, $imie, $tresc, $produkt);
        $this->zdarzenie($osoba, $email, $imie, $tresc, $produkt);
        $this->zapisz($osoba, $email, $imie, $tresc, $produkt, $wyslany);

        return ['ok' => true, 'mail' => $wyslany, 'expert' => $osoba['name']];
    }

    /**
     * Mail do specjalisty.
     *
     * Nadawcą jest adres sklepu, bo tylko z niego wolno wysyłać; adres klienta
     * idzie jako zwrotny, więc handlowiec odpowiada wprost do niego, nie szukając
     * adresu w treści.
     *
     * @param array<string,mixed> $osoba
     */
    private function mail(array $osoba, string $email, string $imie, string $tresc, Product $produkt): bool
    {
        $nazwaSklepu = (string) Configuration::get('PS_SHOP_NAME');

        return (bool) Mail::Send(
            $this->jezykSzablonu((int) $this->context->language->id, 'quince_enquiry'),
            'quince_enquiry',
            $nazwaSklepu . ': ' . $produkt->name,
            [
                '{shop_name}' => $nazwaSklepu,
                '{expert_name}' => (string) $osoba['name'],
                '{customer_name}' => $imie,
                '{customer_email}' => $email,
                '{message}' => nl2br(htmlspecialchars($tresc, ENT_QUOTES, 'UTF-8')),
                '{product_name}' => (string) $produkt->name,
                '{product_url}' => $this->context->link->getProductLink($produkt),
            ],
            (string) $osoba['email'],
            (string) $osoba['name'],
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'quince_contacts/mails/',
            false,
            (int) $this->context->shop->id,
            null,
            $email,
            $imie
        );
    }

    /**
     * Język, w którym mamy szablon wiadomości.
     *
     * PrestaShop szuka pliku po skrócie języka, a brakującego szablonu nie
     * zastępuje niczym: wiadomość po prostu nie wychodzi, a w dzienniku sklepu
     * zostaje „Brakuje szablonu e-mail". Sklep w języku, dla którego szablonu
     * jeszcze nie ma, dostaje więc wiadomość w tym, który jest, zamiast nie
     * dostawać jej wcale.
     */
    private function jezykSzablonu(int $idLang, string $szablon): int
    {
        $katalog = _PS_MODULE_DIR_ . 'quince_contacts/mails/';
        $iso = (string) Language::getIsoById($idLang);

        if ($iso !== '' && is_file($katalog . $iso . '/' . $szablon . '.html')) {
            return $idLang;
        }

        foreach (Language::getLanguages(false) as $jezyk) {
            if (is_file($katalog . $jezyk['iso_code'] . '/' . $szablon . '.html')) {
                return (int) $jezyk['id_lang'];
            }
        }

        return $idLang;
    }

    /**
     * Zdarzenie wychodzące ze sklepu.
     *
     * Odbiorcę wskazuje sklep w module `quince_events`, w jego ekranie
     * ustawień — moduł nie zna ani adresu, ani tego, co stoi po drugiej
     * stronie. Bez ustawionego odbiorcy nie dzieje się nic, a wiadomość
     * i tak poszła mailem.
     *
     * @param array<string,mixed> $osoba
     */
    private function zdarzenie(array $osoba, string $email, string $imie, string $tresc, Product $produkt): void
    {
        $zdarzenia = Module::getInstanceByName('quince_events');

        if (!$zdarzenia || !class_exists(\Quince\Events\Event\EventDispatcher::class)) {
            return;
        }

        (new \Quince\Events\Event\EventDispatcher(new \Quince\Events\Event\EventOutbox()))->dispatch(
            new \Quince\Events\Event\ShopEvent(
                type: 'contact.enquiry',
                data: [
                    'sender' => [
                        // Zero znaczy gość. Sklep nie zgaduje, kto to jest —
                        // dopasowaniem do kontaktu zajmuje się odbiorca.
                        'id_customer' => (int) ($this->context->customer->id ?? 0) > 0
                            && $this->context->customer->isLogged()
                                ? (int) $this->context->customer->id
                                : 0,
                        'email' => $email,
                        'name' => $imie,
                    ],
                    'message' => $tresc,
                    'expert' => [
                        'id_contact' => (int) $osoba['id_contact'],
                        'name' => (string) $osoba['name'],
                        'email' => (string) $osoba['email'],
                    ],
                ],
                context: [
                    'product' => [
                        'id_product' => (int) $produkt->id,
                        'name' => (string) $produkt->name,
                        'reference' => (string) $produkt->reference,
                        'url' => $this->context->link->getProductLink($produkt),
                    ],
                ],
                source: 'quince_contacts'
            )
        );
    }

    /**
     * Log pod odpytywanie Admin API.
     *
     * @param array<string,mixed> $osoba
     */
    private function zapisz(array $osoba, string $email, string $imie, string $tresc, Product $produkt, bool $wyslany): void
    {
        $idCustomer = (int) ($this->context->customer->id ?? 0) > 0 && $this->context->customer->isLogged()
            ? (int) $this->context->customer->id
            : 0;

        (new MessageLog())->record(
            (int) $this->context->shop->id,
            $osoba,
            (int) $produkt->id,
            (string) $produkt->name,
            $idCustomer,
            $imie,
            $email,
            $tresc,
            $wyslany
        );
    }
}
