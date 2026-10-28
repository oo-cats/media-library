<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Flarum\Console\AbstractCommand;

/** Recover files left by a process crash before the upload transaction committed. */
final class OrphansCommand extends AbstractCommand
{
    public function __construct(private PrivateStorage $storage, private Variants $variants)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('media-library:orphans')
            ->setDescription('Preview orphan files older than 24 hours, in batches of 100 IDs.')
            ->addOption('apply', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Actually remove orphan files.');
    }

    protected function fire(): void
    {
        $batch = [];
        $count = 0;
        foreach ($this->storage->storedIds() as $uuid) {
            $batch[$uuid] = true;
            if (count($batch) >= 100) {
                $count += $this->process(array_keys($batch));
                $batch = [];
            }
        }
        $count += $this->process(array_keys($batch));
        $this->info(($this->input->getOption('apply') ? 'Removed: ' : 'Dry run eligible: ').$count);
    }

    private function process(array $uuids): int
    {
        if (!$uuids) {
            return 0;
        }
        $assets = Asset::whereIn('uuid', $uuids)->pluck('uuid', 'id')->all();
        $registered = array_fill_keys(array_values($assets), true);
        $count = 0;
        foreach ($this->variants->orphanPaths($assets) as $path) {
            if ($this->input->getOption('apply') && !unlink($path)) {
                throw new \RuntimeException('Cannot remove orphan variant; retry maintenance.');
            }
            $count++;
        }
        foreach ($uuids as $uuid) {
            if (!isset($registered[$uuid]) && $this->storage->isOldOrphan($uuid, time() - 86400)) {
                $this->info('Orphan: '.$uuid);
                if ($this->input->getOption('apply')) {
                    $this->storage->remove($uuid);
                }
                $count++;
            }
        }
        return $count;
    }
}
