<?php

namespace Tapao\ModerationAI\Api;

use GuzzleHttp\Client;
use Tapao\ModerationAI\Moderator\ModerationResult;
use Flarum\Settings\SettingsRepositoryInterface;

class OpenAIModerationClient
{
    protected Client $http;
    protected string $apiKey;
    protected string $model;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->apiKey = $settings->get('moderationai.openai_api_key', '');
        $this->model  = $settings->get('moderationai.model', 'omni-moderation-latest');
        $this->http   = new Client(['timeout' => 20]);
    }

    /**
     * Moderate mixed text + image inputs in one API call.
     *
     * $inputs format:
     * [
     *   ["type" => "text",      "text" => "some content"],
     *   ["type" => "image_url", "image_url" => ["url" => "https://..."]]
     * ]
     */
    public function moderate(array $inputs): ModerationResult
    {
        if (empty($this->apiKey)) {
            error_log('ModerationAI: OpenAI API key not configured.');
            return ModerationResult::safe($this->model);
        }

        try {
            $response = $this->http->post('https://api.openai.com/v1/moderations', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'input' => $inputs,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return ModerationResult::fromApiResponse($data);

        } catch (\Throwable $e) {
            error_log('ModerationAI API error: ' . $e->getMessage());
            return ModerationResult::safe($this->model);
        }
    }

    /**
     * Validate the OpenAI credentials by making a real moderation call.
     *
     * Optional overrides let the admin test the key/model currently typed in
     * the settings form before saving. Returns a structured result so the UI
     * can surface the actual error message from OpenAI.
     *
     * @return array{ok: bool, error?: string}
     */
    public function testConnection(?string $apiKey = null, ?string $model = null): array
    {
        $apiKey = $apiKey !== null && $apiKey !== '' ? $apiKey : $this->apiKey;
        $model  = $model  !== null && $model  !== '' ? $model  : $this->model;

        if (empty($apiKey)) {
            return ['ok' => false, 'error' => 'OpenAI API key is not configured.'];
        }

        try {
            $this->http->post('https://api.openai.com/v1/moderations', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'input' => 'test',
                ],
            ]);

            return ['ok' => true];

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $response = $e->getResponse();
            $message  = $response ? (string) $response->getBody() : $e->getMessage();

            // Surface OpenAI's own error message when available.
            $decoded = json_decode($message, true);
            if (isset($decoded['error']['message'])) {
                $message = $decoded['error']['message'];
            }

            return ['ok' => false, 'error' => $message];

        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
