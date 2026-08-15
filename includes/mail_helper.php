<?php
// includes/mail_helper.php
require_once __DIR__ . '/../vendor_phpmailer/Exception.php';
require_once __DIR__ . '/../vendor_phpmailer/PHPMailer.php';
require_once __DIR__ . '/../vendor_phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Kirim satu email.
 *
 * @param string|array $to       Satu alamat email, atau array alamat email.
 * @param string       $subject
 * @param string       $htmlBody HTML body email.
 * @param string|null  $altBody  Versi plain-text (opsional, auto-generate kalau kosong).
 * @return bool true kalau berhasil dikirim (atau semua tujuan gagal -> false)
 */
function kirimEmail($to, string $subject, string $htmlBody, ?string $altBody = null): bool
{
    static $config = null;
    if ($config === null) {
        $configFile = __DIR__ . '/../config/mail_config.php';
        if (!file_exists($configFile)) {
            error_log('mail_config.php belum ada. Copy dari mail_config.example.php lalu isi kredensial SMTP.');
            $config = false;
        } else {
            $config = require $configFile;
        }
    }

    if ($config === false || empty($config['ENABLED'])) {
        return false;
    }

    $penerima = is_array($to) ? array_filter($to) : array_filter([$to]);
    if (empty($penerima)) {
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $config['SMTP_HOST'];
        $mail->SMTPAuth   = $config['SMTP_AUTH'] ?? true;
        $mail->Username   = $config['SMTP_USERNAME'];
        $mail->Password   = $config['SMTP_PASSWORD'];
        $mail->SMTPSecure = $config['SMTP_SECURE'] ?? PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $config['SMTP_PORT'] ?? 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($config['FROM_EMAIL'], $config['FROM_NAME'] ?? 'Sistem');

        foreach ($penerima as $email) {
            $mail->addAddress($email);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?? strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        if (!empty($config['DEBUG_LOG'])) {
            error_log('Gagal kirim email: ' . $mail->ErrorInfo);
        }
        return false;
    }
}

/**
 * Ambil email semua user dengan role tertentu, mis. semua 'direksi'.
 *
 * @return string[] daftar email (sudah difilter yang kosong)
 */
function getEmailByRole(PDO $conn, string $role): array
{
    $stmt = $conn->prepare("SELECT email FROM Users WHERE role = :role AND email IS NOT NULL AND email <> ''");
    $stmt->execute(['role' => $role]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Ambil email satu user berdasarkan id.
 */
function getEmailByUserId(PDO $conn, int $userId): ?string
{
    $stmt = $conn->prepare("SELECT email FROM Users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $email = $stmt->fetchColumn();
    return $email ?: null;
}

/**
 * Template HTML sederhana yang dipakai semua email notifikasi supaya
 * tampilannya konsisten. $rows adalah array asosiatif label => value
 * yang akan ditampilkan sebagai tabel ringkas di dalam email.
 */
function templateEmailNotifikasi(string $judul, string $pesanPembuka, array $rows = [], ?string $linkTombol = null, string $labelTombol = 'Buka Sistem'): string
{
    $baseUrl = $GLOBALS['base_url'] ?? '';
    $rowsHtml = '';
    foreach ($rows as $label => $value) {
        $rowsHtml .= '<tr>'
            . '<td style="padding:6px 12px;color:#6b7280;font-size:13px;white-space:nowrap;">' . htmlspecialchars((string) $label) . '</td>'
            . '<td style="padding:6px 12px;color:#111827;font-size:13px;">' . htmlspecialchars((string) $value) . '</td>'
            . '</tr>';
    }

    $tombol = '';
    if ($linkTombol) {
        $tombol = '<div style="margin-top:20px;">'
            . '<a href="' . htmlspecialchars($linkTombol) . '" style="background:#2563eb;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:6px;font-size:14px;display:inline-block;">'
            . htmlspecialchars($labelTombol) . '</a></div>';
    }

    return '
    <div style="font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:24px;">
      <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;">
        <div style="background:#111827;color:#ffffff;padding:16px 24px;font-size:16px;font-weight:bold;">
          ARP Digital
        </div>
        <div style="padding:24px;">
          <h2 style="margin:0 0 12px 0;font-size:18px;color:#111827;">' . htmlspecialchars($judul) . '</h2>
          <p style="margin:0 0 16px 0;font-size:14px;color:#374151;line-height:1.5;">' . nl2br(htmlspecialchars($pesanPembuka)) . '</p>
          ' . ($rowsHtml ? '<table style="width:100%;border-collapse:collapse;background:#f9fafb;border-radius:6px;">' . $rowsHtml . '</table>' : '') . '
          ' . $tombol . '
        </div>
      </div>
    </div>';
}
