<x-filament-panels::page>
    {{-- Settings · WhatsApp (WhatsappSettings), as the designer's panel lays it out. --}}
    @php($t = fn (string $key, array $params = []) => __('widget::shop_settings.whatsapp.'.$key, $params))

    <x-lp::card :title="$t('title')" :help="$t('help')" master="data.whatsapp">
        <x-lp::kv>
            <x-lp::kv-row :label="$t('number')" :error="$this->problem('number')"><x-lp::field model="data.number" ltr :width="260" placeholder="050-0000000" type="tel" /></x-lp::kv-row>
            <x-lp::kv-row :label="$t('heading')" :error="$this->problem('title')"><x-lp::field model="data.title" maxlength="120" /></x-lp::kv-row>
            <x-lp::kv-row :label="$t('button')" :error="$this->problem('button')"><x-lp::field model="data.button" :width="260" maxlength="60" /></x-lp::kv-row>
            <x-lp::kv-row :label="$t('message')" :tip="$t('message_tip')" :error="$this->problem('message')">
                <x-lp::field model="data.message" maxlength="300" />
                <span class="lp-sample">{!! str_replace([':product', ':question'], ['<bdi dir="ltr">:product</bdi>', '<bdi dir="ltr">:question</bdi>'], e($t('message_sample'))) !!}</span>
            </x-lp::kv-row>
            <x-lp::kv-row :label="$t('no_match')"><x-lp::toggle model="data.search_whatsapp" :label="$t('no_match')" /></x-lp::kv-row>
            <x-lp::kv-row :label="$t('offline')" :tip="$t('offline_tip')" :error="$this->problem('when_offline')">
                <x-lp::chips model="data.when_offline" :options="__('widget::shop_settings.whatsapp.offline_options')" :label="$t('offline')" />
            </x-lp::kv-row>
        </x-lp::kv>
    </x-lp::card>

    <x-lp::save />
</x-filament-panels::page>
