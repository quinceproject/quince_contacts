<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Contacts\Mail;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use Context;
use Product;
use Quince\Contacts\Settings\PhotoStore;

/**
 * Wygląd maili modułu: kolory, fonty, stopka i gotowe bloki treści.
 *
 * Kolory i fonty bierzemy z ustawień wyglądu silnika (Quince → Wygląd), tych
 * samych, z których korzysta sklep. Zmiana koloru sklepu zmienia więc i maile,
 * bez osobnego ustawienia w module. Bez silnika zostają wartości domyślne
 * silnika.
 *
 * Bloki (karta produktu, karta osoby, wiadomość) składamy tutaj, a nie w treści
 * ustawianej w panelu: treść z panelu przechodzi przez HTMLPurifier, który
 * zdejmuje część stylów, a mail musi mieć style wpisane w znaczniki, bo część
 * programów pocztowych nie czyta arkuszy.
 *
 * Wszystko, co wpisał klient albo sklep, jest escapowane. Wartości trafiają do
 * szablonu przez `Mail::Send`, który niczego nie escapuje.
 */
final class MailLayout
{
    /** Wartości domyślne silnika (AppearanceSettings), gdy silnika nie ma. */
    private const DEFAULT_PRIMARY = '#c99a3f';
    private const DEFAULT_DARK = '#262a33';
    private const DEFAULT_FONT_HEADING = 'Lexend';
    private const DEFAULT_FONT_BODY = 'Nunito Sans';

    /** Barwy pomocnicze układu, wspólne dla każdego sklepu. */
    private const TEXT = '#141414';
    private const MUTED = '#7a7a7a';
    private const BORDER = '#e3e3e0';
    private const BOX = '#f7f7f6';

    private const FONT_FALLBACK = "'Segoe UI', Arial, Helvetica, sans-serif";

    private const DOMAIN = 'Modules.Quincecontacts.Shop';

    public function __construct(private readonly Context $context)
    {
    }

    /**
     * Zmienne wspólne dla obu maili: barwy, fonty, stopka.
     *
     * `{shop_logo}`, `{shop_name}` i `{shop_url}` dokłada sam `Mail::Send`
     * (logo do maili z Wygląd → Motyw i logo, dołączone do wiadomości).
     *
     * @return array<string,string>
     */
    public function common(): array
    {
        $glowny = $this->color('QUINCE_PRIMARY', self::DEFAULT_PRIMARY);
        $naglowki = $this->font('QUINCE_FONT_HEADING', self::DEFAULT_FONT_HEADING);
        $tekst = $this->font('QUINCE_FONT_BODY', self::DEFAULT_FONT_BODY);

        return [
            '{color_primary}' => $glowny,
            '{color_on_primary}' => $this->textOn($glowny),
            '{color_dark}' => $this->color('QUINCE_DARK', self::DEFAULT_DARK),
            '{font_heading}' => $this->stack($naglowki),
            '{font_body}' => $this->stack($tekst),
            '{fonts_href}' => $this->fontsHref($naglowki, $tekst),
            '{company}' => $this->company(),
            '{shop_domain}' => $this->e((string) Configuration::get('PS_SHOP_DOMAIN')),
        ];
    }

    /**
     * Zmienne produktu.
     *
     * @return array<string,string>
     */
    public function product(Product $produkt, string $etykieta): array
    {
        $adres = $this->context->link->getProductLink($produkt);

        return [
            '{product_name}' => $this->e((string) $produkt->name),
            '{product_url}' => $this->e($adres),
            '{product_reference}' => $this->e((string) $produkt->reference),
            '{product_price}' => $this->e($this->price($produkt)),
            '{product_card}' => $this->productCard($produkt, $adres, $etykieta),
        ];
    }

    /**
     * Karta osoby, do której klient napisał: zdjęcie, imię i nazwisko,
     * stanowisko, telefon, e-mail. Puste pola znikają.
     *
     * @param array<string,mixed> $osoba
     */
    public function expertCard(array $osoba, string $etykieta): string
    {
        $zdjecie = (string) ($osoba['photo'] ?? '');
        $imie = (string) ($osoba['name'] ?? '');
        $stanowisko = (string) ($osoba['job'] ?? '');
        $telefon = (string) ($osoba['phone'] ?? '');
        $email = (string) ($osoba['email'] ?? '');

        $obraz = '';

        if ($zdjecie !== '') {
            $url = $this->absolute((new PhotoStore())->url($zdjecie));
            $obraz = '<td style="vertical-align:top;padding:0 16px 0 0"><img src="' . $this->e($url) . '" alt="' . $this->e($imie)
                . '" width="64" height="64" style="display:block;border:0;width:64px;height:64px;border-radius:50%;object-fit:cover"></td>';
        }

        $wiersze = '<div style="font-weight:700">' . $this->e($imie) . '</div>';

        if ($stanowisko !== '') {
            $wiersze .= '<div style="color:' . self::MUTED . ';font-size:13px;padding:0 0 6px">' . $this->e($stanowisko) . '</div>';
        }

        if ($telefon !== '') {
            $wiersze .= '<div><a href="tel:' . $this->e(preg_replace('/[^0-9+]/', '', $telefon) ?? '') . '" style="color:' . self::TEXT
                . ';font-weight:700;text-decoration:none">' . $this->e($telefon) . '</a></div>';
        }

        if ($email !== '') {
            $wiersze .= '<div><a href="mailto:' . $this->e($email) . '" style="color:' . self::TEXT . ';text-decoration:underline">'
                . $this->e($email) . '</a></div>';
        }

        return $this->label($etykieta, '24px 0 8px')
            . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>' . $obraz
            . '<td style="vertical-align:top;font-size:15px;line-height:22px;color:' . self::TEXT . '">' . $wiersze . '</td>'
            . '</tr></table>';
    }

