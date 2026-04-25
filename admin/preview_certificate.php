<?php
require_once '../db.php';
if (!isset($_GET['certificate_id']) || !is_numeric($_GET['certificate_id'])) {
  http_response_code(400);
  exit;
}
$id = intval($_GET['certificate_id']);
$stmt = $conn->prepare("SELECT distributor_certificates_images, certificate_type_id FROM distributor_certificates WHERE certificate_id = ? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) exit;

$download = isset($_GET['download']) && $_GET['download'] == '1';
$img = $row['distributor_certificates_images'];
$type = $row['certificate_type_id'];
// Try to detect mime type (assume image/png, image/jpeg, or application/pdf)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->buffer($img);
if (strpos($mime, 'pdf') !== false) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="document.pdf"');
} elseif (strpos($mime, 'jpeg') !== false) {
    header('Content-Type: image/jpeg');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="document.jpg"');
} elseif (strpos($mime, 'png') !== false) {
    header('Content-Type: image/png');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="document.png"');
} elseif (strpos($mime, 'webp') !== false) {
    header('Content-Type: image/webp');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="document.webp"');
} else {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="document.bin"');
}
header('Content-Length: ' . strlen($img));
echo $img;
exit;