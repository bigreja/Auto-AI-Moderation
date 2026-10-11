<?php

namespace Tapao\ModerationAI\Action;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Tapao\ModerationAI\Moderator\ModerationResult;
use Tapao\ModerationAI\Model\ModerationLog;
use Tapao\ModerationAI\Trust\UserTrustScore;
use Tapao\ModerationAI\Webhook\WebhookNotifier;

class ActionHandler
{
    const CATEGORY_PRIORITY = [
        'sexual/minors',
        'violence/graphic',
        'hate/threatening',
        'sexual',
        'violence',
        'hate',
        'harassment/threatening',
        'self-harm/intent',
        'self-harm',
        'harassment',
        'illicit/violent',
        'illicit',
    ];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected FlagAction     $flagAction,
        protected HideAction     $hideAction,
        protected DeleteAction   $deleteAction,
        protected WarnUserAction $warnAction,
        protected WebhookNotifier $webhook,
        protected UserTrustScore $trustScore,
    ) {}

    public function handle(ModerationResult $result, mixed $content, string $contentType, ?ModerationLog $log = null): void
    {
        $userToScore = $this->resolveUser($content, $contentType);

        if (!$result->flagged) {
            if ($userToScore) {
                $this->trustScore->recalculate($userToScore);
            }
            return;
        }

        $actionTaken     = 'none';
        $matchedCategory = null;
        $matchedScore    = 0.0;
        $matchedAction   = 'flag';

        foreach (self::CATEGORY_PRIORITY as $category) {
            $score     = (float) ($result->categoryScores[$category] ?? 0);
            $threshold = (float) $this->settings->get("moderationai.threshold.$category", 0.80);
            $action    = $this->settings->get("moderationai.action.$category", 'flag');

            // Trigger if score exceeds threshold OR if OpenAI flagged this category
            if ($score >= $threshold || !empty($result->categories[$category])) {
                $matchedCategory = $category;
                $matchedScore    = $score;
                $matchedAction   = $action;
                break;
            }
        }

        // If flagged by AI but no category matched threshold, fall back to top violation
        if (!$matchedCategory) {
            $top = $result->topViolation();
            if ($top) {
                $matchedCategory = $top['category'];
                $matchedScore    = $top['score'];
                $matchedAction   = $this->settings->get("moderationai.action.{$matchedCategory}", 'flag');
            } else {
                $matchedCategory = 'general';
                $matchedScore    = 1.0;
                $matchedAction   = 'flag';
            }
        }

        if ($matchedCategory) {
            match($matchedAction) {
                'flag'   => $this->flagAction->execute($content, $contentType, $matchedCategory, $matchedScore),
                'hide'   => $this->hideAction->execute($content),
                'delete' => $this->deleteAction->execute($content),
                'warn'   => $this->warnAction->execute($userToScore, $matchedCategory, $matchedScore),
                default  => null,
            };
            $actionTaken = $matchedAction;
        }

        if ($log) {
            $log->update(['action_taken' => $actionTaken]);
        }

        $violation = $result->topViolation();
        if ($violation) {
            $this->webhook->notify($contentType, $content, $violation, $actionTaken);
        }

        if ($userToScore) {
            $this->trustScore->recalculate($userToScore);
        }
    }

    private function resolveUser(mixed $content, string $contentType): ?User
    {
        if ($contentType === 'user' && $content instanceof User) {
            return $content;
        }

        // For posts, discussions, polls, bios — load the user relationship
        if (isset($content->user_id)) {
            return \Flarum\User\User::find($content->user_id);
        }

        return null;
    }
}
