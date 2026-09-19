<?php
// config/drive_config.example.php
//
// CONTOH format konfigurasi Google Drive upload (via Apps Script).
// File ini AMAN di-commit ke Git karena tidak berisi kredensial asli.
//
// Cara pakai:
// 1. Copy file ini menjadi config/drive_config.php
//    (di server: cp config/drive_config.example.php config/drive_config.php)
// 2. Isi 'webapp_url' dan 'secret_token' dengan nilai asli dari
//    Google Apps Script project Anda (lihat README.md bagian
//    "Migrasi Google Drive Upload" untuk langkah lengkapnya).
// 3. config/drive_config.php TIDAK boleh di-commit ke Git — sudah
//    didaftarkan di .gitignore.

return [
    'webapp_url'   => 'https://script.google.com/macros/s/AKfycbxAmu36V7Wo-HMuzOWwzX4lLnWso8qdqwB-rDgc3PcWRXDxB1k_5xRB3VPKaAcbNZjg/exec',
    'secret_token' => 'Xk9mP2vQ8nR5wL1tY7bH4jC6sF3gA0dE',
];