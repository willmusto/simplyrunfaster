<?php
/**
 * One-time admin account seed.
 * Run from CLI: php /home/private/app/scripts/seed_admin.php
 * Delete or chmod 000 this file after use.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$email = 'will@mus.to';
$name  = 'Will Musto';
$role  = 'admin';

$db   = Database::get();
$stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([strtolower($email)]);
if ($stmt->fetch()) {
    echo "Account already exists for {$email} — nothing created.\n";
    exit(1);
}

$password = bin2hex(random_bytes(16));
$hash     = password_hash($password, PASSWORD_DEFAULT);

$stmt = $db->prepare(
    'INSERT INTO users (email, password_hash, role, name, signup_source, theme_preference)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$stmt->execute([strtolower($email), $hash, $role, $name, 'organic', 'system']);
$userId = (int) $db->lastInsertId();

echo "Admin account created.\n";
echo "  ID:       {$userId}\n";
echo "  Email:    {$email}\n";
echo "  Name:     {$name}\n";
echo "  Role:     {$role}\n";
echo "  Password: {$password}\n";
echo "\nStore this password now — it cannot be recovered. Delete or chmod 000 this script after use.\n";