    /** Wiadomość klienta w ramce z paskiem w kolorze sklepu. */
    public function messageBox(string $tresc, string $etykieta): string
    {
        return $this->label($etykieta)
            . '<div style="background:' . self::BOX . ';border-left:4px solid ' . $this->color('QUINCE_PRIMARY', self::DEFAULT_PRIMARY)
            . ';padding:16px 20px;color:' . self::TEXT . '">' . nl2br($this->e($tresc)) . '</div>';
    }

    /**
     * Tabelka „etykieta: wartość”. Wiersz z pustą wartością znika.
     *
     * @param array<string,string> $wiersze etykieta => wartość (zwykły tekst)
     * @param array<string,string> $linki   etykieta => adres odnośnika
     */
    public function rows(string $etykieta, array $wiersze, array $linki = []): string
    {
        $html = '';

        foreach ($wiersze as $nazwa => $wartosc) {
            if (trim($wartosc) === '') {
                continue;
            }

            $tekst = $this->e($wartosc);

            if (isset($linki[$nazwa])) {
                $tekst = '<a href="' . $this->e($linki[$nazwa]) . '" style="color:' . self::TEXT . '">' . $tekst . '</a>';
            }

            $html .= '<tr><td style="color:' . self::MUTED . ';padding:0 16px 0 0;vertical-align:top">' . $this->e($nazwa)
                . '</td><td style="font-weight:700">' . $tekst . '</td></tr>';
        }

        if ($html === '') {
            return '';
        }

        return $this->label($etykieta)
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:15px;line-height:24px;color:' . self::TEXT . '">'
            . $html . '</table>';
    }

    /** Przycisk w kolorze sklepu. */
    public function button(string $tekst, string $adres): string
    {
        $glowny = $this->color('QUINCE_PRIMARY', self::DEFAULT_PRIMARY);
        $naglowki = $this->stack($this->font('QUINCE_FONT_HEADING', self::DEFAULT_FONT_HEADING));

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0 8px"><tr>'
            . '<td style="background:' . $glowny . '"><a href="' . $this->e($adres) . '" style="display:inline-block;padding:0 22px;line-height:46px;font-family:'
            . $naglowki . ';font-size:13px;font-weight:700;letter-spacing:0.65px;text-transform:uppercase;color:' . $this->textOn($glowny)
            . ';text-decoration:none">' . $this->e($tekst) . '</a></td></tr></table>';
    }

    public function trans(string $tekst, int $idLang): string
    {
        $locale = (string) (\Language::getLocaleById($idLang) ?: '');

        return $this->context->getTranslator()->trans($tekst, [], self::DOMAIN, $locale !== '' ? $locale : null);
    }

    // ------------------------------------------------------------------ pomocnicze

