<?php
// config/koneksi.php

// Database configurations
$host = "localhost";
$username = "root";
$password = "";
$database = "arp_aksara";

try {
    // Create PDO connection
    $conn = new PDO("mysql:host=$host;dbname=$database;charset=utf8", $username, $password);
    // Set the PDO error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set default fetch mode to associative array
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // In production, log error and show a generic message. Here we'll output for debugging
    die("Koneksi database gagal: " . $e->getMessage());
}
?>
