<?php

namespace App\Services\Exports\Definitions;

use App\Enums\UserRole;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\Exports\ExportDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Client users (spec 15.2). Name and email, no phone (SCR-033). Only clients are ever included: the users screen also lists
 * contributors and admins, so the export narrows its query to clients whatever the screen shows.
 *
 * @extends ExportDefinition<User>
 */
class ClientExport extends ExportDefinition
{
    public function permission(): string
    {
        return 'import_export.projects';
    }

    public function name(): string
    {
        return 'clients';
    }

    public function logName(): string
    {
        return 'client_export';
    }

    public function headings(): array
    {
        return ['Name', 'Email', 'Organization', 'Role in organization', 'Designation', 'Account status', 'Joined'];
    }

    public function with(): array
    {
        return ['organizations'];
    }

    public function scope(Builder $query): Builder
    {
        return $query->where('role', UserRole::Client->value);
    }

    /** @param  User  $model */
    public function row(Model $model): array
    {
        $organization = $model->organizations->first();
        $membership = $organization?->getRelation('pivot');

        return [
            $model->name,
            $model->email,
            $organization?->name,
            $this->label($membership instanceof OrganizationUser ? $membership->org_role : null),
            $membership instanceof OrganizationUser ? $membership->designation : null,
            $this->label($model->status),
            $this->date($model->created_at),
        ];
    }
}
