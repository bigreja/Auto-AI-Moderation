<?php

namespace Tapao\ModerationAI\Listener;

use Flarum\Post\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use Tapao\ModerationAI\Moderator\ContentModerator;
use Tapao\ModerationAI\Action\ActionHandler;
use Tapao\ModerationAI\Model\ModerationLog;
use Tapao\ModerationAI\Queue\ModerationJob;
use Tapao\ModerationAI\Trust\UserTrustScore;
use Illuminate\Contracts\Queue\Queue;

class ModerateSavedPost
{
    public function __construct(
        protected ContentModerator $moderator,
        protected ActionHandler $actionHandler,
        protected SettingsRepositoryInterface $settings,
        protected Queue $queue,
        protected UserTrustScore $trustScore,
    ) {}

    public function handle(Saved $event): void
    {
        $post  = $event->post;
        $actor = $event->actor;

        // Only moderate standard comment posts (skip event posts where content may be an array)
        if ($post->type !== 'comment') {
            return;
        }

        // Exempt admins and configured groups
        $exemptGroups = json_decode($this->settings->get('moderationai.exempt_groups', '[1]'), true);
        if (!is_array($exemptGroups)) $exemptGroups = [1];
        if ($actor->isAdmin() || $actor->groups()->whereIn('id', $exemptGroups)->exists()) return;

        $rawBody   = is_string($post->content) ? $post->content : '';
        $plainText = strip_tags($rawBody);
        $imageUrls = $this->moderator->extractImageUrls($rawBody);

        $hash   = $this->moderator->contentHash([$plainText], $imageUrls);
        $postId = $post->id;

        // Skip dedup check if unchanged
        if ($postId !== null) {
            $existing = ModerationLog::where('content_type', 'post')
                ->where('content_id', $postId)
                ->where('field', 'body')
                ->where('content_hash', $hash)
                ->exists();
            if ($existing) return;
        }

        // Async mode check
        $asyncMode = $this->settings->get('moderationai.mode', 'sync') === 'async';
        $needsSync = $this->trustScore->requiresSync($actor);

        if ($asyncMode && !$needsSync && $postId !== null) {
            $this->queue->push(new ModerationJob('post', $postId, 'body', [$plainText], $imageUrls));
            return;
        }

        // Synchronous path
        $result = $this->moderator->moderate([$plainText], $imageUrls, $actor);
        if (!$result) return;

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

        $this->actionHandler->handle($result, $post, 'post', $log);
    }
}
