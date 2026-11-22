<?php

namespace Tests\Unit;

use App\Enums\Permission;
use App\Enums\SystemRole;
use PHPUnit\Framework\TestCase;

/**
 * Guards the business rule: Cashier Shift Management is excluded.
 */
class ProductRulesTest extends TestCase
{
    public function test_no_shift_permissions_exist(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertStringNotContainsStringIgnoringCase('shift', $permission->value);
        }
    }

    public function test_no_shift_migrations_exist(): void
    {
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
            $this->assertStringNotContainsStringIgnoringCase('shift', basename($file));
            $this->assertDoesNotMatchRegularExpression("/create\\(\\s*'[^']*shift/i", file_get_contents($file));
        }
    }

    public function test_cashier_role_can_sell_directly(): void
    {
        $permissions = SystemRole::Cashier->permissions();

        $this->assertContains(Permission::PosAccess, $permissions);
        $this->assertContains(Permission::SalesCreate, $permissions);
    }
}
