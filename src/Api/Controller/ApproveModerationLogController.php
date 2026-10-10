<?php

namespace Tapao\ModerationAI\Api\Controller;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Tapao\ModerationAI\Model\ModerationLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;

class ApproveModerationLogController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $id  = $request->getAttribute('id');
        $log = ModerationLog::findOrFail($id);

        $log->update([
            'reviewed_by'     => $actor->id,
            'reviewed_at'     => Carbon::now(),
            'review_decision' => 'approved',
        ]);

        return new JsonResponse([
            'data' => [
                'id'         => (string) $log->id,
                'type'       => 'moderation-logs',
                'attributes' => [
                    'reviewDecision'  => 'approved',
                    'review_decision' => 'approved',
                    'reviewedAt'      => $log->reviewed_at?->toIso8601String(),
                    'reviewed_at'     => $log->reviewed_at?->toIso8601String(),
                ],
            ],
        ]);
    }
}

