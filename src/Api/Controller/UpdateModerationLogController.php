<?php

namespace Tapao\ModerationAI\Api\Controller;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Tapao\ModerationAI\Model\ModerationLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;

class UpdateModerationLogController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $id  = $request->getAttribute('id');
        $log = ModerationLog::findOrFail($id);

        $body = (array) $request->getParsedBody();
        $decision = $body['data']['attributes']['reviewDecision']
            ?? $body['data']['attributes']['review_decision']
            ?? $body['reviewDecision']
            ?? $body['review_decision']
            ?? null;

        if ($decision === 'rejected') {
            $this->restoreContent($log);
        }

        if ($decision) {
            $log->update([
                'reviewed_by'     => $actor->id,
                'reviewed_at'     => Carbon::now(),
                'review_decision' => $decision,
            ]);
        }

        return new JsonResponse([
            'data' => [
                'id'         => (string) $log->id,
                'type'       => 'moderation-logs',
                'attributes' => [
                    'contentType'     => $log->content_type,
                    'content_type'    => $log->content_type,
                    'contentId'       => $log->content_id,
                    'content_id'      => $log->content_id,
                    'field'           => $log->field,
                    'openaiModel'     => $log->openai_model,
                    'openai_model'    => $log->openai_model,
                    'flagged'         => (bool) $log->flagged,
                    'categories'      => $log->categories,
                    'categoryScores'  => $log->category_scores,
                    'category_scores' => $log->category_scores,
                    'actionTaken'     => $log->action_taken,
                    'action_taken'    => $log->action_taken,
                    'reviewDecision'  => $log->review_decision,
                    'review_decision' => $log->review_decision,
                    'reviewedAt'      => $log->reviewed_at?->toIso8601String(),
                    'reviewed_at'     => $log->reviewed_at?->toIso8601String(),
                    'createdAt'       => $log->created_at?->toIso8601String(),
                    'created_at'      => $log->created_at?->toIso8601String(),
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

