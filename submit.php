<?php
/**
 * Academic Hub — Assignment Submission Backend
 * ─────────────────────────────────────────────
 * Handles: file validation → Google Drive upload → email notifications
 *
 * SETUP: Edit the CONFIG section below, then upload to your server.
 */

// ══════════════════════════════════════════
//  CONFIG  ← Edit these values
// ══════════════════════════════════════════

define('LECTURER_EMAIL',    'your@email.com');          // Where YOU receive notifications
define('SITE_NAME',         'Academic Hub');
define('SITE_URL',          'https://yourdomain.com');  // Your website URL (no trailing slash)

// Google Drive folder ID — copy from the Drive URL after /folders/
// e.g. https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOpQrStUvWxYz
// → paste the part after /folders/
define('GOOGLE_DRIVE_FOLDER_ID', 'YOUR_FOLDER_ID_HERE');

// Path to your Google service account JSON key file.
// Upload this file OUTSIDE your public_html for security.
// e.g. /home/yourusername/google-credentials.json
define('GOOGLE_CREDENTIALS_PATH', '/home/yourusername/google-credentials.json');

// Max file size in bytes (10 MB)
define('MAX_FILE_SIZE', 10 * 1024 * 1024);

// ══════════════════════════════════════════
//  END CONFIG
// ══════════════════════════════════════════

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

// ── 1. Validate inputs ────────────────────

$required = ['student_name', 'student_id', 'student_email', 'module', 'assignment_title'];
foreach ($required as $field) {
    if (empty(trim($_POST[$field] ?? ''))) {
        jsonResponse(false, "The field '$field' is required.");
    }
}

$name     = sanitize($_POST['student_name']);
$sid      = sanitize($_POST['student_id']);
$email    = filter_var(trim($_POST['student_email']), FILTER_VALIDATE_EMAIL);
$module   = sanitize($_POST['module']);
$title    = sanitize($_POST['assignment_title']);
$comments = sanitize($_POST['comments'] ?? '');

if (!$email) {
    jsonResponse(false, 'Please enter a valid email address.');
}

// ── 2. Validate uploaded file ─────────────

if (!isset($_FILES['assignment_file']) || $_FILES['assignment_file']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server misconfiguration: missing temp folder.',
        UPLOAD_ERR_CANT_WRITE => 'Server error: cannot write file.',
    ];
    $errCode = $_FILES['assignment_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    jsonResponse(false, $uploadErrors[$errCode] ?? 'File upload failed.');
}

$file      = $_FILES['assignment_file'];
$tmpPath   = $file['tmp_name'];
$origName  = basename($file['name']);
$fileSize  = $file['size'];
$mimeType  = mime_content_type($tmpPath);

// Allowed types
$allowedMimes = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
if (!in_array($mimeType, $allowedMimes, true)) {
    jsonResponse(false, 'Only PDF and Word (.doc/.docx) files are allowed.');
}

if ($fileSize > MAX_FILE_SIZE) {
    jsonResponse(false, 'File exceeds the 10 MB size limit.');
}

// Build a clean filename: StudentID_Title_Timestamp.ext
$ext          = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$safeTitle    = preg_replace('/[^a-zA-Z0-9_-]/', '_', substr($title, 0, 40));
$timestamp    = date('Ymd_His');
$uploadName   = "{$sid}_{$safeTitle}_{$timestamp}.{$ext}";

// ── 3. Upload to Google Drive ─────────────

require_once __DIR__ . '/vendor/autoload.php';

try {
    $driveFileId = uploadToGoogleDrive($tmpPath, $uploadName, $mimeType);
} catch (Exception $e) {
    // Log the error server-side and fail gracefully
    error_log('[AcademicHub] Google Drive upload failed: ' . $e->getMessage());
    jsonResponse(false, 'Could not save your file. Please try again or contact your lecturer.');
}

// ── 4. Send emails ────────────────────────

$submittedAt = date('l, d F Y \a\t H:i T');

