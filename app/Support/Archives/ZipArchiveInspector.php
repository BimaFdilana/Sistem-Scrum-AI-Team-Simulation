<?php

namespace App\Support\Archives;

use InvalidArgumentException;
use ZipArchive;

class ZipArchiveInspector
{
    private const MAX_ENTRIES = 10000;
    private const MAX_ENTRY_BYTES = 268435456;
    private const MAX_TOTAL_UNCOMPRESSED_BYTES = 1073741824;
    private const MAX_COMPRESSION_RATIO = 200;

    public function assertSafeMetadata(string $archivePath): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($archivePath);

        if ($opened !== true) {
            throw new InvalidArgumentException('File tidak dapat dibuka sebagai arsip ZIP yang valid.');
        }

        try {
            if ($zip->numFiles < 1) {
                throw new InvalidArgumentException('Arsip ZIP kosong.');
            }

            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new InvalidArgumentException('Arsip ZIP memiliki terlalu banyak entri.');
            }

            $totalUncompressedBytes = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $name = $entry['name'] ?? '';

                $this->assertSafeEntryName($name);
                $this->assertRegularFileOrDirectory($zip, $index);

                $size = (int) ($entry['size'] ?? 0);
                $compressedSize = (int) ($entry['comp_size'] ?? 0);

                if ($size < 0 || $size > self::MAX_ENTRY_BYTES) {
                    throw new InvalidArgumentException('Arsip ZIP berisi file yang melewati batas ukuran per file.');
                }

                $totalUncompressedBytes += $size;

                if ($totalUncompressedBytes > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
                    throw new InvalidArgumentException('Total ukuran ekstraksi ZIP melewati batas yang diizinkan.');
                }

                if ($size > 0 && ($compressedSize === 0 || $size / $compressedSize > self::MAX_COMPRESSION_RATIO)) {
                    throw new InvalidArgumentException('Rasio kompresi ZIP terlalu tinggi dan berisiko menjadi archive bomb.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function assertSafeEntryName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new InvalidArgumentException('Arsip ZIP berisi nama file yang tidak valid.');
        }

        $normalized = str_replace('\\', '/', $name);

        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) === 1) {
            throw new InvalidArgumentException('Arsip ZIP berisi path absolut yang tidak diizinkan.');
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new InvalidArgumentException('Arsip ZIP berisi path traversal yang tidak diizinkan.');
            }
        }
    }

    private function assertRegularFileOrDirectory(ZipArchive $zip, int $index): void
    {
        $operatingSystem = 0;
        $externalAttributes = 0;

        if (! $zip->getExternalAttributesIndex($index, $operatingSystem, $externalAttributes)) {
            return;
        }

        if ($operatingSystem !== ZipArchive::OPSYS_UNIX) {
            return;
        }

        $fileType = ($externalAttributes >> 16) & 0170000;
        $allowedTypes = [0, 0040000, 0100000];

        if (! in_array($fileType, $allowedTypes, true)) {
            throw new InvalidArgumentException('Arsip ZIP berisi symlink atau special file yang tidak diizinkan.');
        }
    }
}
