<?php

namespace Tapao\ModerationAI\Action;

use Carbon\Carbon;

class HideAction
{
    public function execute(mixed $content): void
    {
        if (!$content) return;

        if (method_exists($content, 'hide')) {
            $content->hide();
        } elseif (isset($content->is_hidden) || property_exists($content, 'is_hidden')) {
            $content->is_hidden = true;
        } elseif (isset($content->hidden_at) || property_exists($content, 'hidden_at')) {
            $content->hidden_at = Carbon::now();
        }

        if (!empty($content->exists)) {
            $content->save();
        }
    }
}
