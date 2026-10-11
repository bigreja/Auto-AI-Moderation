<?php

namespace Tapao\ModerationAI\Listener;

use Flarum\Post\Event\Saving;
use Flarum\Settings\SettingsRepositoryInterface;
use Tapao\ModerationAI\Moderator\ContentModerator;
use Tapao\ModerationAI\Action\ActionHandler;
use Tapao\ModerationAI\Model\ModerationLog;
use Tapao\ModerationAI\Queue\ModerationJob;
use Tapao\ModerationAI\Trust\UserTrustScore;
use Illuminate\Contracts\Queue\Queue;

class ModerateSavingPost
{
    public function __construct(
        protected ContentModerator $moderator,
        protected ActionHandler $actionHandler,
        protected SettingsRepositoryInterface $settings,
        protected Queue $queue,
        protected UserTrustScore $trustScore,
    ) {}

    public function handle(Saving $event): void
    {
        $post  = $event->post;
        $actor = $event->actor;

        // Only moderate standard comment posts (skip event posts where content may be an array)
        if ($post->type !== null && $post->type !== 'comment') {
            return;
        }

        // Check if extension is enabled
        $enabled = $this->settings->get('moderationai.enabled');
        if ($enabled !== null && !filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        // Exempt admins if explicitly configured (default false so admin testing via Postman works)
        $exemptAdmins = filter_var($this->settings->get('moderationai.exempt_admins', false), FILTER_VALIDATE_BOOLEAN);
        if ($exemptAdmins && $actor->isAdmin()) {
            return;
        }

        // Exempt configured user groups
        $exemptGroupsJson = $this->settings->get('moderationai.exempt_groups', '[]');
        $exemptGroups = json_decode($exemptGroupsJson, true);
        if (is_array($exemptGroups) && !empty($exemptGroups)) {
            if ($actor->groups()->whereIn('id', $exemptGroups)->exists()) {
                return;
            }
        }

        $rawBody = is_string($post->content)
            ? $post->content
            : ($event->data['attributes']['content'] ?? '');

        if (!is_string($rawBody) || empty(trim($rawBody))) {
            return;
        }

        $plainText = trim(strip_tags($rawBody));
        $imageUrls = $this->moderator->extractImageUrls($rawBody);

        $hash   = $this->moderator->contentHash([$plainText], $imageUrls);
        $postId = $post->id;

        // Skip dedup check if unchanged (for edits)
        if ($postId !== null) {
            $existing = ModerationLog::where('content_type', 'post')
                ->where('content_id', $postId)
                ->where('field', 'body')
                ->where('content_hash', $hash)
                ->exists();
            if ($existing) {
                return;
            }
        }

        // Async mode check (only for already-persisted posts)
        $asyncMode = $this->settings->get('moderationai.mode', 'sync') === 'async';
        $needsSync = $this->trustScore->requiresSync($actor);

        if ($asyncMode && !$needsSync && $postId !== null) {
            $this->queue->push(new ModerationJob('post', $postId, 'body', [$plainText], $imageUrls));
            return;
        }

        // Synchronous path
        $result = $this->moderator->moderate([$plainText], $imageUrls, $actor);
        if (!$result) {
            return;
        }

        $log = ModerationLog::create([
            'content_type'    => 'post',
            'content_id'      => $postId ?? 0,
            'field'           => 'body',
            'openai_model'    => $result->model,
            'flagged'         => $result->flagged,
            'categories'      => $result->categories,
            'category_scores' => $result->categoryScores,
            'content_hash'    => $hash,
        ]);

        // If post has no ID yet (brand new post), update log content_id after save
        if ($postId === null && method_exists($post, 'afterSave')) {
            $post->afterSave(function ($savedPost) use ($log) {
                if ($log && $savedPost && $savedPost->id) {
                    $log->update(['content_id' => $savedPost->id]);
                }
            });
        }

        $this->actionHandler->handle($result, $post, 'post', $log);
    }
}

