<?php

namespace Tapao\ModerationAI\Trust;

use Carbon\Carbon;
use Flarum\User\User;
use Flarum\Settings\SettingsRepositoryInterface;
use Tapao\ModerationAI\Model\ModerationLog;

class UserTrustScore
{
    public function __construct(protected SettingsRepositoryInterface $settings) {}

    /**
     * Recalculate and persist a user's trust score based on their moderation history.
     * Score 0–100. High score = fewer checks.
     */
    public function recalculate(User $user): int
    {
        $totalPosts   = max($user->comment_count, 1);
        $flaggedCount = ModerationLog::where('content_type', 'post')
            ->whereIn('content_id', function ($q) use ($user) {
                $q->select('id')->from('posts')->where('user_id', $user->id);
            })
            ->where('flagged', true)
            ->count();

        $cleanRatio = 1 - ($flaggedCount / $totalPosts);
        // abs() keeps this correct across Carbon 2 (Flarum 1.x, unsigned) and
        // Carbon 3 (Flarum 2.x, signed — would otherwise return a negative).
        $ageDays    = $user->joined_at
            ? abs(Carbon::now()->diffInDays($user->joined_at))
            : 0;

        // Score formula: clean ratio (70%) + account age factor (30%, max 30 days = full)
        $ageFactor = min($ageDays / 30, 1.0);
        $score     = (int) round(($cleanRatio * 70) + ($ageFactor * 30));
        $score     = max(0, min(100, $score));

        $user->moderation_trust_score = $score;
        if (method_exists($user, 'saveQuietly')) {
            $user->saveQuietly();
        } else {
            User::withoutEvents(function () use ($user) {
                $user->save();
            });
        }

        return $score;
    }

    /**
     * Returns true if we should skip moderation for this user entirely.
     * Threshold: trust score >= configured value (default 90).
     */
    public function shouldSkip(User $user): bool
    {
        $skipThreshold = (int) $this->settings->get('moderationai.trust_skip_threshold', 90);
        return ($user->moderation_trust_score ?? 50) >= $skipThreshold;
    }

    /**
     * Returns true if user should always be moderated synchronously.
     * Applies to new users with low post count.
     */
    public function requiresSync(User $user): bool
    {
        return $user->comment_count <= 5
            || ($user->moderation_trust_score ?? 50) <= 20;
    }
}
