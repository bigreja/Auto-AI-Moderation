<?php

namespace Tapao\ModerationAI\Webhook;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;

class WebhookNotifier
{
    protected Client $http;

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
        $this->http = new Client(['timeout' => 5]);
    }

    public function notify(string $contentType, mixed $content, array $violation, string $actionTaken): void
    {
        $webhookUrl = $this->settings->get('moderationai.webhook_url', '');
        if (empty($webhookUrl)) return;

        $payload = [
            'event'        => 'content_flagged',
            'content_type' => $contentType,
            'content_id'   => $content->id ?? null,
            'user_id'      => $content->user_id ?? $content->id ?? null,
            'category'     => $violation['category'],
            'score'        => $violation['score'],
            'action_taken' => $actionTaken,
            'timestamp'    => Carbon::now()->toIso8601String(),
        ];

        try {
            $this->http->post($webhookUrl, ['json' => $payload]);
        } catch (\Throwable $e) {
            error_log('ModerationAI webhook failed: ' . $e->getMessage());
        }
    }
}
