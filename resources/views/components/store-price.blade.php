<span @if ($cache->isConverted()) title="{{ __('Converted to your currency at the latest exchange rate') }}" @endif>
    <strong class="font-bold">{{ $cache->getStorePriceFormatted() }}</strong>
    @if ($cache->hasAdditionalCost())
        + {{ $cache->getAdditionalCostFormatted() }} {{ __('additional cost') }}
    @endif
    {{ __('at the store') }}
</span>
