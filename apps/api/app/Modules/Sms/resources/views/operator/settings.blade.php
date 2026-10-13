<x-filament-panels::page>
    @php($t = fn (string $key, array $with = []) => __('sms::ui.'.$key, $with))
    @php($small = 'font-size:12.5px;opacity:.7')
    @php($input = 'width:100%;box-sizing:border-box;padding:8px 12px;border:1px solid rgba(127,127,127,.35);border-radius:10px;background:transparent;color:inherit;font:inherit')
    @php($cell = 'padding:7px 10px;border-bottom:1px solid rgba(127,127,127,.15);text-align:start')
    @php($account = $this->account())

    <x-filament::section :heading="$t('account')" :description="$t('account_help')">
        <div style="display:grid;gap:12px;max-width:520px">
            <label style="display:grid;gap:4px"><span style="{{ $small }}">{{ $t('username') }}</span>
                <input type="text" wire:model="username" style="{{ $input }}" dir="ltr" autocomplete="off"></label>
            <label style="display:grid;gap:4px"><span style="{{ $small }}">{{ $t('token') }} @if ($account?->token_hint) ({{ $t('token_saved', ['hint' => $account->token_hint]) }}) @endif</span>
                <input type="password" wire:model="token" style="{{ $input }}" dir="ltr" autocomplete="new-password" placeholder="{{ $account ? $t('token_keep') : '' }}"></label>
            <label style="display:grid;gap:4px"><span style="{{ $small }}">{{ $t('sender') }}</span>
                <input type="text" wire:model="sender" maxlength="11" style="{{ $input }}" dir="ltr"></label>
            <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" wire:model="active"> <span>{{ $t('active') }}</span></label>
            <div><x-filament::button wire:click="save">{{ $t('save') }}</x-filament::button></div>
            @if ($account?->last_error)
                <p style="{{ $small }};color:#dc2626">{{ $t('last_error', ['error' => $account->last_error]) }}</p>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section :heading="$t('test')">
        <div style="display:flex;gap:10px;align-items:end;max-width:520px">
            <label style="display:grid;gap:4px;flex:1"><span style="{{ $small }}">{{ $t('test_phone') }}</span>
                <input type="tel" wire:model="testPhone" style="{{ $input }}" dir="ltr" placeholder="050-1234567"></label>
            <x-filament::button color="gray" wire:click="test">{{ $t('test_send') }}</x-filament::button>
        </div>
    </x-filament::section>

    <x-filament::section :heading="$t('month')">
        @php($month = $this->month())
        @if ($month->isEmpty())
            <p style="{{ $small }}">{{ $t('none') }}</p>
        @else
            <table style="width:100%;border-collapse:collapse;font-size:13.5px">
                <thead><tr><th style="{{ $cell }};{{ $small }}">{{ $t('shop') }}</th><th style="{{ $cell }};{{ $small }}">{{ $t('sent') }}</th><th style="{{ $cell }};{{ $small }}">{{ $t('failed') }}</th></tr></thead>
                <tbody>
                    @foreach ($month as $row)
                        <tr><td style="{{ $cell }}">{{ $row->name }}</td><td style="{{ $cell }}">{{ (int) $row->sent }}</td><td style="{{ $cell }}">{{ (int) $row->failed }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament::section :heading="$t('latest')" collapsible collapsed>
        <table style="width:100%;border-collapse:collapse;font-size:13px">
            <tbody>
                @foreach ($this->latest() as $message)
                    <tr>
                        <td style="{{ $cell }}" dir="ltr">{{ $message->created_at?->timezone('Asia/Jerusalem')->format('d/m H:i') }}</td>
                        <td style="{{ $cell }}" dir="ltr">{{ $message->phone_masked }}</td>
                        <td style="{{ $cell }}">{{ $message->purpose }}</td>
                        <td style="{{ $cell }}">{{ $message->ok ? $t('ok') : $message->error }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
