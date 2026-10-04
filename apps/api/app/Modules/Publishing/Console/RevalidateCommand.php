<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Console;

use App\Modules\Publishing\Jobs\DispatchRevalidation;
use App\Modules\Publishing\Support\CacheTags;
use Illuminate\Console\Command;

/**
 * `hw:revalidate [tags...]` — marks cached web pages stale. With no tags,
 * every public page: the post-deploy step in docs/06 §17 ("DispatchRevalidation
 * for all tags").
 */
final class RevalidateCommand extends Command
{
    protected $signature = 'hw:revalidate
                            {tags?* : Tags to refresh, e.g. index place:koshi/sunsari/example (default: public)}
                            {--queue : Queue it, with retries, instead of sending now — for container boot, when the web tier may not be up yet}';

    protected $description = 'Tell the web tier to refresh cached pages';

    public function handle(): int
    {
        /** @var list<string> $tags */
        $tags = $this->argument('tags') ?: [CacheTags::PUBLIC];

        if ($this->option('queue')) {
            DispatchRevalidation::dispatch($tags);
            $this->components->info('Queued: '.implode(', ', $tags));

            return self::SUCCESS;
        }

        DispatchRevalidation::dispatchSync($tags);

        $this->components->info('Sent: '.implode(', ', $tags));

        return self::SUCCESS;
    }
}
