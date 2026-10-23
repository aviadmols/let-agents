<x-filament-panels::page>
    {{-- Settings · Privacy and account (PrivacySettings): download everything, end the plan, delete the shop, delete my account. --}}
    @php($t = fn (string $key, array $params = []) => __('admin::privacy.'.$key, $params))
    @php($shop = $this->shop())
    @php($owner = $this->owner())
    @php($date = fn ($at) => $at?->locale(app()->getLocale())->translatedFormat('j.n.Y'))

    <div style="display: grid; gap: 16px; max-width: 720px">
        {{-- Download everything --}}
        <x-lp::card :title="$t('export.title')" :help="$t('export.help')" data-privacy-export>
            @if ($owner)
                <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center">
                    <a class="lp-btn lp-btn-primary" href="{{ $this->exportUrl() }}" download data-export>{{ $t('export.button') }}</a>
                    <span class="lp-note">{{ $t('export.note') }}</span>
                </div>
            @else
                <p class="lp-note">{{ $t('owners_only') }}</p>
            @endif
        </x-lp::card>

        {{-- End the plan --}}
        <x-lp::card :title="$t('plan.title')" data-privacy-plan>
            @if ($shop->leaving() || $shop->cancelled_at)
                @php($until = $shop->leaving() ? null : $shop->planRunsUntil())
                @if ($until)
                    <p class="lp-set-help" data-plan-runs-until>{{ $t('plan.ending', ['date' => $date($shop->cancelled_at), 'until' => $date($until)]) }}</p>
                @else
                    <p class="lp-set-help">{{ $t('plan.ended', ['date' => $date($shop->cancelled_at ?? now())]) }}</p>
                @endif
                @unless ($shop->leaving())<p class="lp-note">{{ $t('plan.again') }}</p>@endunless
            @else
                {{-- a plan paid ahead runs to the end of its period; any other ends at once --}}
                @php($now = $this->keepsPaidPeriod() ? '' : '_now')
                <p class="lp-set-help">{{ $t('plan.active'.$now) }}</p>
                @if (! $owner)
                    <p class="lp-note">{{ $t('owners_only') }}</p>
                @elseif ($confirming !== 'plan')
                    <div><button type="button" class="lp-btn" wire:click="$set('confirming', 'plan')" data-end-plan>{{ $t('plan.button') }}</button></div>
                @else
                    <div class="lp-privacy-confirm" role="group">
                        <p style="margin: 0 0 12px; font-weight: 500">{{ $t('plan.confirm'.$now) }}</p>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap">
                            <button type="button" class="lp-btn lp-btn-danger" wire:click="endPlan" wire:loading.attr="disabled" data-end-plan-yes>{{ $t('plan.yes') }}</button>
                            <button type="button" class="lp-btn" wire:click="$set('confirming', null)">{{ $t('back') }}</button>
                        </div>
                    </div>
                @endif
            @endif
        </x-lp::card>

        {{-- Delete the shop and all it holds --}}
        <x-lp::card :title="$t('delete.title')" data-privacy-shop>
            @if ($shop->leaving())
                <p class="lp-set-help" style="font-weight: 500">{{ $t('delete.pending', ['date' => $date($shop->deletion_due_at)]) }}</p>
                @if ($shop->deletion_asked_by)<p class="lp-note">{{ $t('delete.pending_by', ['name' => $shop->deletion_asked_by]) }}</p>@endif
                @if ($owner)
                    <div style="margin-top: 12px"><button type="button" class="lp-btn lp-btn-primary" wire:click="keepShop" wire:loading.attr="disabled" data-keep-shop>{{ $t('delete.keep') }}</button></div>
                @endif
            @else
                <p class="lp-set-help">{{ $t('delete.help', ['days' => $this->deletionDays()]) }}</p>
                @if (! $owner)
                    <p class="lp-note">{{ $t('owners_only') }}</p>
                @elseif ($confirming !== 'shop')
                    <div><button type="button" class="lp-btn lp-btn-danger" wire:click="$set('confirming', 'shop')" data-delete-shop>{{ $t('delete.button') }}</button></div>
                @else
                    <div class="lp-privacy-confirm lp-acct-form" role="group" style="grid-template-columns: 1fr">
                        <p style="margin: 0; font-weight: 500">{{ $t('delete.tip_export') }}</p>
                        <label>{{ $t('delete.type_name', ['name' => $shop->name]) }}
                            <x-lp::field model="shopName" autocomplete="off" />
                            @if ($this->problem('shopName'))<span class="lp-set-error" role="alert">{{ $this->problem('shopName') }}</span>@endif
                        </label>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap">
                            <button type="button" class="lp-btn lp-btn-danger" wire:click="deleteShop" wire:loading.attr="disabled" data-delete-shop-yes>{{ $t('delete.confirm_button') }}</button>
                            <button type="button" class="lp-btn" wire:click="$set('confirming', null)">{{ $t('back') }}</button>
                        </div>
                    </div>
                @endif
            @endif
        </x-lp::card>

        {{-- Delete my own account --}}
        @unless ($this->isOperator())
            @php($alone = $this->aloneIn())
            <x-lp::card :title="$t('me.title')" :help="$t('me.help')" data-privacy-me>
                @if ($alone !== [])
                    <p class="lp-note">{{ $t('me.alone', ['shops' => implode(', ', $alone)]) }}</p>
                @elseif ($confirming !== 'me')
                    <div><button type="button" class="lp-btn lp-btn-danger" wire:click="$set('confirming', 'me')" data-delete-me>{{ $t('me.button') }}</button></div>
                @else
                    <div class="lp-privacy-confirm lp-acct-form" role="group" style="grid-template-columns: 1fr">
                        <label>{{ $t('me.type_phrase') }} <code class="lp-privacy-phrase" dir="ltr">{{ $this::PHRASE }}</code>
                            <x-lp::field model="phrase" ltr autocomplete="off" />
                            @if ($this->problem('phrase'))<span class="lp-set-error" role="alert">{{ $this->problem('phrase') }}</span>@endif
                        </label>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap">
                            <button type="button" class="lp-btn lp-btn-danger" wire:click="deleteMe" wire:loading.attr="disabled" data-delete-me-yes>{{ $t('me.confirm_button') }}</button>
                            <button type="button" class="lp-btn" wire:click="$set('confirming', null)">{{ $t('back') }}</button>
                        </div>
                    </div>
                @endif
            </x-lp::card>
        @endunless
    </div>
</x-filament-panels::page>
