<?php

namespace App\Enums;

enum InventoryReason: string
{
    case Sale = 'sale';
    case Restock = 'restock';
    case ReturnIn = 'return';
    case Adjustment = 'adjustment';
}
