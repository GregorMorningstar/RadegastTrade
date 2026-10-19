<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the append-only audit log of the trade module. The context holds ids and short facts, never secrets, tokens
 * or whole records.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(?User $actor, ?int $organizationId, string $action, ?Model $subject = null, array $context = []): void
    {
        AuditLog::create([
            'organization_id' => $organizationId ?? $actor?->organization_id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject?->getKey(),
            'context' => $context === [] ? null : $context,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }
}
