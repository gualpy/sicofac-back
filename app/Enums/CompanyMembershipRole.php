<?php

namespace App\Enums;

enum CompanyMembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Seller = 'seller';
}

