<?php
// config/mail_config.php
//
// COPY file ini menjadi "mail_config.php" (tanpa .example) lalu isi
// kredensial SMTP yang sebenarnya. JANGAN commit mail_config.php yang
// sudah berisi password ke Git — tambahkan ke .gitignore.
//
// ================== CONTOH PAKAI GMAIL ==================
// 1. Aktifkan 2-Step Verification di akun Gmail yang akan dipakai kirim.
// 2. Buat "App Password" di https://myaccount.google.com/apppasswords
//    (pilih app "Mail", device "Other" -> beri nama "ARP Aksara").
// 3. Gmail akan memberi password 16 digit -> itu yang dipakai di
//    MAIL_PASSWORD di bawah (BUKAN password akun Gmail biasa).
// 4. Gmail SMTP membatasi ~500 email/hari untuk akun biasa. Kalau
//    volumenya besar, pertimbangkan pakai domain email kantor sendiri
//    (Zoho Mail, Google Workspace, atau SMTP relay seperti Mailgun/SES).
//
// ================== CONTOH PAKAI DOMAIN SENDIRI (cPanel/Zoho/dst) ==========
// Tanyakan ke admin hosting: host SMTP, port, dan apakah SSL/TLS.
// Biasanya: smtp.namadomain.com, port 465 (SSL) atau 587 (TLS).

return [
    // Aktif/nonaktifkan pengiriman email secara global. Set false untuk
    // mematikan sementara (misal saat development di localhost tanpa internet).
    'ENABLED'      => true,

    'SMTP_HOST'    => 'smtp.gmail.com',
    'SMTP_PORT'    => 587,           // 587 = TLS (STARTTLS), 465 = SSL
    'SMTP_SECURE'  => 'tls',         // 'tls' atau 'ssl'
    'SMTP_AUTH'    => true,

    'SMTP_USERNAME' => 'aksarariksaperdana12@gmail.com', // alamat pengirim
    'SMTP_PASSWORD' => 'jfpr ebrn yfou fkfs',

    'FROM_EMAIL'   => 'aksarariksaperdana12@gmail.com',
    'FROM_NAME'    => 'ARP Digital',

    // Kalau true, error SMTP akan ditulis ke error_log server (disarankan
    // aktif saat awal setup untuk debugging, lalu boleh dimatikan).
    'DEBUG_LOG'    => true,
];
