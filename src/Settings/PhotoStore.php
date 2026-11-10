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

use ImageManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Zdjęcia osób.
 *
 * Plików nie trzymamy w katalogu modułu: odinstalowanie albo aktualizacja modułu
 * skasowałaby wtedy zdjęcia razem z kodem. Idą tam, gdzie sklep trzyma resztę
 * swoich obrazów, pod własnym podkatalogiem.
 */
final class PhotoStore
{
    /** Ścieżka względem katalogu obrazów sklepu. */
    private const SUBDIR = 'quince/contacts/';

    /** Typy, które przyjmujemy, i rozszerzenie zapisywanego pliku. */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    /** Górna granica rozmiaru pliku: zdjęcie osoby nie potrzebuje więcej. */
    private const MAX_BYTES = 2097152;

    public function dir(): string
    {
        return _PS_IMG_DIR_ . self::SUBDIR;
    }

    public function url(string $file): string
    {
        return $file === '' ? '' : _PS_IMG_ . self::SUBDIR . $file;
    }

    public function ensureDir(): bool
    {
        $katalog = $this->dir();

        return is_dir($katalog) || mkdir($katalog, 0755, true);
    }

    /**
     * Przyjęcie wgranego pliku.
     *
     * Zwraca nazwę pliku albo null, gdy nic nie wgrano lub plik nie przeszedł
     * sprawdzenia. Sprawdzamy sam plik, nie jego nazwę: rozszerzenie da się
     * dopisać do czegokolwiek.
     */
    public function accept(?UploadedFile $plik, int $idContact): ?string
    {
        if ($plik === null || !$plik->isValid()) {
            return null;
        }

        if ($plik->getSize() > self::MAX_BYTES) {
            return null;
        }

        // Trzy sprawdzenia, bo każde da się obejść osobno: rozszerzenie dopisze
        // każdy, typ MIME wyznacza serwer z zawartości, a `isRealImage` otwiera
        // plik i patrzy, czy w środku naprawdę jest obraz.
        $rozszerzenie = self::EXTENSIONS[$plik->getMimeType() ?? ''] ?? null;

        if ($rozszerzenie === null) {
            return null;
        }

        if (!ImageManager::isRealImage($plik->getPathname())) {
            return null;
        }

        if (!$this->ensureDir()) {
            return null;
        }

        // Nazwa z niepowtarzalnym członem: przeglądarka trzyma poprzednie zdjęcie
        // w pamięci podręcznej i bez zmiany nazwy pokazywałaby je po podmianie.
        $nazwa = $idContact . '-' . uniqid('', false) . '.' . $rozszerzenie;

        // Plik nie trafia na dysk taki, jaki przyszedł — zostaje przepisany
        // piksel po pikselu. Wszystko, co ktoś doczepił do obrazu poza samym
        // obrazem, ginie po drodze.
        if (!ImageManager::resize($plik->getPathname(), $this->dir() . $nazwa, null, null, $rozszerzenie)) {
            return null;
        }

        return $nazwa;
    }

    /** Skasowanie pliku, gdy osoba znika albo dostaje nowe zdjęcie. */
    public function remove(string $file): void
    {
        if ($file === '') {
            return;
        }

        $sciezka = $this->dir() . basename($file);

        if (is_file($sciezka)) {
            @unlink($sciezka);
        }
    }

    /**
     * Sprzątanie po odinstalowaniu modułu.
     *
     * Zdjęcia bez wierszy w bazie to pliki, których nikt już nie odnajdzie ani
     * nie skasuje z panelu — zostałyby na dysku na zawsze.
     */
    public function purge(): void
    {
        foreach (glob($this->dir() . '*') ?: [] as $plik) {
            if (is_file($plik)) {
                @unlink($plik);
            }
        }
    }
}
