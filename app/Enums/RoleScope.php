<?php

namespace App\Enums;

enum RoleScope: string
{
    /** Platform operator roles. Never assignable to a company membership. */
    case Platform = 'platform';

    /** Roles held through a company membership. */
    case Tenant = 'tenant';
}
