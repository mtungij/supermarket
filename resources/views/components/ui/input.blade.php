@props(['name', 'label', 'type' => 'text', 'id' => null])
@php($id ??= $name)
<div>
    <label for="{{ $id }}" class="form-label">{{ $label }}</label>
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
           @if ($type !== 'password') value="{{ old($name) }}" @endif
           {{ $attributes->merge(['class' => 'form-input']) }}
           @error($name) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror>
    @error($name)
        <p id="{{ $id }}-error" class="form-error">{{ $message }}</p>
    @enderror
</div>
