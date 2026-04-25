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
$currentPage = 'dashboard';

$distributor_id = $_SESSION['distributor_id'];

// Add this block to fetch distributor info for header
$stmt = $conn->prepare("SELECT * FROM distributor WHERE distributor_id = ?");
$stmt->execute([$distributor_id]);
$distributor = $stmt->fetch();

$username = $distributor['name'] ?? '';
$stmtShop = $conn->prepare("SELECT name FROM shop WHERE distributor_id = ? LIMIT 1");
$stmtShop->execute([$distributor_id]);
$shopname = $stmtShop->fetchColumn() ?: '';
$profilePic = isset($distributor['profile_pic']) && $distributor['profile_pic'] ? $distributor['profile_pic'] : "images/profile.jpg";

// Today's Orders - Count all orders created today (regardless of scheduled date or status)
$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM orders 
    WHERE shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?) 
    AND DATE(order_date) = DATE(NOW())
");
$stmt->execute([$distributor_id]);
$todays_orders = $stmt->fetchColumn() ?: 0;

// Today's Revenue - Sum total_amount of all orders created today (regardless of status)
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) 
    FROM orders 
    WHERE shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?) 
    AND DATE(order_date) = DATE(NOW())
");
$stmt->execute([$distributor_id]);
$todays_revenue = $stmt->fetchColumn() ?: 0;

// Pending Orders (urgent)
$stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?) AND status = 'Pending'");
$stmt->execute([$distributor_id]);
$pending_orders = $stmt->fetchColumn() ?: 0;

// Completed Today
$stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?) AND status = 'Completed' AND DATE(order_date) = DATE(NOW())");
$stmt->execute([$distributor_id]);
$completed_today = $stmt->fetchColumn() ?: 0;

// Monthly Revenue
$stmt = $conn->prepare("SELECT SUM(total_amount) FROM orders WHERE shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?) AND MONTH(order_date) = MONTH(CURRENT_DATE()) AND YEAR(order_date) = YEAR(CURRENT_DATE())");
$stmt->execute([$distributor_id]);
$monthly_revenue = $stmt->fetchColumn() ?: 0;

// This Week's Revenue
$stmt = $conn->prepare("SELECT SUM(total_amount) FROM orders WHERE shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?) AND YEARWEEK(order_date, 1) = YEARWEEK(CURDATE(), 1)");
$stmt->execute([$distributor_id]);
$this_week_revenue = $stmt->fetchColumn() ?: 0;

// --- Visual Analytics Data Preparation ---
// Monthly Sales Data with Filter
$selected_year = isset($_GET['sales_year']) ? (int)$_GET['sales_year'] : date('Y');
$selected_month = isset($_GET['sales_month']) ? (int)$_GET['sales_month'] : 0; // 0 = all months

// Check if this is an AJAX request
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] == '1';

// Get available years for filter
$years_stmt = $conn->prepare("
    SELECT DISTINCT YEAR(order_date) AS year
    FROM orders
    WHERE shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?)
    ORDER BY year DESC
");
$years_stmt->execute([$distributor_id]);
$available_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

