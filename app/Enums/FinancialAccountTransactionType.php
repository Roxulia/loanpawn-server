<?php

namespace App\Enums;

enum FinancialAccountTransactionType: string
{
    case OpeningBalance = 'OPENING_BALANCE';
    case PawnLoanDisbursement = 'PAWN_LOAN_DISBURSEMENT';
    case PawnInterestPayment = 'PAWN_INTEREST_PAYMENT';
    case PawnRedemption = 'PAWN_REDEMPTION';
    case DebtDisbursement = 'DEBT_DISBURSEMENT';
    case DebtPayment = 'DEBT_PAYMENT';
    case BusinessLoanReceipt = 'BUSINESS_LOAN_RECEIPT';
    case BusinessLoanPayment = 'BUSINESS_LOAN_PAYMENT';
    case ExpensePayment = 'EXPENSE_PAYMENT';
    case CapitalContribution = 'CAPITAL_CONTRIBUTION';
    case CapitalWithdrawal = 'CAPITAL_WITHDRAWAL';
    case AccountTransfer = 'ACCOUNT_TRANSFER';
    case TransferFee = 'TRANSFER_FEE';
    case Adjustment = 'ADJUSTMENT';
    case Reversal = 'REVERSAL';
}