    private function productCard(Product $produkt, string $adres, string $etykieta): string
    {
        $wiersze = [];
        $idLang = (int) $this->context->language->id;

        if ((string) $produkt->reference !== '') {
            $wiersze[] = [$this->trans('SKU', $idLang), (string) $produkt->reference];
        }

        $cena = $this->price($produkt);

        if ($cena !== '') {
            $wiersze[] = [$this->trans('Price', $idLang), $cena];
        }

        $tabela = '';

        foreach ($wiersze as [$nazwa, $wartosc]) {
            $tabela .= '<tr><td style="color:' . self::MUTED . ';padding:0 16px 0 0">' . $this->e($nazwa)
                . '</td><td style="font-weight:700">' . $this->e($wartosc) . '</td></tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid ' . self::BORDER . '"><tr><td style="padding:16px 20px">'
            . $this->label($etykieta, '0')
            . '<a href="' . $this->e($adres) . '" style="display:block;font-weight:700;color:' . self::TEXT . ';text-decoration:none;padding:2px 0 10px">'
            . $this->e((string) $produkt->name) . '</a>'
            . ($tabela !== '' ? '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:22px;color:' . self::TEXT . '">' . $tabela . '</table>' : '')
            . '</td></tr></table>';
    }

    /**
     * Cena brutto, a w nawiasie netto, tak jak klient widzi ją w sklepie
     * (grupa klienta, waluta, rabaty katalogowe). Przy cenie bez podatku
     * jedna kwota.
     */
    private function price(Product $produkt): string
    {
        // Bez koszyka w kontekście rdzeń rzuca wyjątek. Na froncie koszyk jest
        // zawsze, ale brak ceny nie może zatrzymać maila do klienta.
        try {
            $brutto = (float) Product::getPriceStatic((int) $produkt->id, true);
            $netto = (float) Product::getPriceStatic((int) $produkt->id, false);
        } catch (\Throwable) {
            return '';
        }

        if ($brutto <= 0.0) {
            return '';
        }

        $idLang = (int) $this->context->language->id;
        $waluta = $this->context->currency ? (string) $this->context->currency->iso_code : '';
        $locale = $this->context->getCurrentLocale();

        if ($locale === null || $waluta === '') {
            return '';
        }

        $b = $locale->formatPrice($brutto, $waluta);

        if (abs($brutto - $netto) < 0.005) {
            return $b;
        }

        return sprintf(
            '%s %s (%s %s)',
            $b,
            $this->trans('gross', $idLang),
            $locale->formatPrice($netto, $waluta),
            $this->trans('net', $idLang)
        );
    }

    /**
     * Dane sprzedawcy do stopki: nazwa, adres i dane rejestrowe z Parametry
     * sklepu → Kontakt → Sklepy. Puste pola pomijamy.
     */
    private function company(): string
    {
        $nazwa = trim((string) Configuration::get('PS_SHOP_NAME'));
        $kod = trim((string) Configuration::get('PS_SHOP_CODE'));
        $miasto = trim((string) Configuration::get('PS_SHOP_CITY'));

        $czesci = array_filter([
            trim((string) Configuration::get('PS_SHOP_ADDR1')),
            trim((string) Configuration::get('PS_SHOP_ADDR2')),
            trim($kod . ' ' . $miasto),
        ], static fn (string $t): bool => $t !== '');

        // Szczegóły rejestracji to pole wielowierszowe; w stopce idą w jednej linii.
        foreach (preg_split('/[\r\n]+/', (string) Configuration::get('PS_SHOP_DETAILS')) ?: [] as $linia) {
            $linia = trim($linia, " \t,;");

            if ($linia !== '') {
                $czesci[] = $linia;
            }
        }

        $reszta = $czesci === [] ? '' : '<span style="color:#ffffff">, ' . $this->e(implode(', ', $czesci)) . '</span>';

        return '<span style="color:#ffffff;font-weight:700">' . $this->e($nazwa) . '</span>' . $reszta;
    }

    private function label(string $tekst, string $odstep = '24px 0 6px'): string
    {
        return '<div style="font-size:12px;line-height:20px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:'
            . self::MUTED . ';padding:' . $odstep . '">' . $this->e($tekst) . '</div>';
    }

    /** Barwa z ustawień silnika, tylko w postaci #rrggbb. */
    private function color(string $klucz, string $domyslna): string
    {
        $wartosc = strtolower(trim((string) Configuration::get($klucz)));

        return preg_match('/^#[0-9a-f]{6}$/', $wartosc) ? $wartosc : $domyslna;
    }

    /** Tekst na tle przycisku: ciemny na jasnym, biały na ciemnym. */
    private function textOn(string $hex): string
    {
        [$r, $g, $b] = array_map('hexdec', str_split(substr($hex, 1), 2));

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#1c1c1c' : '#ffffff';
    }

    /** Nazwa fontu z ustawień silnika; tylko litery, cyfry i spacje. */
    private function font(string $klucz, string $domyslny): string
    {
        $nazwa = trim((string) Configuration::get($klucz));

        return preg_match('/^[A-Za-z0-9 ]{2,60}$/', $nazwa) ? $nazwa : $domyslny;
    }

    private function stack(string $font): string
    {
        return "'" . $font . "', " . self::FONT_FALLBACK;
    }

    /**
     * Arkusz fontów z Google Fonts. Program pocztowy, który go nie wczyta,
     * pokaże fonty zapasowe ze stosu.
     */
    private function fontsHref(string $naglowki, string $tekst): string
    {
        $rodziny = ['family=' . str_replace(' ', '+', $naglowki) . ':wght@700'];

        if ($tekst !== $naglowki) {
            $rodziny[] = 'family=' . str_replace(' ', '+', $tekst) . ':wght@400;700';
        } else {
            $rodziny = ['family=' . str_replace(' ', '+', $naglowki) . ':wght@400;700'];
        }

        return $this->e('https://fonts.googleapis.com/css2?' . implode('&', $rodziny) . '&display=swap');
    }

    /** Pełny adres: mail czyta się poza sklepem. */
    private function absolute(string $sciezka): string
    {
        if (preg_match('#^https?://#i', $sciezka)) {
            return $sciezka;
        }

        return rtrim($this->context->shop->getBaseURL(true), '/') . '/' . ltrim(
            // _PS_IMG_ zawiera już katalog sklepu; base URL też, więc go zdejmujemy.
            (string) preg_replace('#^' . preg_quote((string) $this->context->shop->physical_uri, '#') . '#', '', $sciezka),
            '/'
        );
    }

    private function e(string $tekst): string
    {
        return htmlspecialchars($tekst, ENT_QUOTES, 'UTF-8');
    }
}
