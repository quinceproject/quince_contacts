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

require_once __DIR__ . '/vendor/autoload.php';

use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyType;
use Quince\Contacts\Settings\MessageLog;
use Quince\Contacts\Settings\People;
use Quince\Contacts\Settings\PhotoStore;

/**
 * Quince Contacts — ludzie zamiast adresu skrzynki.
 *
 * Klient, który widzi twarz, imię i stanowisko doradcy, pyta chętniej niż ten,
 * który widzi formularz „napisz do nas". Moduł pokazuje takie osoby tam, gdzie
 * pytanie się rodzi — przy produkcie z ich działu.
 *
 * Kartoteką ludzi jest sam PrestaShop: osoba to kontakt sklepu z Sklep →
 * Kontakty. Instalator dokłada tej encji telefon przez mechanizm dodatkowych
 * pól z 9.2, więc numer widać też w natywnym formularzu, na liście kontaktów
 * i w Admin API. Reszta — zdjęcie, stanowisko, kolejność i przypisanie do
 * kategorii — leży u nas, bo tego mechanizm nie obsłuży (albo, jak przy
 * stanowisku, obsługuje wadliwie; opis przy `addContactFields()`).
 *
 * Ekran „Kontakty" w panelu wypełnia komplet z jednego miejsca. Nie jest to
 * drugi zestaw danych: nazwa i adres e-mail zapisują się prosto do kontaktu
 * sklepu, telefon przez mechanizm dodatkowych pól.
 */
class Quince_Contacts extends Module
{
    /** Pola dokładane do kontaktu sklepu. */
    public const FIELD_PHONE = 'phone';
    public const FIELD_JOB_TITLE = 'job_title';

