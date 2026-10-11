<?php

namespace Tapao\ModerationAI\Listener;

use Flarum\Discussion\Event\Saving;
use Flarum\Settings\SettingsRepositoryInterface;
use Tapao\ModerationAI\Moderator\ContentModerator;
use Tapao\ModerationAI\Action\ActionHandler;
use Tapao\ModerationAI\Model\ModerationLog;

class ModerateSavingDiscussion
{
    public function __construct(
        protected ContentModerator $moderator,
        protected ActionHandler $actionHandler,
        protected SettingsRepositoryInterface $settings,
    ) {}

    public function handle(Saving $event): void
    {
        $discussion = $event->discussion;
        $actor      = $event->actor;

        $enabled = $this->settings->get('moderationai.enabled');
        if ($enabled !== null && !filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $exemptAdmins = filter_var($this->settings->get('moderationai.exempt_admins', false), FILTER_VALIDATE_BOOLEAN);
        if ($exemptAdmins && $actor->isAdmin()) {
            return;
        }

        $title = trim($discussion->title ?? ($event->data['attributes']['title'] ?? ''));
        if (empty($title)) {
            return;
        }

        $hash = $this->moderator->contentHash([$title]);
        if (!empty($discussion->id)) {
            if (ModerationLog::where('content_type', 'discussion')
                ->where('content_id', $discussion->id)
                ->where('field', 'title')
                ->where('content_hash', $hash)->exists()) {
                return;
            }
        }

        // Check private message setting
        if (($discussion->is_private ?? false)
            && !filter_var($this->settings->get('moderationai.scan_private_messages', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $result = $this->moderator->moderate([$title], [], $actor);
        if (!$result) {
            return;
        }

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

        if (empty($discussion->id) && method_exists($discussion, 'afterSave')) {
            $discussion->afterSave(function ($savedDiscussion) use ($log) {
                if ($log && $savedDiscussion && $savedDiscussion->id) {
                    $log->update(['content_id' => $savedDiscussion->id]);
                }
            });
        }

        $this->actionHandler->handle($result, $discussion, 'discussion', $log);
    }
}

