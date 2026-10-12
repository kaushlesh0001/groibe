<?php

namespace App\Policies;

use App\Enums\ImportType;
use App\Models\Import;
use App\Models\User;

/**
 * Imports hold personal data in the stored file and the error report, so beyond holding the permission for the
 * file's type (section 5, SCR-031) an admin works only with their own imports. A super admin (audit_log.view_all)
 * sees all of them.
 */
class ImportPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin() && collect(ImportType::cases())->contains(fn (ImportType $type): bool => $actor->can($type->permission()));
    }

    /** Pass the type to ask about one kind of file: `can('create', [Import::class, ImportType::Clients])`. */
    public function create(User $actor, ?ImportType $type = null): bool
    {
        if (! $actor->isAdmin()) {
            return false;
        }

        return $type === null ? $this->viewAny($actor) : $actor->can($type->permission());
    }

    public function view(User $actor, Import $import): bool
    {
        return $actor->isAdmin()
            && $actor->can($import->type->permission())
            && ($import->created_by === $actor->getKey() || $actor->can('audit_log.view_all'));
    }

    public function update(User $actor, Import $import): bool
    {
        return $this->view($actor, $import);
    }

    public function delete(User $actor, Import $import): bool
    {
        return false;
    }
}
