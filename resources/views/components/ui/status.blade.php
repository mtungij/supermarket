{{-- Colour-coded document status badge. --}}
@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $color = match ($value) {
        'approved', 'posted', 'received', 'active' => 'green',
        'submitted', 'pending_approval', 'in_transit', 'partially_received' => 'amber',
        'cancelled', 'rejected', 'revoked' => 'red',
        'closed' => 'blue',
        default => 'slate',
    };
    $label = method_exists($status, 'label') ? $status->label() : ucfirst(str_replace('_', ' ', $value));
@endphp
<x-ui.badge :color="$color">{{ __($label) }}</x-ui.badge>
