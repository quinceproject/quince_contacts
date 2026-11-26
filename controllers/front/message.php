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

use Quince\Contacts\Mail\MailLayout;
use Quince\Contacts\Settings\FormSettings;
use Quince\Contacts\Settings\MessageLog;
use Quince\Contacts\Settings\People;

/**
 * Wiadomość do specjalisty z karty produktu.
 *
 * Formularza nie sprawdzamy tutaj poza tym, co dotyczy samej treści. Znacznik
 * strony, pułapkę, czas wypełnienia i limit powtórzeń bierze na siebie strażnik
 * silnika. Odpala się na `actionFrontControllerInitAfter` dla każdego żądania
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
        $pola = (new FormSettings())->fields();

        $email = trim((string) Tools::getValue('email'));
        $tresc = trim((string) Tools::getValue('message'));
        // Pole schowane w ustawieniach nie bierze udziału, nawet gdy ktoś je dośle.
        $imie = $pola['name']['show'] ? mb_substr(trim((string) Tools::getValue('name')), 0, 128) : '';
        $telefon = $pola['phone']['show'] ? mb_substr(trim((string) Tools::getValue('phone')), 0, 64) : '';
        $idProduct = (int) Tools::getValue('id_product');

        if ($email === '' || !Validate::isEmail($email) || $tresc === '') {
            return ['ok' => false, 'error' => 'form'];
        }

        if (($pola['name']['required'] && $imie === '')
            || ($pola['phone']['required'] && $telefon === '')
            || ($telefon !== '' && !Validate::isPhoneNumber($telefon))) {
            return ['ok' => false, 'error' => 'form'];
        }

        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;
        $produkt = null;
        $temat = '';
        $strona = '';

        if ($idProduct > 0) {
            $produkt = new Product($idProduct, false, $idLang, $idShop);

            // Pisać można w sprawie produktu, który klient może zobaczyć w tym sklepie.
            // Produkt widoczny, ale wyłączony ze sprzedaży, przechodzi.
            if (!$produkt->id || !$produkt->active || $produkt->visibility === 'none'
                || !$produkt->isAssociatedToShop($idShop)) {
                return ['ok' => false, 'error' => 'product'];
            }

            $osoba = (new People())->expertForCategory((int) $produkt->id_category_default, $idLang);
        } else {
            // Zapytanie ogólne: przycisk w bloku na dowolnej stronie. Osobę
            // wskazuje przycisk numerem kontaktu, a bez niego dostaje je osoba
            // domyślna sklepu. Temat to napis z przycisku, strona to adres,
            // z którego klient pisał.
            $temat = $this->temat((string) Tools::getValue('topic'));
            $strona = $this->strona((string) Tools::getValue('page_url'));
            $osoba = (new People())->expert((int) Tools::getValue('id_contact'), $idLang);
        }

        if ($osoba === null || !Validate::isEmail($osoba['email'])) {
            return ['ok' => false, 'error' => 'expert'];
        }

        $wyslany = $this->mail($osoba, $email, $imie, $telefon, $tresc, $produkt, $temat, $strona);
        $this->zdarzenie($osoba, $email, $imie, $telefon, $tresc, $produkt, $temat, $strona);
        $this->zapisz($osoba, $email, $imie, $telefon, $tresc, $produkt, $wyslany, $temat, $strona);
        $this->potwierdzenie($osoba, $email, $imie, $telefon, $tresc, $produkt, $temat, $strona);

        return ['ok' => true, 'mail' => $wyslany, 'expert' => $osoba['name']];
    }

    /**
     * Temat zapytania ogólnego: napis z przycisku, zwykły tekst w jednej linii.
     * Przychodzi od przeglądarki, więc traktujemy go jak wpis klienta.
     */
    private function temat(string $tekst): string
    {
        $tekst = trim((string) preg_replace('/\s+/u', ' ', strip_tags($tekst)));

        return mb_substr($tekst, 0, 255);
    }

    /**
     * Adres strony, z której przyszło zapytanie. Tylko adres tego sklepu: obcy
     * trafiłby do maila jako odnośnik, który handlowiec kliknie w zaufaniu.
     * Obcy albo pusty daje adres strony głównej sklepu.
     */
    private function strona(string $adres): string
    {
        $adres = mb_substr(trim($adres), 0, 512);
        $host = strtolower((string) parse_url($adres, PHP_URL_HOST));
        $schemat = strtolower((string) parse_url($adres, PHP_URL_SCHEME));
        $sklep = array_filter([
            strtolower((string) Configuration::get('PS_SHOP_DOMAIN')),
            strtolower((string) Configuration::get('PS_SHOP_DOMAIN_SSL')),
            strtolower((string) $this->context->shop->domain),
            strtolower((string) $this->context->shop->domain_ssl),
        ]);

        if ($host !== '' && in_array($schemat, ['http', 'https'], true) && in_array($host, $sklep, true)) {
            return $adres;
        }

        return $this->context->link->getPageLink('index', true);
    }

    /**
     * Potwierdzenie dla klienta, gdy sklep je włączył.
     *
     * W języku, w którym klient przegląda sklep; pusta treść w tym języku daje
     * treść w języku domyślnym sklepu. Kopia do osoby, do której klient napisał,
     * idzie jako ukryta kopia, a odpowiedź klienta trafia wprost do niej.
     *
     * @param array<string,mixed> $osoba
     */
    private function potwierdzenie(array $osoba, string $email, string $imie, string $telefon, string $tresc, ?Product $produkt, string $temat = '', string $strona = ''): void
    {
        $ustawienia = new FormSettings();

        if (!$ustawienia->confirmEnabled()) {
            return;
        }

        $idLang = (int) $this->context->language->id;
        $wyglad = new MailLayout($this->context);

        // Zapytanie bez produktu: w miejsce karty produktu temat z przycisku,
        // a przycisk powrotu prowadzi na stronę, z której klient pisał.
        // Zmienne produktu w treści ustawionej przez sklep dostają temat
        // i adres tej strony, więc gotowe treści nie zostają z pustymi miejscami.
        if ($produkt === null) {
            $produktZmienne = [
                '{product_price}' => '',
                '{product_url}' => htmlspecialchars($strona, ENT_QUOTES, 'UTF-8'),
                '{product_card}' => $temat !== ''
                    // Inny napis niż przy produkcie: „Your question is about”
                    // ma po polsku tłumaczenie „Pytasz o produkt”.
                    ? $wyglad->rows($wyglad->trans('Your question', $idLang), [
                        $wyglad->trans('Topic', $idLang) => $temat,
                    ])
                    : '',
            ];
            $powrot = $wyglad->trans('Back to the page', $idLang);
        } else {
            $produktZmienne = $wyglad->product($produkt, $wyglad->trans('Your question is about', $idLang));
            $powrot = $wyglad->trans('Back to the product', $idLang);
        }

        $dane = [
            'name' => $imie,
            'email' => $email,
            'phone' => $telefon,
            'message' => $tresc,
            'product_name' => $produkt !== null ? (string) $produkt->name : $temat,
            'shop_name' => (string) Configuration::get('PS_SHOP_NAME'),
            'product_reference' => $produkt !== null ? (string) $produkt->reference : '',
            'product_price' => html_entity_decode($produktZmienne['{product_price}'], ENT_QUOTES, 'UTF-8'),
            'product_url' => html_entity_decode($produktZmienne['{product_url}'], ENT_QUOTES, 'UTF-8'),
            'expert_name' => (string) $osoba['name'],
            'expert_job' => (string) ($osoba['job'] ?? ''),
            'expert_phone' => (string) ($osoba['phone'] ?? ''),
            'expert_email' => (string) $osoba['email'],
        ];

        $szablon = $ustawienia->bodyFor($idLang);

        if (trim($szablon) === '') {
            return;
        }

        $bloki = [
            '{product_card}' => $produktZmienne['{product_card}'],
            '{expert_card}' => $wyglad->expertCard($osoba, $wyglad->trans('You will hear from', $idLang)),
            '{message_box}' => $wyglad->messageBox($tresc, $wyglad->trans('Your message', $idLang)),
            '{reply_to}' => $wyglad->rows($wyglad->trans('We will reply to', $idLang), [
                $wyglad->trans('Email', $idLang) => $email,
                $wyglad->trans('Phone', $idLang) => $telefon,
            ]),
            '{product_button}' => $wyglad->button($powrot, $dane['product_url']),
        ];

        $html = $ustawienia->render($szablon, $dane, $bloki);
        $temat = $ustawienia->renderSubject($ustawienia->subjectFor($idLang), $dane);
        $kopia = $ustawienia->copyEnabled() && Validate::isEmail((string) $osoba['email'])
            ? (string) $osoba['email']
            : null;

        Mail::Send(
            $this->jezykSzablonu($idLang, 'quince_confirmation'),
            'quince_confirmation',
            $temat !== '' ? $temat : $dane['shop_name'],
            [
                // Treść jest już złożona i escapowana w FormSettings::render().
                '{content}' => $html,
                '{content_text}' => $this->tekst($html),
            ] + $wyglad->common(),
            $email,
            $imie !== '' ? $imie : null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'quince_contacts/mails/',
            false,
            (int) $this->context->shop->id,
            $kopia,
            (string) $osoba['email'],
            (string) $osoba['name']
        );
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
    private function mail(array $osoba, string $email, string $imie, string $telefon, string $tresc, ?Product $produkt, string $temat = '', string $strona = ''): bool
    {
        $nazwaSklepu = (string) Configuration::get('PS_SHOP_NAME');
        // Zapytanie bez produktu ma własny szablon: zamiast karty produktu
        // temat z przycisku i strona, z której klient pisał.
        $szablon = $produkt !== null ? 'quince_enquiry' : 'quince_enquiry_general';
        // Szablon w języku, w którym klient pisał; etykiety bloków w tym samym.
        $idLang = $this->jezykSzablonu((int) $this->context->language->id, $szablon);
        $wyglad = new MailLayout($this->context);
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');

        if ($produkt !== null) {
            $kontekst = $wyglad->product($produkt, $wyglad->trans('Product', $idLang));
            $tytul = $nazwaSklepu . ': ' . $produkt->name;
        } else {
            $kontekst = [
                '{topic}' => $e($temat),
                '{page_url}' => $e($strona),
                '{page_card}' => $wyglad->rows($wyglad->trans('Sent from', $idLang), [
                    $wyglad->trans('Topic', $idLang) => $temat,
                    $wyglad->trans('Page', $idLang) => $strona,
                ], [
                    $wyglad->trans('Page', $idLang) => $strona,
                ]),
            ];
            $tytul = $nazwaSklepu . ': ' . ($temat !== '' ? $temat : $wyglad->trans('A question from the shop', $idLang));
        }

        return (bool) Mail::Send(
            $idLang,
            $szablon,
            $tytul,
            [
                '{expert_name}' => $e((string) $osoba['name']),
                // Mail::Send wkleja zmienne do HTML bez escapowania, a imię i adres
                // wpisuje odwiedzający: bez tego wstawiłby do maila własny odnośnik.
                '{customer_name}' => $e($imie),
                '{customer_email}' => $e($email),
                '{customer_phone}' => $e($telefon !== '' ? $telefon : '-'),
                '{message}' => nl2br($e($tresc)),
                '{message_text}' => $tresc,
                '{customer_rows}' => $wyglad->rows($wyglad->trans('Customer', $idLang), [
                    $wyglad->trans('Name', $idLang) => $imie,
                    $wyglad->trans('Email', $idLang) => $email,
                    $wyglad->trans('Phone', $idLang) => $telefon,
                ], [
                    $wyglad->trans('Email', $idLang) => 'mailto:' . $email,
                    $wyglad->trans('Phone', $idLang) => 'tel:' . preg_replace('/[^0-9+]/', '', $telefon),
                ]),
                '{message_box}' => $wyglad->messageBox($tresc, $wyglad->trans('Message', $idLang)),
            ] + $kontekst + $wyglad->common(),
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
     * Wersja tekstowa treści potwierdzenia: bez znaczników, akapity i bloki
     * w osobnych wierszach.
     */
    private function tekst(string $html): string
    {
        $html = (string) preg_replace('#<(br|/p|/div|/tr|/h1|/h2|/table)\b[^>]*>#i', "\n", $html);
        $html = (string) preg_replace('#</td>#i', ' ', $html);
        $tekst = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');

        return trim((string) preg_replace("/[ \t]*\n[\s]*\n+/", "\n\n", $tekst));
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
     * ustawień. Ten moduł nie zna adresu odbiorcy. Bez ustawionego odbiorcy nie dzieje się nic, a wiadomość
     * i tak poszła mailem.
     *
     * @param array<string,mixed> $osoba
     */
    private function zdarzenie(array $osoba, string $email, string $imie, string $telefon, string $tresc, ?Product $produkt, string $temat = '', string $strona = ''): void
    {
        // Kontekst mówi, w jakiej sprawie klient pisał: o produkt albo
        // z przycisku w bloku na stronie sklepu, z tematem tego przycisku.
        $kontekst = $produkt !== null
            ? [
                'product' => [
                    'id_product' => (int) $produkt->id,
                    'name' => (string) $produkt->name,
                    'reference' => (string) $produkt->reference,
                    'url' => $this->context->link->getProductLink($produkt),
                ],
            ]
            : [
                'page' => [
                    'topic' => $temat,
                    'url' => $strona,
                ],
            ];

        $zdarzenia = Module::getInstanceByName('quince_events');

        if (!$zdarzenia || !class_exists(\Quince\Events\Event\EventDispatcher::class)) {
            return;
        }

        (new \Quince\Events\Event\EventDispatcher(new \Quince\Events\Event\EventOutbox()))->dispatch(
            new \Quince\Events\Event\ShopEvent(
                type: 'contact.enquiry',
                data: [
                    'sender' => [
                        // Zero znaczy gość. Sklep nie zgaduje, kto to jest:
                        // dopasowaniem do kontaktu zajmuje się odbiorca.
                        'id_customer' => (int) ($this->context->customer->id ?? 0) > 0
                            && $this->context->customer->isLogged()
                                ? (int) $this->context->customer->id
                                : 0,
                        'email' => $email,
                        'name' => $imie,
                        // Pusty, gdy klient go nie podał albo sklep schował pole.
                        'phone' => $telefon,
                    ],
                    'message' => $tresc,
                    'expert' => [
                        'id_contact' => (int) $osoba['id_contact'],
                        'name' => (string) $osoba['name'],
                        'email' => (string) $osoba['email'],
                    ],
                ],
                context: $kontekst,
                source: 'quince_contacts'
            )
        );
    }

    /**
     * Log pod odpytywanie Admin API.
     *
     * @param array<string,mixed> $osoba
     */
    private function zapisz(array $osoba, string $email, string $imie, string $telefon, string $tresc, ?Product $produkt, bool $wyslany, string $temat = '', string $strona = ''): void
    {
        $idCustomer = (int) ($this->context->customer->id ?? 0) > 0 && $this->context->customer->isLogged()
            ? (int) $this->context->customer->id
            : 0;

        (new MessageLog())->record(
            (int) $this->context->shop->id,
            $osoba,
            $produkt !== null ? (int) $produkt->id : 0,
            $produkt !== null ? (string) $produkt->name : '',
            $idCustomer,
            $imie,
            $email,
            $tresc,
            $wyslany,
            $telefon,
            $temat,
            $strona
        );
    }
}
