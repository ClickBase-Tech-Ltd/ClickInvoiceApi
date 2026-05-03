<?php

namespace App\Services;

/**
 * Single place for subtotal / discount / tax / totals (matches InvoiceController@store).
 */
class InvoiceTotalsService
{
    /**
     * @param  array<int, array{quantity?: mixed, amount?: mixed}>  $items  amount = unit price
     * @return array{subtotal: float, discountAmount: float, taxAmount: float, totalAmount: float, balanceDue: float}
     */
    public function compute(
        array $items,
        float $discountPercentage,
        float $taxPercentage,
        float $amountPaid
    ): array {
        $subtotal = 0.0;
        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 1);
            $unit = (float) ($item['amount'] ?? 0);
            $subtotal += $qty * $unit;
        }

        $discountAmount = $subtotal * ($discountPercentage / 100);
        $amountAfterDiscount = max(0.0, $subtotal - $discountAmount);
        $taxAmount = $amountAfterDiscount * ($taxPercentage / 100);
        $totalAmount = $amountAfterDiscount + $taxAmount;
        $balanceDue = max(0.0, $totalAmount - $amountPaid);

        return [
            'subtotal' => $subtotal,
            'discountAmount' => $discountAmount,
            'taxAmount' => $taxAmount,
            'totalAmount' => $totalAmount,
            'balanceDue' => $balanceDue,
        ];
    }
}
