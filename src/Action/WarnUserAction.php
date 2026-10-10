<?php

namespace Tapao\ModerationAI\Action;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;

class WarnUserAction
{
    public function __construct(protected SettingsRepositoryInterface $settings) {}

    public function execute(mixed $user, string $category = '', float $score = 0.0): void
    {
        if (!$user) return;

        // fof/warnings integration — only when the extension is installed
        if (class_exists(\FoF\Warnings\Warning::class)) {
            $this->issueFofWarning($user, $category, $score);
            return;
        }

        // flarum/suspend integration — suspend user briefly as fallback
        if (class_exists(\Flarum\Suspend\Event\Suspended::class)
            && $this->settings->get('moderationai.warn_suspend_hours', 0) > 0) {
            $this->suspendUser($user);
            return;
        }

        // Bare-minimum fallback: send a Flarum system notification
        $this->sendNotification($user, $category, $score);
    }

    private function issueFofWarning(mixed $user, string $category, float $score): void
    {
        $warning = new \FoF\Warnings\Warning();
        $warning->user_id    = $user->id;
        $warning->created_by = null; // system-issued
        $warning->reason     = $this->settings->get(
            'moderationai.warn_reason',
            'Automated moderation: content policy violation'
        );
        $warning->reason_detail = $category
            ? "Category: $category (" . round($score * 100) . "% confidence)"
            : null;
        $warning->points_given = (int) $this->settings->get('moderationai.warn_points', 1);
        $warning->created_at   = Carbon::now();
        $warning->save();

        // Recalculate user strike count so fof/warnings suspension thresholds fire
        if (method_exists($warning, 'afterSave')) {
            $warning->afterSave();
        }
    }

    private function suspendUser(mixed $user): void
    {
        $hours = (int) $this->settings->get('moderationai.warn_suspend_hours', 24);
        $user->suspended_until = Carbon::now()->addHours($hours);
        $user->save();
    }

    private function sendNotification(mixed $user, string $category, float $score): void
    {
        // No warning extension available — log only
        // A proper Flarum notification would require a registered NotificationDriver;
        // that is out of scope without a UI to display it.
        error_log(sprintf(
            'ModerationAI: User %d warned — %s (%.0f%%)',
            $user->id, $category ?: 'policy violation', $score * 100
        ));
    }
}
