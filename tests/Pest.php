<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
use Phattarachai\DbSnapshotSyncLaravel\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * A fake disk that throws on listing a missing directory, as Google Drive
 * (masbug/flysystem-google-drive-ext) does, where local and S3 list it as empty.
 *
 * @param  array<string, mixed>  $config
 */
function driveLikeDisk(string $name, array $config = []): FilesystemAdapter
{
    $fake = Storage::fake($name, $config);

    $disk = new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
    {
        public function files($directory = null, $recursive = false)
        {
            if (! $this->directoryExists((string) $directory)) {
                throw UnableToReadFile::fromLocation((string) $directory, 'File not found');
            }

            return parent::files($directory, $recursive);
        }
    };

    Storage::set($name, $disk);

    return $disk;
}
