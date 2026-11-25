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

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use Context;
use Language;
use Shop;
use Tools;

/**
 * Formularz „Napisz do nas” w szufladzie specjalisty i potwierdzenie dla klienta.
 *
 * Pola: imię i nazwisko oraz telefon sklep może pokazać albo schować i uczynić
 * obowiązkowymi. E-mail i wiadomość są zawsze widoczne i obowiązkowe: bez adresu
 * nie ma dokąd odpisać, bez wiadomości nie ma o czym rozmawiać.
 *
 * Potwierdzenie: mail do klienta zaraz po wysłaniu formularza, w języku, w którym
 * przegląda sklep, z kopią do osoby, do której napisał (gdy sklep tak ustawi).
 * Temat i treść HTML ustawia sklep w każdym języku.
 *
 * Bezpieczeństwo: treść HTML pisze sklep, ale i tak przechodzi przez HTMLPurifier
 * rdzenia przy zapisie. Dane klienta wstawiane w zmienne są zawsze escapowane,
 * więc formularz nie wstawi do maila znaczników ani odnośników.
 */
final class FormSettings
{
    /** Pola, o których decyduje sklep. E-mail i wiadomość są poza listą: zawsze. */
    public const FIELDS = ['name', 'phone'];

    private const KEY_FIELDS = 'QUINCE_CONTACTS_FIELDS';
    private const KEY_CONFIRM = 'QUINCE_CONTACTS_CONFIRM';
    private const KEY_COPY = 'QUINCE_CONTACTS_CONFIRM_COPY';
    private const KEY_SUBJECT = 'QUINCE_CONTACTS_CONFIRM_SUBJECT';
    private const KEY_BODY = 'QUINCE_CONTACTS_CONFIRM_BODY';

    /**
     * JSON bez znaków `<`, `>`, `&`, `'` i `"` (zamienione na `<` itd.).
     * PrestaShop zapisuje ustawienie z HTML-em po swojemu: `<\/p>` z domyślnego
     * `json_encode` brał za tekst i zamieniał na encje, co psuło treść maila.
     * Bez tych znaków nie ma czego zmieniać, a `json_decode` odtwarza treść 1:1.
     */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    private const SUBJECT_MAX = 150;
    private const BODY_MAX = 20000;

    /** Domyślnie: oba pola widoczne, żadne nieobowiązkowe. */
    private const DEFAULT_FIELDS = [
        'name' => ['show' => true, 'required' => false],
        'phone' => ['show' => true, 'required' => false],
    ];

    private const DEFAULT_SUBJECT = 'We have your message';

    /**
     * Logo, pasek w kolorze sklepu i stopkę z danymi sprzedawcy dokłada szablon
     * maila. Treść zawiera tylko to, co między nimi; bloki w klamrach składa
     * MailLayout.
     */
    private const DEFAULT_BODY = '<h1>We have your message</h1>'
        . '<p>Hello {name},</p>'
        . '<p>thank you for your message. We will reply as soon as we can, usually within one business day.</p>'
        . '<div>{product_card}</div>'
        . '<div>{expert_card}</div>'
        . '<div>{message_box}</div>'
        . '<div>{reply_to}</div>'
        . '<div>{product_button}</div>';

    /** @return array<string,array{show:bool,required:bool}> */
    public function fields(): array
    {
        $zapisane = json_decode(html_entity_decode((string) Configuration::get(self::KEY_FIELDS), ENT_QUOTES, 'UTF-8'), true);
        $out = self::DEFAULT_FIELDS;

        foreach (self::FIELDS as $pole) {
            if (is_array($zapisane) && isset($zapisane[$pole]) && is_array($zapisane[$pole])) {
                $pokaz = !empty($zapisane[$pole]['show']);
                // Pole schowane nie może być obowiązkowe: klient nie ma go jak wypełnić.
                $out[$pole] = ['show' => $pokaz, 'required' => $pokaz && !empty($zapisane[$pole]['required'])];
            }
        }

        return $out;
    }

    public function confirmEnabled(): bool
    {
        return (string) Configuration::get(self::KEY_CONFIRM) === '1';
    }

    public function copyEnabled(): bool
    {
        return (string) Configuration::get(self::KEY_COPY) === '1';
    }

    /** @return array<int,string> temat po id języka */
    public function subjects(): array
    {
        return $this->texts(self::KEY_SUBJECT, self::DEFAULT_SUBJECT);
    }

    /** @return array<int,string> treść HTML po id języka */
    public function bodies(): array
    {
        return $this->texts(self::KEY_BODY, self::DEFAULT_BODY);
    }

