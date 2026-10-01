<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Records who changed what in admin_audit_logs, with before/after snapshots. */
class AuditLogger
{
    private const HIDDEN = ['created_at', 'updated_at', 'search_vector'];

    public function created(StaffUser $staff, Model $model): void
    {
        $this->write($staff, 'created', $model, null, $this->snapshot($model->getAttributes()));
    }

    /** Call with the original attributes captured before update(). */
    public function updated(StaffUser $staff, Model $model, array $before): void
    {
        $changed = array_keys(array_diff_key($model->getChanges(), array_flip(self::HIDDEN)));

        if ($changed === []) {
            return;
        }

        $this->write(
            $staff, 'updated', $model,
            $this->snapshot(array_intersect_key($before, array_flip($changed))),
            $this->snapshot(array_intersect_key($model->getAttributes(), array_flip($changed))),
        );
    }

    public function deleted(StaffUser $staff, Model $model): void
    {
        $this->write($staff, 'deleted', $model, $this->snapshot($model->getAttributes()), null);
    }

    /** $verb is the action without the entity prefix, e.g. 'restored' becomes 'book.restored'. */
    public function custom(StaffUser $staff, string $verb, Model $model, ?array $before = null, ?array $after = null): void
    {
        $this->write($staff, $verb, $model, $before, $after);
    }

    private function write(StaffUser $staff, string $action, Model $model, ?array $before, ?array $after): void
    {
        $type = Str::snake(class_basename($model));

        AdminAuditLog::create([
            'staff_user_id' => $staff->id,
            'action' => "{$type}.{$action}",
            'entity_type' => $type,
            'entity_id' => $model->getKey(),
            'before_data' => $before,
            'after_data' => $after,
        ]);
    }

    private function snapshot(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::HIDDEN));
    }
}
