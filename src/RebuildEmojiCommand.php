<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Flarum\Console\AbstractCommand;
use Flarum\User\User;
use Symfony\Component\Console\Input\InputOption;

final class RebuildEmojiCommand extends AbstractCommand
{
    public function __construct(private Variants $variants)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('media-library:emoji-rebuild')
            ->setDescription('Preview or rebuild a bounded page of active emoji using current processing settings.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Generate variants; originals remain unchanged.')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Resume after the previous internal cursor.', 0)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum records in this batch.', 50);
    }

    protected function fire(): void
    {
        $limit = max(1, min(100, (int) $this->input->getOption('limit')));
        $rows = Asset::where('is_emoji', true)->where('status', 'active')
            ->where('id', '>', max(0, (int) $this->input->getOption('after')))->orderBy('id')->limit($limit)->get();
        $failed = 0;
        foreach ($rows as $asset) {
            $this->info('Emoji: '.$asset->uuid);
            if (!$this->input->getOption('apply')) {
                continue;
            }
            try {
                $owner = User::findOrFail($asset->user_id);
                $this->variants->prepare($owner, $asset, true);
                $this->variants->pruneRetired($asset);
            } catch (\Throwable $error) {
                // Bounded and sanitized: no paths, request bodies or raw exception text.
                $this->error('Failed: '.$asset->uuid.' ['.get_class($error).']');
                $failed++;
            }
        }
        $this->info('Next --after='.($rows->last()->id ?? $this->input->getOption('after')).'; failed='.$failed);
        if ($failed) {
            throw new \RuntimeException('Some emoji failed; existing variants were preserved. Retry this batch after correcting settings or quota.');
        }
    }
}
