<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
$pdo = Database::get();
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "Tables:\n";
foreach ($tables as $t) echo "  $t\n";

// Find Liam
$stmt = $pdo->query("SELECT id, first_name, last_name FROM users WHERE first_name LIKE '%Liam%' LIMIT 5");
echo "\nUsers named Liam:\n";
foreach ($stmt->fetchAll() as $r) {
    echo "  user_id={$r['id']} {$r['first_name']} {$r['last_name']}\n";
}

// Also try old schema
try {
    $stmt2 = $pdo->query("SELECT id FROM athletes LIMIT 1");
    echo "athletes table exists\n";
} catch (Exception $e) {
    echo "No athletes table\n";
}
