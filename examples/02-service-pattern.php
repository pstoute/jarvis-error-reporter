<?php

/**
 * Example: Using Jarvis in Service Classes
 *
 * This example demonstrates how to integrate Jarvis error reporting
 * into your business logic layer while maintaining clean error handling.
 */

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use StouteWebSolutions\JarvisErrorReporter\Facades\Jarvis;
use Exception;

class OrderProcessingService
{
    /**
     * Process an order with comprehensive error tracking.
     */
    public function processOrder(Order $order): Payment
    {
        // Add context about what we're processing
        Jarvis::setContext([
            'operation' => 'process_order',
            'order_id' => $order->id,
            'order_total' => $order->total,
            'customer_id' => $order->customer_id,
            'payment_method' => $order->payment_method,
        ]);

        try {
            return DB::transaction(function () use ($order) {
                // Validate order
                $this->validateOrder($order);

                // Process payment
                $payment = $this->chargePayment($order);

                // Update inventory
                $this->updateInventory($order);

                // Send confirmation
                $this->sendConfirmation($order, $payment);

                return $payment;
            });
        } catch (Exception $e) {
            // Capture with additional context about the failure
            Jarvis::capture($e, [
                'order_state' => $order->fresh()->status,
                'payment_attempted' => isset($payment),
                'transaction_failed' => true,
            ]);

            // Re-throw or handle appropriately
            throw $e;
        }
    }

    /**
     * Example: Capturing non-critical errors without breaking flow.
     */
    public function notifyWarehouse(Order $order): void
    {
        Jarvis::setContext([
            'operation' => 'notify_warehouse',
            'order_id' => $order->id,
        ]);

        try {
            // Attempt to notify external warehouse system
            $response = Http::timeout(5)
                ->post(config('services.warehouse.url'), [
                    'order_id' => $order->id,
                    'items' => $order->items->toArray(),
                ]);

            if (!$response->successful()) {
                throw new Exception(
                    "Warehouse notification failed: {$response->status()}"
                );
            }
        } catch (Exception $e) {
            // Capture the error but don't fail the order
            Jarvis::capture($e, [
                'severity' => 'warning',
                'non_critical' => true,
                'warehouse_url' => config('services.warehouse.url'),
            ]);

            // Log for manual follow-up
            logger()->warning('Warehouse notification failed, manual intervention may be required', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            // Don't throw - this is a non-critical failure
        }
    }

    /**
     * Example: Adding context progressively through a complex operation.
     */
    public function importOrdersFromCSV(string $filePath): array
    {
        $results = [
            'imported' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        Jarvis::setContext([
            'operation' => 'csv_import',
            'file_path' => $filePath,
            'started_at' => now()->toIso8601String(),
        ]);

        $rows = $this->parseCSV($filePath);
        $total = count($rows);

        foreach ($rows as $index => $row) {
            // Update context for each row
            Jarvis::setContext([
                'current_row' => $index + 1,
                'total_rows' => $total,
                'progress_percent' => round((($index + 1) / $total) * 100, 2),
            ]);

            try {
                $this->importSingleOrder($row);
                $results['imported']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'row' => $index + 1,
                    'error' => $e->getMessage(),
                ];

                // Capture with row-specific context
                Jarvis::capture($e, [
                    'row_data' => $row,
                    'row_number' => $index + 1,
                    'import_batch' => $filePath,
                ]);
            }
        }

        return $results;
    }

    // Helper methods...
    private function validateOrder(Order $order): void
    {
        // Implementation
    }

    private function chargePayment(Order $order): Payment
    {
        // Implementation
        return new Payment();
    }

    private function updateInventory(Order $order): void
    {
        // Implementation
    }

    private function sendConfirmation(Order $order, Payment $payment): void
    {
        // Implementation
    }

    private function parseCSV(string $filePath): array
    {
        // Implementation
        return [];
    }

    private function importSingleOrder(array $row): void
    {
        // Implementation
    }
}

/*
 * Key Takeaways:
 *
 * 1. Add operation context before try blocks
 * 2. Include relevant business data in context
 * 3. Capture errors with additional failure context
 * 4. Decide whether to re-throw or continue
 * 5. Update context progressively for long-running operations
 */