    /** Temat w języku klienta, a gdy pusty: w języku domyślnym sklepu. */
    public function subjectFor(int $idLang): string
    {
        return $this->pick($this->subjects(), $idLang);
    }

    /** Treść w języku klienta, a gdy pusta: w języku domyślnym sklepu. */
    public function bodyFor(int $idLang): string
    {
        return $this->pick($this->bodies(), $idLang);
    }

    /** @param array<string,mixed> $values wysyłka formularza ekranu Kontakty */
    public function save(array $values): void
    {
        $pola = [];

        foreach (self::FIELDS as $pole) {
            $wiersz = is_array($values['field'][$pole] ?? null) ? $values['field'][$pole] : [];
            $pokaz = !empty($wiersz['show']);
            $pola[$pole] = ['show' => $pokaz, 'required' => $pokaz && !empty($wiersz['required'])];
        }

        $this->set(self::KEY_FIELDS, (string) json_encode($pola), true);

        // Pola potwierdzenia przychodzą jako `confirm[...]`: makro pola
        // wielojęzycznego numeruje języki kolejnym poziomem tablicy tylko dla
        // nazwy zakończonej nawiasem.
        $potwierdzenie = is_array($values['confirm'] ?? null) ? $values['confirm'] : [];

        $this->set(self::KEY_CONFIRM, !empty($potwierdzenie['enabled']) ? '1' : '0');
        $this->set(self::KEY_COPY, !empty($potwierdzenie['copy']) ? '1' : '0');

        $tematy = [];
        foreach ((array) ($potwierdzenie['subject'] ?? []) as $idLang => $tekst) {
            // Temat to zwykły tekst w jednej linii.
            $tekst = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $tekst)));
            $tematy[(int) $idLang] = mb_substr($tekst, 0, self::SUBJECT_MAX);
        }
        $this->set(self::KEY_SUBJECT, (string) json_encode($tematy, self::JSON_FLAGS), true);

        $tresci = [];
        foreach ((array) ($potwierdzenie['body'] ?? []) as $idLang => $html) {
            // HTML od sklepu, ale przez HTMLPurifier rdzenia: bez skryptów,
            // atrybutów zdarzeń i adresów `javascript:`.
            $html = mb_substr((string) $html, 0, self::BODY_MAX);
            $tresci[(int) $idLang] = $this->withoutActiveContent(trim((string) Tools::purifyHTML($html, null, true)));
        }
        $this->set(self::KEY_BODY, (string) json_encode($tresci, self::JSON_FLAGS), true);
    }

    /**
     * Gotowa treść maila: zmienne podmienione na dane. Dane klienta i produktu
     * escapowane, wiadomość z zachowanymi przełamaniami wierszy.
     *
     * @param array<string,string> $dane  name, email, phone, message, product_name, shop_name,
     *                                    product_reference, product_price, product_url,
     *                                    expert_name, expert_job, expert_phone, expert_email
     * @param array<string,string> $bloki gotowy HTML bloków z MailLayout, np. '{product_card}'
     */
    public function render(string $szablon, array $dane, array $bloki = []): string
    {
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');

        $zmienne = [
            '{logo}' => $this->logoTag(),
            '{message}' => nl2br($e($dane['message'] ?? '')),
        ];

        foreach (['name', 'email', 'phone', 'product_name', 'shop_name', 'product_reference', 'product_price',
            'product_url', 'expert_name', 'expert_job', 'expert_phone', 'expert_email'] as $pole) {
            $zmienne['{' . $pole . '}'] = $e($dane[$pole] ?? '');
        }

        // Bloki są już złożone i escapowane w MailLayout.
        foreach (self::BLOCKS as $blok) {
            $zmienne[$blok] = (string) ($bloki[$blok] ?? '');
        }

        return strtr($szablon, $zmienne);
    }

    /** Bloki, które sklep może wstawić do treści potwierdzenia. */
    public const BLOCKS = ['{product_card}', '{expert_card}', '{message_box}', '{reply_to}', '{product_button}'];

    /** Temat z podstawionymi zmiennymi: zwykły tekst. */
    public function renderSubject(string $szablon, array $dane): string
    {
        return strtr($szablon, [
            '{shop_name}' => (string) ($dane['shop_name'] ?? ''),
            '{product_name}' => (string) ($dane['product_name'] ?? ''),
        ]);
    }

    /** @return string[] */
    public static function configurationKeys(): array
    {
        return [self::KEY_FIELDS, self::KEY_CONFIRM, self::KEY_COPY, self::KEY_SUBJECT, self::KEY_BODY];
    }

    // ------------------------------------------------------------------ pomocnicze

    /**
     * Treść maila bez elementów, które w mailu nie mają nic do roboty.
     *
     * HTMLPurifier rdzenia działa w trybie zaufanym i przepuszcza `<script>`,
     * choć zdejmuje atrybuty zdarzeń i adresy `javascript:`. Usuwamy więc
     * takie elementy sami, parserem HTML, razem z ich zawartością.
     */
    private function withoutActiveContent(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $poprzednie = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="q-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        foreach (['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'base', 'frame', 'frameset', 'applet'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $element) {
                $element->parentNode?->removeChild($element);
            }
        }

        $korzen = $dom->getElementById('q-root') ?? $dom->getElementsByTagName('div')->item(0);
        $out = '';

        foreach ($korzen ? iterator_to_array($korzen->childNodes) : [] as $wezel) {
            $out .= $dom->saveHTML($wezel);
        }

        return trim($out);
    }

    /**
     * Logo sklepu z ustawień wyglądu (Wygląd → Motyw i logo): najpierw logo do
     * maili, a gdy go nie ma, główne. Pełny adres, bo mail czyta się poza sklepem.
     */
    private function logoTag(): string
    {
        $plik = (string) (Configuration::get('PS_LOGO_MAIL') ?: Configuration::get('PS_LOGO'));
        $context = Context::getContext();

        if ($plik === '' || !$context->shop) {
            return '';
        }

        $adres = rtrim($context->shop->getBaseURL(true), '/') . '/img/' . ltrim($plik, '/');
        $nazwa = (string) Configuration::get('PS_SHOP_NAME');

        return '<img src="' . htmlspecialchars($adres, ENT_QUOTES, 'UTF-8') . '" alt="'
            . htmlspecialchars($nazwa, ENT_QUOTES, 'UTF-8') . '" style="max-width:200px;height:auto;border:0">';
    }

    /**
     * Teksty po id języka. Dopóki sklep nic nie zapisał: tekst domyślny
     * przetłumaczony na każdy język sklepu (domena Shop modułu).
     *
     * @return array<int,string>
     */
    private function texts(string $klucz, string $domyslny): array
    {
        $raw = Configuration::get($klucz);

        if ($raw === false || $raw === null || $raw === '') {
            $out = [];
            $translator = Context::getContext()->getTranslator();

            foreach (Language::getLanguages(false) as $jezyk) {
                $locale = (string) ($jezyk['locale'] ?? '');
                $tlumacz = $locale !== '' ? Context::getContext()->getTranslatorFromLocale($locale) : $translator;
                $out[(int) $jezyk['id_lang']] = $klucz === self::KEY_BODY
                    ? $this->translatedBody($tlumacz, $locale)
                    : $tlumacz->trans($domyslny, [], 'Modules.Quincecontacts.Shop', $locale ?: null);
            }

            return $out;
        }

        $dane = json_decode(html_entity_decode((string) $raw, ENT_QUOTES, 'UTF-8'), true);
        $out = [];

        foreach (is_array($dane) ? $dane : [] as $idLang => $tekst) {
            $out[(int) $idLang] = (string) $tekst;
        }

        return $out;
    }

    /** Domyślna treść złożona z przetłumaczonych zdań, w kształcie DEFAULT_BODY. */
    private function translatedBody(object $t, string $locale): string
    {
        $d = 'Modules.Quincecontacts.Shop';
        $l = $locale ?: null;

        return '<h1>' . $t->trans('We have your message', [], $d, $l) . '</h1>'
            . '<p>' . $t->trans('Hello {name},', [], $d, $l) . '</p>'
            . '<p>' . $t->trans('thank you for your message. We will reply as soon as we can, usually within one business day.', [], $d, $l) . '</p>'
            . '<div>{product_card}</div>'
            . '<div>{expert_card}</div>'
            . '<div>{message_box}</div>'
            . '<div>{reply_to}</div>'
            . '<div>{product_button}</div>';
    }

    /** @param array<int,string> $teksty */
    private function pick(array $teksty, int $idLang): string
    {
        if (isset($teksty[$idLang]) && trim($teksty[$idLang]) !== '') {
            return $teksty[$idLang];
        }

        return $teksty[(int) Configuration::get('PS_LANG_DEFAULT')] ?? '';
    }

    /** Zapis do każdego sklepu z bieżącego kontekstu (wzór z People::saveDefaultExpert). */
    private function set(string $klucz, string $wartosc, bool $html = false): void
    {
        foreach (Shop::getContextListShopID() as $idShop) {
            Configuration::updateValue($klucz, $wartosc, $html, null, (int) $idShop);
        }

        if (Shop::getContext() === Shop::CONTEXT_ALL) {
            Configuration::updateGlobalValue($klucz, $wartosc, $html);
        }
    }
}
