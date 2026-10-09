<?php
// create_test_user.php
// A script to quickly create the sample Technical Department user.
// Run via CLI or browser.

require_once 'workspace/config/database.php';

echo "<h2>Creating Sample Technical Department User</h2>\n";

$name = "Rizki Putra";
$email = "rizki.putra@kawanlamacorp.com";
$role = "Technical Department";
$username = "rizki.putra";
$password = "password123";
$team_name = "General Affairs (GA)";
$pgroups = ['A99', 'R99', 'F99'];

$hash = password_hash($password, PASSWORD_DEFAULT);

// 1. Insert into Workspace DB
try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        echo "<p>User '$username' already exists in Workspace DB.</p>\n";
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (username, name, email, role, password, team_name) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $name, $email, $role, $hash, $team_name]);
        echo "<p>User '$username' successfully created in Workspace DB.</p>\n";
    }
} catch (PDOException $e) {
    echo "<p>Error creating user in Workspace DB: " . $e->getMessage() . "</p>\n";
}

// 2. Insert mapping for PGroups
try {
    $stmt = $pdo->prepare("INSERT IGNORE INTO technical_team_pgroups (team_name, pgroup) VALUES (?, ?)");
    foreach ($pgroups as $pg) {
        $stmt->execute([$team_name, $pg]);
    }
    echo "<p>Successfully mapped PGroups (" . implode(", ", $pgroups) . ") to '$team_name'.</p>\n";
} catch (PDOException $e) {
    echo "<p>Error mapping PGroups: " . $e->getMessage() . "</p>\n";
}

// 3. Insert into VSS DB
try {
    $vss_pdo = get_vss_pdo();

    $vss_check = $vss_pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
    $vss_check->execute([$email, $username]);
    if ($vss_check->fetch()) {
        echo "<p>User with email '$email' or username '$username' already exists in VSS DB.</p>\n";
    } else {
        $vss_insert = $vss_pdo->prepare("INSERT INTO users (full_name, email, username, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 1)");
        // Fallback role for Technical Department is 'requestor'
        $vss_insert->execute([$name, $email, $username, $hash, 'requestor']);
        echo "<p>User '$email' successfully created in VSS DB.</p>\n";
    }
} catch (PDOException $e) {
    echo "<p>Error creating user in VSS DB: " . $e->getMessage() . "</p>\n";
}

echo "<hr><p>Done.</p>\n";
