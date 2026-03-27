<?php
// config.php


// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'payment');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset("utf8mb4");

// Include functions file
require_once 'functions.php';

// Time zone
date_default_timezone_set('Asia/Manila'); // Change to your timezone

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>