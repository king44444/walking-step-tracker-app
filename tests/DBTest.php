<?php

use PHPUnit\Framework\TestCase;
use App\Config\DB;

class DBTest extends TestCase
{
    public function testPdoReturnsValidConnection()
    {
        // Test that DB::pdo() returns a valid PDO instance
        $pdo = DB::pdo();
        $this->assertInstanceOf(PDO::class, $pdo);

        // Test that we can execute a simple query
        $result = $pdo->query('SELECT 1 as test')->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(['test' => '1'], $result);

        // Test that foreign keys are enabled
        $fkResult = $pdo->query('PRAGMA foreign_keys')->fetchColumn();
        $this->assertEquals('1', $fkResult);

        // Test that WAL mode is enabled
        $walResult = $pdo->query('PRAGMA journal_mode')->fetchColumn();
        $this->assertEquals('wal', strtolower($walResult));
    }

    public function testPdoReturnsSameInstance()
    {
        // Test that multiple calls return the same instance (memoization)
        $pdo1 = DB::pdo();
        $pdo2 = DB::pdo();
        $this->assertSame($pdo1, $pdo2);
    }
}
