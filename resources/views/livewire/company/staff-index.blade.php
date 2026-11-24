<div>
    @include('components.ui.flash')

    <div class="card relative overflow-x-auto p-0">
        <table data-stack class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800">
                <tr>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Phone') }}</th>
                    <th class="px-4 py-3">{{ __('Role') }}</th>
                    <th class="px-4 py-3">{{ __('Branches') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    @if ($manageable)<th class="px-4 py-3 text-right">{{ __('Actions') }}</th>@endif
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
                @foreach ($members as $member)
                    <tr wire:key="member-{{ $member->id }}" data-staff-row>
                        <td class="px-4 py-3"><p class="font-medium">{{ $member->user->name }}</p><p class="text-xs text-slate-500 [overflow-wrap:anywhere]">{{ $member->user->email }}</p></td>
                        <td class="px-4 py-3">{{ $member->user->phone ?: '—' }}</td>
                        <td class="px-4 py-3">{{ __($member->role->name) }}</td>
                        <td class="px-4 py-3">{{ $member->all_branches ? __('All branches') : ($member->branches->pluck('name')->join(', ') ?: '—') }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $member->is_active ? 'bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-100' : 'bg-slate-100 text-slate-500 dark:bg-slate-700' }}">
                                {{ $member->is_active ? __('Active') : __('Inactive') }}
                            </span>
                        </td>
                        @if ($manageable)
                            <td class="px-4 py-3 text-right" data-label="">
                                @if ($manageable[$member->id] ?? false)
                                    <div class="flex flex-wrap justify-end gap-1">
                                        <button type="button" wire:click="edit({{ $member->id }})" class="inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-medium text-brand-700 hover:bg-brand-50 dark:text-brand-100 dark:hover:bg-brand-900/30" data-edit-staff="{{ $member->id }}">{{ __('Edit') }}</button>
                                        <button type="button" wire:click="askPassword({{ $member->id }})" class="inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-700" data-reset-password="{{ $member->id }}">{{ __('Reset password') }}</button>
                                    </div>
                                @else
                                    <span class="text-xs text-slate-400">{{ __('No access') }}</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $members->links() }}</div>

    {{-- Edit staff member --}}
    <x-ui.modal wire:model="editing" :title="__('Edit staff member')" max-width="max-w-2xl">
        <form wire:submit="save" class="space-y-5" data-edit-staff-form>
            @if ($identityLocked)
                <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">{{ __('This person also works for another company, so their name, email and phone can only be changed by them. You can still change their role, branches and status here.') }}</p>
            @endif
            <div class="grid gap-5 md:grid-cols-2">
                <x-ui.field class="md:col-span-2" :label="__('Full name')" for="st-name" error="name"><input id="st-name" type="text" wire:model="name" class="form-input" autocomplete="off" @disabled($identityLocked)></x-ui.field>
                <x-ui.field :label="__('Email')" for="st-email" error="email"><input id="st-email" type="email" wire:model="email" class="form-input" autocomplete="off" @disabled($identityLocked)></x-ui.field>
                <x-ui.field :label="__('Phone')" for="st-phone" error="phone"><input id="st-phone" type="tel" wire:model="phone" class="form-input" autocomplete="off" placeholder="+255 7xx xxx xxx" @disabled($identityLocked)></x-ui.field>
                <x-ui.field :label="__('Role')" for="st-role" error="role" :hint="$isSelf ? __('You cannot change your own role.') : null">
                    <select id="st-role" wire:model.live="role" class="form-input" @disabled($isSelf)>
                        @foreach ($roles as $r) <option value="{{ $r->slug }}">{{ __($r->name) }}</option> @endforeach
                    </select>
                </x-ui.field>
                <div>
                    <p class="form-label">{{ __('Status') }}</p>
                    <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model="isActive" class="size-5 rounded border-slate-300 accent-brand-600" @disabled($isSelf)> {{ __('Active (can sign in and work)') }}</label>
                    @error('is_active') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <fieldset class="space-y-2">
                <legend class="form-label">{{ __('Branches') }}</legend>
                @if ($selectedRole?->all_branches)
                    <p class="text-sm text-slate-600 dark:text-slate-300">{{ __('This role always has access to all branches.') }}</p>
                @else
                    @if ($actorAllBranches)
                        <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model.live="allBranches" class="size-5 rounded border-slate-300 accent-brand-600"> {{ __('All branches (including new ones)') }}</label>
                    @endif
                    @unless ($allBranches)
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($branchOptions as $b)
                                <label class="flex min-h-11 items-center gap-3 rounded-lg border border-slate-200 px-3 text-sm dark:border-slate-700"><input type="checkbox" wire:model="branches" value="{{ $b->uuid }}" class="size-5 rounded border-slate-300 accent-brand-600"> {{ $b->name }}</label>
                            @endforeach
                        </div>
                    @endunless
                @endif
                @error('branches') <p class="form-error">{{ $message }}</p> @enderror
            </fieldset>

            @if ($history->isNotEmpty())
                <details class="rounded-xl border border-slate-200 p-3 text-sm dark:border-slate-700">
                    <summary class="cursor-pointer font-medium">{{ __('Recent changes') }}</summary>
                    <ol class="mt-2 space-y-1 text-slate-600 dark:text-slate-300">
                        @foreach ($history as $e)
                            <li>{{ $e->created_at->format('d/m/Y H:i') }} · {{ __(str_replace('_', ' ', ucfirst($e->event))) }}@if ($e->from_status || $e->to_status) ({{ $e->from_status }} → {{ $e->to_status }})@endif @if ($e->notes) — {{ $e->notes }}@endif · {{ $e->user?->name }}</li>
                        @endforeach
                    </ol>
                </details>
            @endif

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" wire:click="$set('editing', false)" class="btn btn-secondary">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save" data-save-staff>
                    <span wire:loading.remove wire:target="save">{{ __('Save changes') }}</span><span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Reset password (confirmation dialog) --}}
    @if ($passwordMember)
        <x-ui.confirm-dialog wire:model="resettingPassword" confirm="resetPassword" tone="danger" max-width="max-w-md"
            :title="__('Reset password for :name?', ['name' => $passwordMember->user->name])"
            :description="__('They will be signed out of all devices and must use the new password.')"
            :confirm-label="__('Set new password')" :busy-label="__('Saving…')" :disabled="$identityLocked">
            @if ($identityLocked)
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ __('This person also works for another company, so you cannot set their password. Send them a reset link by email instead.') }}</p>
            @else
                <div class="space-y-4">
                    <x-ui.field :label="__('New password')" for="st-password" error="password"><input id="st-password" type="password" wire:model="password" class="form-input" autocomplete="new-password"></x-ui.field>
                    <x-ui.field :label="__('Confirm password')" for="st-password2"><input id="st-password2" type="password" wire:model="password_confirmation" class="form-input" autocomplete="new-password"></x-ui.field>
                </div>
            @endif
            <p class="mt-4 border-t border-slate-200 pt-3 text-sm dark:border-slate-700">{{ __('Or let them choose a password themselves:') }}
                <button type="button" wire:click="sendResetLink" class="inline-flex min-h-10 items-center font-semibold text-brand-700 underline dark:text-brand-200" data-send-reset-link>{{ __('Email a reset link to :email', ['email' => $passwordMember->user->email]) }}</button></p>
        </x-ui.confirm-dialog>
    @endif
</div>
