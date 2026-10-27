<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPlatformPermission(Permission::AuditLogsView);
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $this->viewAny($user);
    }
}
