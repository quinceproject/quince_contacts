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
 * Osoby pokazywane w sklepie.
 *
 * Kartoteką ludzi jest sam PrestaShop: kontakt sklepu ma nazwę i adres e-mail,
 * a instalator modułu dokłada mu telefon jako dodatkowe pole encji. Tutaj leży
 * to, czego ten mechanizm nie obsłuży: zdjęcie, kolejność, przypisanie do
 * kategorii i stanowisko.
 *
 * Stanowisko leży tutaj, bo pole językowe zgłoszone przez mechanizm dodatkowych
 * pól rozwala w PrestaShopie 9.2 i listę kontaktów, i zapis karty kontaktu.
 *
 * Konsekwencja: wiersz w tej tabeli nie opisuje osoby, tylko mówi „ten kontakt
 * sklepu pokazuje się klientom jako osoba".
 */
final class People
{
    public const TABLE = 'quince_contact';
    public const TABLE_LANG = 'quince_contact_lang';
    public const TABLE_CATEGORY = 'quince_contact_category';

    /** Katalog na zdjęcia, względem katalogu z plikami sklepu. */
    public const PHOTO_DIR = 'img/quince/contacts/';

    /**
     * Osoba pokazywana tam, gdzie kategoria nie ma nikogo przypisanego.
     *
     * Zero znaczy „nikt": karta produktu z kategorii bez specjalisty nie
     * pokazuje wtedy przycisku, tak jak przed dołożeniem tego ustawienia.
     */
    public const DEFAULT_KEY = 'QUINCE_CONTACTS_DEFAULT';

