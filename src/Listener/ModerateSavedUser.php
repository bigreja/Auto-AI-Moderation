<?php

namespace Tapao\ModerationAI\Listener;

use Flarum\User\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use Tapao\ModerationAI\Moderator\ContentModerator;
use Tapao\ModerationAI\Action\ActionHandler;
use Tapao\ModerationAI\Model\ModerationLog;

class ModerateSavedUser
{
    public function __construct(
        protected ContentModerator $moderator,
        protected ActionHandler $actionHandler,
        protected SettingsRepositoryInterface $settings,
    ) {}

    public function handle(Saved $event): void
    {
        $user  = $event->user;
        $actor = $event->actor;

        if ($actor && $actor->isAdmin()) return;

        $isNew = $user->wasRecentlyCreated;

        // Check changed text fields on create or update
        $textFields = array_values(array_filter([
            ($isNew || $user->wasChanged('username'))     ? $user->username     : null,
            ($isNew || $user->wasChanged('display_name')) ? $user->display_name : null,
        ]));

        // Check avatar image
        $imageUrls = [];
        if (($isNew || $user->wasChanged('avatar_url'))
            && $this->settings->get('moderationai.scan_images', true)) {
            $avatarUrl = $user->avatar_url;
            if ($avatarUrl && filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
                $imageUrls[] = $avatarUrl;
            }
        }

        if (empty($textFields) && empty($imageUrls)) return;

        $hash = $this->moderator->contentHash($textFields, $imageUrls);
        if ($user->id && ModerationLog::where('content_type', 'user')
            ->where('content_id', $user->id)
            ->where('field', 'username/avatar')
            ->where('content_hash', $hash)
            ->exists()) {
            return;
        }

        $result = $this->moderator->moderate($textFields, $imageUrls, $user);
        if (!$result) return;

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

        $this->actionHandler->handle($result, $user, 'user', $log);
    }
}
