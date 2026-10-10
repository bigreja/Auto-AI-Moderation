<?php

namespace Tapao\ModerationAI\Listener;

use Flarum\Discussion\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use Tapao\ModerationAI\Moderator\ContentModerator;
use Tapao\ModerationAI\Action\ActionHandler;
use Tapao\ModerationAI\Model\ModerationLog;

class ModerateSavedDiscussion
{
    public function __construct(
        protected ContentModerator $moderator,
        protected ActionHandler $actionHandler,
        protected SettingsRepositoryInterface $settings,
    ) {}

    public function handle(Saved $event): void
    {
        $discussion = $event->discussion;
        $actor      = $event->actor;

        if ($actor->isAdmin()) return;

        $title = trim($discussion->title ?? '');
        if (empty($title)) return;

        // Skip if title unchanged
        $hash = $this->moderator->contentHash([$title]);
        if (ModerationLog::where('content_type', 'discussion')
            ->where('content_id', $discussion->id ?? 0)
            ->where('field', 'title')
            ->where('content_hash', $hash)->exists()) return;

        // Check private message setting
        if (($discussion->is_private ?? false)
            && !$this->settings->get('moderationai.scan_private_messages', true)) {
            return;
        }

        $result = $this->moderator->moderate([$title], [], $actor);
        if (!$result) return;

        $log = ModerationLog::create([
            'content_type'    => 'discussion',
            'content_id'      => $discussion->id ?? 0,
            'field'           => 'title',
            'openai_model'    => $result->model,
            'flagged'         => $result->flagged,
            'categories'      => $result->categories,
            'category_scores' => $result->categoryScores,
            'content_hash'    => $hash,
        ]);

        $this->actionHandler->handle($result, $discussion, 'discussion', $log);
    }
}