    public function installTables(): bool
    {
        $silnik = _MYSQL_ENGINE_;

        $sql = [
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
                `id_contact` INT(11) UNSIGNED NOT NULL,
                `photo` VARCHAR(255) NOT NULL DEFAULT "",
                `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                `position` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_contact`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',

            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_LANG . '` (
                `id_contact` INT(11) UNSIGNED NOT NULL,
                `id_lang` INT(11) UNSIGNED NOT NULL,
                `job_title` VARCHAR(128) NOT NULL DEFAULT "",
                PRIMARY KEY (`id_contact`, `id_lang`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',

            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_CATEGORY . '` (
                `id_contact` INT(11) UNSIGNED NOT NULL,
                `id_category` INT(11) UNSIGNED NOT NULL,
                PRIMARY KEY (`id_contact`, `id_category`),
                INDEX `idx_category` (`id_category`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',
        ];

        foreach ($sql as $zapytanie) {
            if (!Db::getInstance()->execute($zapytanie)) {
                return false;
            }
        }

        return true;
    }

    public function dropTables(): bool
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE_CATEGORY . '`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE_LANG . '`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    /**
     * Osoby w kolejności ustawionej w panelu, z danymi kontaktu sklepu.
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(int $idLang): array
    {
        $zapytanie = new DbQuery();
        $zapytanie->select('q.id_contact, q.photo, q.active, q.position, c.email, cl.name');
        $zapytanie->from(self::TABLE, 'q');
        $zapytanie->innerJoin('contact', 'c', 'c.id_contact = q.id_contact');
        $zapytanie->leftJoin('contact_lang', 'cl', 'cl.id_contact = q.id_contact AND cl.id_lang = ' . (int) $idLang);
        $zapytanie->orderBy('q.position ASC, q.id_contact ASC');

        $wiersze = Db::getInstance()->executeS($zapytanie) ?: [];
        $kategorie = $this->categoriesByContact();
        $stanowiska = $this->jobTitles();
        $nazwy = $this->names();

        $out = [];

        foreach ($wiersze as $wiersz) {
            $id = (int) $wiersz['id_contact'];

            $out[] = [
                'id_contact' => $id,
                'name' => (string) ($wiersz['name'] ?? ''),
                'email' => (string) ($wiersz['email'] ?? ''),
                'photo' => (string) $wiersz['photo'],
                'active' => (bool) $wiersz['active'],
                'position' => (int) $wiersz['position'],
                'categories' => $kategorie[$id] ?? [],
                'job_titles' => $stanowiska[$id] ?? [],
                'names' => $nazwy[$id] ?? [],
            ];
        }

        return $out;
    }

    /**
     * Kontakty sklepu, których jeszcze nie ma na naszej liście.
     *
     * @return array<int,array{id_contact:int,name:string,email:string}>
     */
    public function available(int $idLang): array
    {
        $zapytanie = new DbQuery();
        $zapytanie->select('c.id_contact, c.email, cl.name');
        $zapytanie->from('contact', 'c');
        $zapytanie->leftJoin('contact_lang', 'cl', 'cl.id_contact = c.id_contact AND cl.id_lang = ' . (int) $idLang);
        $zapytanie->leftJoin(self::TABLE, 'q', 'q.id_contact = c.id_contact');
        $zapytanie->where('q.id_contact IS NULL');
        $zapytanie->orderBy('cl.name ASC');

        $out = [];

        foreach (Db::getInstance()->executeS($zapytanie) ?: [] as $wiersz) {
            $out[] = [
                'id_contact' => (int) $wiersz['id_contact'],
                'name' => (string) ($wiersz['name'] ?? ''),
                'email' => (string) ($wiersz['email'] ?? ''),
            ];
        }

        return $out;
    }

    /** Dołożenie kontaktu sklepu do listy osób pokazywanych klientom. */
    public function add(int $idContact): bool
    {
        if ($idContact <= 0 || $this->exists($idContact)) {
            return false;
        }

        return Db::getInstance()->insert(self::TABLE, [
            'id_contact' => $idContact,
            'photo' => '',
            'active' => 1,
            'position' => $this->nextPosition(),
        ]);
    }

    /**
     * Zdjęcie i kategorie jednej osoby.
     *
     * @param int[] $categories
     */
    public function save(int $idContact, ?string $photo, array $categories, bool $active, int $position): bool
    {
        if (!$this->exists($idContact)) {
            return false;
        }

        $dane = [
            'active' => $active ? 1 : 0,
            'position' => $position,
        ];

        // Puste zdjęcie znaczy „nie zmieniaj", nie „skasuj": formularz bez pliku
        // wysyła pustą wartość przy każdym zapisie.
        if ($photo !== null) {
            $dane['photo'] = pSQL($photo);
        }

        $ok = Db::getInstance()->update(self::TABLE, $dane, 'id_contact = ' . $idContact);

        Db::getInstance()->delete(self::TABLE_CATEGORY, 'id_contact = ' . $idContact);

        foreach (array_unique(array_map('intval', $categories)) as $idCategory) {
            if ($idCategory > 0) {
                Db::getInstance()->insert(self::TABLE_CATEGORY, [
                    'id_contact' => $idContact,
                    'id_category' => $idCategory,
                ], false, true, Db::INSERT_IGNORE);
            }
        }

        return (bool) $ok;
    }

    /** Zdjęcie osoby: sama nazwa pliku, bez ścieżki. */
    public function photo(int $idContact): string
    {
        return (string) Db::getInstance()->getValue(
            'SELECT photo FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_contact = ' . $idContact
        );
    }

    /**
     * Zdjęcie znika razem z wierszem: plik bez wiersza to śmieć, którego nikt
     * już nie znajdzie.
     */
    public function remove(int $idContact): bool
    {
        Db::getInstance()->delete(self::TABLE_CATEGORY, 'id_contact = ' . $idContact);
        Db::getInstance()->delete(self::TABLE_LANG, 'id_contact = ' . $idContact);

        return Db::getInstance()->delete(self::TABLE, 'id_contact = ' . $idContact);
    }

    public function exists(int $idContact): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_contact = ' . $idContact
        );
    }

    /**
     * Osoby pokazywane przy produkcie z podanej kategorii.
     *
     * Dopasowanie idzie po ścieżce w górę: kategoria wskazana w panelu obejmuje
     * wszystko, co pod nią leży. Sprzedawca przypisujący kogoś do „Opon" nie musi
     * wracać tutaj po każdym dołożeniu podkategorii.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forCategory(int $idCategory, int $idLang): array
    {
        if ($idCategory <= 0) {
            return [];
        }

        // Ścieżka od korzenia do wskazanej kategorii. `nleft`/`nright` opisują
        // zagnieżdżenie, więc przodkowie to te, które ją obejmują.
        $przodkowie = Db::getInstance()->executeS(
            'SELECT rodzic.id_category
             FROM `' . _DB_PREFIX_ . 'category` dziecko
             JOIN `' . _DB_PREFIX_ . 'category` rodzic
               ON rodzic.nleft <= dziecko.nleft AND rodzic.nright >= dziecko.nright
             WHERE dziecko.id_category = ' . $idCategory
        ) ?: [];

        $identyfikatory = array_map(static fn (array $w): int => (int) $w['id_category'], $przodkowie);

        if ($identyfikatory === []) {
            return [];
        }

        $lista = implode(',', $identyfikatory);

        $wiersze = Db::getInstance()->executeS(
            'SELECT DISTINCT q.id_contact, q.photo, q.position, c.email, cl.name
             FROM `' . _DB_PREFIX_ . self::TABLE . '` q
             JOIN `' . _DB_PREFIX_ . self::TABLE_CATEGORY . '` qc ON qc.id_contact = q.id_contact
             JOIN `' . _DB_PREFIX_ . 'contact` c ON c.id_contact = q.id_contact
             LEFT JOIN `' . _DB_PREFIX_ . 'contact_lang` cl
               ON cl.id_contact = q.id_contact AND cl.id_lang = ' . $idLang . '
             WHERE q.active = 1 AND qc.id_category IN (' . $lista . ')
             ORDER BY q.position ASC, q.id_contact ASC'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[] = [
                'id_contact' => (int) $wiersz['id_contact'],
                'name' => (string) ($wiersz['name'] ?? ''),
                'email' => (string) ($wiersz['email'] ?? ''),
                'photo' => (string) $wiersz['photo'],
            ];
        }

        return $out;
    }

    /**
     * Osoba do pokazania przy produkcie z danej kategorii.
     *
     * Bierzemy pierwszą z listy, czyli tę, którą sklep ustawił najwyżej: przy
     * produkcie ma stać jeden człowiek, a nie spis działu. Szukamy w kategorii
     * i w jej przodkach, więc wystarczy przypisać osobę do działu, żeby obsłużyła
     * wszystkie jego podkategorie.
     *
     * W odróżnieniu od `forCategory()` zwracamy komplet do karty kontaktowej:
     * stanowisko z naszej tabeli i telefon z pola dodatkowego kontaktu.
     *
     * @return array{id_contact:int,name:string,job:string,email:string,phone:string,photo:string}|null
     */
    public function expertForCategory(int $idCategory, int $idLang): ?array
    {
        $osoby = $this->forCategory($idCategory, $idLang);
        $idContact = $osoby === [] ? $this->defaultExpert() : (int) $osoby[0]['id_contact'];

        if ($idContact === 0) {
            return null;
        }

        return $this->card($idContact, $idLang);
    }

    /** Osoba wskazana w ustawieniach na wypadek kategorii bez przypisania. */
    public function defaultExpert(): int
    {
        $wybrana = (int) \Configuration::get(self::DEFAULT_KEY);

        return $wybrana > 0 && $this->exists($wybrana) ? $wybrana : 0;
    }

    /** Zapis osoby domyślnej do każdego sklepu z bieżącego kontekstu. */
    public function saveDefaultExpert(int $idContact): void
    {
        $wartosc = (string) max(0, $idContact);

        foreach (\Shop::getContextListShopID() as $idShop) {
            \Configuration::updateValue(self::DEFAULT_KEY, $wartosc, false, null, (int) $idShop);
        }

        if (\Shop::getContext() === \Shop::CONTEXT_ALL) {
            \Configuration::updateGlobalValue(self::DEFAULT_KEY, $wartosc);
        }
    }

    /**
     * Karta osoby: nazwa i adres z kontaktu sklepu, stanowisko i zdjęcie u nas,
     * telefon z pola dodatkowego encji kontaktu.
     *
     * @return array{id_contact:int,name:string,job:string,email:string,phone:string,photo:string}|null
     */
    private function card(int $idContact, int $idLang): ?array
    {
        $osoba = Db::getInstance()->getRow(
            'SELECT q.photo, c.email, cl.name
             FROM `' . _DB_PREFIX_ . self::TABLE . '` q
             JOIN `' . _DB_PREFIX_ . 'contact` c ON c.id_contact = q.id_contact
             LEFT JOIN `' . _DB_PREFIX_ . 'contact_lang` cl
               ON cl.id_contact = q.id_contact AND cl.id_lang = ' . $idLang . '
             WHERE q.id_contact = ' . $idContact . ' AND q.active = 1'
        );

        if (!$osoba) {
            return null;
        }

        $stanowisko = Db::getInstance()->getValue(
            'SELECT job_title FROM `' . _DB_PREFIX_ . self::TABLE_LANG . '`'
            . ' WHERE id_contact = ' . $idContact . ' AND id_lang = ' . $idLang
        );

        // Telefon leży w tabeli pól dodatkowych encji kontaktu, dokładanej przez
        // instalator modułu. Czytamy go zapytaniem, a nie przez usługę silnika
        // pól dodatkowych: na froncie ta usługa nie jest wstrzykiwana, a kolumna
        // ma stałą nazwę złożoną z nazwy modułu i pola.
        $telefon = Db::getInstance()->getValue(
            'SELECT `quince_contacts_phone` FROM `' . _DB_PREFIX_ . 'contact_extra`'
            . ' WHERE id_contact = ' . $idContact
        );

        return [
            'id_contact' => $idContact,
            'name' => (string) ($osoba['name'] ?? ''),
            'job' => is_string($stanowisko) ? $stanowisko : '',
            'email' => (string) ($osoba['email'] ?? ''),
            'phone' => is_string($telefon) ? $telefon : '',
            'photo' => (string) $osoba['photo'],
        ];
    }

    /**
     * Kategorie sklepu do przypisania osobie: płaska lista z poziomem zagnieżdżenia.
     *
     * @return array<int,array{id:int,name:string,level:int}>
     */
    public function categoryChoices(int $idLang): array
    {
        $korzen = (int) \Configuration::get('PS_HOME_CATEGORY');
        $idShop = (int) \Context::getContext()->shop->id;

        // Złączenie po języku ORAZ po sklepie. `category_lang` ma wiersz na każdą
        // parę język–sklep, więc samo `id_lang` daje tyle kopii każdej kategorii,
        // ile jest sklepów.
        $wiersze = Db::getInstance()->executeS(
            'SELECT c.id_category, cl.name, c.level_depth FROM `' . _DB_PREFIX_ . 'category` c'
            . ' JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.id_category = c.id_category'
            . ' AND cl.id_lang = ' . $idLang
            . ' AND cl.id_shop = ' . $idShop
            . ' WHERE c.active = 1 AND c.id_category != ' . $korzen
            . ' AND c.id_parent != 0 ORDER BY c.nleft'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $w) {
            $out[] = [
                'id' => (int) $w['id_category'],
                'name' => (string) $w['name'],
                'level' => max(0, (int) $w['level_depth'] - 2),
            ];
        }

        return $out;
    }

    /**
     * Nazwa kontaktu w każdym języku sklepu.
     *
     * @return array<int,array<int,string>>
     */
    private function names(): array
    {
        $out = [];

        $wiersze = Db::getInstance()->executeS(
            'SELECT cl.id_contact, cl.id_lang, cl.name
             FROM `' . _DB_PREFIX_ . 'contact_lang` cl
             JOIN `' . _DB_PREFIX_ . self::TABLE . '` q ON q.id_contact = cl.id_contact'
        ) ?: [];

        foreach ($wiersze as $wiersz) {
            $out[(int) $wiersz['id_contact']][(int) $wiersz['id_lang']] = (string) $wiersz['name'];
        }

        return $out;
    }

    /**
     * Stanowisko każdej osoby w każdym języku.
     *
     * @return array<int,array<int,string>>
     */
    private function jobTitles(): array
    {
        $out = [];

        $wiersze = Db::getInstance()->executeS(
            'SELECT id_contact, id_lang, job_title FROM `' . _DB_PREFIX_ . self::TABLE_LANG . '`'
        ) ?: [];

        foreach ($wiersze as $wiersz) {
            $out[(int) $wiersz['id_contact']][(int) $wiersz['id_lang']] = (string) $wiersz['job_title'];
        }

        return $out;
    }

    /**
     * Zapis stanowiska w każdym języku.
     *
     * @param array<int,string> $titles id_lang => stanowisko
     */
    public function saveJobTitles(int $idContact, array $titles): void
    {
        foreach ($titles as $idLang => $tytul) {
            $idLang = (int) $idLang;

            if ($idLang <= 0) {
                continue;
            }

            Db::getInstance()->execute(
                'INSERT INTO `' . _DB_PREFIX_ . self::TABLE_LANG . '` (id_contact, id_lang, job_title)
                 VALUES (' . $idContact . ', ' . $idLang . ', "' . pSQL(substr(trim($tytul), 0, 128)) . '")
                 ON DUPLICATE KEY UPDATE job_title = VALUES(job_title)'
            );
        }
    }

    /**
     * Kategorie przypisane do osób.
     *
     * @return array<int,int[]>
     */
    private function categoriesByContact(): array
    {
        $out = [];

        $wiersze = Db::getInstance()->executeS(
            'SELECT id_contact, id_category FROM `' . _DB_PREFIX_ . self::TABLE_CATEGORY . '`'
        ) ?: [];

        foreach ($wiersze as $wiersz) {
            $out[(int) $wiersz['id_contact']][] = (int) $wiersz['id_category'];
        }

        return $out;
    }

    private function nextPosition(): int
    {
        return 1 + (int) Db::getInstance()->getValue(
            'SELECT MAX(position) FROM `' . _DB_PREFIX_ . self::TABLE . '`'
        );
    }
}