    public function __construct()
    {
        $this->name = 'quince_contacts';
        $this->tab = 'front_office_features';
        $this->version = '0.4.3';
        $this->author = 'Quince Project';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.2.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        // Ekrany modułu dziedziczą po kontrolerze silnika, a szablony po jego
        // layoucie. Bez tej deklaracji PrestaShop pozwoli wyłączyć silnik,
        // a moduł przestanie otwierać swoje ekrany bez żadnego ostrzeżenia.
        $this->dependencies = ['quince_engine'];

        // Jeden wpis w gałęzi „Quince project" w menu PrestaShopa, prowadzący
        // na ekran modułu. Podstron nie zgłaszamy: moduł ma je we własnym
        // bocznym pasku, a menu sklepu urosłoby wtedy o kilkanaście pozycji.
        // Kolejność wpisów w gałęzi ustala silnik.
        $this->tabs = [
            [
                'class_name' => 'AdminQuinceContacts',
                'visible' => true,
                'name' => 'Contacts',
                'parent_class_name' => 'AdminQuinceProject',
                'route_name' => 'quince_contacts',
                'wording' => 'Contacts',
                'wording_domain' => 'Modules.Quincecontacts.Admin',
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('Quince contacts', [], 'Modules.Quincecontacts.Admin');
        $this->description = $this->trans(
            'Shows the people behind the shop: photo, position and phone, next to the products they know.',
            [],
            'Modules.Quincecontacts.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Uninstall the module? Photos, job titles, category assignments and the log of enquiries sent to '
            . 'a specialist will be removed. Phone numbers stay with the shop contacts.',
            [],
            'Modules.Quincecontacts.Admin'
        );
    }

    public function install(): bool
    {
        return parent::install()
            && (new People())->installTables()
            && (new MessageLog())->installTable()
            && $this->addContactFields()
            && $this->registerHook('actionQuinceRegisterFeature')
            && $this->registerHook('displayQuinceExpert')
            && $this->registerHook('actionObjectContactDeleteAfter');
    }

    /**
     * Odinstalowanie zabiera nasze dane, ale nie cudze.
     *
     * Telefon zostaje przy kontaktach sklepu: sprzedawca, który odinstalowuje
     * moduł, nie spodziewa się, że straci przy okazji numery telefonów wpisane
     * w kartach kontaktów. Zdjęcia, stanowiska i przypisania do kategorii nie
     * mają poza modułem żadnego znaczenia, więc odchodzą razem z nim.
     */
    public function uninstall(): bool
    {
        $this->removeContactFields(false);

        (new PhotoStore())->purge();

        return (new People())->dropTables()
            && (new MessageLog())->dropTable()
            && parent::uninstall();
    }

    /**
     * Telefon dokładany do encji kontaktu.
     *
     * Pole wchodzi w natywny formularz kontaktu, na listę kontaktów i do Admin
     * API — sklep dostaje je w miejscu, w którym już pracuje, a system zewnętrzny
     * czyta je bez naszego udziału.
     *
     * Napis „Phone" zostaje po angielsku w natywnym formularzu i na liście
     * kontaktów. PrestaShop 9.2 dokłada nazwy pól dodatkowych do katalogu
     * tłumaczeń jako „klucz = klucz" po wczytaniu plików modułu, więc nadpisuje
     * nasz przekład tego samego napisu; przetłumaczyć da się go w panelu
     * tłumaczeń PrestaShopa. Dlatego własny ekran opisuje to pole innym
     * napisem — inaczej straciłby polską nazwę razem z nim.
     *
     * Stanowiska tą drogą nie dokładamy, choć pasowałoby tu tak samo.
     * PrestaShop 9.2 beta nie potrafi zapisać pola **językowego** na encji, której
     * tabela językowa nie ma `id_shop` — `ExtraPropertyWriter::writeLang()` buduje
     * zapis z tym kluczem bezwarunkowo, a `contact_lang` takiej kolumny nie ma.
     * Próba zapisania kończy się błędem SQL i stroną błędu zamiast zapisu. To samo
     * psuje listę kontaktów, gdy takie pole zgłosi się na siatkę. Stanowisko
     * trzymamy więc u siebie, obok zdjęcia i kategorii; gdy poprawka wejdzie do
     * PrestaShopa, przeniesienie z powrotem to zmiana w instalatorze i skrypt
     * przenoszący dane.
     */
    private function addContactFields(): bool
    {
        $telefon = new ExtraPropertyDefinition(
            entityName: 'contact',
            propertyName: self::FIELD_PHONE,
            type: ExtraPropertyType::STRING,
            scope: ExtraPropertyScope::COMMON,
            size: 64,
            displayFront: true,
            associatedForms: ['contact'],
            associatedGrids: ['contact'],
            associatedApis: ['/contacts', '/contacts/{contactId}'],
            labelWording: 'Phone',
            labelDomain: 'Modules.Quincecontacts.Admin'
        );

        return $this->registerExtraProperty($telefon) !== false;
    }

    /** @param bool $dropData czy skasować także wpisane wartości */
    public function removeContactFields(bool $dropData): void
    {
        $definicja = new ExtraPropertyDefinition(
            entityName: 'contact',
            propertyName: self::FIELD_PHONE,
            type: ExtraPropertyType::STRING,
            scope: ExtraPropertyScope::COMMON,
            moduleName: $this->name
        );

        $this->unregisterExtraProperty($definicja, $dropData);
    }

    /**
     * Zdjęcie pola stanowiska z instalacji sprzed 0.2.0.
     *
     * Kasujemy razem z danymi, bo danych i tak nie ma — zapis nigdy się nie udał.
     */
    public function removeJobTitleField(): void
    {
        $this->unregisterExtraProperty(new ExtraPropertyDefinition(
            entityName: 'contact',
            propertyName: self::FIELD_JOB_TITLE,
            type: ExtraPropertyType::STRING,
            scope: ExtraPropertyScope::LANG,
            moduleName: $this->name
        ), true);
    }

    /**
     * Zgłoszenie do silnika: co dostarczamy i gdzie tego szukać.
     *
     * @return array<int,array{type:string,id:string,label:string,route:string}>
     */
    public function hookActionQuinceRegisterFeature(): array
    {
        return [[
            'type' => 'contacts',
            'id' => 'quince_contacts',
            'label' => $this->trans('Contacts', [], 'Modules.Quincecontacts.Admin'),
            'route' => 'quince_contacts',
        ]];
    }

    /**
     * „Zapytaj specjalistę" na karcie produktu.
     *
     * Osobę dobieramy po kategorii produktu i po jej przodkach, więc wystarczy
     * przypisać kogoś do działu, żeby obsłużył wszystkie podkategorie. Bez osoby
     * dla tej kategorii nie rysujemy nic: przycisk prowadzący do pustej szuflady
     * byłby gorszy niż jego brak.
     *
     * @param array{product?:mixed} $params
     */
    public function hookDisplayQuinceExpert(array $params): string
    {
        $produkt = $params['product'] ?? null;

        if (!is_array($produkt) && !($produkt instanceof ArrayAccess)) {
            return '';
        }

        $idLang = (int) $this->context->language->id;
        $osoba = (new People())->expertForCategory((int) ($produkt['id_category_default'] ?? 0), $idLang);

        if ($osoba === null) {
            return '';
        }

        $this->context->smarty->assign([
            'quinceExpert' => $osoba,
            'quinceExpertPhoto' => (new PhotoStore())->url($osoba['photo']),
            'quinceExpertProduct' => (int) ($produkt['id_product'] ?? 0),
            'quinceExpertUrl' => $this->context->link->getModuleLink(
                'quince_contacts',
                'message',
                ['ajax' => 1]
            ),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/expert.tpl');
    }

    /**
     * Skasowany kontakt sklepu zabiera swoją osobę.
     *
     * Bez tego zostaje osoba-widmo: zdjęcie i kategorie wskazujące na kontakt,
     * którego już nie ma — a więc bez nazwy, bez adresu i bez sposobu, żeby ją
     * z panelu poprawić.
     *
     * @param array{object?:object} $params
     */
    public function hookActionObjectContactDeleteAfter(array $params): void
    {
        $kontakt = $params['object'] ?? null;

        if (!$kontakt instanceof Contact) {
            return;
        }

        (new People())->remove((int) $kontakt->id);
    }

    /**
     * Przycisk „Konfiguruj" w menedżerze modułów.
     *
     * PrestaShop pokazuje go po samym istnieniu tej metody
     * (`ModuleRepository::__construct`, `method_exists($tmpModule, 'getContent')`),
     * a my przenosimy sprzedawcę na ekran modułu w panelu.
     */
    public function getContent(): void
    {
        \Tools::redirectAdmin($this->get('router')->generate('quince_contacts'));
    }

}
