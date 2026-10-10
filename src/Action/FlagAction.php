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
            $flag = new \Flarum\Flags\Flag();
            $flag->post_id       = $content->id;
            $flag->type          = 'ai_moderation';
            $flag->reason        = "AI: $category (" . round($score * 100) . "%)";
            $flag->reason_detail = null;
            $flag->user_id       = null; // system-generated flag
            $flag->created_at    = Carbon::now();
            $flag->save();
            return;
        }

        // Fallback: hide the content and add note
        if (method_exists($content, 'hide')) {
            $content->hide();
            $content->save();
        }
    }
}
