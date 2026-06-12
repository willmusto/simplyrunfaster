<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
$pdo = Database::get();
$cols = $pdo->query('DESCRIBE users')->fetchAll(PDO::FETCH_COLUMN);
echo 'users: ' . implode(', ', $cols) . "\n";
$cols2 = $pdo->query('DESCRIBE athletes')->fetchAll(PDO::FETCH_COLUMN);
echo 'athletes: ' . implode(', ', $cols2) . "\n";
$cols3 = $pdo->query('DESCRIBE athlete_profiles')->fetchAll(PDO::FETCH_COLUMN);
echo 'athlete_profiles: ' . implode(', ', $cols3) . "\n";
$liam = $pdo->query("SELECT a.id as athlete_id, u.name FROM athletes a JOIN users u ON u.id = a.user_id WHERE u.name LIKE '%Liam%' LIMIT 3")->fetchAll();
echo 'Liam: '; print_r($liam);
