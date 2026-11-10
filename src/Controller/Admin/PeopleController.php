<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Contacts\Controller\Admin;

use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyReaderInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyWriterInterface;
use Quince\Contacts\Settings\People;
use Quince\Contacts\Settings\PhotoStore;
use Quince\Engine\Controller\Admin\QuinceController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Osoby pokazywane klientom.
 *
 * Ekran obsługuje komplet: nazwę, adres e-mail, telefon, stanowisko, zdjęcie
 * i kategorie. Dane nie są jednak zdublowane — każde pole zapisuje się tam,
 * gdzie mieszka: nazwa i e-mail w kontakcie sklepu, telefon jako dodatkowe pole
 * tej encji, stanowisko, zdjęcie i kategorie u nas. Karta kontaktu
 * w PrestaShopie zostaje drugimi drzwiami do tych samych danych.
 */
class PeopleController extends QuinceController
{
    public function __construct(
        private readonly People $people,
        private readonly PhotoStore $photos,
        private readonly ExtraPropertyWriterInterface $extraProperties,
        private readonly ExtraPropertyReaderInterface $extraPropertiesReader
    ) {
    }

    public function indexAction(): Response
    {
        $idLang = (int) $this->getContext()->language->id;
        $osoby = [];

        $telefony = $this->phones();

        $domyslna = $this->people->defaultExpert();

        foreach ($this->people->all($idLang) as $osoba) {
            $osoba['photoUrl'] = $this->photos->url($osoba['photo']);
            $osoba['phone'] = $telefony[$osoba['id_contact']] ?? '';
            $osoba['is_default'] = (int) $osoba['id_contact'] === $domyslna;
            $osoby[] = $osoba;
        }

        return $this->renderPanel('@Modules/quince_contacts/views/templates/admin/people.html.twig', [
            'people' => $osoby,
            'languages' => $this->languages(),
            'available' => $this->people->available($idLang),
            'categories' => $this->people->categoryChoices($idLang),
            'contactsRoute' => $this->contactsRoute(),
            'defaultExpert' => $this->people->defaultExpert(),
            'layoutTitle' => 'Contacts',
            'layoutTitleDomain' => 'Modules.Quincecontacts.Admin',
            'quinceRoute' => 'quince_contacts',
        ]);
    }

