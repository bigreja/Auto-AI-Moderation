<?php

namespace Tapao\ModerationAI\Action;

class HideAction
{
    public function execute(mixed $content): void
    {
        if (!$content) return;

        if (method_exists($content, 'hide')) {
            $content->hide();
            $content->save();
        } elseif (isset($content->is_hidden) || property_exists($content, 'is_hidden')) {
            $content->is_hidden = true;
            $content->save();
        } elseif (isset($content->hidden_at) || property_exists($content, 'hidden_at')) {
            $content->hidden_at = \Carbon\Carbon::now();
            $content->save();
        }
    }
}
