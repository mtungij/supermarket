@if (session('status'))
    <div class="mb-4 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900 dark:border-brand-900 dark:bg-brand-900/30 dark:text-brand-100" role="status">{{ session('status') }}</div>
@endif
@error('subscription')
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-900/30 dark:text-amber-100" role="alert">{{ $message }}</div>
@enderror
@if (session('error'))
    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-900/30 dark:text-red-200" role="alert">{{ session('error') }}</div>
@endif
