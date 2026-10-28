@props(['usd', 'sign' => false, 'size' => 'text-base', 'tone' => ''])
@php $v = ves($usd); @endphp
<span {{ $attributes->class(['num inline-flex flex-col leading-tight', $tone]) }}>
    <span class="{{ $size }} font-extrabold">{{ usd($usd, $sign) }}</span>
    @if($v)<span class="text-xs font-medium text-muted">{{ $v }}</span>@endif
</span>
