<?php

namespace Tapao\ModerationAI\Api\Controller;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Tapao\ModerationAI\Model\ModerationLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;

class RejectModerationLogController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $id  = $request->getAttribute('id');
        $log = ModerationLog::findOrFail($id);

        $this->restoreContent($log);

        $log->update([
            'reviewed_by'     => $actor->id,
            'reviewed_at'     => Carbon::now(),
            'review_decision' => 'rejected',
        ]);

        return new JsonResponse([
            'data' => [
                'id'         => (string) $log->id,
                'type'       => 'moderation-logs',
                'attributes' => [
                    'reviewDecision'  => 'rejected',
                    'review_decision' => 'rejected',
                    'reviewedAt'      => $log->reviewed_at?->toIso8601String(),
                    'reviewed_at'     => $log->reviewed_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    private function restoreContent(ModerationLog $log): void
    {
        if ($log->action_taken !== 'hide') {
            return;
        }

        $content = match($log->content_type) {
            'post'       => \Flarum\Post\Post::find($log->content_id),
            'discussion' => \Flarum\Discussion\Discussion::find($log->content_id),
            default      => null,
        };

        if (!$content) {
            return;
        }

        if (method_exists($content, 'restore')) {
            $content->restore();
            $content->save();
        } elseif (isset($content->hidden_at) || property_exists($content, 'hidden_at')) {
            $content->hidden_at = null;
            $content->hidden_by_id = null;
            $content->save();
        } elseif (isset($content->is_hidden) || property_exists($content, 'is_hidden')) {
            $content->is_hidden = false;
            $content->save();
        }
    }
}

