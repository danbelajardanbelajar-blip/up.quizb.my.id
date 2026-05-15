<?php
// ============================================
// includes/mailer.php — PHPMailer Helper
// PHPMailer ada di: public_html/vendor/phpmailer/
// ============================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

$projectRoot = dirname(__DIR__, 2);
$composerAutoload = $projectRoot . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} else {
    $phpmailerCandidates = [
        $projectRoot . '/vendor/phpmailer/src/',
        $projectRoot . '/vendor/phpmailer/phpmailer/src/',
        dirname($projectRoot) . '/vendor/phpmailer/src/',
        dirname($projectRoot) . '/vendor/phpmailer/phpmailer/src/',
    ];
    $phpmailerBase = null;
    foreach ($phpmailerCandidates as $candidate) {
        if (is_dir($candidate)) {
            $phpmailerBase = rtrim($candidate, '/') . '/';
            break;
        }
    }
    if (!$phpmailerBase) {
        throw new RuntimeException('PHPMailer library tidak ditemukan. Diperiksa pada: ' . implode(', ', $phpmailerCandidates));
    }
    require_once $phpmailerBase . 'Exception.php';
    require_once $phpmailerBase . 'PHPMailer.php';
    require_once $phpmailerBase . 'SMTP.php';
}

/**
 * Kirim email konfirmasi pendaftaran.
 *
 * @param  string $toEmail   Email tujuan
 * @param  string $toName    Nama penerima
 * @param  string $token     Token verifikasi (64 karakter hex)
 * @return bool              true jika berhasil
 * @throws MailException     jika gagal
 */
function sendVerificationEmail(string $toEmail, string $toName, string $token): bool
{
    $verifyUrl = rtrim(APP_URL, '/') . '/verify-email.php?token=' . urlencode($token);

    $mail = new PHPMailer(true);

    // SMTP Configuration
    $mail->isSMTP();
    $mail->Host       = 'mail.quizb.my.id';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'admin@quizb.my.id';
    $mail->Password   = '26hWFxVKza3r@gJ';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;   // SSL port 465
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 15;

    // Pengirim & Penerima
    $mail->setFrom('admin@quizb.my.id', APP_NAME);
    $mail->addAddress($toEmail, $toName);
    $mail->addReplyTo('admin@quizb.my.id', APP_NAME);

    // Konten Email
    $mail->isHTML(true);
    $mail->Subject = '✅ Konfirmasi Email Kamu — ' . APP_NAME;
    $mail->Body    = buildVerificationEmailHtml($toName, $verifyUrl);
    $mail->AltBody = buildVerificationEmailText($toName, $verifyUrl);

    return $mail->send();
}

/**
 * Template HTML email konfirmasi
 */
function buildVerificationEmailHtml(string $name, string $verifyUrl): string
{
    $appName = htmlspecialchars(APP_NAME);
    $safeName = htmlspecialchars($name);
    $safeUrl  = htmlspecialchars($verifyUrl);

    return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Konfirmasi Email — {$appName}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:40px 20px;">
    <tr>
      <td align="center">
        <table width="100%" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

          <!-- Header -->
          <tr>
            <td style="background:linear-gradient(135deg,#6366f1,#4f46e5);padding:36px 40px;text-align:center;">
              <div style="width:56px;height:56px;background:rgba(255,255,255,0.2);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                <span style="font-size:28px;font-weight:900;color:#fff;">Q</span>
              </div>
              <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">{$appName}</h1>
              <p style="margin:6px 0 0;color:rgba(255,255,255,0.8);font-size:13px;">Konfirmasi Alamat Email</p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:40px 40px 32px;">
              <h2 style="margin:0 0 12px;color:#111827;font-size:20px;font-weight:700;">Halo, {$safeName}! 👋</h2>
              <p style="margin:0 0 20px;color:#374151;font-size:15px;line-height:1.6;">
                Terima kasih sudah mendaftar di <strong>{$appName}</strong>. Satu langkah lagi —
                klik tombol di bawah untuk mengaktifkan akunmu.
              </p>

              <!-- CTA Button -->
              <table width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0;">
                <tr>
                  <td align="center">
                    <a href="{$safeUrl}"
                       style="display:inline-block;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:14px 36px;border-radius:10px;letter-spacing:0.3px;">
                      ✅ Konfirmasi Email Sekarang
                    </a>
                  </td>
                </tr>
              </table>

              <p style="margin:0 0 8px;color:#6b7280;font-size:13px;">
                Tombol tidak berfungsi? Salin link ini ke browser:
              </p>
              <p style="margin:0 0 24px;word-break:break-all;">
                <a href="{$safeUrl}" style="color:#6366f1;font-size:12px;">{$safeUrl}</a>
              </p>

              <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">

              <p style="margin:0;color:#9ca3af;font-size:12px;line-height:1.6;">
                Link konfirmasi ini berlaku selama <strong>24 jam</strong>.
                Jika kamu tidak mendaftar di {$appName}, abaikan email ini.
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#f9fafb;padding:20px 40px;text-align:center;border-top:1px solid #e5e7eb;">
              <p style="margin:0;color:#9ca3af;font-size:12px;">
                © 2025 {$appName} · <a href="https://quizb.my.id" style="color:#6366f1;text-decoration:none;">quizb.my.id</a>
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

/**
 * Fallback plain-text email
 */
function buildVerificationEmailText(string $name, string $verifyUrl): string
{
    $appName = APP_NAME;
    return <<<TEXT
Halo, {$name}!

Terima kasih sudah mendaftar di {$appName}.
Klik link berikut untuk mengaktifkan akun kamu:

{$verifyUrl}

Link ini berlaku selama 24 jam.
Jika kamu tidak mendaftar di {$appName}, abaikan email ini.

— Tim {$appName}
TEXT;
}
