<?php

namespace Tapao\ModerationAI\Listener;

use Flarum\User\Event\Saving;
use Flarum\Settings\SettingsRepositoryInterface;
use Tapao\ModerationAI\Moderator\ContentModerator;
use Tapao\ModerationAI\Action\ActionHandler;
use Tapao\ModerationAI\Model\ModerationLog;

class ModerateSavingUser
{
    public function __construct(
        protected ContentModerator $moderator,
        protected ActionHandler $actionHandler,
        protected SettingsRepositoryInterface $settings,
    ) {}

    public function handle(Saving $event): void
    {
        $user  = $event->user;
        $actor = $event->actor;

        $enabled = $this->settings->get('moderationai.enabled');
        if ($enabled !== null && !filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $exemptAdmins = filter_var($this->settings->get('moderationai.exempt_admins', false), FILTER_VALIDATE_BOOLEAN);
        if ($exemptAdmins && $actor && $actor->isAdmin()) {
            return;
        }

        // Check changed text fields
        $textFields = array_values(array_filter([
            ($user->isDirty('username') || empty($user->id)) ? $user->username : null,
            ($user->isDirty('display_name') || empty($user->id)) ? $user->display_name : null,
        ]));

        // Check avatar image
        $imageUrls = [];
        if (($user->isDirty('avatar_url') || empty($user->id))
            && filter_var($this->settings->get('moderationai.scan_images', true), FILTER_VALIDATE_BOOLEAN)) {
            $avatarUrl = $user->avatar_url;
            if ($avatarUrl && filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
                $imageUrls[] = $avatarUrl;
            }
        }

        if (empty($textFields) && empty($imageUrls)) {
            return;
        }

        $hash = $this->moderator->contentHash($textFields, $imageUrls);
        if (!empty($user->id)) {
            if (ModerationLog::where('content_type', 'user')
                ->where('content_id', $user->id)
                ->where('field', 'username/avatar')
                ->where('content_hash', $hash)
                ->exists()) {
                return;
            }
        }

        $result = $this->moderator->moderate($textFields, $imageUrls, $user);
        if (!$result) {
            return;
        }

        $log = ModerationLog::create([
            'content_type'    => 'user',
            'content_id'      => $user->id ?? 0,
            'field'           => 'username/avatar',
            'openai_model'    => $result->model,
            'flagged'         => $result->flagged,
            'categories'      => $result->categories,
            'category_scores' => $result->categoryScores,
            'content_hash'    => $hash,
        ]);

        if (empty($user->id) && method_exists($user, 'afterSave')) {
            $user->afterSave(function ($savedUser) use ($log) {
                if ($log && $savedUser && $savedUser->id) {
                    $log->update(['content_id' => $savedUser->id]);
                }
            });
        }

        $this->actionHandler->handle($result, $user, 'user', $log);
    }
}

