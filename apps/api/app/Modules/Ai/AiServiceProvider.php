<?php

namespace App\Modules\Ai;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Ai\Contracts\BatchChatModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Contracts\ImageModel;
use App\Modules\Ai\Contracts\ListsProviderModels;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\Ai\Contracts\WebSearchModel;
use App\Modules\Ai\Support\AllowanceHome;
use App\Modules\Ai\Support\Drivers\AnthropicBatches;
use App\Modules\Ai\Support\Drivers\AnthropicChat;
use App\Modules\Ai\Support\Drivers\GeminiEmbeddings;
use App\Modules\Ai\Support\Drivers\OpenAiBatches;
use App\Modules\Ai\Support\Drivers\OpenAiChat;
use App\Modules\Ai\Support\Drivers\OpenAiEmbeddings;
use App\Modules\Ai\Support\Drivers\ReplicateImages;
use App\Modules\Ai\Support\MonthlySpendGuard;
use App\Modules\Ai\Support\ProviderBatchModel;
use App\Modules\Ai\Support\ProviderChatModel;
use App\Modules\Ai\Support\ProviderEmbedder;
use App\Modules\Ai\Support\ProviderImageModel;
use App\Modules\Ai\Support\SdkModelLister;
use App\Modules\Ai\Support\UsageStrip;
use App\Modules\Runs\Contracts\CallJournal;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;

final class AiServiceProvider extends ModuleServiceProvider
{
    /**
     * One class per provider and job. A new provider is a case in AiProviderName and a driver
     * here; the settings that name a provider and model then reach it without other changes.
     */
    public const CHAT_DRIVERS = [OpenAiChat::class, AnthropicChat::class];

    /** Providers with a batch API: many questions at once, answered within a day at half price. */
    public const BATCH_DRIVERS = [OpenAiBatches::class, AnthropicBatches::class];

    /** Providers that make pictures (Try-On). */
    public const IMAGE_DRIVERS = [ReplicateImages::class];

    public const EMBEDDING_DRIVERS = [OpenAiEmbeddings::class, GeminiEmbeddings::class];

    /** Providers that put pictures and words in one space. */
    public const IMAGE_EMBEDDING_DRIVERS = [GeminiEmbeddings::class];

    protected function registerModule(): void
    {
        // What this module shows on the shop's home and notices.
        $this->app->tag([AllowanceHome::class], HomeSource::TAG);
        $this->app->bind(ListsProviderModels::class, SdkModelLister::class);
        $this->app->bind(SpendGuard::class, MonthlySpendGuard::class);

        $this->app->tag(self::CHAT_DRIVERS, 'ai.chat_drivers');
        $this->app->tag(self::EMBEDDING_DRIVERS, 'ai.embedding_drivers');
        $this->app->tag(self::IMAGE_EMBEDDING_DRIVERS, 'ai.image_embedding_drivers');
        $this->app->bind(ChatModel::class, fn ($app) => new ProviderChatModel($app->tagged('ai.chat_drivers'), $app->make(CallJournal::class)));
        $this->app->bind(VisionModel::class, fn ($app) => new ProviderChatModel($app->tagged('ai.chat_drivers'), $app->make(CallJournal::class)));
        $this->app->bind(WebSearchModel::class, fn ($app) => new ProviderChatModel($app->tagged('ai.chat_drivers'), $app->make(CallJournal::class)));
        $this->app->tag(self::BATCH_DRIVERS, 'ai.batch_drivers');
        $this->app->bind(BatchChatModel::class, fn ($app) => new ProviderBatchModel($app->tagged('ai.batch_drivers'), $app->make(CallJournal::class)));
        $this->app->tag(self::IMAGE_DRIVERS, 'ai.image_drivers');
        $this->app->bind(ImageModel::class, fn ($app) => new ProviderImageModel($app->tagged('ai.image_drivers'), $app->make(CallJournal::class)));
        $this->app->bind(Embedder::class, fn ($app) => new ProviderEmbedder($app->tagged('ai.embedding_drivers'), $app->tagged('ai.image_embedding_drivers')));
        $this->app->bind(ImageEmbedder::class, fn ($app) => new ProviderEmbedder($app->tagged('ai.embedding_drivers'), $app->tagged('ai.image_embedding_drivers')));
    }

    protected function bootModule(): void
    {
        // The month's allowance above every admin screen: a share for a shop, dollars for the operator.
        FilamentView::registerRenderHook(PanelsRenderHook::CONTENT_START, fn (): string => app(UsageStrip::class)->render()->toHtml());
    }
}
