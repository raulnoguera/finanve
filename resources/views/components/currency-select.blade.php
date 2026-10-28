@props(['id' => null, 'model' => null])
@if(ve_mode())
    <select name="currency" @if($id) id="{{ $id }}" @endif @if($model) x-model="{{ $model }}" @endif aria-label="Moneda" {{ $attributes->merge(['class' => 'field']) }}>
        <option value="USD">USD $</option>
        <option value="VES">Bs</option>
    </select>
@else
    <input type="hidden" name="currency" value="{{ base_currency() }}">
    <span {{ $attributes->merge(['class' => 'field grid place-items-center font-extrabold text-muted']) }} title="{{ config('currencies.'.base_currency().'.name') }}">{{ base_currency() }}</span>
@endif
