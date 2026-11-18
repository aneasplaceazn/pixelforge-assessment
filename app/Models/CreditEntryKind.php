<?php

namespace App\Models;

enum CreditEntryKind: string
{
    case Purchase = 'purchase';
    case Reserve = 'reserve';
    case Settle = 'settle';
    case Release = 'release';
    case DebtPayment = 'debt_payment';
}
