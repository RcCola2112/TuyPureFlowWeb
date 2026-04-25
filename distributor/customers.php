<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['distributor_id'])) {
    echo '<script>window.location.replace("../index.html");</script>';
    exit;
}
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 1 Jan 2000 00:00:00 GMT");
include '../db.php';
$currentPage = 'customers';

// Get distributor info from session or database
$distributor_id = $_SESSION['distributor_id'] ?? 1;
$stmt = $conn->prepare("SELECT * FROM distributor WHERE distributor_id = ?");
$stmt->execute([$distributor_id]);
$distributor = $stmt->fetch();

$username = $distributor['name'] ?? '';
$stmtShop = $conn->prepare("SELECT name FROM shop WHERE distributor_id = ? LIMIT 1");
$stmtShop->execute([$distributor_id]);
$shopname = $stmtShop->fetchColumn() ?: '';
$profilePic = isset($distributor['profile_pic']) && $distributor['profile_pic'] ? $distributor['profile_pic'] : "images/profile.jpg";

// Fetch customers who ordered from this distributor's shop(s)
$stmt = $conn->prepare(
  "SELECT c.consumer_id, c.full_name, c.contact_number, c.email,
    COUNT(o.order_id) AS total_orders,
    MAX(o.order_date) AS last_order,
    SUM(o.total_amount) AS total_revenue,
    SUM(CASE WHEN o.status = 'Pending' THEN 1 ELSE 0 END) AS pending_orders,
    SUM(CASE WHEN o.status = 'Completed' THEN 1 ELSE 0 END) AS completed_orders
   FROM consumer c
   JOIN orders o ON c.consumer_id = o.consumer_id
   WHERE o.shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?)
   GROUP BY c.consumer_id
   ORDER BY last_order DESC"
);
$stmt->execute([$distributor_id]);
$customers = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Customers | Tuy PureFlow Distributor</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .gradient-text {
      background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    .fade-in {
      animation: fadeIn 0.5s ease-out;
    }
    .table-row {
      transition: all 0.2s ease;
    }
    .table-row:hover {
      background: linear-gradient(90deg, #fef2f2 0%, #ffffff 100%);
      transform: scale(1.01);
    }
  </style>
</head>
<body class="flex bg-gray-100 min-h-screen">

  <!-- Sidebar -->
  <?php include 'sidebar.php'; ?>
  <!-- Main Content -->
  <div class="ml-64 flex flex-col flex-1">
    <!-- Header -->
    <?php include 'header.php'; ?>
    <!-- Page Content -->
    <main class="flex-1 p-6 overflow-x-auto bg-gradient-to-br from-gray-50 to-blue-50">
      <div class="mb-8">
        <h1 class="text-4xl font-bold gradient-text mb-2 flex items-center gap-3">
          <i class="fas fa-users text-blue-600"></i>
          Customers
        </h1>
        <p class="text-gray-600">View and manage your customer base</p>
      </div>
      <div class="bg-white shadow-sm border border-gray-200 rounded-xl overflow-hidden fade-in">
        <table class="min-w-full">
          <thead class="bg-gradient-to-r from-blue-500 to-blue-600 text-white">
            <tr>
              <th class="px-6 py-3 text-left text-sm font-semibold uppercase tracking-wider">Customer Name</th>
              <th class="px-6 py-3 text-left text-sm font-semibold uppercase tracking-wider">Contact</th>
              <th class="px-6 py-3 text-left text-sm font-semibold uppercase tracking-wider">Email</th>
              <th class="px-6 py-3 text-left text-sm font-semibold uppercase tracking-wider">Total Orders</th>
              <th class="px-6 py-3 text-left text-sm font-semibold uppercase tracking-wider">Total Revenue</th>
              <th class="px-6 py-3 text-left text-sm font-semibold uppercase tracking-wider">Last Order</th>
              <th class="px-6 py-3 text-left text-sm font-semibold uppercase tracking-wider">Status</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <?php if (empty($customers)): ?>
              <tr>
                <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                  <i class="fas fa-users text-4xl mb-2 block"></i>
                  <p>No customers found</p>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($customers as $c): ?>
              <tr class="table-row hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex items-center gap-2">
                    <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
                      <i class="fas fa-user text-blue-600"></i>
                    </div>
                    <span class="font-medium text-gray-900"><?= htmlspecialchars($c['full_name']) ?></span>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex items-center gap-2">
                    <i class="fas fa-phone text-gray-400"></i>
                    <span class="text-gray-700"><?= htmlspecialchars($c['contact_number'] ?? '-') ?></span>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex items-center gap-2">
                    <i class="fas fa-envelope text-gray-400"></i>
                    <span class="text-gray-700"><?= htmlspecialchars($c['email'] ?? '-') ?></span>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-100 text-blue-700 font-semibold">
                    <i class="fas fa-shopping-bag text-sm"></i>
                    <?= number_format($c['total_orders'] ?? 0) ?>
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="font-semibold text-green-600">₱<?= number_format($c['total_revenue'] ?? 0, 2) ?></span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-gray-600">
                  <?= $c['last_order'] ? date('M d, Y', strtotime($c['last_order'])) : '-' ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex flex-col gap-1">
                    <?php if (($c['pending_orders'] ?? 0) > 0): ?>
                      <span class="inline-block px-2 py-1 rounded text-xs font-semibold bg-yellow-100 text-yellow-700">
                        <?= $c['pending_orders'] ?> Pending
                      </span>
                    <?php endif; ?>
                    <?php if (($c['completed_orders'] ?? 0) > 0): ?>
                      <span class="inline-block px-2 py-1 rounded text-xs font-semibold bg-green-100 text-green-700">
                        <?= $c['completed_orders'] ?> Completed
                      </span>
                    <?php endif; ?>
                    <?php if (($c['pending_orders'] ?? 0) == 0 && ($c['completed_orders'] ?? 0) == 0): ?>
                      <span class="text-gray-400 text-xs">-</span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </main>
  </div>
</body>
</html>
