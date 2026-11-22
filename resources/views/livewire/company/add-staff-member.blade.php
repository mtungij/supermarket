<div class="mb-6">
    @if (! $open)
        <button type="button" wire:click="$set('open', true)" class="btn btn-primary"><x-icon name="plus" /> {{ __('Add staff member') }}</button>
    @else
        <div class="card space-y-5">
            <h3 class="font-semibold">{{ __('Add staff member') }}</h3>
            <div class="grid gap-5 md:grid-cols-2">
                <x-ui.field :label="__('Email')" for="st-email" error="email"><input id="st-email" type="email" wire:model.blur="email" class="form-input" autocomplete="off"></x-ui.field>
                <x-ui.field :label="__('Name')" for="st-name" error="name" :hint="__('Not needed if the email already has an account.')"><input id="st-name" type="text" wire:model="name" class="form-input"></x-ui.field>
                <x-ui.field :label="__('Password')" for="st-pw" error="password"><input id="st-pw" type="password" wire:model="password" class="form-input" autocomplete="new-password"></x-ui.field>
                <x-ui.field :label="__('Confirm password')" for="st-pw2" error="password_confirmation"><input id="st-pw2" type="password" wire:model="password_confirmation" class="form-input" autocomplete="new-password"></x-ui.field>
                <x-ui.field :label="__('Role')" for="st-role" error="role"><select id="st-role" wire:model.live="role" class="form-input">@foreach ($roles as $r) <option value="{{ $r->slug }}">{{ __($r->name) }}</option> @endforeach</select></x-ui.field>
                @unless ($roles->firstWhere('slug', $role)?->all_branches)
                    <fieldset>
                        <legend class="form-label">{{ __('Branches') }}</legend>
                        @foreach ($branchOptions as $b)
                            <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" value="{{ $b->uuid }}" wire:model="branches" class="size-5 rounded"> {{ $b->name }}</label>
                        @endforeach
                        @error('branches') <p class="form-error">{{ $message }}</p> @enderror
                    </fieldset>
                @endunless
            </div>
            @error('subscription') <p class="form-error">{{ $message }}</p> @enderror
            <div class="flex gap-3"><button type="button" wire:click="save" wire:loading.attr="disabled" class="btn btn-primary">{{ __('Save') }}</button><button type="button" wire:click="$set('open', false)" class="btn btn-secondary">{{ __('Cancel') }}</button></div>
        </div>
    @endif
</div>