// Function to get monthly sales data
function getMonthlySalesData($conn, $distributor_id, $selected_year, $selected_month) {
    $monthly_labels = [];
    $monthly_sales = [];
    $is_daily = false;
    $chart_label = 'Monthly Revenue';
    
    if ($selected_month > 0) {
        // Show specific month - daily view
        $is_daily = true;
        $chart_label = 'Daily Sales';
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $selected_month, $selected_year);
        $daily_sales_map = [];
        
        $stmt = $conn->prepare("
            SELECT DAY(order_date) AS day, SUM(total_amount) AS sales
            FROM orders
            WHERE shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?)
              AND YEAR(order_date) = ?
              AND MONTH(order_date) = ?
              AND (
                  status = 'Completed'
                  OR 
                  (DATE(order_date) >= '2024-12-04' AND DATE(order_date) <= DATE(NOW()))
              )
            GROUP BY day
            ORDER BY day ASC
        ");
        $stmt->execute([$distributor_id, $selected_year, $selected_month]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $daily_sales_map[(int)$row['day']] = (float)$row['sales'];
        }
        
        // Generate labels and data for all days in month
        for ($day = 1; $day <= $days_in_month; $day++) {
            $monthly_labels[] = $day;
            $monthly_sales[] = isset($daily_sales_map[$day]) ? $daily_sales_map[$day] : 0;
        }
    } else {
        // Show all months in selected year
        $chart_label = 'Monthly Revenue';
        for ($month = 1; $month <= 12; $month++) {
            $monthly_labels[] = date('M', mktime(0, 0, 0, $month, 1));
        }
        
        // Get sales data for each month in the year
        $monthly_sales_map = [];
        $stmt = $conn->prepare("
            SELECT MONTH(order_date) AS month_num, SUM(total_amount) AS sales
            FROM orders
            WHERE shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?)
              AND YEAR(order_date) = ?
              AND (
                  status = 'Completed'
                  OR 
                  (DATE(order_date) >= '2024-12-04' AND DATE(order_date) <= DATE(NOW()))
              )
            GROUP BY month_num
            ORDER BY month_num ASC
        ");
        $stmt->execute([$distributor_id, $selected_year]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $monthly_sales_map[(int)$row['month_num']] = (float)$row['sales'];
        }
        
        // Match sales data to labels (fill with 0 if no data for a month)
        for ($month = 1; $month <= 12; $month++) {
            $monthly_sales[] = isset($monthly_sales_map[$month]) ? $monthly_sales_map[$month] : 0;
        }
    }
    
    return [
        'labels' => $monthly_labels,
        'sales' => $monthly_sales,
        'label' => $chart_label,
        'isDaily' => $is_daily
    ];
}

// Get monthly sales data
$sales_data = getMonthlySalesData($conn, $distributor_id, $selected_year, $selected_month);
$monthly_labels = $sales_data['labels'];
$monthly_sales = $sales_data['sales'];

// If AJAX request, return JSON and exit
if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'labels' => $monthly_labels,
        'sales' => $monthly_sales,
        'label' => $sales_data['label'],
        'isDaily' => $sales_data['isDaily']
    ]);
    exit;
}

// Order Status Breakdown
$order_status_data = [0, 0, 0]; // Pending, Completed, Cancelled
$stmt = $conn->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM orders
    WHERE shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?)
    GROUP BY status
