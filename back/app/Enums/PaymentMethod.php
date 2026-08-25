<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case NoFinancialSystem = 'no_utiliza_sist_financiero';
    case DebtCompensation = 'compensacion_deudas';
    case DebitCard = 'tarjeta_debito';
    case ElectronicMoney = 'dinero_electronico';
    case PrepaidCard = 'tarjeta_prepago';
    case CreditCard = 'tarjeta_credito';
    case BankTransfer = 'transferencia_bancaria';
    case Other = 'otros';
    case EndorsedSecurities = 'endoso_titulos';
}
