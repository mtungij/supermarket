<?php

namespace App\Models\Concerns;

use App\Models\DocumentEvent;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasDocumentEvents
{
    public function events(): MorphMany
    {
        return $this->morphMany(DocumentEvent::class, 'documentable')
            ->withoutGlobalScope(CompanyScope::class)
            ->orderBy('id');
    }

    public function recordEvent(string $event, ?string $from, ?string $to, ?User $user, ?string $notes = null): DocumentEvent
    {
        $record = new DocumentEvent;
        $record->forceFill([
            'company_id' => $this->company_id,
            'documentable_type' => $this->getMorphClass(),
            'documentable_id' => $this->getKey(),
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $user?->id,
            'notes' => $notes,
        ])->save();

        return $record;
    }
}
