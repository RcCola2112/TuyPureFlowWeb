<?php
require_once '../db.php';
if (!isset($_GET['distributor_id']) || !is_numeric($_GET['distributor_id'])) {
  echo '<div class="text-red-600">Invalid distributor ID.</div>';
  exit;
}
$id = intval($_GET['distributor_id']);
$stmt = $conn->prepare("SELECT dc.certificate_id, ct.certificate_name, dc.uploaded_at, dc.status, dc.remarks FROM distributor_certificates dc LEFT JOIN certificate_type ct ON dc.certificate_type_id = ct.certificate_type_id WHERE dc.distributor_id = ? ORDER BY dc.uploaded_at DESC");
$stmt->execute([$id]);
$certs = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$certs) {
  echo '<div class="text-gray-600">No documents uploaded.</div>';
  exit;
}
echo '<table class="w-full text-sm border rounded mb-2"><thead><tr><th class="px-3 py-2 text-left">Type</th><th class="px-3 py-2 text-left">File</th><th class="px-3 py-2 text-left">Uploaded</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Remarks</th></tr></thead><tbody>';
foreach ($certs as $cert) {
  $downloadUrl = 'preview_certificate.php?certificate_id=' . $cert['certificate_id'] . '&download=1';
  $type = htmlspecialchars($cert['certificate_name'] ?? 'Unknown');
  $status = htmlspecialchars($cert['status'] ?? 'Pending');
  $remarks = htmlspecialchars($cert['remarks'] ?? '');
  echo '<tr class="border-b">';
  echo '<td class="px-3 py-2">' . $type . '</td>';
  echo '<td class="px-3 py-2"><a href="' . $downloadUrl . '" target="_blank" class="text-blue-600 underline">Download</a></td>';
  echo '<td class="px-3 py-2">' . htmlspecialchars($cert['uploaded_at']) . '</td>';
  echo '<td class="px-3 py-2">' . $status . '</td>';
  echo '<td class="px-3 py-2">' . $remarks . '</td>';
  echo '</tr>';
}
echo '</tbody></table>';
?>