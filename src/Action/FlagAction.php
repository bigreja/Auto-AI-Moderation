<?php

namespace Tapao\ModerationAI\Action;

use Carbon\Carbon;

class FlagAction
{
    public function execute(mixed $content, string $contentType, string $category, float $score): void
    {
        if (!$content) return;

        // Integration with flarum/flags if installed
        if (class_exists(\Flarum\Flags\Flag::class) && $contentType === 'post') {
            $saveFlag = function ($post) use ($category, $score) {
                if (!$post || !$post->id) return;
                $flag = new \Flarum\Flags\Flag();
                $flag->post_id       = $post->id;
                $flag->type          = 'ai_moderation';
                $flag->reason        = "AI: $category (" . round($score * 100) . "%)";
                $flag->reason_detail = null;
                $flag->user_id       = null; // system-generated flag
                $flag->created_at    = Carbon::now();
                $flag->save();
            };

            if (!empty($content->id)) {
                $saveFlag($content);
            } elseif (method_exists($content, 'afterSave')) {
                $content->afterSave($saveFlag);
            }
            return;
        }

        // Fallback: hide the content
        if (method_exists($content, 'hide')) {
            $content->hide();
            if (!empty($content->exists)) {
                $content->save();
            }
        }
    }
}
