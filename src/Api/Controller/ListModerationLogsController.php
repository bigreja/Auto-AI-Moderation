<?php

namespace Tapao\ModerationAI\Api\Controller;

use Flarum\Http\RequestUtil;
use Tapao\ModerationAI\Model\ModerationLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;

class ListModerationLogsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $queryParams  = $request->getQueryParams();
        $flaggedParam = $queryParams['flagged'] ?? $queryParams['is_flagged'] ?? null;
        $limit        = min(max((int)($queryParams['limit'] ?? 50), 1), 100);
        $offset       = max((int)($queryParams['offset'] ?? 0), 0);

        $query = ModerationLog::query()->orderByDesc('created_at');

        if ($flaggedParam !== null) {
            $flaggedOnly = filter_var($flaggedParam, FILTER_VALIDATE_BOOLEAN);
            $query->where('flagged', $flaggedOnly);
        } else {
            $query->where('flagged', true);
        }

        $type = $queryParams['type'] ?? $queryParams['content_type'] ?? null;
        if (!empty($type)) {
            $query->where('content_type', $type);
        }

        $logs = $query->skip($offset)->take($limit)->get();

        $data = $logs->map(fn($log) => [
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
        ])->values()->all();

        return new JsonResponse(['data' => $data]);
    }
}

