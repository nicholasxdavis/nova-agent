<?php
// File: user.php
// Path: /api/auth/user.php

session_start();

if (isset($_SESSION['user_id'])) {
    echo json_encode([
        'isLoggedIn' => true,
        'user' => [
            'id' => $_SESSION['user_id'],
            'full_name' => $_SESSION['user_full_name'],
            'email' => $_SESSION['user_email']
        ]
    ]);
} else {
    echo json_encode(['isLoggedIn' => false]);
}

