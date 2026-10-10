<?php

namespace Tapao\ModerationAI\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Post\Post;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Tapao\ModerationAI\Moderator\ContentModerator;
use Tapao\ModerationAI\Action\ActionHandler;
use Tapao\ModerationAI\Model\ModerationLog;
use Symfony\Component\Console\Input\InputOption;

class RetrospectiveScanCommand extends AbstractCommand
{
    public function __construct(
        protected ContentModerator $moderator,
        protected ActionHandler $actionHandler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('moderationai:scan')
             ->setDescription('Retrospectively scan existing content for policy violations')
             ->addOption('type',  null, InputOption::VALUE_REQUIRED, 'Content type: post|discussion|user', 'post')
             ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max records to scan', 100)
             ->addOption('since', null, InputOption::VALUE_OPTIONAL, 'Only scan content since date (Y-m-d)');
    }

    protected function fire(): int
    {
        $type  = $this->input->getOption('type');
        $limit = (int) $this->input->getOption('limit');
        $since = $this->input->getOption('since');

        $moderator     = $this->moderator;
        $actionHandler = $this->actionHandler;

        $this->info("Starting retrospective scan: type=$type, limit=$limit");

        $query = match($type) {
            'post'       => Post::query()->where('type', 'comment')->whereNotNull('content'),
            'discussion' => Discussion::query()->whereNotNull('title'),
            'user'       => User::query(),
            default      => throw new \InvalidArgumentException("Unknown type: $type"),
        };

        $timestampCol = $type === 'user' ? 'joined_at' : 'created_at';

        if ($since) {
            $query->where($timestampCol, '>=', $since);
        }

        $count = 0;
        $query->orderByDesc($timestampCol)->take($limit)->get()->each(function ($record) use (
            $type, $moderator, $actionHandler, &$count
        ) {
            [$texts, $images] = match($type) {
                'post'       => [[strip_tags(is_string($record->content) ? $record->content : '')], is_string($record->content) ? $moderator->extractImageUrls($record->content) : []],
                'discussion' => [[$record->title ?? ''], []],
                'user'       => [[$record->username ?? ''], array_values(array_filter([$record->avatar_url]))],
            };

            $hash = $moderator->contentHash($texts, $images);

            // Skip already scanned content
            if (ModerationLog::where('content_type', $type)
                ->where('content_id', $record->id)
                ->where('content_hash', $hash)->exists()) {
                return;
            }

            $result = $moderator->moderate($texts, $images);
            if (!$result) return;

            $log = ModerationLog::create([
                'content_type'    => $type,
                'content_id'      => $record->id,
                'field'           => match($type) { 'post' => 'body', 'discussion' => 'title', 'user' => 'username/avatar' },
                'openai_model'    => $result->model,
                'flagged'         => $result->flagged,
                'categories'      => $result->categories,
                'category_scores' => $result->categoryScores,
                'content_hash'    => $hash,
            ]);

            if ($result->flagged) {
                $this->warn("  ⚑ Flagged $type #{$record->id}: " . json_encode($result->topViolation()));
            }

            $actionHandler->handle($result, $record, $type, $log);

            $count++;
        });

        $this->info("Scan complete. Processed $count records.");
        return 0;
    }
}
