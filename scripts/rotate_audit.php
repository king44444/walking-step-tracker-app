<?php
/**
 * SMS Audit Table Rotation Script
 *
 * Prunes old records from SMS audit tables based on configurable retention period.
 * Tables pruned: sms_audit, sms_outbound_audit, message_status
 *
 * Retention period configured via settings.sms.audit_retention_days (default: 90)
 */

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/../api/lib/settings.php';

$pdo = \App\Config\DB::pdo();

// Get retention period from settings (default 90 days)
$retentionDays = (int)setting_get('sms.audit_retention_days', 90);
$cut = (new DateTimeImmutable("-{$retentionDays} days", new DateTimeZone('UTC')))->format(DateTime::ATOM);

echo "Starting SMS audit rotation - retaining {$retentionDays} days, cutoff: {$cut}\n";

$tables = [
    'sms_audit' => 'created_at',
    'sms_outbound_audit' => 'created_at',
    'message_status' => 'received_at_utc'
];

$totalDeleted = 0;

foreach ($tables as $table => $dateColumn) {
    try {
        // Count records to be deleted
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$dateColumn} < ?");
        $countStmt->execute([$cut]);
        $countToDelete = $countStmt->fetchColumn();

        if ($countToDelete > 0) {
            // Delete old records
            $deleteStmt = $pdo->prepare("DELETE FROM {$table} WHERE {$dateColumn} < ?");
            $deleteStmt->execute([$cut]);
            $deleted = $deleteStmt->rowCount();

            echo "Table {$table}: deleted {$deleted} records (expected {$countToDelete})\n";
            $totalDeleted += $deleted;
        } else {
            echo "Table {$table}: no records to delete\n";
        }
    } catch (Throwable $e) {
        echo "ERROR pruning table {$table}: " . $e->getMessage() . "\n";
    }
}

echo "SMS audit rotation completed - total records deleted: {$totalDeleted}\n";
