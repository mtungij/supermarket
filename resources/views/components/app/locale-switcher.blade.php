<form method="POST" action="{{ route('locale.update') }}">
    @csrf
    <label for="locale-{{ $id = uniqid() }}" class="sr-only">{{ __('Language') }}</label>
    <select id="locale-{{ $id }}" name="locale" onchange="this.form.submit()"
            class="min-h-11 rounded-xl border border-slate-300 bg-white px-2 text-sm dark:border-slate-600 dark:bg-slate-800">
        @foreach (config('saas.supported_locales') as $code => $label)
            <option value="{{ $code }}" @selected(app()->getLocale() === $code)>{{ strtoupper($code) }}</option>
        @endforeach
    </select>
</form>
