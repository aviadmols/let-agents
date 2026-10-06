<?php

namespace App\Modules\Ai;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\ListsProviderModels;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Support\Drivers\AnthropicChat;
use App\Modules\Ai\Support\Drivers\OpenAiChat;
use App\Modules\Ai\Support\Drivers\OpenAiEmbeddings;
use App\Modules\Ai\Support\MonthlySpendGuard;
use App\Modules\Ai\Support\ProviderChatModel;
use App\Modules\Ai\Support\ProviderEmbedder;
use App\Modules\Ai\Support\SdkModelLister;

final class AiServiceProvider extends ModuleServiceProvider
{
    /**
     * One class per provider and job. A new provider is a case in AiProviderName and a driver
     * here; the settings that name a provider and model then reach it without other changes.
     */
    public const CHAT_DRIVERS = [OpenAiChat::class, AnthropicChat::class];

    public const EMBEDDING_DRIVERS = [OpenAiEmbeddings::class];

    protected function registerModule(): void
    {
        $this->app->bind(ListsProviderModels::class, SdkModelLister::class);
        $this->app->bind(SpendGuard::class, MonthlySpendGuard::class);

        $this->app->tag(self::CHAT_DRIVERS, 'ai.chat_drivers');
        $this->app->tag(self::EMBEDDING_DRIVERS, 'ai.embedding_drivers');
        $this->app->bind(ChatModel::class, fn ($app) => new ProviderChatModel($app->tagged('ai.chat_drivers')));
        $this->app->bind(Embedder::class, fn ($app) => new ProviderEmbedder($app->tagged('ai.embedding_drivers')));
    }
}
