<div>
    <label class="label" for="{{ $id }}">Categoría</label>
    <select id="{{ $id }}" name="category_id" class="field">
        <option value="">Sin categoría</option>
        @foreach($cats->whereNull('parent_id') as $c)
            @if($kids->has($c->id))
                <optgroup label="{{ $c->name }}">
                    <option value="{{ $c->id }}" @selected($c->id === $defaultCategory)>{{ $c->name }} (general)</option>
                    @foreach($kids[$c->id] as $k)<option value="{{ $k->id }}">{{ $k->name }}</option>@endforeach
                </optgroup>
            @else
                <option value="{{ $c->id }}" @selected($c->id === $defaultCategory)>{{ $c->name }}</option>
            @endif
        @endforeach
    </select>
</div>
