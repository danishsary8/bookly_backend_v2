<!doctype html>
<html lang="{{ app()->getLocale() }}"><body>
<h1>{{ __('credit.subject') }}</h1>
<p>{{ __('credit.greeting', ['name' => $recipientName]) }}</p>
<p>{{ __('credit.gift', ['amount' => $amount]) }}</p>
@if ($giftMessage)<p style="white-space: pre-wrap">{{ $giftMessage }}</p>@endif
<p>{{ __('credit.code', ['code' => $giftCode]) }}</p>
<p>{{ __('credit.expires', ['date' => $expires]) }}</p>
<p>{{ __('credit.keep_safe') }}</p>
</body></html>
