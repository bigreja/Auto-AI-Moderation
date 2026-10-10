<?php

namespace Tapao\ModerationAI\Moderator;

use Flarum\Settings\SettingsRepositoryInterface;
use Tapao\ModerationAI\Api\OpenAIModerationClient;
use Tapao\ModerationAI\Trust\UserTrustScore;

class ContentModerator
{
    public function __construct(
        protected OpenAIModerationClient $client,
        protected SettingsRepositoryInterface $settings,
        protected UserTrustScore $trustScore,
    ) {}

    public function moderate(array $textInputs = [], array $imageUrls = [], ?\Flarum\User\User $user = null): ?ModerationResult
    {
        if (!$this->settings->get('moderationai.enabled', false)) return null;

        // Phase 5: skip moderation for high-trust users
        if ($user && $this->trustScore->shouldSkip($user)) return null;

        // Phase 2: gate image scanning by setting
        if (!$this->settings->get('moderationai.scan_images', true)) {
            $imageUrls = [];
        }

        $inputs = [];
        foreach ($textInputs as $text) {
            if (!empty(trim((string)$text))) {
                $inputs[] = ['type' => 'text', 'text' => strip_tags((string)$text)];
            }
        }
        foreach ($imageUrls as $url) {
            if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                $inputs[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
            }
        }

        if (empty($inputs)) return null;

        return $this->client->moderate($inputs);
    }

    public function extractImageUrls(string $content): array
    {
        $urls = [];

        // HTML img tags
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
            $urls = array_merge($urls, $matches[1] ?? []);
        }

        // Markdown images: ![alt](url)
        if (preg_match_all('/!\[.*?\]\((https?:\/\/[^\s\)]+)\)/i', $content, $matches)) {
            $urls = array_merge($urls, $matches[1] ?? []);
        }

        // BBCode images: [img]url[/img]
        if (preg_match_all('/\[img\](https?:\/\/[^\[]+)\[\/img\]/i', $content, $matches)) {
            $urls = array_merge($urls, $matches[1] ?? []);
        }

        return array_values(array_unique(array_filter(
            $urls,
            fn($url) => filter_var($url, FILTER_VALIDATE_URL)
        )));
    }

    public function contentHash(array $texts, array $imageUrls = []): string
    {
        return hash('sha256', json_encode([$texts, $imageUrls]));
    }
}