    /** Dołożenie kontaktu sklepu do listy osób. */
    public function addAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_contacts_add', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', 'Modules.Quincecontacts.Admin'));

            return $this->redirectToRoute('quince_contacts');
        }

        $idContact = (int) $request->request->get('id_contact');

        if ($this->people->add($idContact)) {
            $this->addFlash('success', $this->trans('Person added.', 'Modules.Quincecontacts.Admin'));
        }

        return $this->redirectToRoute('quince_contacts');
    }

    /**
     * Zapis całej listy naraz.
     *
     * Ekran jest jednym formularzem, więc zapis też: zdjęcia, kategorie,
     * widoczność i kolejność przychodzą razem. Kolejność bierzemy z kolejności
     * pól w formularzu — przeciągnięcie wiersza zmienia ją bez osobnego pola.
     */
    public function saveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_contacts_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', 'Modules.Quincecontacts.Admin'));

            return $this->redirectToRoute('quince_contacts');
        }

        $wiersze = $request->request->all('person');
        $pliki = $request->files->all('person');
        $pozycja = 0;

        foreach (is_array($wiersze) ? $wiersze : [] as $idContact => $wiersz) {
            $idContact = (int) $idContact;

            if (!$this->people->exists($idContact)) {
                continue;
            }

            // Wiersz oznaczony koszem odchodzi razem ze zdjęciem. Nie zmieniamy
            // przy tym kontaktu sklepu: osoba znika z listy, kontakt zostaje.
            if (!empty($wiersz['remove'])) {
                $this->photos->remove($this->people->photo($idContact));
                $this->people->remove($idContact);

                continue;
            }

            $this->saveContact($idContact, $wiersz);
            $this->savePhone($idContact, (string) ($wiersz['phone'] ?? ''));

            $this->people->saveJobTitles(
                $idContact,
                is_array($wiersz['job_title'] ?? null) ? $wiersz['job_title'] : []
            );

            $nowe = $this->photos->accept($pliki[$idContact]['photo'] ?? null, $idContact);

            if ($nowe !== null) {
                // Poprzednie zdjęcie znika dopiero, gdy nowe leży już na dysku.
                $this->photos->remove($this->people->photo($idContact));
            }

            $this->people->save(
                $idContact,
                $nowe,
                array_map('intval', is_array($wiersz['categories'] ?? null) ? $wiersz['categories'] : []),
                !empty($wiersz['active']),
                $pozycja++
            );
        }

        $this->zapiszDomyslna($wiersze);

        $this->addFlash('success', $this->trans('Settings saved.', 'Modules.Quincecontacts.Admin'));

        return $this->redirectToRoute('quince_contacts');
    }

    /**
     * Osoba dla reszty sklepu: jedna albo żadna.
     *
     * Przełącznik stoi przy każdej osobie, więc sprzedawca może włączyć go dwa
     * razy. Sprawdzamy stan po zapisie, a nie w chwili kliknięcia: przeniesienie
     * roli na kogoś innego to jedno zaznaczenie i jedno odznaczenie w tym samym
     * formularzu i ma się udać bez ostrzeżenia. Dopiero dwa włączone naraz są
     * błędem — wtedy nie ruszamy niczego i mówimy, kogo dotyczy.
     *
     * Wiersz oznaczony koszem się nie liczy: właśnie znika.
     *
     * @param array<int|string,array<string,mixed>> $wiersze
     */
    private function zapiszDomyslna(array $wiersze): void
    {
        $wskazani = [];

        foreach (is_array($wiersze) ? $wiersze : [] as $idContact => $wiersz) {
            if (!empty($wiersz['default']) && empty($wiersz['remove'])) {
                $wskazani[] = (int) $idContact;
            }
        }

        if (count($wskazani) > 1) {
            $nazwy = [];

            foreach ($wskazani as $idContact) {
                $kontakt = new \Contact($idContact, (int) $this->getContext()->language->id);
                $nazwy[] = (string) $kontakt->name;
            }

            $this->addFlash('error', $this->trans(
                'Only one person can answer for the rest of the shop, and you picked %people%. Nothing was changed here.',
                'Modules.Quincecontacts.Admin',
                ['%people%' => implode(', ', $nazwy)]
            ));

            return;
        }

        $this->people->saveDefaultExpert($wskazani === [] ? 0 : $wskazani[0]);
    }

    /**
     * Nazwa i adres e-mail idą tam, gdzie mieszkają — do kontaktu sklepu.
     *
     * @param array<string,mixed> $wiersz
     */
    private function saveContact(int $idContact, array $wiersz): void
    {
        $kontakt = new \Contact($idContact);

        if (!\Validate::isLoadedObject($kontakt)) {
            return;
        }

        $nazwy = is_array($wiersz['name'] ?? null) ? $wiersz['name'] : [];

        foreach ($nazwy as $idLang => $nazwa) {
            $nazwa = trim((string) $nazwa);

            // Pusta nazwa zostawia poprzednią: kontakt bez nazwy nie da się
            // rozpoznać na żadnej liście, a formularz bywa wysyłany z pominiętym
            // tłumaczeniem.
            if ($nazwa !== '') {
                $kontakt->name[(int) $idLang] = $nazwa;
            }
        }

        $email = trim((string) ($wiersz['email'] ?? ''));

        if ($email !== '' && \Validate::isEmail($email)) {
            $kontakt->email = $email;
        }

        $kontakt->update();
    }

    /**
     * Telefony wszystkich osób, jednym zapytaniem.
     *
     * @return array<int,string>
     */
    private function phones(): array
    {
        $identyfikatory = array_column($this->people->all((int) $this->getContext()->language->id), 'id_contact');

        if ($identyfikatory === []) {
            return [];
        }

        $odczyt = $this->extraPropertiesReader->getMultipleExtraProperties(
            'contact',
            'id_contact',
            $identyfikatory,
            null,
            ShopConstraint::shop((int) $this->getContext()->shop->id)
        );

        $out = [];

        foreach ($odczyt as $idContact => $pola) {
            $out[(int) $idContact] = (string) ($pola['quince_contacts']['phone'] ?? '');
        }

        return $out;
    }

    /**
     * Telefon zapisujemy mechanizmem PrestaShopa, nie prosto do kolumny.
     *
     * Nazwa kolumny zależy od modułu i wersji, a mechanizm zna ją sam — dzięki
     * temu ta sama wartość wraca potem w natywnej karcie kontaktu i w API.
     */
    private function savePhone(int $idContact, string $phone): void
    {
        $this->extraProperties->writeAll(
            'contact',
            'id_contact',
            $idContact,
            ['quince_contacts' => ['phone' => trim($phone)]],
            ShopConstraint::shop((int) $this->getContext()->shop->id)
        );
    }

    /**
     * Adres listy kontaktów sklepu — ekran odsyła tam po dane, których sam
     * nie zmienia.
     */
    private function contactsRoute(): string
    {
        try {
            return $this->generateUrl('admin_contacts_index');
        } catch (\Throwable) {
            return '';
        }
    }
}