// Email to lecturer
$lecturerSubject = "[New Submission] {$name} — {$module}";
$lecturerBody    = emailTemplate(
    "New Assignment Received",
    "A student has submitted an assignment.",
    [
        'Student Name'  => $name,
        'Student ID'    => $sid,
        'Email'         => $email,
        'Module'        => $module,
        'Title'         => $title,
        'Submitted At'  => $submittedAt,
        'Comments'      => $comments ?: '—',
        'Drive File'    => '<a href="https://drive.google.com/file/d/' . $driveFileId . '/view">View on Google Drive</a>',
    ]
);
sendEmail(LECTURER_EMAIL, $lecturerSubject, $lecturerBody, SITE_NAME);

// Confirmation email to student
$studentSubject = "Assignment Received — {$title}";
$studentBody    = emailTemplate(
    "Submission Confirmed ✓",
    "We have received your assignment. Keep this email for your records.",
    [
        'Name'         => $name,
        'Student ID'   => $sid,
        'Module'       => $module,
        'Title'        => $title,
        'Submitted At' => $submittedAt,
    ],
    "Your submission has been securely saved. Your lecturer will review it and post your grade on the portal."
);
sendEmail($email, $studentSubject, $studentBody, SITE_NAME . ' — No Reply');

// ── 5. Done ───────────────────────────────

jsonResponse(true, "Your assignment has been submitted successfully. A confirmation has been sent to {$email}.");


// ══════════════════════════════════════════
//  HELPER FUNCTIONS
// ══════════════════════════════════════════

/**
 * Upload a file to Google Drive using a Service Account.
 * Returns the Drive file ID.
 */
function uploadToGoogleDrive(string $tmpPath, string $fileName, string $mimeType): string
{
    $client = new Google\Client();
    $client->setAuthConfig(GOOGLE_CREDENTIALS_PATH);
    $client->addScope(Google\Service\Drive::DRIVE_FILE);

    $service = new Google\Service\Drive($client);

    $fileMetadata = new Google\Service\Drive\DriveFile([
        'name'    => $fileName,
        'parents' => [GOOGLE_DRIVE_FOLDER_ID],
    ]);

    $content  = file_get_contents($tmpPath);
    $uploaded = $service->files->create($fileMetadata, [
        'data'       => $content,
        'mimeType'   => $mimeType,
        'uploadType' => 'multipart',
        'fields'     => 'id',
    ]);

    return $uploaded->id;
}

/**
 * Send an HTML email.
 */
function sendEmail(string $to, string $subject, string $htmlBody, string $fromName): void
{
    $fromEmail = 'no-reply@' . parse_url(SITE_URL, PHP_URL_HOST);
    $headers   = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);
    mail($to, $subject, $htmlBody, $headers);
}

/**
 * Build a simple HTML email template.
 */
function emailTemplate(string $heading, string $intro, array $rows, string $footer = ''): string
{
    $rowsHtml = '';
    foreach ($rows as $label => $value) {
        $rowsHtml .= "<tr>
            <td style='padding:8px 12px;background:#f9f9f9;font-weight:600;font-size:13px;width:160px;border-bottom:1px solid #eee;'>{$label}</td>
            <td style='padding:8px 12px;font-size:13px;border-bottom:1px solid #eee;'>{$value}</td>
        </tr>";
    }
    $footerHtml = $footer ? "<p style='margin:16px 0 0;font-size:13px;color:#666;'>{$footer}</p>" : '';

    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Segoe UI,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;border:1px solid #e0e0e0;">
        <tr><td style="background:#2c3e50;padding:20px 28px;">
          <h1 style="margin:0;color:#fff;font-size:20px;">Academic Hub</h1>
        </td></tr>
        <tr><td style="padding:24px 28px;">
          <h2 style="margin:0 0 8px;font-size:18px;color:#2c3e50;">{$heading}</h2>
          <p style="margin:0 0 20px;font-size:14px;color:#555;">{$intro}</p>
          <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eee;border-radius:6px;overflow:hidden;">{$rowsHtml}</table>
          {$footerHtml}
        </td></tr>
        <tr><td style="background:#f9f9f9;padding:14px 28px;text-align:center;font-size:12px;color:#aaa;border-top:1px solid #eee;">
          &copy; Academic Hub &mdash; This is an automated message, please do not reply.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
}

/**
 * Sanitize a plain text field.
 */
function sanitize(string $value): string
{
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

/**
 * Output JSON and exit.
 */
function jsonResponse(bool $success, string $message): never
{
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}
