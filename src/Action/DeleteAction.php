<?php

namespace Tapao\ModerationAI\Action;

use Illuminate\Validation\ValidationException;

class DeleteAction
{
    public function execute(mixed $content): void
    {
        if (!$content) return;

        if (!empty($content->exists) && method_exists($content, 'delete')) {
            $content->delete();
        } else {
            throw ValidationException::withMessages([
                'content' => ['Your content was blocked by automated moderation.'],
            ]);
        }
    }
}
