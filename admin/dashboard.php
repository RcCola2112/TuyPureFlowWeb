<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once '../db.php';
// Real metrics from database
$pendingDistributors = $conn->query("SELECT COUNT(*) FROM distributor WHERE status = 'pending'")->fetchColumn();
$totalOrders = $conn->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalConsumers = $conn->query("SELECT COUNT(*) FROM consumer")->fetchColumn();
$totalShops = $conn->query("SELECT COUNT(*) FROM shop")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Admin Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 font-sans text-gray-700">
  <?php include 'header.php'; ?>
  <div class="flex min-h-screen">
    <?php include 'sidebar.php'; ?>
    <main class="flex-1 p-8 overflow-y-auto" style="margin-left: 16rem; padding-top: 88px; min-height: calc(100vh - 72px);">
      <h1 class="text-2xl font-bold text-gray-800 mb-4">Dashboard Overview</h1>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
        <div class="bg-white rounded-lg shadow p-6 flex flex-col items-center">
          <div class="text-sm text-gray-500 mb-1">Pending Distributor Applications</div>
          <div class="text-3xl font-bold text-blue-600 mb-2"><?php echo $pendingDistributors; ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-6 flex flex-col items-center">
          <div class="text-sm text-gray-500 mb-1">Total Orders</div>
          <div class="text-3xl font-bold text-red-600 mb-2"><?php echo $totalOrders; ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-6 flex flex-col items-center">
          <div class="text-sm text-gray-500 mb-1">Total Consumers</div>
          <div class="text-3xl font-bold text-yellow-600 mb-2"><?php echo $totalConsumers; ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-6 flex flex-col items-center">
          <div class="text-sm text-gray-500 mb-1">Total Shops</div>
          <div class="text-3xl font-bold text-pink-600 mb-2"><?php echo $totalShops; ?></div>
        </div>
      </div>
      <!-- Placeholder for charts and recent activity feed -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
        <div class="bg-white p-6 rounded-xl shadow border border-gray-100 flex flex-col">
          <h3 class="text-lg font-semibold mb-4 text-gray-700">Metrics Chart</h3>
          <div class="h-80 flex items-center justify-center">
            <canvas id="ordersChart" width="600" height="260"></canvas>
            <?php
            // Get orders per day for the last 7 days
            $ordersData = [];
            $labels = [];
            for ($i = 6; $i >= 0; $i--) {
              $date = date('Y-m-d', strtotime("-$i days"));
              $labels[] = date('D', strtotime($date));
              $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE DATE(order_date) = ?");
              $stmt->execute([$date]);
              $ordersData[] = (int)$stmt->fetchColumn();
            }
            ?>
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <script>
              const ctx = document.getElementById('ordersChart').getContext('2d');
              const ordersChart = new Chart(ctx, {
                type: 'line',
                data: {
                  labels: <?= json_encode($labels) ?>,
                  datasets: [{
                    label: 'Orders per Day',
                    data: <?= json_encode($ordersData) ?>,
                    borderColor: '#3578C9',
                    backgroundColor: 'rgba(63,224,232,0.2)',
                    pointBackgroundColor: '#3FE0E8',
                    pointRadius: 6,
                    fill: true,
                    tension: 0.4
                  }]
                },
                options: {
                  responsive: false,
                  plugins: {
                    legend: { display: false }
                  },
                  scales: {
                    y: {
                      beginAtZero: true,
                      ticks: { stepSize: 1 }
                    }
                  }
                }
              });
            </script>
          </div>
        </div>
        <div class="bg-white p-6 rounded-xl shadow border border-gray-100 flex flex-col">
          <h3 class="text-lg font-semibold mb-4 text-gray-700">Recent Activity</h3>
          <ul class="text-gray-500">
            <?php
            // Recent distributor applications
            $recentDistributors = $conn->query("SELECT name, created_at FROM distributor ORDER BY created_at DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($recentDistributors)) {
              foreach ($recentDistributors as $d) {
                echo '<li>Distributor "' . htmlspecialchars($d['name']) . '" submitted application (' . htmlspecialchars($d['created_at']) . ')</li>';
              }
            } else {
              echo '<li>No recent distributor applications.</li>';
            }
            // Recent feedback/disputes (feedback table: id, user_id, message, date)
            $recentFeedback = $conn->query("SELECT user_id, message, date FROM feedback ORDER BY date DESC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($recentFeedback)) {
              foreach ($recentFeedback as $f) {
                echo '<li>Dispute opened by User #' . htmlspecialchars($f['user_id']) . ': ' . htmlspecialchars(substr($f['message'],0,40)) . ' (' . htmlspecialchars($f['date']) . ')</li>';
              }
            } else {
              echo '<li>No recent feedback or disputes.</li>';
            }
              // ...Transactions and Monitoring section removed...
            ?>
          </ul>
        </div>
      </div>
    </main>
  </div>
</body>
</html>
