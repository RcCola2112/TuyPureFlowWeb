<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once '../db.php';
$stmt = $conn->prepare("SELECT cf.feedback_id, s.distributor_id, s.name AS shop_name, cf.rating, cf.comments FROM consumer_feedback cf LEFT JOIN shop s ON cf.shop_id = s.shop_id WHERE s.distributor_id IS NOT NULL ORDER BY cf.feedback_id DESC LIMIT 200");
$stmt->execute();
$distributors = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("SELECT cf.feedback_id, cf.consumer_id, cf.rating, cf.comments FROM consumer_feedback cf ORDER BY cf.feedback_id DESC LIMIT 200");
$stmt->execute();
$consumers = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>User Violations</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 font-sans text-gray-700">
  <?php include 'header.php'; ?>
  <div class="flex min-h-screen">
    <?php include 'sidebar.php'; ?>
    <main class="flex-1 p-8 overflow-y-auto" style="margin-left: 16rem; padding-top: 88px;">
      <h1 class="text-2xl font-bold text-gray-800 mb-4">User Violations</h1>
      <div class="mb-6">
        <button class="px-4 py-2 rounded-t bg-blue-600 text-white">Distributors</button>
        <button class="px-4 py-2 rounded-t bg-gray-200 text-gray-700">Consumers</button>
      </div>
      <div class="mb-8">
        <table class="min-w-full bg-white rounded-lg shadow mb-4">
          <thead>
            <tr class="bg-gray-100 text-gray-700">
              <th class="py-2 px-4 text-left">Name</th>
              <th class="py-2 px-4 text-left">Violation Type</th>
              <th class="py-2 px-4 text-left">Report Count</th>
              <th class="py-2 px-4 text-left">Status</th>
              <th class="py-2 px-4 text-left">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($distributors as $u): ?>
              <tr class="border-b">
                <td class="py-2 px-4"><?= htmlspecialchars($u['shop_name'] ?? 'Shop') ?></td>
                <td class="py-2 px-4">Feedback</td>
                <td class="py-2 px-4"><?= htmlspecialchars($u['rating']) ?></td>
                <td class="py-2 px-4"><?= htmlspecialchars(substr($u['comments'] ?? '-',0,60)) ?></td>
                <td class="py-2 px-4 flex gap-2">
                  <button class="bg-green-500 text-white px-2 py-1 rounded text-xs">Clear</button>
                  <button class="bg-yellow-500 text-white px-2 py-1 rounded text-xs">Suspend</button>
                  <button class="bg-red-500 text-white px-2 py-1 rounded text-xs">Ban</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <!-- History/log with fake data -->
        <!-- History / Log of Actions removed: No fake data. Add dynamic log here if needed. -->
      </div>
    </main>
  </div>
</body>
</html>
