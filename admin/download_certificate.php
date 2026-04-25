<?php
require_once '../db.php';
if (!isset($_GET['certificate_id']) || !is_numeric($_GET['certificate_id'])) {
  die('Invalid certificate ID.');
}
$id = intval($_GET['certificate_id']);
$stmt = $conn->prepare("SELECT distributor_certificates_images, certificate_type_id FROM distributor_certificates WHERE certificate_id = ? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) die('Certificate not found.');
$typeMap = [1 => 'mayor_permit', 2 => 'sanitary_permit', 3 => 'dti_sec_registration', 4 => 'certificate'];
$filename = ($typeMap[$row['certificate_type_id']] ?? 'certificate') . '_' . $id . '.pdf';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $row['distributor_certificates_images'];
exit;