<?php

declare(strict_types=1);

namespace Local\MediaLibrary;

use Carbon\Carbon;
use Flarum\Console\AbstractCommand;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/** Two-phase purge is restartable. A purging row cannot be restored or newly referenced. */
final class PruneCommand extends AbstractCommand
{
    public function __construct(private ConnectionInterface $db, private PrivateStorage $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('media-library:prune')
            ->setDescription('Preview or purge unreferenced attachments archived for at least 7 days.')
            ->addOption('apply', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Actually remove files and metadata.')
            ->addOption('limit', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Maximum records per run.', 100);
    }

    protected function fire(): void
    {
        $limit = max(1, min(1000, (int) $this->input->getOption('limit')));
        $candidates = Asset::where(function ($q) {
            $q->where('status', 'purging')->orWhere(function ($q) {
                $q->where('status', 'archived')->where('archived_at', '<=', Carbon::now()->subDays(7));
            });
        })->whereDoesntHave('posts')->orderBy('id')->limit($limit)->get();
        $count = 0;
        foreach ($candidates as $candidate) {
            if (!$this->input->getOption('apply')) {
                $this->info('Eligible: '.$candidate->uuid);
                continue;
            }
            $asset = $this->db->transaction(function () use ($candidate) {
                $asset = Asset::whereKey($candidate->id)->lockForUpdate()->first();
                if (!$asset || !in_array($asset->status, ['archived', 'purging'], true) || $asset->posts()->exists()
                    || ($asset->status === 'archived' && Carbon::parse($asset->archived_at)->gt(Carbon::now()->subDays(7)))) {
                    return null;
                }
                $asset->status = 'purging';
                $asset->save();
                return $asset;
            });
            if (!$asset) {
                continue;
            }
            $this->storage->remove($asset->uuid);
            $this->db->transaction(function () use ($asset) {
                // Same lock order as upload: user first. No reference can target a purging row.
                $user = User::whereKey($asset->user_id)->lockForUpdate()->first();
                $locked = Asset::whereKey($asset->id)->lockForUpdate()->first();
                if (!$locked) {
                    return;
                }
                $locked->delete();
                if ($user) {
                    $user->local_media_bytes = max(0, (int) $user->local_media_bytes - $locked->size - (int) $locked->derived_bytes);
                    $user->local_media_count = max(0, (int) $user->local_media_count - 1);
                    $user->save();
                }
            });
            $count++;
        }
        $this->info($this->input->getOption('apply') ? 'Purged: '.$count : 'Dry run: '.$candidates->count().' eligible. No files changed.');
    }
}
