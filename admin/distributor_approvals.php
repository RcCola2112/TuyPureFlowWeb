<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once '../db.php';
$response = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['distributor_id'])) {
  $distributor_id = intval($_POST['distributor_id']);
  if ($_POST['action'] === 'approve') {
    $stmt = $conn->prepare("UPDATE distributor SET status = 'approved' WHERE distributor_id = ?");
    $success = $stmt->execute([$distributor_id]);
    $response = ['success' => $success, 'action' => 'approve'];
  } elseif ($_POST['action'] === 'reject') {
    $stmt = $conn->prepare("UPDATE distributor SET status = 'rejected' WHERE distributor_id = ?");
    $success = $stmt->execute([$distributor_id]);
    $response = ['success' => $success, 'action' => 'reject'];
  }
  header('Content-Type: application/json');
  echo json_encode($response);
  exit;
}
$stmt = $conn->prepare("SELECT distributor_id, name, phone, email, created_at FROM distributor WHERE status = 'pending'");
$stmt->execute();
$pendingDistributors = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Distributor Approvals</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 font-sans text-gray-700">
  <?php include 'header.php'; ?>
  <div class="flex min-h-screen">
    <?php include 'sidebar.php'; ?>
    <main class="flex-1 p-8 overflow-y-auto" style="margin-left: 16rem; padding-top: 88px;">
      <h1 class="text-2xl font-bold text-gray-800 mb-4">Distributor Approvals</h1>
      <div class="mb-6 flex justify-between items-center">
        <input type="text" class="border rounded px-4 py-2 w-64" placeholder="Search distributors...">
        <button class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Filter</button>
      </div>
      <table class="min-w-full bg-white rounded-lg shadow mb-8">
        <thead>
          <tr class="bg-gray-100 text-gray-700">
            <th class="py-2 px-4 text-left">Name</th>
            <th class="py-2 px-4 text-left">Email</th>
            <th class="py-2 px-4 text-left">Phone</th>
            <!-- Removed Documents column -->
            <th class="py-2 px-4 text-left">Date Submitted</th>
            <th class="py-2 px-4 text-left">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendingDistributors as $d): ?>
          <tr class="border-b">
            <td class="py-2 px-4"><?= htmlspecialchars($d['name']) ?></td>
            <td class="py-2 px-4"><?= htmlspecialchars($d['email']) ?></td>
            <td class="py-2 px-4"><?= htmlspecialchars($d['phone']) ?></td>
            <!-- Removed Documents cell -->
            <td class="py-2 px-4"><?= htmlspecialchars($d['created_at']) ?></td>
            <td class="py-2 px-4 flex gap-2">
              <button class="bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600" onclick="approveDistributor(<?= $d['distributor_id'] ?>, this)">Approve</button>
              <button class="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600" onclick="rejectDistributor(<?= $d['distributor_id'] ?>, this)">Reject</button>
              <button class="bg-blue-500 text-white px-3 py-1 rounded hover:bg-blue-600" onclick="viewDocs(<?= $d['distributor_id'] ?>)">Download Certificates</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <!-- Modal for document review -->
      <div class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50 hidden" id="modal-docs">
        <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-2xl relative">
          <button onclick="closeDocsModal()" class="absolute top-2 right-2 text-gray-500 hover:text-red-600 text-xl">&times;</button>
          <h2 class="text-xl font-bold mb-4">Distributor Documents</h2>
          <div id="docs-content">Loading...</div>
        </div>
      </div>
      <script>
            function approveDistributor(id, btn) {
              if (!confirm('Approve this distributor?')) return;
              fetch('distributor_approvals.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=approve&distributor_id=' + encodeURIComponent(id)
              })
              .then(res => res.json())
              .then(data => {
                if (data.success) {
                  btn.closest('tr').remove();
                } else {
                  alert('Failed to approve distributor.');
                }
              });
            }
            function rejectDistributor(id, btn) {
              if (!confirm('Reject this distributor?')) return;
              fetch('distributor_approvals.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=reject&distributor_id=' + encodeURIComponent(id)
              })
              .then(res => res.json())
              .then(data => {
                if (data.success) {
                  btn.closest('tr').remove();
                } else {
                  alert('Failed to reject distributor.');
                }
              });
            }
      function viewDocs(distributorId) {
        // Show modal
        document.getElementById('modal-docs').classList.remove('hidden');
        // Fetch documents via AJAX
        fetch('get_distributor_docs.php?distributor_id=' + distributorId)
          .then(res => res.text())
          .then(html => {
            document.getElementById('docs-content').innerHTML = html;
          });
      }
      function closeDocsModal() {
        document.getElementById('modal-docs').classList.add('hidden');
        document.getElementById('docs-content').innerHTML = 'Loading...';
      }
      </script>
    </main>
  </div>
</body>
</html>
