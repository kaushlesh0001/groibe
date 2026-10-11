<?php

namespace App\Support;

use App\Enums\AdminRole;

/**
 * Admin-side permission sets derived from spec section 5.
 *
 * Ability letters: c create, r read (view), u update, d delete, a approve.
 * Permission names are "<resource>.<ability>", for example "organizations.update".
 * Contributor and client abilities on their own records are Policy logic, not permissions.
 */
class PermissionMatrix
{
    private const ABILITIES = [
        'c' => 'create',
        'r' => 'view',
        'u' => 'update',
        'd' => 'delete',
        'a' => 'approve',
    ];

    /**
     * resource => [delivery_manager, community_manager, finance_ops] ability letters.
     * Super admin always receives every permission.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const MATRIX = [
        'contributors' => ['ru', 'crua', 'r'],
        'clients' => ['cru', 'r', 'r'],
        'organizations' => ['cru', 'r', 'r'],
        'briefs' => ['rua', 'r', ''],
        'projects' => ['crua', 'r', 'r'],
        'sows' => ['cru', '', 'r'],
        'milestones' => ['crud', '', ''],
        'tasks' => ['crud', '', ''],
        'submissions' => ['rua', '', ''],
        'deliverables' => ['cru', '', ''],
        'open_roles' => ['cru', 'crud', ''],
        'applications' => ['rua', 'rua', ''],
        'reviews' => ['rua', 'r', ''],
        'proof_of_work' => ['crua', 'rua', ''],
        'payouts' => ['r', '', 'crud'],
        // Pass 7 (SCR-024): invoice references follow the payouts row. Delivery managers read, finance manages.
        'invoice_refs' => ['r', '', 'crud'],
        'agreements' => ['r', 'r', 'r'],
        // Pass 7 (SCR-022): moderating project threads, and answering support threads.
        'threads' => ['ru', '', ''],
        'support' => ['', 'ru', ''],
        'cms' => ['', 'crud', ''],
        // SCR-021: the contact form inbox. Read and update for community managers; delete is super admin only (personal data).
        'contact_messages' => ['', 'ru', ''],
        'admin_users' => ['', '', ''],
        'taxonomy' => ['r', 'crud', ''],
        'project_types' => ['r', '', ''],
        // Pass 7 (SCR-023): the email log is evidence with recipient addresses, so super admin only.
        'email_log' => ['', '', ''],
        'settings' => ['', '', ''],
        'feature_flags' => ['', '', ''],
    ];

    /** @var list<string> */
    private const EXTRA = [
        'audit_log.view',
        'audit_log.view_all',
        'users.impersonate',
        'import_export.contributors',
        'import_export.projects',
        'import_export.payouts',
    ];

    /** MATRIX column index per non-super admin role. */
    private const COLUMNS = [
        'delivery_manager' => 0,
        'community_manager' => 1,
        'finance_ops' => 2,
    ];

    /** @var array<string, list<string>> */
    private const EXTRA_BY_ROLE = [
        'delivery_manager' => ['audit_log.view', 'import_export.contributors', 'import_export.projects'],
        'community_manager' => ['audit_log.view', 'import_export.contributors'],
        'finance_ops' => ['audit_log.view', 'import_export.payouts'],
    ];

    /** @return list<string> */
    public static function allPermissions(): array
    {
        $names = [];

        foreach (self::MATRIX as $resource => $_) {
            foreach (self::ABILITIES as $ability) {
                $names[] = "{$resource}.{$ability}";
            }
        }

        return [...$names, ...self::EXTRA];
    }

    /** @return list<string> */
    public static function forRole(AdminRole $role): array
    {
        if ($role === AdminRole::SuperAdmin) {
            return self::allPermissions();
        }

        $column = self::COLUMNS[$role->value];

        $names = [];

        foreach (self::MATRIX as $resource => $columns) {
            foreach (str_split($columns[$column]) as $letter) {
                $names[] = "{$resource}.".self::ABILITIES[$letter];
            }
        }

        return [...$names, ...self::EXTRA_BY_ROLE[$role->value]];
    }
}
