<?php

declare(strict_types=1);

namespace HtmlToJapaneseTranslator;

use LogicException;

final class TranslatorConfig
{
    /**
     * @var string[]
     */
    private array $apiKeys;

    private string $modelName;

    public function __construct(string|array $apiKey, string $modelName = 'gemini-2.5-flash')
    {
        if (empty($apiKey)) {
            throw new LogicException('API key cannot be empty.');
        }

        $this->apiKeys = is_string($apiKey) ? [$apiKey] : $apiKey;
        $this->modelName = $modelName;
    }

    public function getRandomApiKey(): string
    {
        return $this->apiKeys[array_rand($this->apiKeys)];
    }

    public function getModelName(): string
    {
        return $this->modelName;
    }
}
