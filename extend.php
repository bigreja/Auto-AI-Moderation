<?php

use Flarum\Extend;
use Tapao\ModerationAI\Listener;
use Tapao\ModerationAI\Console\RetrospectiveScanCommand;
use Tapao\ModerationAI\Api\Controller;

return [
    // ── Frontend ──────────────────────────────────────────────────────────
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    // ── Core Events ───────────────────────────────────────────────────────
    (new Extend\Event())
        ->listen(\Flarum\Post\Event\Saving::class,       Listener\ModerateSavingPost::class)
        ->listen(\Flarum\Discussion\Event\Saving::class, Listener\ModerateSavingDiscussion::class)
        ->listen(\Flarum\User\Event\Saving::class,       Listener\ModerateSavingUser::class),

    // ── Bridge: blomstra/flarum-ext-upload ────────────────────────────────
    (new Extend\Conditional())
        ->whenExtensionEnabled('blomstra-upload', fn () => [
            (new Extend\Event())
                ->listen(\Blomstra\Upload\Events\UploadingFile::class,
                         Listener\Bridge\ModerateUploadingFile::class),
        ]),

    // ── Bridge: fof/upload ────────────────────────────────────────────────
    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-upload', fn () => [
            (new Extend\Event())
                ->listen(\FoF\Upload\Events\UploadingFile::class,
                         Listener\Bridge\ModerateUploadingFile::class),
        ]),

    // ── Bridge: fof/polls ────────────────────────────────────────────────
    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-polls', fn () => [
            (new Extend\Event())
                ->listen(\FoF\Polls\Events\SavingPoll::class,
                         Listener\Bridge\ModerateSavingPoll::class),
        ]),

    // ── Bridge: fof/user-bio ─────────────────────────────────────────────
    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-user-bio', fn () => [
            (new Extend\Event())
                ->listen(\FoF\UserBio\Event\Saving::class,
                         Listener\Bridge\ModerateSavingBio::class),
        ]),

    // ── Bridge: fof/profile-image-crop ───────────────────────────────────
    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-profile-image-crop', fn () => [
            (new Extend\Event())
                ->listen(\FoF\ProfileImageCrop\Events\SavingProfileImage::class,
                         Listener\Bridge\ModerateSavingProfileImage::class),
        ]),

    // ── Bridge: fof/warnings ─────────────────────────────────────────────
    // WarnUserAction auto-detects fof/warnings at runtime via class_exists();
    // no extra event listener needed — the bridge is inside WarnUserAction itself.
    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-warnings', fn () => [
            (new Extend\Settings())
                ->serializeToForum('moderationai.warn_points',  'moderationai.warn_points',  'intval', 1)
                ->serializeToForum('moderationai.warn_reason',  'moderationai.warn_reason',  null, ''),
        ]),

    // ── Bridge: flarum/suspend ────────────────────────────────────────────
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-suspend', fn () => [
            (new Extend\Settings())
                ->serializeToForum('moderationai.warn_suspend_hours', 'moderationai.warn_suspend_hours', 'intval', 0),
        ]),

    // ── Admin API Routes (log review queue & test connection) ─────────────
    (new Extend\Routes('api'))
        ->get('/moderation-logs',               'moderationai.logs.index',   Controller\ListModerationLogsController::class)
        ->post('/moderation-logs/{id}/approve', 'moderationai.logs.approve', Controller\ApproveModerationLogController::class)
        ->post('/moderation-logs/{id}/reject',  'moderationai.logs.reject',  Controller\RejectModerationLogController::class)
        ->patch('/moderation-logs/{id}',        'moderationai.logs.update',  Controller\UpdateModerationLogController::class)
        ->post('/moderationai-test',            'moderationai.test',         Controller\TestConnectionController::class),

    // ── Artisan Commands ─────────────────────────────────────────────────
    (new Extend\Console())
        ->command(RetrospectiveScanCommand::class),

    // ── Settings serialized to forum ─────────────────────────────────────
    (new Extend\Settings())
        ->serializeToForum('moderationai.enabled', 'moderationai.enabled', 'boolval', false),

    // ── Database Migrations ───────────────────────────────────────────────
];
