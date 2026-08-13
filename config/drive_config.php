<?php
// config/drive_config.php
//
// KREDENSIAL RAHASIA — jangan commit file ini ke Git.
// File ini sudah didaftarkan di .gitignore.
//
// Cara isi:
// 1. 'webapp_url'   -> URL Web App hasil Deploy dari Google Apps Script,
//                      formatnya: https://script.google.com/macros/s/xxxxxxxxxxxx/exec
// 2. 'secret_token' -> HARUS SAMA PERSIS dengan nilai SECRET_TOKEN di Code.gs.
//                      Buat token acak sendiri (32+ karakter), jangan pakai
//                      contoh token dari tutorial/chat manapun.

return [
    'webapp_url'   => 'https://script.google.com/macros/s/AKfycbxAmu36V7Wo-HMuzOWwzX4lLnWso8qdqwB-rDgc3PcWRXDxB1k_5xRB3VPKaAcbNZjg/exec',
    'secret_token' => 'Xk9mP2vQ8nR5wL1tY7bH4jC6sayaF3gA0dE',
];