");
$stmt->execute([$distributor_id]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status = trim($row['status']);
    if (strtolower($status) == 'pending') {
        $order_status_data[0] = (int)$row['cnt'];
    } elseif (strtolower($status) == 'completed') {
        $order_status_data[1] = (int)$row['cnt'];
    } elseif (strtolower($status) == 'cancelled') {
        $order_status_data[2] = (int)$row['cnt'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Distributor Dashboard | Tuy PureFlow</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .gradient-text {
      background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .stat-card {
      background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
      transition: all 0.3s ease;
      border-left: 4px solid transparent;
      position: relative;
      overflow: hidden;
    }
    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, transparent, currentColor, transparent);
      opacity: 0;
      transition: opacity 0.3s ease;
    }
    .stat-card:hover::before {
      opacity: 1;
    }
    .stat-card:hover {
      transform: translateY(-6px) scale(1.02);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
    }
    .stat-card.blue { border-left-color: #3b82f6; }
    .stat-card.blue::before { background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.6), transparent); }
    .stat-card.blue:hover { box-shadow: 0 12px 30px rgba(59, 130, 246, 0.2); }
    
    .stat-card.indigo { border-left-color: #6366f1; }
    .stat-card.indigo::before { background: linear-gradient(90deg, transparent, rgba(99, 102, 241, 0.6), transparent); }
    .stat-card.indigo:hover { box-shadow: 0 12px 30px rgba(99, 102, 241, 0.2); }
    
    .stat-card.green { border-left-color: #10b981; }
    .stat-card.green::before { background: linear-gradient(90deg, transparent, rgba(16, 185, 129, 0.6), transparent); }
    .stat-card.green:hover { box-shadow: 0 12px 30px rgba(16, 185, 129, 0.2); }
    
    .stat-card.yellow { border-left-color: #f59e0b; }
    .stat-card.yellow::before { background: linear-gradient(90deg, transparent, rgba(245, 158, 11, 0.6), transparent); }
    .stat-card.yellow:hover { box-shadow: 0 12px 30px rgba(245, 158, 11, 0.2); }
    
    .stat-card.pink { border-left-color: #ec4899; }
    .stat-card.pink::before { background: linear-gradient(90deg, transparent, rgba(236, 72, 153, 0.6), transparent); }
    .stat-card.pink:hover { box-shadow: 0 12px 30px rgba(236, 72, 153, 0.2); }
    
    .stat-card.purple { border-left-color: #a855f7; }
    .stat-card.purple::before { background: linear-gradient(90deg, transparent, rgba(168, 85, 247, 0.6), transparent); }
    .stat-card.purple:hover { box-shadow: 0 12px 30px rgba(168, 85, 247, 0.2); }
    
    .stat-card.emerald { border-left-color: #10b981; }
    .stat-card.emerald::before { background: linear-gradient(90deg, transparent, rgba(16, 185, 129, 0.6), transparent); }
    .stat-card.emerald:hover { box-shadow: 0 12px 30px rgba(16, 185, 129, 0.2); }
    
    .chart-card {
      background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
      transition: all 0.3s ease;
      border-top: 3px solid transparent;
    }
    .chart-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    }
    .chart-card.blue { border-top-color: #3b82f6; }
    .chart-card.green { border-top-color: #10b981; }
    
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
    .icon-wrapper {
      width: 3rem;
      height: 3rem;
      border-radius: 0.75rem;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, currentColor, transparent);
      opacity: 0.1;
    }
  </style>
</head>
<body class="flex bg-gray-100 min-h-screen">
  <!-- Sidebar -->
  <?php include 'sidebar.php'; ?>
  <!-- Main Content -->
  <div class="flex-1 flex flex-col min-h-screen ml-64">
    <!-- Header -->
    <?php include 'header.php'; ?>
    <!-- Page Content -->
    <main class="flex-1 p-6 overflow-x-auto bg-gradient-to-br from-gray-50 to-blue-50">
      <div class="mb-8">
        <h1 class="text-4xl font-bold gradient-text mb-2 flex items-center gap-3">
          <i class="fas fa-chart-line text-blue-600"></i>
          Dashboard
        </h1>
        <p class="text-gray-600">Welcome to your distributor dashboard</p>
      </div>
      <!-- Today's Metrics -->
      <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-700 mb-4 flex items-center gap-2">
          <i class="fas fa-calendar-day text-blue-600"></i>
          Today's Overview
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-8">
          <div class="stat-card blue rounded-xl p-4 shadow-sm border border-gray-200 fade-in">
            <div class="flex items-center justify-between mb-3">
              <div class="icon-wrapper text-blue-600" style="width: 2.5rem; height: 2.5rem;">
                <i class="fas fa-shopping-cart text-xl"></i>
              </div>
              <i class="fas fa-arrow-up-right text-blue-600 opacity-50 text-sm"></i>
            </div>
            <h2 class="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1">Today's Orders</h2>
            <p class="text-3xl font-bold text-blue-600"><?= $todays_orders ?></p>
          </div>
          <div class="stat-card green rounded-xl p-4 shadow-sm border border-gray-200 fade-in">
            <div class="flex items-center justify-between mb-3">
              <div class="icon-wrapper text-green-600" style="width: 2.5rem; height: 2.5rem;">
                <i class="fas fa-peso-sign text-xl"></i>
              </div>
              <i class="fas fa-arrow-up-right text-green-600 opacity-50 text-sm"></i>
            </div>
            <h2 class="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1">Today's Revenue</h2>
            <p class="text-3xl font-bold text-green-600">₱<?= number_format($todays_revenue, 2) ?></p>
          </div>
          <div class="stat-card yellow rounded-xl p-4 shadow-sm border border-gray-200 fade-in">
            <div class="flex items-center justify-between mb-3">
              <div class="icon-wrapper text-yellow-600" style="width: 2.5rem; height: 2.5rem;">
                <i class="fas fa-clock text-xl"></i>
              </div>
              <i class="fas fa-exclamation-triangle text-yellow-600 opacity-50 text-sm"></i>
            </div>
            <h2 class="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1">Pending Orders</h2>
            <p class="text-3xl font-bold text-yellow-600"><?= $pending_orders ?></p>
          </div>
          <div class="stat-card emerald rounded-xl p-4 shadow-sm border border-gray-200 fade-in">
            <div class="flex items-center justify-between mb-3">
              <div class="icon-wrapper text-emerald-600" style="width: 2.5rem; height: 2.5rem;">
                <i class="fas fa-check-circle text-xl"></i>
              </div>
              <i class="fas fa-arrow-up-right text-emerald-600 opacity-50 text-sm"></i>
            </div>
            <h2 class="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1">Completed Today</h2>
            <p class="text-3xl font-bold text-emerald-600"><?= $completed_today ?></p>
          </div>
        </div>
      </div>

      <!-- Business Health Metrics -->
      <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-700 mb-4 flex items-center gap-2">
          <i class="fas fa-chart-line text-indigo-600"></i>
          Business Health
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
          <div class="stat-card indigo rounded-xl p-4 shadow-sm border border-gray-200 fade-in">
            <div class="flex items-center justify-between mb-3">
              <div class="icon-wrapper text-indigo-600" style="width: 2.5rem; height: 2.5rem;">
                <i class="fas fa-peso-sign text-xl"></i>
              </div>
              <i class="fas fa-arrow-up-right text-indigo-600 opacity-50 text-sm"></i>
            </div>
            <h2 class="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1">Monthly Revenue</h2>
            <p class="text-3xl font-bold text-indigo-600">₱<?= number_format($monthly_revenue, 2) ?></p>
          </div>
          <div class="stat-card green rounded-xl p-4 shadow-sm border border-gray-200 fade-in">
            <div class="flex items-center justify-between mb-3">
              <div class="icon-wrapper text-green-600" style="width: 2.5rem; height: 2.5rem;">
                <i class="fas fa-money-bill-wave text-xl"></i>
              </div>
              <i class="fas fa-arrow-up-right text-green-600 opacity-50 text-sm"></i>
            </div>
            <h2 class="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1">This Week's Revenue</h2>
            <p class="text-3xl font-bold text-green-600">₱<?= number_format($this_week_revenue, 2) ?></p>
          </div>
        </div>
      </div>
      <!-- Visual Overview -->
      <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-700 mb-4 flex items-center gap-2">
          <i class="fas fa-chart-pie text-purple-600"></i>
          Quick Overview
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
          <div class="chart-card green bg-white p-4 rounded-xl shadow-sm border border-gray-200 fade-in">
            <h2 class="font-semibold mb-3 flex items-center gap-2 text-gray-800 text-base">
              <div class="w-8 h-8 rounded-lg bg-green-100 flex items-center justify-center">
                <i class="fas fa-chart-pie text-green-600 text-sm"></i>
              </div>
              Order Status Breakdown
            </h2>
            <div style="height: 300px; position: relative;">
              <canvas id="orderStatusChart"></canvas>
            </div>
          </div>
          <div class="chart-card blue bg-white p-4 rounded-xl shadow-sm border border-gray-200 fade-in">
            <div class="flex items-center justify-between mb-3">
              <h2 class="font-semibold flex items-center gap-2 text-gray-800 text-base">
                <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
                  <i class="fas fa-chart-line text-blue-600 text-sm"></i>
                </div>
                Monthly Sales Trend
              </h2>
              <div class="flex items-center gap-2" id="salesFilterForm">
                <select name="sales_year" id="sales_year" class="px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" onchange="return false;">
                  <?php foreach ($available_years as $year): ?>
                    <option value="<?= $year ?>" <?= $year == $selected_year ? 'selected' : '' ?>><?= $year ?></option>
                  <?php endforeach; ?>
                </select>
                <select name="sales_month" id="sales_month" class="px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" onchange="return false;">
                  <option value="0" <?= $selected_month == 0 ? 'selected' : '' ?>>All Months</option>
                  <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $selected_month == $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                  <?php endfor; ?>
                </select>
                <button type="button" onclick="applySalesFilter()" class="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors">
                  <i class="fas fa-filter"></i> Filter
                </button>
              </div>
            </div>
            <div style="height: 300px; position: relative;">
              <canvas id="monthlySalesChart"></canvas>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 fade-in">
        <h2 class="text-lg font-semibold mb-4 flex items-center gap-2 text-gray-800">
          <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
            <i class="fas fa-list text-blue-600 text-sm"></i>
          </div>
          Recent Orders
        </h2>
        <div class="overflow-x-auto">
        <table class="min-w-full">
          <thead class="bg-gradient-to-r from-blue-500 to-blue-600 text-white">
            <tr>
              <th class="py-2 px-4 border-b text-left">Customer</th>
              <th class="py-2 px-4 border-b text-left">Total Amount</th>
              <th class="py-2 px-4 border-b text-left">Status</th>
              <th class="py-2 px-4 border-b text-left">Order Date</th>
              <th class="py-2 px-4 border-b text-left">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php
            // Fetch recent orders
            $stmt = $conn->prepare("SELECT o.order_id, co.full_name AS customer_name, o.total_amount, o.status, o.order_date
                                     FROM orders o
                                     JOIN shop s ON o.shop_id = s.shop_id
                                     JOIN consumer co ON o.consumer_id = co.consumer_id
                                     WHERE s.distributor_id = ?
                                     ORDER BY o.order_date DESC
                                     LIMIT 5");
            $stmt->execute([$distributor_id]);
            $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($recent_orders as $order):
            ?>
              <tr class="hover:bg-gray-50">
                <td class="py-2 px-4 border-b"><?= htmlspecialchars($order['customer_name']) ?></td>
                <td class="py-2 px-4 border-b">₱<?= number_format($order['total_amount'], 2) ?></td>
                <td class="py-2 px-4 border-b">
                  <span class="inline-block px-3 py-1 text-xs font-semibold rounded-full
                              <?= $order['status'] == 'Completed' ? 'bg-green-100 text-green-600' : '' ?>
                              <?= $order['status'] == 'Pending' ? 'bg-yellow-100 text-yellow-600' : '' ?>
                              <?= $order['status'] == 'Cancelled' ? 'bg-red-100 text-red-600' : '' ?>">
                    <?= htmlspecialchars($order['status']) ?>
                  </span>
                </td>
                <td class="py-2 px-4 border-b"><?= htmlspecialchars($order['order_date']) ?></td>
                <td class="py-2 px-4 border-b">
                  <a href="orders.php?order_id=<?= htmlspecialchars($order['order_id']) ?>" class="text-blue-600 hover:text-blue-800">
                    View
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
    </main>
  </div>
  <!-- Chart.js Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    // Order Status Breakdown Chart
    var ctx2 = document.getElementById('orderStatusChart');
    if (ctx2) {
      var orderStatusChart = new Chart(ctx2, {
        type: 'doughnut',
        data: {
          labels: ['Pending', 'Completed', 'Cancelled'],
          datasets: [{
            data: <?= json_encode($order_status_data) ?>,
            backgroundColor: ['#fbbf24', '#22c55e', '#ef4444'],
            borderWidth: 3,
            borderColor: '#ffffff',
            hoverOffset: 4
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom',
              labels: {
                padding: 15,
                font: {
                  size: 12
                },
                usePointStyle: true
              }
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  var label = context.label || '';
                  var value = context.parsed || 0;
                  var total = context.dataset.data.reduce((a, b) => a + b, 0);
                  var percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                  return label + ': ' + value + ' (' + percentage + '%)';
                }
              }
            }
          }
        }
      });
    }

    // Monthly Sales Trend Chart
    var ctx1 = document.getElementById('monthlySalesChart');
    var monthlySalesChart = null;
    
    if (ctx1) {
      var chartLabel = <?= $selected_month > 0 ? "'Daily Sales'" : "'Monthly Revenue'" ?>;
      
      monthlySalesChart = new Chart(ctx1, {
        type: 'line',
        data: {
          labels: <?= json_encode($monthly_labels) ?>,
          datasets: [{
            label: chartLabel,
            data: <?= json_encode($monthly_sales) ?>,
            borderColor: 'rgba(59, 130, 246, 1)',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 5,
            pointHoverRadius: 7,
            pointBackgroundColor: 'rgba(59, 130, 246, 1)',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2,
            pointHoverBackgroundColor: 'rgba(59, 130, 246, 1)',
            pointHoverBorderColor: '#ffffff',
            pointHoverBorderWidth: 3,
            borderWidth: 2
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: true,
              position: 'top',
              labels: {
                font: {
                  size: 12
                },
                padding: 10
              }
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  var label = context.dataset.label || '';
                  var value = context.parsed.y;
                  return label + ': ₱' + value.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                  });
                }
              }
            }
          },
          scales: {
            x: {
              grid: {
                display: false
              },
              ticks: {
                font: {
                  size: 11
                },
                maxRotation: <?= $selected_month > 0 ? '45' : '0' ?>,
                minRotation: <?= $selected_month > 0 ? '45' : '0' ?>
              }
            },
            y: {
              beginAtZero: true,
              grid: {
                color: 'rgba(0, 0, 0, 0.05)'
              },
              ticks: {
                callback: function(value) {
                  if (value >= 1000000) {
                    return '₱' + (value / 1000000).toFixed(1) + 'M';
                  } else if (value >= 1000) {
                    return '₱' + (value / 1000).toFixed(1) + 'K';
                  }
                  return '₱' + value.toLocaleString();
                },
                font: {
                  size: 11
                }
              }
            }
          }
        }
      });
    }
    
    // Function to apply filter - only updates when button is clicked
    function applySalesFilter() {
      const year = document.getElementById('sales_year').value;
      const month = document.getElementById('sales_month').value;
      updateMonthlySalesChart(year, month);
    }
    
    // AJAX filtering for monthly sales chart
    function updateMonthlySalesChart(year, month) {
      // Use provided values or get from selects
      year = year || document.getElementById('sales_year').value;
      month = month || document.getElementById('sales_month').value;
      
      if (!monthlySalesChart) return;
      
      // Show loading state
      const chartContainer = document.getElementById('monthlySalesChart').parentElement;
      const originalHeight = chartContainer.style.height;
      const canvas = document.getElementById('monthlySalesChart');
      canvas.style.opacity = '0.5';
      
      // Fetch new data via AJAX
      fetch(`dashboard.php?ajax=1&sales_year=${year}&sales_month=${month}`)
        .then(response => response.json())
        .then(data => {
          if (data.success && monthlySalesChart) {
            // Update chart data
            monthlySalesChart.data.labels = data.labels;
            monthlySalesChart.data.datasets[0].data = data.sales;
            monthlySalesChart.data.datasets[0].label = data.label;
            
            // Update x-axis rotation
            monthlySalesChart.options.scales.x.ticks.maxRotation = data.isDaily ? 45 : 0;
            monthlySalesChart.options.scales.x.ticks.minRotation = data.isDaily ? 45 : 0;
            
            // Update the chart
            monthlySalesChart.update('active');
            canvas.style.opacity = '1';
          }
        })
        .catch(error => {
          console.error('Error updating chart:', error);
          canvas.style.opacity = '1';
        });
    }
    
    // PREVENT automatic refresh when selecting year/month
    document.addEventListener('DOMContentLoaded', function() {
      const yearSelect = document.getElementById('sales_year');
      const monthSelect = document.getElementById('sales_month');
      
      // Block all automatic actions on change
      if (yearSelect) {
        yearSelect.addEventListener('change', function(e) {
          e.preventDefault();
          e.stopPropagation();
          e.stopImmediatePropagation();
          return false;
        }, true);
      }
      
      if (monthSelect) {
        monthSelect.addEventListener('change', function(e) {
          e.preventDefault();
          e.stopPropagation();
          e.stopImmediatePropagation();
          return false;
        }, true);
      }
    });
  </script>
</body>
</html>