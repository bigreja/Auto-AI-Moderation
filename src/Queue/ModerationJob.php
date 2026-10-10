<?php

namespace Tapao\ModerationAI\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tapao\ModerationAI\Moderator\ContentModerator;
use Tapao\ModerationAI\Action\ActionHandler;
use Tapao\ModerationAI\Model\ModerationLog;

class ModerationJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 10;

    public function __construct(
        public readonly string $contentType,
        public readonly int    $contentId,
        public readonly string $field,
        public readonly array  $textInputs = [],
        public readonly array  $imageUrls  = [],
    ) {}

    public function handle(ContentModerator $moderator, ActionHandler $actionHandler): void
    {
        $hash = $moderator->contentHash($this->textInputs, $this->imageUrls);

        // Skip if already processed (guard against duplicate runs on retry)
        if (ModerationLog::where('content_type', $this->contentType)
            ->where('content_id', $this->contentId)
            ->where('field', $this->field)
            ->where('content_hash', $hash)
            ->exists()) {
            return;
        }

        $result = $moderator->moderate($this->textInputs, $this->imageUrls);
        if (!$result) return;

        $model = match($this->contentType) {
            'post'       => \Flarum\Post\Post::find($this->contentId),
            'discussion' => \Flarum\Discussion\Discussion::find($this->contentId),
            'user'       => \Flarum\User\User::find($this->contentId),
            default      => null,
        };

        // Content may have been deleted before the job ran
        if ($this->contentType !== 'upload' && $model === null) {
            error_log("ModerationAI: {$this->contentType} #{$this->contentId} not found when job ran — skipping.");
            return;
        }

        $log = ModerationLog::create([
            'content_type'    => $this->contentType,
            'content_id'      => $this->contentId,
            'field'           => $this->field,
            'openai_model'    => $result->model,
            'flagged'         => $result->flagged,
            'categories'      => $result->categories,
            'category_scores' => $result->categoryScores,
            'content_hash'    => $hash,
        ]);

        if ($model) {
            $actionHandler->handle($result, $model, $this->contentType, $log);
        }
    }
}
