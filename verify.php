<?php
// verify.php
require 'config.php';

if (isset($_GET['email']) && isset($_GET['token'])) {
    $email = mysqli_real_escape_string($conn, $_GET['email']);
    $token = mysqli_real_escape_string($conn, $_GET['token']);

    // Check if a user matches the email and token
    $stmt = $conn->prepare("SELECT id, is_active FROM users WHERE email = ? AND verification_token = ? LIMIT 1");
    $stmt->bind_param("ss", $email, $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        if ($user['is_active'] == 0) {
            // Activate the account
            $update_stmt = $conn->prepare("UPDATE users SET is_active = 1, verification_token = NULL WHERE id = ?");
            $update_stmt->bind_param("i", $user['id']);
            
            if ($update_stmt->execute()) {
                header("Location: login.php?msg=" . urlencode("Email verified successfully! You can now log in."));
            } else {
                header("Location: login.php?error=" . urlencode("Database error during activation."));
            }
        } else {
            header("Location: login.php?msg=" . urlencode("This account is already verified. Please log in."));
        }
    } else {
        header("Location: login.php?error=" . urlencode("Invalid or expired verification link."));
    }
} else {
    header("Location: login.php?error=" . urlencode("Missing verification parameters."));
}
exit();
?>