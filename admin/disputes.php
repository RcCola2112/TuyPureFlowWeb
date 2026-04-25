<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once '../db.php';
$stmt = $conn->prepare("SELECT id, user_id, message, date, resolved, reply FROM feedback");
$stmt->execute();
$disputes = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Disputes & Escalations</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 font-sans text-gray-700">
  <?php include 'header.php'; ?>
  <div class="flex min-h-screen">
    <?php include 'sidebar.php'; ?>
    <main class="flex-1 p-8 overflow-y-auto" style="margin-left: 16rem; padding-top: 88px;">
      <h1 class="text-2xl font-bold text-gray-800 mb-4">Disputes & Escalations</h1>
      <table class="min-w-full bg-white rounded-lg shadow mb-8">
        <thead>
          <tr class="bg-gray-100 text-gray-700">
            <th class="py-2 px-4 text-left">Case ID</th>
            <th class="py-2 px-4 text-left">Involved Users</th>
            <th class="py-2 px-4 text-left">Reason</th>
            <th class="py-2 px-4 text-left">Severity</th>
            <th class="py-2 px-4 text-left">Status</th>
            <th class="py-2 px-4 text-left">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($disputes as $d): ?>
          <tr class="border-b">
            <td class="py-2 px-4"><?= htmlspecialchars($d['id']) ?></td>
            <td class="py-2 px-4"><?= htmlspecialchars($d['user_id']) ?></td>
            <td class="py-2 px-4"><?= htmlspecialchars($d['message']) ?></td>
            <td class="py-2 px-4"><?= htmlspecialchars($d['severity'] ?? '-') ?></td>
            <td class="py-2 px-4"><?= htmlspecialchars($d['status'] ?? '-') ?></td>
            <td class="py-2 px-4 flex gap-2">
              <button class="bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600">Resolve</button>
              <button class="bg-yellow-500 text-white px-3 py-1 rounded hover:bg-yellow-600">Escalate</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- No hardcoded detail view. Add dynamic detail view here if needed. -->
    </main>
  </div>
</body>
</html>
