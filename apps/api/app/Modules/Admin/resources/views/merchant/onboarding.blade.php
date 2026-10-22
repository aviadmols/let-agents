<x-filament-panels::page>
    {{-- A new shop's first-run screens (Onboarding), as the designer's panel lays them out: the steps beside the step. --}}
    @php($t = fn (string $key, array $params = []) => __('admin::onboarding.'.$key, $params))
    @php($edit = $this->canEdit())
    @php($first = $this->firstStep())
    @php($shop = \Filament\Facades\Filament::getTenant())
    @php($wins = $this->steps())
    @php($reading = $step === 2 && $this->reading())
    @php($scanning = $this->scanning())
    @php($languages = \App\Modules\Admin\Actions\SaveShopProfile::LANGUAGES)

    {{-- the icons only these screens draw --}}
    <svg width="0" height="0" style="position: absolute" aria-hidden="true" focusable="false">
        <symbol id="lp-onb-i-box" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="m3 7.5 9 4.5 9-4.5M12 12v9"/></symbol>
        <symbol id="lp-onb-i-img" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-8 9"/></symbol>
        <symbol id="lp-onb-i-brain" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4a3 3 0 0 0-3 3 3 3 0 0 0-2 5 3 3 0 0 0 2 5 3 3 0 0 0 6 1V5a3 3 0 0 0-3-1zM15 4a3 3 0 0 1 3 3 3 3 0 0 1 2 5 3 3 0 0 1-2 5 3 3 0 0 1-6 1"/></symbol>
        <symbol id="lp-onb-i-link" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></symbol>
    </svg>

    <div class="lp-onb-wrap" data-onboarding data-step="{{ $step }}" @if ($reading || ($step === 3 && $scanning)) wire:poll.10s @endif>
        @unless ($edit)
            <div class="lp-hint" role="note" data-viewer><span class="lp-mark lp-mark-sm" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 3h14a7 7 0 0 1-14 0zM5 21h14a7 7 0 0 0-14 0z" fill="currentColor"/></svg></span><span>{{ $t('viewer') }}</span></div>
        @endunless

        <div class="lp-onb">
            {{-- the steps: what is behind, what is now --}}
            <ol class="lp-onb-steps" data-rail>
                @foreach (\App\Modules\Admin\Filament\Merchant\Pages\Onboarding::STEPS as $n => $key)
                    @php($isDone = $n < $step)
                    <li @class(['lp-onb-step', 'is-done' => $isDone, 'is-now' => $n === $step]) data-rail-step="{{ $key }}" @if ($n === $step) aria-current="step" @endif>
                        <span class="lp-onb-i">@if ($isDone)<svg aria-hidden="true"><use href="#lp-i-check"/></svg><span class="sr-only">{{ $t('rail.done') }}</span>@else{{ $n }}@endif</span>
                        <span>
                            {{ $key === 'steps' ? ($wins === [] ? $t('rail.steps_plain') : $t('rail.steps', ['count' => count($wins)])) : $t('rail.'.$key) }}
                            <small>{{ $key === 'connect' && $n < $first ? $t('rail.connected_sub', ['platform' => $shop->platform->label()]) : $t('rail.'.$key.'_sub') }}</small>
                        </span>
                    </li>
                @endforeach
            </ol>

            <div class="lp-onb-body">
                @if ($step === 1)
                    {{-- 1 · the connection: the platform, the address, and for WordPress its plugin and token --}}
                    @php($connected = $this->connected())
                    @php($package = $this->package())
                    <section class="lp-onb-pane" data-pane="connect">
                        <h2>{{ $t('connect.title') }}</h2>
                        <p class="lp-onb-lead">{{ $t('connect.lead') }}</p>
                        <div class="lp-onb-platforms" role="radiogroup">
                            <button type="button" role="radio" @class(['lp-onb-platform', 'is-on' => $otherPlatform]) aria-checked="{{ $otherPlatform ? 'true' : 'false' }}" wire:click="$set('otherPlatform', true)" data-platform="shopify">
                                <span class="lp-onb-plogo" aria-hidden="true"><svg viewBox="0 0 24 24"><path fill="currentColor" d="M15.337 23.979l7.216-1.561s-2.604-17.613-2.625-17.73c-.018-.116-.114-.192-.211-.192s-1.929-.136-1.929-.136-1.275-1.274-1.439-1.411c-.045-.037-.075-.057-.121-.074l-.914 21.104h.023zM11.71 11.305s-.81-.424-1.774-.424c-1.447 0-1.504.906-1.504 1.141 0 1.232 3.24 1.715 3.24 4.629 0 2.295-1.44 3.76-3.406 3.76-2.354 0-3.54-1.465-3.54-1.465l.646-2.086s1.245 1.066 2.28 1.066c.675 0 .975-.545.975-.932 0-1.619-2.654-1.694-2.654-4.359-.034-2.237 1.571-4.416 4.827-4.416 1.257 0 1.875.361 1.875.361l-.945 2.715-.02.01zM11.17.83c.136 0 .271.038.405.135-.984.465-2.064 1.639-2.508 3.992-.656.213-1.293.405-1.889.578C7.697 3.75 8.951.84 11.17.84V.83zm1.235 2.949v.135c-.754.232-1.583.484-2.394.736.466-1.777 1.333-2.645 2.085-2.971.193.501.309 1.176.309 2.1zm.539-2.234c.694.074 1.141.867 1.429 1.755-.349.114-.735.231-1.158.366v-.252c0-.752-.096-1.371-.271-1.871v.002zm2.992 1.289c-.02 0-.06.021-.078.021s-.289.075-.714.21c-.423-1.233-1.176-2.37-2.508-2.37h-.115C12.135.209 11.669 0 11.265 0 8.159 0 6.675 3.877 6.21 5.846c-1.194.365-2.063.636-2.16.674-.675.213-.694.232-.772.87-.075.462-1.83 14.063-1.83 14.063L15.009 24l.927-21.166z"/></svg></span>
                                <b>Shopify</b>
                                <span>{{ $t('connect.shopify') }}</span>
                            </button>
                            <button type="button" role="radio" @class(['lp-onb-platform', 'is-on' => ! $otherPlatform]) aria-checked="{{ $otherPlatform ? 'false' : 'true' }}" wire:click="$set('otherPlatform', false)" data-platform="woocommerce">
                                <span class="lp-onb-plogo lp-onb-plogo-woo" aria-hidden="true"><svg viewBox="0 0 24 24"><path fill="currentColor" d="M.754 9.58a.754.754 0 00-.754.758v2.525c0 .42.339.758.758.758h3.135l1.431.799-.326-.799h2.373a.757.757 0 00.758-.758v-2.525a.757.757 0 00-.758-.758H.754zm2.709.445h.03c.065.001.124.023.179.067a.26.26 0 01.103.19.29.29 0 01-.033.16c-.13.239-.236.64-.322 1.199-.083.541-.114.965-.094 1.267a.392.392 0 01-.039.219.213.213 0 01-.176.12c-.086.006-.177-.034-.263-.124-.31-.316-.555-.788-.735-1.416-.216.425-.375.744-.478.957-.196.376-.363.568-.502.578-.09.007-.166-.069-.233-.228-.17-.436-.352-1.277-.548-2.524a.297.297 0 01.054-.222c.047-.064.116-.095.21-.102.169-.013.265.065.288.238.103.695.217 1.284.336 1.766l.727-1.387c.066-.126.15-.192.25-.199.146-.01.237.083.273.28.083.441.188.817.315 1.136.086-.844.233-1.453.44-1.828a.255.255 0 01.218-.147zm1.293.36c.056 0 .116.006.18.02.232.05.411.177.53.386.107.18.161.395.161.654 0 .343-.087.654-.26.94-.2.332-.459.5-.781.5a.88.88 0 01-.18-.022.763.763 0 01-.531-.384 1.287 1.287 0 01-.158-.659c0-.342.085-.655.258-.937.202-.333.462-.498.78-.498zm2.084 0c.056 0 .116.006.18.02.236.05.411.177.53.386.107.18.16.395.16.654 0 .343-.086.654-.259.94-.2.332-.459.5-.781.5a.88.88 0 01-.18-.022.763.763 0 01-.531-.384 1.287 1.287 0 01-.16-.659c0-.342.087-.655.26-.937.202-.333.462-.498.78-.498zm4.437.047c-.305 0-.546.102-.718.304-.173.203-.256.49-.256.856 0 .395.086.697.256.906.17.21.418.316.744.316.315 0 .559-.107.728-.316.17-.21.256-.504.256-.883s-.087-.673-.26-.879c-.176-.202-.424-.304-.75-.304zm-1.466.002a1.13 1.13 0 00-.84.326c-.223.22-.332.499-.332.838 0 .362.108.658.328.88.22.223.505.336.861.336.103 0 .22-.016.346-.052v-.54c-.117.034-.216.051-.303.051a.545.545 0 01-.422-.177c-.106-.12-.16-.278-.16-.48 0-.19.053-.348.156-.468a.498.498 0 01.397-.181c.103 0 .212.015.332.049v-.537a1.394 1.394 0 00-.363-.045zm12.414 0a1.135 1.135 0 00-.84.326c-.223.22-.332.499-.332.838 0 .362.108.658.328.88.22.223.506.336.861.336.103 0 .22-.016.346-.052v-.54c-.116.034-.216.051-.303.051a.545.545 0 01-.422-.177c-.106-.12-.16-.278-.16-.48 0-.19.053-.348.156-.468a.498.498 0 01.397-.181c.103 0 .212.015.332.049v-.537a1.394 1.394 0 00-.363-.045zm-9.598.06l-.29 2.264h.579l.156-1.559.395 1.559h.412l.379-1.555.164 1.555h.603l-.304-2.264h-.791l-.12.508c-.03.13-.06.264-.087.4l-.067.352a29.97 29.97 0 00-.258-1.26h-.771zm2.768 0l-.29 2.264h.579l.156-1.559.396 1.559h.412l.375-1.555.165 1.555h.603l-.305-2.264h-.789l-.119.508c-.03.13-.06.264-.086.4l-.066.352c-.063-.352-.15-.771-.26-1.26h-.771zm3.988 0v2.264h.611v-1.031h.012l.494 1.03h.645l-.489-1.019a.61.61 0 00.37-.552.598.598 0 00-.25-.506c-.167-.123-.394-.186-.68-.186h-.713zm3.377 0v2.264H24v-.483h-.63v-.414h.54v-.468h-.54v-.416h.626v-.483H22.76zm-4.793.004v2.264h1.24v-.483h-.627v-.416h.541v-.468h-.54v-.415h.622v-.482h-1.236zm2.025.432c.146.003.25.025.313.072.063.046.091.12.091.227 0 .156-.135.236-.404.24v-.54zm-15.22.011c-.104 0-.205.069-.301.211a1.078 1.078 0 00-.2.639c0 .096.02.2.06.303.049.13.117.198.196.215.083.016.173-.02.27-.106.123-.11.205-.273.252-.492.016-.077.023-.16.023-.246 0-.097-.02-.2-.06-.303-.05-.13-.116-.198-.196-.215a.246.246 0 00-.045-.006zm2.083 0c-.103 0-.204.069-.3.211a1.078 1.078 0 00-.2.639c0 .096.02.2.06.303.049.13.117.198.196.215.083.016.173-.02.27-.106.123-.11.205-.273.252-.492.013-.077.023-.16.023-.246 0-.097-.02-.2-.06-.303-.05-.13-.116-.198-.196-.215a.246.246 0 00-.045-.006zm4.428.006c.233 0 .354.218.354.66-.004.273-.038.46-.098.553a.293.293 0 01-.262.139.266.266 0 01-.242-.139c-.056-.093-.084-.28-.084-.562 0-.436.11-.65.332-.65Z"/></svg></span>
                                <b>WooCommerce</b>
                                <span>{{ $t('connect.woocommerce') }}</span>
                            </button>
                        </div>

                        @if ($otherPlatform)
                            <div class="lp-fact" data-shopify-note>{{ $t('connect.shopify_note') }}</div>
                        @else
                            <div class="lp-onb-address">
                                <label for="lp-onb-url">{{ $t('connect.address') }}</label>
                                <div class="lp-set-field lp-set-wide"><svg class="lp-ico" aria-hidden="true"><use href="#lp-onb-i-link"/></svg><input id="lp-onb-url" type="text" dir="ltr" readonly value="{{ $shop->domain }}"></div>
                                <p class="lp-sample">{{ $t('connect.read_only') }}</p>
                            </div>

                            {{-- the plugin and its token, as Settings · Connection and plan has them --}}
                            @php($c = fn (string $key, array $params = []) => __('admin::shop_settings.connection.'.$key, $params))
                            <div class="lp-onb-plugin" data-plugin>
                                <div class="lp-card-t">
                                    <h2>{{ $t('connect.plugin') }}</h2>
                                    <span class="lp-pill" data-tone="{{ $connected ? 'good' : 'warn' }}" data-connected="{{ $connected ? '1' : '0' }}"><i></i>{{ $t($connected ? 'connect.connected' : 'connect.not_connected') }}</span>
                                </div>
                                <x-lp::row :title="$c('download')" :sub="$package ? $c('download_sub', ['version' => $package['version']]) : $c('no_package')">
                                    @if ($package && $edit)
                                        <button type="button" class="lp-btn lp-btn-sm" wire:click="downloadPlugin" wire:loading.attr="disabled" wire:target="downloadPlugin" data-download>{{ $c('download') }}</button>
                                    @endif
                                </x-lp::row>
                                <x-lp::row :title="$c('token')" :sub="$this->hasToken() ? $c('token_replaces') : $c('token_sub')">
                                    @if ($this->canConnect())
                                        <button type="button" class="lp-btn lp-btn-sm" wire:click="createToken" wire:loading.attr="disabled" wire:target="createToken"
                                            @if ($this->hasToken()) wire:confirm="{{ $c('token_confirm') }}" @endif data-create-token>{{ $c('token') }}</button>
                                    @else
                                        <span class="lp-set-note">{{ __('admin::shop_settings.owners_only') }}</span>
                                    @endif
                                </x-lp::row>
                                @if ($token)
                                    <div class="lp-fact lp-onb-token" data-token x-data="{ copied: false }">
                                        <b>{{ $c('token_once') }}</b>
                                        <span class="lp-onb-token-row">
                                            <span class="lp-set-field lp-set-wide"><input type="text" readonly dir="ltr" value="{{ $token }}" x-ref="token" x-on:focus="$event.target.select()" aria-label="{{ $c('token') }}"></span>
                                            <button type="button" class="lp-btn lp-btn-primary lp-btn-sm"
                                                x-on:click="navigator.clipboard?.writeText($refs.token.value).then(() => copied = true).catch(() => { $refs.token.select(); document.execCommand('copy'); copied = true })">
                                                <span x-show="! copied">{{ $c('copy') }}</span><span x-show="copied" x-cloak>{{ $c('copied') }}</span>
                                            </button>
                                        </span>
                                        <ol>
                                            <li>{{ $c('step_install') }}</li>
                                            <li>{{ $c('step_paste') }}</li>
                                        </ol>
                                    </div>
                                @endif
                            </div>

                            <div class="lp-onb-actions">
                                @if ($edit)
                                    <button type="button" class="lp-btn lp-btn-primary" wire:click="connect" wire:loading.attr="disabled" wire:target="connect" data-connect>{{ $t('connect.go') }}</button>
                                @else
                                    <button type="button" class="lp-btn lp-btn-primary" wire:click="$set('step', 2)">{{ $t('scan.next') }}</button>
                                @endif
                            </div>
                        @endif
                    </section>
                @elseif ($step === 2)
                    {{-- 2 · what Lets found, and the form to fix it (the same saving as Settings · General) --}}
                    <section class="lp-onb-pane" data-pane="check">
                        @if ($reading)
                            <div class="lp-onb-reading" data-reading>
                                <span class="lp-onb-think" role="status"><span class="lp-onb-mark is-think" aria-hidden="true"><i></i><i></i></span><span class="lp-onb-think-t">{{ $t('check.reading', ['site' => $shop->domain]) }}</span></span>
                            </div>
                        @endif
                        <h2>{{ $t('check.title') }}</h2>
                        <p class="lp-onb-lead">{{ $t('check.lead') }}</p>
                        <div class="lp-onb-found" data-found>
                            @foreach ($this->found() as $i => $row)
                                <div class="lp-onb-found-row" style="--i: {{ $i }}" data-found-row="{{ $row['key'] }}">
                                    <span class="lp-onb-k">{{ $row['label'] }}</span>
                                    <b>{{ $row['value'] }}</b>
                                    <svg class="lp-onb-ok" aria-hidden="true"><use href="#lp-i-check"/></svg>
                                </div>
                            @endforeach
                        </div>
                        <div class="lp-onb-actions">
                            <button type="button" class="lp-btn lp-btn-primary" wire:click="{{ $edit ? 'confirm' : '$set(\'step\', 3)' }}" wire:loading.attr="disabled" data-confirm>{{ $t('check.confirm') }}</button>
                            @if ($edit)
                                <button type="button" class="lp-btn" wire:click="fix" aria-expanded="{{ $fixing ? 'true' : 'false' }}" data-fix>{{ $t($fixing ? 'check.fix_close' : 'check.fix') }}</button>
                            @endif
                        </div>

                        @if ($fixing && $edit)
                            <div class="lp-onb-form" data-form>
                                <label for="lp-onb-name">{{ $t('form.name') }}</label>
                                <div class="lp-set-field lp-set-wide"><input id="lp-onb-name" type="text" wire:model="data.name"></div>
                                @if ($this->problem('name'))<span class="lp-set-error">{{ $this->problem('name') }}</span>@endif

                                <label>{{ $t('form.languages') }} <span class="lp-sample">{{ $t('form.languages_tip') }}</span></label>
                                {{-- the first is the main one: the language the store's content is read in --}}
                                <div class="lp-onb-dd" x-data="{ v: $wire.entangle('data.languages'), open: false, q: '', names: @js($languages), main: @js(__('admin::shop_settings.general.main')) }"
                                    x-on:click.outside="open = false" x-on:keydown.escape="open = false" data-languages>
                                    <button type="button" class="lp-onb-dd-btn" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-haspopup="listbox" aria-label="{{ $t('form.languages') }}">
                                        <span class="lp-onb-dd-vals">
                                            <template x-for="(code, i) in (v || [])" :key="code"><span class="lp-chip lp-chip-sm lp-chip-plus" x-text="(names[code] || code) + (i === 0 ? ' · ' + main : '')"></span></template>
                                        </span>
                                        <svg class="lp-ico" aria-hidden="true"><use href="#lp-i-down"/></svg>
                                    </button>
                                    <div class="lp-onb-dd-menu" x-show="open" x-cloak>
                                        <div class="lp-set-field lp-set-wide"><svg class="lp-ico" aria-hidden="true"><use href="#lp-i-search"/></svg><input type="text" x-model="q" placeholder="{{ $t('form.language_search') }}" aria-label="{{ $t('form.language_search') }}"></div>
                                        <div class="lp-onb-dd-list" role="listbox" aria-multiselectable="true">
                                            <template x-for="(name, code) in names" :key="code">
                                                <button type="button" role="option" class="lp-onb-dd-opt" x-show="! q || name.toLowerCase().includes(q.toLowerCase()) || code.includes(q.toLowerCase())"
                                                    x-bind:aria-selected="(v || []).includes(code) ? 'true' : 'false'"
                                                    x-on:click="(v || []).includes(code) ? ((v || []).length > 1 && (v = v.filter((c) => c !== code))) : (v = [...(v || []), code])">
                                                    <span class="lp-onb-dd-check"><svg aria-hidden="true"><use href="#lp-i-check"/></svg></span>
                                                    <span x-text="name"></span>
                                                    <small x-show="(v || [])[0] === code" x-text="main"></small>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <label>{{ $t('form.kind') }}</label>
                                <x-lp::opts model="data.kind" :options="__('admin::onboarding.form.kinds')" :columns="3" />

                                <label>{{ $t('form.sells') }} <span class="lp-sample">{{ $t('form.sells_tip') }}</span></label>
                                <div class="lp-chips" x-data="{ v: $wire.entangle('data.sells'), word: '' }" data-sells>
                                    <template x-for="s in (v || [])" :key="s">
                                        <span class="lp-chip lp-chip-plus"><span x-text="s"></span><button type="button" x-on:click="v = v.filter((x) => x !== s)" class="lp-chip-x" aria-label="{{ $t('form.remove') }}">✕</button></span>
                                    </template>
                                    <span class="lp-set-field" style="max-width: 200px; min-height: 34px">
                                        <input type="text" x-model="word" placeholder="{{ $t('form.sells_add') }}" aria-label="{{ $t('form.sells') }}" x-on:keydown.enter.prevent="if (word.trim() && ! (v || []).includes(word.trim())) { v = [...(v || []), word.trim()]; } word = ''">
                                    </span>
                                </div>

                                <div class="lp-onb-actions">
                                    <button type="button" class="lp-btn lp-btn-primary" wire:click="saveProfile" wire:loading.attr="disabled" wire:target="saveProfile" data-save>{{ $t('form.save') }}</button>
                                </div>
                            </div>
                        @endif
                    </section>
                @elseif ($step === 3)
                    {{-- 3 · the scan, its counts as they grow, and the store's own search to try beside it --}}
                    @php($rows = $this->scanRows())
                    @php($learned = $this->learned())
                    @php($tryUrl = $this->tryUrl())
                    @php($searchable = $this->searchable())
                    <section class="lp-onb-pane" data-pane="scan" data-scanning="{{ $scanning ? '1' : '0' }}">
                        <div class="lp-onb-scan-head">
                            <span @class(['lp-onb-sand', 'is-done' => ! $scanning])><span @class(['lp-onb-mark', 'is-think' => $scanning]) aria-hidden="true"><i></i><i></i></span></span>
                            <div>
                                <h2>{{ $t($scanning ? 'scan.title' : 'scan.title_done') }}</h2>
                                <p class="lp-onb-lead">{{ $t('scan.lead') }}</p>
                            </div>
                        </div>
                        <div class="lp-onb-split">
                            <div>
                                <div class="lp-onb-scan" data-scan>
                                    @php($icons = ['products' => 'lp-onb-i-box', 'images' => 'lp-onb-i-img', 'pages' => 'lp-i-doc', 'tags' => 'lp-i-spark', 'knowledge' => 'lp-onb-i-brain'])
                                    @foreach ($rows as $row)
                                        <div @class(['lp-onb-scan-row', 'is-done' => $row['state'] === 'done', 'is-wait' => $row['state'] === 'waiting']) data-scan-row="{{ $row['key'] }}" data-state="{{ $row['state'] }}">
                                            <span class="lp-onb-ic" aria-hidden="true"><svg><use href="#{{ $icons[$row['key']] }}"/></svg></span>
                                            <div><div class="lp-onb-t">{{ $row['title'] }}</div><div class="lp-onb-s">{{ $row['sub'] }}</div></div>
                                            <div class="lp-onb-n"><span data-n>{{ number_format($row['n']) }}</span><div class="lp-onb-s">{{ $row['unit'] }}</div></div>
                                        </div>
                                    @endforeach
                                </div>
                                @if ($learned !== [])
                                    <div class="lp-onb-learned" aria-live="polite" data-learned>
                                        @foreach ($learned as $fact)
                                            <div class="lp-onb-fact"><svg aria-hidden="true"><use href="#lp-i-check"/></svg><span><b>{{ $fact['title'] }}</b>{{ $fact['text'] }}</span></div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            @if ($tryUrl)
                                {{-- the real drawer on the real store, in the team's preview: a try opens it there with the words typed --}}
                                <div class="lp-card lp-onb-try" data-try x-data="{ q: '', url: @js($tryUrl), go(words) { words = String(words || '').trim(); const at = this.url + (this.url.includes('?') ? '&' : '?') + (words ? 'la_q=' + encodeURIComponent(words) : 'lsOpen=1'); window.open(at, '_blank', 'noopener'); @if ($edit) $wire.looked(); @endif } }">
                                    <div class="lp-card-t">
                                        <h2>{{ $t('try.title') }}</h2>
                                        @if ($searchable !== null)<span class="lp-sample" data-searchable>{{ trans_choice('admin::onboarding.try.knows', $searchable, ['count' => number_format($searchable)]) }}</span>@endif
                                    </div>
                                    <p class="lp-onb-help">{{ $t($this->live() ? 'try.help_live' : 'try.help') }}</p>
                                    <form class="lp-set-field lp-set-wide lp-onb-try-field" x-on:submit.prevent="go(q)">
                                        <svg class="lp-ico" aria-hidden="true"><use href="#lp-i-search"/></svg>
                                        <input type="search" x-model="q" placeholder="{{ $t('try.placeholder') }}" aria-label="{{ $t('try.label') }}" enterkeyhint="search">
                                    </form>
                                    @if ($this->tries() !== [])
                                        <div class="lp-chips lp-onb-tries">
                                            @foreach ($this->tries() as $try)
                                                <button type="button" class="lp-chip lp-chip-sm" x-on:click="go(@js($try))">{{ $try }}</button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div class="lp-onb-actions">
                            <button type="button" class="lp-btn lp-btn-primary" wire:click="{{ $edit ? 'toFirstSteps' : '$set(\'step\', 4)' }}" data-next>{{ $t('scan.next') }}</button>
                            @if ($edit && ! $scanning && $this->progress() !== null)
                                <button type="button" class="lp-btn" wire:click="scanAgain" wire:loading.attr="disabled" data-again>{{ $t('scan.again') }}</button>
                            @endif
                            @if ($scanning)<span class="lp-sample">{{ $t('scan.running') }}</span>@endif
                        </div>
                    </section>
                @else
                    {{-- 4 · the first steps, the same the home shows --}}
                    @php($summary = $this->summary())
                    @php($left = collect($wins)->where('done', false)->count())
                    <section class="lp-onb-pane" data-pane="steps">
                        <h2>{{ $t($scanning ? 'steps.title_running' : 'steps.title', ['shop' => $shop->name]) }}</h2>
                        <p class="lp-onb-lead">{{ trim(($summary ?? '').' '.($left > 0 ? trans_choice('admin::onboarding.steps.lead', $left, ['count' => $left]) : '')) }}</p>
                        @if ($wins !== [])
                            <div class="lp-card lp-onb-wins" data-first-steps>
                                @if ($left === 0)
                                    <div class="lp-wins-done">
                                        <span class="lp-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 3h14a7 7 0 0 1-14 0zM5 21h14a7 7 0 0 0-14 0z" fill="currentColor"/></svg></span>
                                        <div style="flex: 1"><b>{{ __('admin::home.steps.all_done') }}</b><div class="lp-note">{{ __('admin::home.steps.all_done_body') }}</div></div>
                                    </div>
                                @else
                                    @include('admin::merchant.first-steps', ['steps' => $wins])
                                @endif
                            </div>
                        @endif
                        <div class="lp-onb-actions">
                            @if ($edit)
                                <button type="button" class="lp-btn lp-btn-primary" wire:click="finish" data-home>{{ $t('steps.home') }}</button>
                            @else
                                <a class="lp-btn lp-btn-primary" href="{{ \App\Modules\Admin\Filament\Merchant\Pages\ShopOverview::getUrl() }}" data-home>{{ $t('steps.home') }}</a>
                            @endif
                        </div>
                    </section>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
