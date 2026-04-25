<?php
// download_certificate.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include '../db.php';

$certificate_id = isset($_GET['certificate_id']) ? intval($_GET['certificate_id']) : 0;
if (!$certificate_id) {
    http_response_code(400);
    echo 'Invalid certificate ID.';
    exit;
}

$stmt = $conn->prepare("SELECT distributor_certificates_images FROM distributor_certificates WHERE certificate_id = ? LIMIT 1");
$stmt->execute([$certificate_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['distributor_certificates_images'])) {
    http_response_code(404);
    echo 'Certificate not found.';
    exit;
}

$blob = $row['distributor_certificates_images'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->buffer($blob);
if (!$mime || $mime === 'application/octet-stream') {
    // Fallback: try to detect by magic bytes
    if (substr($blob, 0, 4) === "%PDF") {
        $mime = 'application/pdf';
    } else {
        $mime = 'image/png'; // Default to PNG for images
    }
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="certificate_' . $certificate_id . '"');
echo $blob;
exit;
