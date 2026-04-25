  <?php
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);
  session_start();
  if (!isset($_SESSION['distributor_id'])) {
    // Check if AJAX request first
    if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
      echo '<script>window.location.replace("../index.html");</script>';
      exit;
  }
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: Sat, 1 Jan 2000 00:00:00 GMT");

  include '../db.php';
  $currentPage = 'analytics';

  // Get distributor info
  $distributor_id = $_SESSION['distributor_id'];
  $stmt = $conn->prepare("SELECT * FROM distributor WHERE distributor_id = ?");
  $stmt->execute([$distributor_id]);
  $distributor = $stmt->fetch();

  $username = $distributor['name'] ?? '';
  $stmtShop = $conn->prepare("SELECT name FROM shop WHERE distributor_id = ? LIMIT 1");
  $stmtShop->execute([$distributor_id]);
  $shopname = $stmtShop->fetchColumn() ?: '';
  $profilePic = "images/profile.jpg";

  // =========================================================
  // ALL ANALYTICS LIMITED TO THIS DISTRIBUTOR'S SHOPS
  // =========================================================

  // Get all shop IDs for this distributor
  $shop_ids_stmt = $conn->prepare("SELECT shop_id FROM shop WHERE distributor_id = ?");
  $shop_ids_stmt->execute([$distributor_id]);
  $shop_ids = $shop_ids_stmt->fetchAll(PDO::FETCH_COLUMN);

  // Get calendar filter (start/end date) with fallback values
  $start_date = isset($_GET['start_date']) && $_GET['start_date'] ? $_GET['start_date'] : date('Y-m-01');
  $end_date = isset($_GET['end_date']) && $_GET['end_date'] ? $_GET['end_date'] : date('Y-m-d');
  $selected_period = isset($_GET['period']) ? (int)$_GET['period'] : 30;

  // ==================== HELPER FUNCTIONS FROM get_analytics.php (adapted for multiple shops) ====================
  
  function getOverallStatsMultiShop($conn, $shop_ids, $period) {
  if (empty($shop_ids)) {
          return ['total_orders' => 0, 'total_revenue' => 0, 'avg_order_value' => 0, 'unique_customers' => 0, 'today_orders' => 0, 'today_revenue' => 0];
      }
      
      $placeholders = implode(',', array_fill(0, count($shop_ids), '?'));
      $stmt = $conn->prepare("
          SELECT 
              COUNT(*) as total_orders,
              SUM(total_amount) as total_revenue,
              AVG(total_amount) as avg_order_value,
              COUNT(DISTINCT consumer_id) as unique_customers,
              SUM(CASE WHEN status = 'Completed' THEN total_amount ELSE 0 END) as completed_revenue,
              SUM(CASE WHEN status = 'Pending' THEN total_amount ELSE 0 END) as pending_revenue
          FROM orders 
          WHERE shop_id IN ($placeholders) AND order_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
      ");
      $stmt->execute(array_merge($shop_ids, [$period]));
      $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
      
      // Get today's stats
      $stmtToday = $conn->prepare("
          SELECT 
              COUNT(*) as today_orders,
              SUM(total_amount) as today_revenue
          FROM orders 
          WHERE shop_id IN ($placeholders) AND DATE(order_date) = CURDATE()
      ");
      $stmtToday->execute($shop_ids);
      $todayStats = $stmtToday->fetch(PDO::FETCH_ASSOC) ?: [];
      
      return array_merge($stats, $todayStats);
  }
  
  function getDailySalesTrendMultiShop($conn, $shop_ids, $start_date, $end_date) {
    if (empty($shop_ids)) {
      return [];
    }
    $placeholders = implode(',', array_fill(0, count($shop_ids), '?'));
    $stmt = $conn->prepare("
      SELECT 
        DATE(order_date) as date,
        COUNT(*) as orders,
        SUM(total_amount) as revenue,
        COUNT(DISTINCT consumer_id) as customers
      FROM orders 
      WHERE shop_id IN ($placeholders) 
      AND DATE(order_date) >= ? AND DATE(order_date) <= ?
      AND (
        status = 'Completed'
        OR 
        (DATE(order_date) >= '2024-12-04' AND DATE(order_date) <= DATE(NOW()))
      )
      GROUP BY DATE(order_date)
      ORDER BY date ASC
    ");
    $stmt->execute(array_merge($shop_ids, [$start_date, $end_date]));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
  
  function getTopSellingProductsMultiShop($conn, $shop_ids, $period) {
      if (empty($shop_ids)) {
          return [];
      }
      
      $placeholders = implode(',', array_fill(0, count($shop_ids), '?'));
      $stmt = $conn->prepare("
          SELECT 
              c.container_name,
              SUM(oi.quantity) as total_quantity,
              SUM(oi.quantity * oi.price) as total_revenue,
              COUNT(DISTINCT o.order_id) as order_count
          FROM order_items oi
          JOIN orders o ON oi.order_id = o.order_id
          JOIN container c ON oi.container_id = c.container_id
          WHERE o.shop_id IN ($placeholders) 
          AND o.order_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND (
              o.status = 'Completed'
              OR 
              (DATE(o.order_date) >= '2024-12-04' AND DATE(o.order_date) <= DATE(NOW()))
          )
          GROUP BY c.container_id, c.container_name
          ORDER BY total_revenue DESC
          LIMIT 10
      ");
      $stmt->execute(array_merge($shop_ids, [$period]));
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
  
  function getCustomerAnalyticsMultiShop($conn, $shop_ids, $period) {
      if (empty($shop_ids)) {
          return ['total_customers' => 0, 'returning_customers' => 0, 'new_customers' => 0, 'avg_customer_lifetime_value' => 0];
      }
      
      $placeholders = implode(',', array_fill(0, count($shop_ids), '?'));
      $stmt = $conn->prepare("
          SELECT 
              COUNT(DISTINCT consumer_id) as total_customers,
              COUNT(DISTINCT CASE WHEN order_count > 1 THEN consumer_id END) as returning_customers,
              COUNT(DISTINCT CASE WHEN order_count = 1 THEN consumer_id END) as new_customers
          FROM (
              SELECT consumer_id, COUNT(*) as order_count
              FROM orders 
              WHERE shop_id IN ($placeholders) 
              AND order_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND (
                  status = 'Completed'
                  OR 
                  (DATE(order_date) >= '2024-12-04' AND DATE(order_date) <= DATE(NOW()))
              )
              GROUP BY consumer_id
          ) customer_orders
      ");
      $stmt->execute(array_merge($shop_ids, [$period]));
      $customerStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
      
      // Customer lifetime value
      $stmtCLV = $conn->prepare("
          SELECT AVG(customer_revenue) as avg_customer_lifetime_value
          FROM (
              SELECT consumer_id, SUM(total_amount) as customer_revenue
              FROM orders 
              WHERE shop_id IN ($placeholders)
              AND (
                  status = 'Completed'
                  OR 
                  (DATE(order_date) >= '2024-12-04' AND DATE(order_date) <= DATE(NOW()))
              )
              GROUP BY consumer_id
          ) customer_revenue
      ");
      $stmtCLV->execute($shop_ids);
      $clv = $stmtCLV->fetch(PDO::FETCH_ASSOC) ?: [];
      
      return array_merge($customerStats, $clv);
  }
  
  function getGrowthMetricsMultiShop($conn, $shop_ids, $period) {
      if (empty($shop_ids)) {
          return ['revenue_growth' => 0, 'order_growth' => 0, 'current_period' => [], 'previous_period' => []];
      }
      
      $placeholders = implode(',', array_fill(0, count($shop_ids), '?'));
      $stmt = $conn->prepare("
          SELECT 
              SUM(total_amount) as current_revenue,
              COUNT(*) as current_orders
          FROM orders 
          WHERE shop_id IN ($placeholders) AND order_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
      ");
      $stmt->execute(array_merge($shop_ids, [$period]));
      $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
      
      $stmt = $conn->prepare("
          SELECT 
              SUM(total_amount) as previous_revenue,
              COUNT(*) as previous_orders
          FROM orders 
          WHERE shop_id IN ($placeholders) AND order_date >= DATE_SUB(NOW(), INTERVAL ? DAY) 
            AND order_date < DATE_SUB(NOW(), INTERVAL ? DAY)
      ");
      $stmt->execute(array_merge($shop_ids, [$period * 2, $period]));
      $previous = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
      
      $currentRevenue = floatval($current['current_revenue'] ?? 0);
      $previousRevenue = floatval($previous['previous_revenue'] ?? 0);
      $currentOrders = intval($current['current_orders'] ?? 0);
      $previousOrders = intval($previous['previous_orders'] ?? 0);
      
      $revenueGrowth = $previousRevenue > 0 ? 
          (($currentRevenue - $previousRevenue) / $previousRevenue) * 100 : 0;
      
      $orderGrowth = $previousOrders > 0 ? 
          (($currentOrders - $previousOrders) / $previousOrders) * 100 : 0;
      
      return [
          'revenue_growth' => $revenueGrowth,
          'order_growth' => $orderGrowth,
          'current_period' => $current,
          'previous_period' => $previous
      ];
  }
  
  function getInventoryAnalyticsMultiShop($conn, $shop_ids) {
      if (empty($shop_ids)) {
          return ['inventory_items' => [], 'total_inventory_value' => 0, 'low_stock_count' => 0, 'total_items' => 0];
      }
      
      $placeholders = implode(',', array_fill(0, count($shop_ids), '?'));
      $stmt = $conn->prepare("
          SELECT 
              c.container_name,
              c.stock_quantity,
              c.price_with_container as price,
              (c.stock_quantity * c.price_with_container) as inventory_value,
              CASE 
                  WHEN c.stock_quantity = 0 THEN 'Out of Stock'
                  WHEN c.stock_quantity <= 5 THEN 'Low Stock'
                  ELSE 'In Stock'
              END as stock_status
          FROM container c
          WHERE c.shop_id IN ($placeholders)
          ORDER BY c.stock_quantity ASC
      ");
      $stmt->execute($shop_ids);
      $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
      
      $totalValue = array_sum(array_column($inventory, 'inventory_value'));
      $lowStockCount = count(array_filter($inventory, function($item) {
          return $item['stock_status'] === 'Low Stock' || $item['stock_status'] === 'Out of Stock';
      }));
      
      return [
          'inventory_items' => $inventory,
          'total_inventory_value' => $totalValue,
          'low_stock_count' => $lowStockCount,
          'total_items' => count($inventory)
      ];
  }
  
  function getSeasonalTrendsMultiShop($conn, $shop_ids) {
      if (empty($shop_ids)) {
          return [];
      }
      
      $placeholders = implode(',', array_fill(0, count($shop_ids), '?'));
      $stmt = $conn->prepare("
          SELECT 
              MONTH(order_date) as month,
              COUNT(*) as orders,
              SUM(total_amount) as revenue
          FROM orders 
          WHERE shop_id IN ($placeholders) AND order_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
          GROUP BY MONTH(order_date)
          ORDER BY month ASC
      ");
      $stmt->execute($shop_ids);
      return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
  
  /**
   * Forecast function for the last 30 days (backtesting)
   * Fetches additional historical data for training, then forecasts the last 30 days
   * Returns forecast data for the last 30 days of the provided daily_trend
   */
  function calculateForecastLast30Days($daily_trend, $conn, $shop_ids) {
      if (empty($daily_trend) || count($daily_trend) < 1) {
          return [];
      }
      
      $totalDays = count($daily_trend);
      $forecastDays = min(30, $totalDays); // Forecast up to 30 days, or all available if less
      
      if ($forecastDays < 1) {
          return [];
      }
      
      // Get the last N days to forecast (this is what we'll forecast)
      $lastDays = array_slice($daily_trend, -$forecastDays);
      $firstForecastDate = $lastDays[0]['date'];
      
      // Fetch additional historical data BEFORE the forecast period for training
      // Get data from 60 days before the first forecast date, up to (but not including) the first forecast date
      $trainingData = [];
      if (!empty($shop_ids)) {
          $placeholders = implode(',', array_fill(0, count($shop_ids), '?'));
          $firstDate = new DateTime($firstForecastDate);
          $firstDate->modify('-60 days'); // Get 60 days before for training
          
          $stmt = $conn->prepare("
              SELECT 
                  DATE(order_date) as date,
                  COUNT(*) as orders,
                  SUM(total_amount) as revenue,
                  COUNT(DISTINCT consumer_id) as customers
              FROM orders 
              WHERE shop_id IN ($placeholders) 
              AND order_date >= ? 
              AND order_date < ?
              AND (
                  status = 'Completed'
                  OR 
                  (DATE(order_date) >= '2024-12-04' AND DATE(order_date) <= DATE(NOW()))
              )
              GROUP BY DATE(order_date)
              ORDER BY date ASC
          ");
          $stmt->execute(array_merge($shop_ids, [
              $firstDate->format('Y-m-d'),
              $firstForecastDate
          ]));
          $trainingData = $stmt->fetchAll(PDO::FETCH_ASSOC);
      }
      
      // If no training data from database, use available data before forecast period as fallback
      if (empty($trainingData) && $totalDays > $forecastDays) {
          $trainingData = array_slice($daily_trend, 0, -$forecastDays);
      }
      
      if (empty($trainingData)) {
          // If still no training data, use a simple average of all available data
          $allRevenues = array_map(function($day) {
              return floatval($day['revenue'] ?? 0);
          }, $daily_trend);
          $simpleAvg = count($allRevenues) > 0 ? array_sum($allRevenues) / count($allRevenues) : 0;
          
          // Generate simple forecast using average
          $forecast = [];
          foreach ($lastDays as $actualDay) {
              $forecast[] = [
                  'date' => $actualDay['date'],
                  'revenue' => max(0, $simpleAvg)
              ];
          }
          return $forecast;
      }
      
      // Extract revenue values from training data with dates
      $trainingDataWithDates = [];
      foreach ($trainingData as $day) {
          $date = new DateTime($day['date']);
          $trainingDataWithDates[] = [
              'date' => $day['date'],
              'revenue' => floatval($day['revenue'] ?? 0),
              'day_of_week' => (int)$date->format('w'), // 0 = Sunday, 6 = Saturday
              'day_of_month' => (int)$date->format('j'),
              'is_weekend' => in_array((int)$date->format('w'), [0, 6])
          ];
      }
      
      $trainingRevenues = array_column($trainingDataWithDates, 'revenue');
      
      // ==================== IMPROVED FORECASTING METHOD ====================
      // Method: Exponential Smoothing with Trend + Day-of-Week Seasonality
      
      // 1. Calculate day-of-week multipliers (seasonality)
      $dayOfWeekTotals = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
      $dayOfWeekCounts = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
      
      foreach ($trainingDataWithDates as $day) {
          $dow = $day['day_of_week'];
          $dayOfWeekTotals[$dow] += $day['revenue'];
          $dayOfWeekCounts[$dow]++;
      }
      
      $dayOfWeekAverages = [];
      $overallAverage = 0;
      foreach ($dayOfWeekTotals as $dow => $total) {
          if ($dayOfWeekCounts[$dow] > 0) {
              $dayOfWeekAverages[$dow] = $total / $dayOfWeekCounts[$dow];
              $overallAverage += $total;
          } else {
              $dayOfWeekAverages[$dow] = 0;
          }
      }
      $totalDays = array_sum($dayOfWeekCounts);
      $overallAverage = $totalDays > 0 ? $overallAverage / $totalDays : 0;
      
      // Calculate multipliers (how much each day differs from average)
      $dayMultipliers = [];
      foreach ($dayOfWeekAverages as $dow => $avg) {
          if ($overallAverage > 0) {
              $dayMultipliers[$dow] = $avg / $overallAverage;
              // Cap multipliers to reasonable range (0.5 to 2.0)
              $dayMultipliers[$dow] = max(0.5, min(2.0, $dayMultipliers[$dow]));
          } else {
              $dayMultipliers[$dow] = 1.0;
          }
      }
      
      // 2. Exponential Smoothing with Trend (Holt's method)
      $alpha = 0.3; // Smoothing parameter for level (0-1, higher = more responsive)
      $beta = 0.1;  // Smoothing parameter for trend (0-1, higher = more responsive)
      
      // Initialize level and trend
      $n = count($trainingRevenues);
      if ($n < 2) {
          // Fallback to simple average if not enough data
          $level = $n > 0 ? $trainingRevenues[0] : 0;
          $trend = 0;
      } else {
          // Use median of first few days for initial level (more robust than mean)
          $initWindow = min(7, $n);
          $initValues = array_slice($trainingRevenues, 0, $initWindow);
          sort($initValues);
          $level = $initValues[floor(count($initValues) / 2)];
          
          // Calculate initial trend from recent differences
          $trendWindow = min(14, $n);
          $trendDiffs = [];
          for ($i = max(0, $n - $trendWindow); $i < $n - 1; $i++) {
              if ($trainingRevenues[$i + 1] > 0 && $trainingRevenues[$i] > 0) {
                  $trendDiffs[] = $trainingRevenues[$i + 1] - $trainingRevenues[$i];
              }
          }
          $trend = count($trendDiffs) > 0 ? array_sum($trendDiffs) / count($trendDiffs) : 0;
          $trend = $trend * 0.5; // Dampen initial trend
      }
      
      // Apply exponential smoothing to training data
      foreach ($trainingRevenues as $revenue) {
          $prevLevel = $level;
          $level = $alpha * $revenue + (1 - $alpha) * ($level + $trend);
          $trend = $beta * ($level - $prevLevel) + (1 - $beta) * $trend;
      }
      
      // 3. Generate forecast with seasonality adjustments
      $forecast = [];
      foreach ($lastDays as $index => $actualDay) {
          $forecastDate = new DateTime($actualDay['date']);
          $dayOfWeek = (int)$forecastDate->format('w');
          
          // Base forecast: level + trend projection
          $baseForecast = max(0, $level + ($index + 1) * $trend);
          
          // Apply day-of-week seasonality multiplier
          $multiplier = isset($dayMultipliers[$dayOfWeek]) ? $dayMultipliers[$dayOfWeek] : 1.0;
          $forecastValue = $baseForecast * $multiplier;
          
          // Ensure non-negative
          $forecastValue = max(0, $forecastValue);
          
          $forecast[] = [
              'date' => $actualDay['date'],
              'revenue' => $forecastValue
          ];
      }
      
      return $forecast;
  }
  
  /**
   * Forecast function for next 3 days (future forecasting)
   * Uses the same exponential smoothing method with seasonality
   * Returns forecast data for the next 3 days from today
   */
  function calculateFutureForecast($daily_trend, $conn, $shop_ids, $forecastDays = 3) {
      if (empty($daily_trend) || count($daily_trend) < 1) {
          return [];
      }
      
      // Use all available daily_trend data as training
      $trainingData = $daily_trend;
      
      // Extract revenue values from training data with dates
      $trainingDataWithDates = [];
      foreach ($trainingData as $day) {
          $date = new DateTime($day['date']);
          $trainingDataWithDates[] = [
              'date' => $day['date'],
              'revenue' => floatval($day['revenue'] ?? 0),
              'day_of_week' => (int)$date->format('w'), // 0 = Sunday, 6 = Saturday
              'day_of_month' => (int)$date->format('j'),
              'is_weekend' => in_array((int)$date->format('w'), [0, 6])
          ];
      }
      
      $trainingRevenues = array_column($trainingDataWithDates, 'revenue');
      
      if (count($trainingRevenues) < 1) {
          return [];
      }
      
      // ==================== EXPONENTIAL SMOOTHING WITH SEASONALITY ====================
      
      // 1. Calculate day-of-week multipliers (seasonality)
      $dayOfWeekTotals = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
      $dayOfWeekCounts = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
      
      foreach ($trainingDataWithDates as $day) {
          $dow = $day['day_of_week'];
          $dayOfWeekTotals[$dow] += $day['revenue'];
          $dayOfWeekCounts[$dow]++;
      }
      
      $dayOfWeekAverages = [];
      $overallAverage = 0;
      foreach ($dayOfWeekTotals as $dow => $total) {
          if ($dayOfWeekCounts[$dow] > 0) {
              $dayOfWeekAverages[$dow] = $total / $dayOfWeekCounts[$dow];
              $overallAverage += $total;
          } else {
              $dayOfWeekAverages[$dow] = 0;
          }
      }
      $totalDays = array_sum($dayOfWeekCounts);
      $overallAverage = $totalDays > 0 ? $overallAverage / $totalDays : 0;
      
      // Calculate multipliers (how much each day differs from average)
      $dayMultipliers = [];
      foreach ($dayOfWeekAverages as $dow => $avg) {
          if ($overallAverage > 0) {
              $dayMultipliers[$dow] = $avg / $overallAverage;
              // Cap multipliers to reasonable range (0.5 to 2.0)
              $dayMultipliers[$dow] = max(0.5, min(2.0, $dayMultipliers[$dow]));
          } else {
              $dayMultipliers[$dow] = 1.0;
          }
      }
      
      // 2. Exponential Smoothing with Trend (Holt's method)
      $alpha = 0.3; // Smoothing parameter for level
      $beta = 0.1;  // Smoothing parameter for trend
      
      // Initialize level and trend
      $n = count($trainingRevenues);
      if ($n < 2) {
          $level = $n > 0 ? $trainingRevenues[0] : 0;
          $trend = 0;
      } else {
          // Use median of first few days for initial level
          $initWindow = min(7, $n);
          $initValues = array_slice($trainingRevenues, 0, $initWindow);
          sort($initValues);
          $level = $initValues[floor(count($initValues) / 2)];
          
          // Calculate initial trend from recent differences
          $trendWindow = min(14, $n);
          $trendDiffs = [];
          for ($i = max(0, $n - $trendWindow); $i < $n - 1; $i++) {
              if ($trainingRevenues[$i + 1] > 0 && $trainingRevenues[$i] > 0) {
                  $trendDiffs[] = $trainingRevenues[$i + 1] - $trainingRevenues[$i];
              }
          }
          $trend = count($trendDiffs) > 0 ? array_sum($trendDiffs) / count($trendDiffs) : 0;
          $trend = $trend * 0.5; // Dampen initial trend
      }
      
      // Apply exponential smoothing to training data
      foreach ($trainingRevenues as $revenue) {
          $prevLevel = $level;
          $level = $alpha * $revenue + (1 - $alpha) * ($level + $trend);
          $trend = $beta * ($level - $prevLevel) + (1 - $beta) * $trend;
      }
      
      // 3. Generate forecast for next N days
      $forecast = [];
      $today = new DateTime();
      $lastDate = !empty($daily_trend) ? new DateTime(end($daily_trend)['date']) : $today;
      
      for ($i = 1; $i <= $forecastDays; $i++) {
          $forecastDate = clone $lastDate;
          $forecastDate->modify("+$i days");
          $dayOfWeek = (int)$forecastDate->format('w');
          
          // Base forecast: level + trend projection
          $baseForecast = max(0, $level + $i * $trend);
          
          // Apply day-of-week seasonality multiplier
          $multiplier = isset($dayMultipliers[$dayOfWeek]) ? $dayMultipliers[$dayOfWeek] : 1.0;
          $forecastValue = $baseForecast * $multiplier;
          
          // Ensure non-negative
          $forecastValue = max(0, $forecastValue);
          
          $forecast[] = [
              'date' => $forecastDate->format('Y-m-d'),
              'revenue' => $forecastValue
          ];
      }
      
      return $forecast;
  }
  

      // Initialize variables
  $overall_stats = $today_stats = $customer_stats = [];
  $daily_trend = $top_products_data = [];
  $growth_metrics = [];
  $inventory_analytics = [];
  $seasonal_trends = [];
  $revenue_growth_percent = 0;
  $forecast_data = [];
  $future_forecast_data = [];
  
  if (empty($shop_ids)) {
      // If distributor has no shops, set all stats to empty
      $overall_stats = ['total_orders' => 0, 'total_revenue' => 0, 'avg_order_value' => 0, 'unique_customers' => 0];
      $today_stats = ['today_orders' => 0, 'today_revenue' => 0];
      $customer_stats = ['total_customers' => 0, 'returning_customers' => 0, 'new_customers' => 0];
      $daily_trend = [];
      $top_products_data = [];
      $growth_metrics = ['revenue_growth' => 0, 'order_growth' => 0];
      $revenue_growth_percent = 0;
      $forecast_data = [];
      $future_forecast_data = [];
  } else {
      $placeholders = implode(',', array_fill(0, count($shop_ids), '?'));
      
      // ==================== OVERALL STATS ====================
      $overall_stats = getOverallStatsMultiShop($conn, $shop_ids, $selected_period);
      $today_stats = [
          'today_orders' => $overall_stats['today_orders'] ?? 0,
          'today_revenue' => $overall_stats['today_revenue'] ?? 0
      ];
      // Remove today stats from overall_stats to keep it clean
      unset($overall_stats['today_orders'], $overall_stats['today_revenue']);
      
      // ==================== DAILY SALES TREND ====================
      $daily_trend = getDailySalesTrendMultiShop($conn, $shop_ids, $start_date, $end_date);
      
      // ==================== FORECAST FOR LAST 30 DAYS (BACKTESTING) ====================
      $forecast_data = calculateForecastLast30Days($daily_trend, $conn, $shop_ids);
      
      // ==================== FORECAST FOR NEXT 3 DAYS (FUTURE) ====================
      $future_forecast_data = calculateFutureForecast($daily_trend, $conn, $shop_ids, 3);
      
      // ==================== TOP SELLING PRODUCTS ====================
      $top_products_data = getTopSellingProductsMultiShop($conn, $shop_ids, $selected_period);
      
      // ==================== CUSTOMER ANALYTICS ====================
      $customer_stats = getCustomerAnalyticsMultiShop($conn, $shop_ids, $selected_period);
      
      // ==================== GROWTH METRICS ====================
      $growth_metrics = getGrowthMetricsMultiShop($conn, $shop_ids, $selected_period);
      $current_revenue = floatval($growth_metrics['current_period']['current_revenue'] ?? 0);
      $previous_revenue = floatval($growth_metrics['previous_period']['previous_revenue'] ?? 0);
      $current_orders = intval($growth_metrics['current_period']['current_orders'] ?? 0);
      $previous_orders = intval($growth_metrics['previous_period']['previous_orders'] ?? 0);
      $revenue_growth_percent = $growth_metrics['revenue_growth'];
      $order_growth = $growth_metrics['order_growth'];
      
      // ==================== INVENTORY ANALYTICS ====================
      $inventory_analytics = getInventoryAnalyticsMultiShop($conn, $shop_ids);
      
      // ==================== SEASONAL TRENDS ====================
      $seasonal_trends = getSeasonalTrendsMultiShop($conn, $shop_ids);
      
      // ==================== TOP PRODUCTS TABLE ====================
      $top_products_table_stmt = $conn->prepare("
          SELECT c.container_name AS name, SUM(oi.quantity) AS total_qty
          FROM order_items oi
          JOIN container c ON oi.container_id = c.container_id
          JOIN shop s ON c.shop_id = s.shop_id
          WHERE s.shop_id IN ($placeholders)
          GROUP BY c.container_name
          ORDER BY total_qty DESC
          LIMIT 5
      ");
      $top_products_table_stmt->execute($shop_ids);
      $top_products = $top_products_table_stmt->fetchAll(PDO::FETCH_ASSOC);
      
      // ==================== MOST ACTIVE CUSTOMERS ====================
      $active_cust_stmt = $conn->prepare("
          SELECT c.full_name AS name, COUNT(o.order_id) AS orders
          FROM orders o
          JOIN consumer c ON o.consumer_id = c.consumer_id
          WHERE o.shop_id IN ($placeholders)
          GROUP BY c.consumer_id
          ORDER BY orders DESC
          LIMIT 5
      ");
      $active_cust_stmt->execute($shop_ids);
      $active_customers = $active_cust_stmt->fetchAll(PDO::FETCH_ASSOC);
      
      // ==================== ORDER STATUS COUNTS ====================
      $delivered_orders = 0;
      $pending_orders = 0;
      $total_orders = intval($overall_stats['total_orders'] ?? 0);
      
      $status_stmt = $conn->prepare("
          SELECT status, COUNT(*) as count
          FROM orders 
          WHERE shop_id IN ($placeholders)
          GROUP BY status
      ");
      $status_stmt->execute($shop_ids);
      $status_counts = $status_stmt->fetchAll(PDO::FETCH_ASSOC);
      foreach ($status_counts as $status) {
          if (strtolower($status['status']) === 'completed') {
              $delivered_orders = intval($status['count']);
          } elseif (strtolower($status['status']) === 'pending') {
              $pending_orders = intval($status['count']);
          }
      }
  }
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8">
    <title>Analytics | Tuy PureFlow Distributor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
      .analytics-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border-left: 4px solid transparent;
        transition: all 0.3s ease;
      }
      .analytics-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
      }
      .analytics-card.blue { border-left-color: #3b82f6; }
      .analytics-card.blue:hover { box-shadow: 0 10px 25px rgba(59, 130, 246, 0.2); }
      .analytics-card.green { border-left-color: #10b981; }
      .analytics-card.green:hover { box-shadow: 0 10px 25px rgba(16, 185, 129, 0.2); }
      .analytics-card.yellow { border-left-color: #f59e0b; }
      .analytics-card.yellow:hover { box-shadow: 0 10px 25px rgba(245, 158, 11, 0.2); }
      .analytics-card.pink { border-left-color: #ec4899; }
      .analytics-card.pink:hover { box-shadow: 0 10px 25px rgba(236, 72, 153, 0.2); }
      .analytics-card.purple { border-left-color: #a855f7; }
      .analytics-card.purple:hover { box-shadow: 0 10px 25px rgba(168, 85, 247, 0.2); }
      .analytics-card.red { border-left-color: #ef4444; }
      .analytics-card.red:hover { box-shadow: 0 10px 25px rgba(239, 68, 68, 0.2); }
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
      .chart-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border-top: 3px solid transparent;
        transition: all 0.3s ease;
      }
      .chart-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
      }
      .chart-card.blue { border-top-color: #3b82f6; }
      .chart-card.green { border-top-color: #10b981; }
      .chart-card.orange { border-top-color: #f59e0b; }
      .chart-card.purple { border-top-color: #a855f7; }
      .chart-card.pink { border-top-color: #ec4899; }
      .chart-card.teal { border-top-color: #14b8a6; }
      .chart-card.indigo { border-top-color: #6366f1; }
      .chart-card.yellow { border-top-color: #eab308; }
      .table-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        transition: all 0.3s ease;
      }
      .table-card:hover {
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
      }
      .table-row {
        transition: all 0.2s ease;
      }
      .table-row:hover {
        background: linear-gradient(90deg, #f8fafc 0%, #ffffff 100%);
        transform: scale(1.01);
      }
      .chart-container {
        position: relative;
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border-radius: 1rem;
        padding: 1.5rem;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        transition: all 0.3s ease;
      }
      .chart-container:hover {
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        transform: translateY(-2px);
      }
      .chart-title {
        font-size: 1.125rem;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
      }
      .chart-title i {
        color: #3b82f6;
        font-size: 1.25rem;
      }
    </style>
  </head>
  <body class="flex bg-gray-100 min-h-screen">
    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="ml-64 flex flex-col flex-1 min-h-screen">
      <!-- Header -->
      <?php include 'header.php'; ?>

      <main class="flex-1 p-6 overflow-x-auto bg-gradient-to-br from-gray-50 to-blue-50">
        <div class="mb-8">
          <h1 class="text-4xl font-bold gradient-text mb-2 flex items-center gap-3">
            <i class="fas fa-chart-line text-blue-600"></i>
            Analytics Dashboard
          </h1>
          <p class="text-gray-600">Comprehensive analytics and insights</p>
        </div>

        <?php if (empty($shop_ids)): ?>
          <div class="bg-white p-8 rounded-xl shadow-sm border border-gray-200 text-center fade-in">
            <div class="w-16 h-16 mx-auto mb-3 rounded-full bg-gray-100 flex items-center justify-center">
              <i class="fas fa-chart-line text-3xl text-gray-400"></i>
            </div>
            <h2 class="text-lg font-semibold text-gray-700 mb-2">No Data Available</h2>
            <p class="text-gray-500 text-sm">You currently don't have any registered shops or sales activity yet.</p>
          </div>
        <?php else: ?>
          <!-- Sales Trend Calendar Filter -->
          <form class="mb-6 flex items-center justify-center gap-4" method="get" id="salesTrendFilterForm">
            <input type="hidden" name="page" value="analytics">
            <label class="font-semibold text-gray-700">Start at:</label>
            <input type="date" name="start_date" value="<?= htmlspecialchars($_GET['start_date'] ?? date('Y-m-01')) ?>" class="border rounded px-3 py-2">
            <label class="font-semibold text-gray-700">End at:</label>
            <input type="date" name="end_date" value="<?= htmlspecialchars($_GET['end_date'] ?? date('Y-m-d')) ?>" class="border rounded px-3 py-2">
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Filter</button>
          </form>
          
          <!-- Main Metrics -->
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200 fade-in" id="stat-total-revenue">
              <div class="flex items-center gap-3 mb-2">
                <i class="fas fa-money-bag text-green-600 text-xl"></i>
                <p class="text-sm font-semibold text-blue-600">Total Revenue</p>
              </div>
              <p class="text-2xl font-bold text-green-600" id="stat-revenue-value">₱<?= number_format($overall_stats['total_revenue'] ?? 0, 2) ?></p>
              <p class="text-xs text-gray-500 mt-1">From <?= htmlspecialchars($start_date) ?> to <?= htmlspecialchars($end_date) ?></p>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200 fade-in" id="stat-total-orders">
              <div class="flex items-center gap-3 mb-2">
                <i class="fas fa-shopping-cart text-blue-600 text-xl"></i>
                <p class="text-sm font-semibold text-blue-600">Total Orders</p>
              </div>
              <p class="text-2xl font-bold text-blue-600" id="stat-orders-value"><?= number_format($overall_stats['total_orders'] ?? 0) ?></p>
              <p class="text-xs text-gray-500 mt-1">From <?= htmlspecialchars($start_date) ?> to <?= htmlspecialchars($end_date) ?></p>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200 fade-in" id="stat-today-revenue">
              <div class="flex items-center gap-3 mb-2">
                <i class="fas fa-calendar-day text-orange-600 text-xl"></i>
                <p class="text-sm font-semibold text-blue-600">Today's Revenue</p>
              </div>
              <p class="text-2xl font-bold text-orange-600" id="stat-today-value">₱<?= number_format($today_stats['today_revenue'] ?? 0, 2) ?></p>
              <p class="text-xs text-gray-500 mt-1">Today</p>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200 fade-in" id="stat-avg-order">
              <div class="flex items-center gap-3 mb-2">
                <i class="fas fa-chart-line text-purple-600 text-xl"></i>
                <p class="text-sm font-semibold text-blue-600">Avg Order Value</p>
              </div>
              <p class="text-2xl font-bold text-purple-600" id="stat-avg-value">₱<?= number_format($overall_stats['avg_order_value'] ?? 0, 2) ?></p>
              <p class="text-xs text-gray-500 mt-1">Per order</p>
            </div>
          </div>
          
          <!-- Growth Analysis -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200 fade-in">
              <div class="flex items-center gap-3 mb-2">
                <i class="fas fa-chart-line text-blue-600 text-xl"></i>
                <p class="text-sm font-semibold text-blue-600">Revenue Growth</p>
              </div>
              <p class="text-2xl font-bold text-blue-600">₱<?= number_format($current_revenue, 2) ?></p>
              <div class="flex items-center gap-2 mt-2">
                <i class="fas fa-arrow-<?= $revenue_growth_percent >= 0 ? 'up' : 'down' ?> text-<?= $revenue_growth_percent >= 0 ? 'green' : 'red' ?>-600"></i>
                <p class="text-sm font-semibold <?= $revenue_growth_percent >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                  <?= $revenue_growth_percent >= 0 ? '+' : '' ?><?= number_format($revenue_growth_percent, 1) ?>%
                </p>
              </div>
              <p class="text-xs text-gray-500 mt-1">vs previous period</p>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200 fade-in">
              <div class="flex items-center gap-3 mb-2">
                <i class="fas fa-shopping-bag text-blue-600 text-xl"></i>
                <p class="text-sm font-semibold text-blue-600">Order Growth</p>
              </div>
              <p class="text-2xl font-bold text-blue-600"><?= number_format($current_orders) ?></p>
              <div class="flex items-center gap-2 mt-2">
                <i class="fas fa-arrow-<?= $order_growth >= 0 ? 'up' : 'down' ?> text-<?= $order_growth >= 0 ? 'green' : 'red' ?>-600"></i>
                <p class="text-sm font-semibold <?= $order_growth >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                  <?= $order_growth >= 0 ? '+' : '' ?><?= number_format($order_growth, 1) ?>%
                </p>
              </div>
              <p class="text-xs text-gray-500 mt-1">vs previous <?= $selected_period ?> days</p>
            </div>
          </div>
          
          <!-- Daily Sales Trend Chart -->
          <div class="mb-6 chart-container fade-in">
            <h2 class="chart-title">
              <i class="fas fa-chart-line"></i>
              Daily Sales Trend
            </h2>
            <div style="height: 400px; position: relative;">
              <canvas id="dailyTrendChart"></canvas>
            </div>
          </div>

          <!-- Top Products Table -->
          <div class="table-card bg-white p-4 rounded-xl shadow-sm border border-gray-200 mb-6 fade-in">
            <h2 class="text-lg font-semibold mb-4 flex items-center gap-2 text-gray-800">
              <div class="w-8 h-8 rounded-lg bg-green-100 flex items-center justify-center">
                <i class="fas fa-box text-green-600 text-sm"></i>
              </div>
              Top-Selling Containers
            </h2>
            <?php if (empty($top_products)): ?>
              <div class="text-center py-8">
                <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-gray-100 flex items-center justify-center">
                  <i class="fas fa-box text-xl text-gray-400"></i>
                </div>
                <p class="text-gray-500 text-sm">No sales data available.</p>
              </div>
            <?php else: ?>
              <div class="overflow-x-auto">
                <table class="w-full table-auto">
                  <thead class="bg-gradient-to-r from-green-500 to-green-600 text-white">
                    <tr>
                      <th class="px-4 py-3 text-left font-semibold">Container</th>
                      <th class="px-4 py-3 text-left font-semibold">Total Ordered</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($top_products as $prod): ?>
                      <tr class="table-row border-b">
                        <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($prod['name']) ?></td>
                        <td class="px-4 py-3">
                          <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-green-100 text-green-700 font-semibold">
                            <i class="fas fa-shopping-cart text-sm"></i>
                            <?= htmlspecialchars($prod['total_qty']) ?>
                          </span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <!-- Most Active Customers Table -->
          <div class="table-card bg-white p-4 rounded-xl shadow-sm border border-gray-200 mb-6 fade-in">
            <h2 class="text-lg font-semibold mb-4 flex items-center gap-2 text-gray-800">
              <div class="w-8 h-8 rounded-lg bg-pink-100 flex items-center justify-center">
                <i class="fas fa-users text-pink-600 text-sm"></i>
              </div>
              Most Active Customers
            </h2>
            <?php if (empty($active_customers)): ?>
              <div class="text-center py-8">
                <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-gray-100 flex items-center justify-center">
                  <i class="fas fa-users text-xl text-gray-400"></i>
                </div>
                <p class="text-gray-500 text-sm">No customer data available.</p>
              </div>
            <?php else: ?>
              <div class="overflow-x-auto">
                <table class="w-full table-auto">
                  <thead class="bg-gradient-to-r from-pink-500 to-pink-600 text-white">
                    <tr>
                      <th class="px-4 py-3 text-left font-semibold">Customer</th>
                      <th class="px-4 py-3 text-left font-semibold">Orders</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($active_customers as $cust): ?>
                      <tr class="table-row border-b">
                        <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($cust['name']) ?></td>
                        <td class="px-4 py-3">
                          <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-pink-100 text-pink-700 font-semibold">
                            <i class="fas fa-shopping-bag text-sm"></i>
                            <?= htmlspecialchars($cust['orders']) ?>
                          </span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </main>
    </div>

    <!-- Chart.js Scripts -->
    <script>
      const distributorId = <?= $distributor_id ?>;
      let currentPeriod = <?= $selected_period ?>;
      let dailyTrendChart = null;
      let topProductsChart = null;
      let orderStatusChart = null;
      let revenueGrowthChart = null;
      
      // Initialize on page load
      document.addEventListener('DOMContentLoaded', function() {
        initializeCharts();
      });
      
      function changePeriod(days) {
        currentPeriod = days;
        const url = new URL(window.location.href);
        url.searchParams.set('period', currentPeriod);
        window.location.href = url.toString();
      }
      
      function initializeCharts() {
        // 1. Daily Sales Trend Chart with Forecast for Last 30 Days + Next 3 Days
        const dailyTrendCtx = document.getElementById('dailyTrendChart');
        if (dailyTrendCtx) {
          const dailyData = <?= json_encode($daily_trend ?? []) ?>;
          const forecastData = <?= json_encode($forecast_data ?? []) ?>;
          const futureForecastData = <?= json_encode($future_forecast_data ?? []) ?>;
          
          // Prepare labels and data arrays
          const allLabels = [];
          const actualRevenues = [];
          const forecastRevenues = [];
          const futureForecastRevenues = [];
          
          // Create a map of forecast data by date for quick lookup
          const forecastMap = {};
          if (forecastData && forecastData.length > 0) {
            forecastData.forEach(f => {
              forecastMap[f.date] = parseFloat(f.revenue || 0);
            });
          }
          
          // Create a map of future forecast data by date
          const futureForecastMap = {};
          if (futureForecastData && futureForecastData.length > 0) {
            futureForecastData.forEach(f => {
              futureForecastMap[f.date] = parseFloat(f.revenue || 0);
            });
          }
          
          // Get the forecast period dates for matching
          const forecastDaysCount = forecastData.length;
          const forecastStartIndex = forecastDaysCount > 0 ? dailyData.length - forecastDaysCount : -1;
          
          // Process all historical data
          dailyData.forEach((d, index) => {
            const date = new Date(d.date);
            allLabels.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
            
            // Actual revenue - show for all days
            actualRevenues.push(parseFloat(d.revenue || 0));
            
            // Forecast revenue - show for forecast period and one point before for connection
            const isForecastPeriod = index >= forecastStartIndex && forecastStartIndex >= 0;
            const isBeforeForecast = index === forecastStartIndex - 1 && forecastStartIndex > 0;
            
            if ((isForecastPeriod || isBeforeForecast) && Object.keys(forecastMap).length > 0) {
              if (isBeforeForecast) {
                // Set the point just before the forecast period to the actual value for smooth connection
                forecastRevenues.push(parseFloat(d.revenue || 0));
              } else {
                // Find matching forecast for this date
                const forecastValue = forecastMap[d.date];
                forecastRevenues.push(forecastValue !== undefined ? forecastValue : null);
              }
            } else {
              forecastRevenues.push(null);
            }
            
            // Future forecast - null for historical data
            futureForecastRevenues.push(null);
          });
          
          // Add future forecast dates and data
          if (futureForecastData && futureForecastData.length > 0) {
            // Get the last actual revenue to connect from
            const lastActualRevenue = dailyData.length > 0 ? parseFloat(dailyData[dailyData.length - 1].revenue || 0) : 0;
            
            // Set connection point: update the last point in futureForecastRevenues to connect from actual
            if (futureForecastRevenues.length > 0) {
              futureForecastRevenues[futureForecastRevenues.length - 1] = lastActualRevenue;
            }
            
            // Add future forecast dates
            futureForecastData.forEach((f) => {
              const date = new Date(f.date);
              allLabels.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
              
              // Actual revenue - null for future dates
              actualRevenues.push(null);
              
              // Historical forecast - null for future dates
              forecastRevenues.push(null);
              
              // Future forecast - add value
              futureForecastRevenues.push(parseFloat(f.revenue || 0));
            });
          }
          
          dailyTrendChart = new Chart(dailyTrendCtx, {
            type: 'line',
            data: {
              labels: allLabels,
              datasets: [
                {
                  label: 'Daily Revenue (₱)',
                  data: actualRevenues,
                  borderColor: '#3b82f6',
                  backgroundColor: 'rgba(59, 130, 246, 0.15)',
                  fill: true,
                  tension: 0.4,
                  pointRadius: 4,
                  pointHoverRadius: 7,
                  pointBackgroundColor: '#3b82f6',
                  pointBorderColor: '#ffffff',
                  pointBorderWidth: 2,
                  pointHoverBackgroundColor: '#2563eb',
                  pointHoverBorderColor: '#ffffff',
                  pointHoverBorderWidth: 3,
                  borderWidth: 3,
                  spanGaps: false,
                  cubicInterpolationMode: 'monotone'
                },
                {
                  label: 'Forecast Last 30 Days (₱)',
                  data: forecastRevenues,
                  borderColor: '#ef4444',
                  backgroundColor: 'rgba(239, 68, 68, 0.08)',
                  fill: false,
                  tension: 0.4,
                  pointRadius: 5,
                  pointHoverRadius: 8,
                  borderWidth: 3,
                  spanGaps: true,
                  pointBackgroundColor: '#ef4444',
                  pointBorderColor: '#ffffff',
                  pointBorderWidth: 2,
                  pointHoverBackgroundColor: '#dc2626',
                  pointHoverBorderColor: '#ffffff',
                  pointHoverBorderWidth: 3,
                  cubicInterpolationMode: 'monotone'
                },
                {
                  label: 'Next 3 Days Forecast (₱)',
                  data: futureForecastRevenues,
                  borderColor: '#f59e0b',
                  backgroundColor: 'rgba(245, 158, 11, 0.08)',
                  fill: false,
                  tension: 0.4,
                  pointRadius: 6,
                  pointHoverRadius: 9,
                  borderWidth: 3,
                  spanGaps: true,
                  pointBackgroundColor: '#f59e0b',
                  pointBorderColor: '#ffffff',
                  pointBorderWidth: 2,
                  pointHoverBackgroundColor: '#d97706',
                  pointHoverBorderColor: '#ffffff',
                  pointHoverBorderWidth: 3,
                  cubicInterpolationMode: 'monotone',
                  borderDash: [0]
                }
              ]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              interaction: {
                intersect: false,
                mode: 'index'
              },
              animation: {
                duration: 1500,
                easing: 'easeInOutQuart'
              },
              plugins: {
                legend: {
                  display: true,
                  position: 'top',
                  align: 'end',
                  labels: {
                    usePointStyle: true,
                    padding: 20,
                    font: {
                      size: 13,
                      weight: '600',
                      family: "'Inter', 'Segoe UI', system-ui, sans-serif"
                    },
                    color: '#374151',
                    boxWidth: 12,
                    boxHeight: 12
                  }
                },
                tooltip: {
                  backgroundColor: 'rgba(17, 24, 39, 0.95)',
                  padding: 12,
                  titleFont: {
                    size: 14,
                    weight: '600',
                    family: "'Inter', 'Segoe UI', system-ui, sans-serif"
                  },
                  bodyFont: {
                    size: 13,
                    weight: '500',
                    family: "'Inter', 'Segoe UI', system-ui, sans-serif"
                  },
                  titleColor: '#f9fafb',
                  bodyColor: '#f9fafb',
                  borderColor: 'rgba(255, 255, 255, 0.1)',
                  borderWidth: 1,
                  cornerRadius: 8,
                  displayColors: true,
                  callbacks: {
                    label: function(context) {
                      let label = context.dataset.label || '';
                      if (label) {
                        label += ': ';
                      }
                      const value = context.parsed.y;
                      if (value >= 1000000) {
                        label += '₱' + (value / 1000000).toFixed(2) + 'M';
                      } else if (value >= 1000) {
                        label += '₱' + (value / 1000).toFixed(2) + 'K';
                      } else {
                        label += '₱' + value.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                      }
                      return label;
                    }
                  }
                }
              },
              scales: {
                x: {
                  grid: {
                    display: true,
                    color: 'rgba(229, 231, 235, 0.5)',
                    lineWidth: 1,
                    drawBorder: false
                  },
                  ticks: {
                    color: '#6b7280',
                    font: {
                      size: 11,
                      family: "'Inter', 'Segoe UI', system-ui, sans-serif"
                    },
                    maxRotation: 45,
                    minRotation: 45,
                    padding: 8
                  },
                  border: {
                    display: false
                  }
                },
                y: {
                  beginAtZero: true,
                  grid: {
                    display: true,
                    color: 'rgba(229, 231, 235, 0.5)',
                    lineWidth: 1,
                    drawBorder: false
                  },
                  ticks: {
                    color: '#6b7280',
                    font: {
                      size: 11,
                      weight: '500',
                      family: "'Inter', 'Segoe UI', system-ui, sans-serif"
                    },
                    padding: 12,
                    callback: function(value) {
                      if (value >= 1000000) {
                        return '₱' + (value / 1000000).toFixed(1) + 'M';
                      } else if (value >= 1000) {
                        return '₱' + (value / 1000).toFixed(1) + 'K';
                      }
                      return '₱' + value.toLocaleString();
                    }
                  },
                  border: {
                    display: false
                  }
                }
              }
            }
          });
        }

        // 2. Top Products Sold (Bar Chart)
        const topProductsCtx = document.getElementById('topProductsChart');
        if (topProductsCtx) {
          const productData = <?= json_encode($top_products_data ?? []) ?>;
          const labels = productData.map(p => p.container_name || 'Unknown');
          const quantities = productData.map(p => parseInt(p.total_quantity || 0));
          
          topProductsChart = new Chart(topProductsCtx, {
            type: 'bar',
            data: {
              labels: labels,
              datasets: [{
                label: 'Units Sold',
                data: quantities,
                backgroundColor: '#22c55e'
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: {
                  display: false
                }
              },
              scales: {
                y: {
                  beginAtZero: true
                }
              }
            }
          });
        }

        // 3. Order Status Distribution (Doughnut Chart)
        const orderStatusCtx = document.getElementById('orderStatusChart');
        if (orderStatusCtx) {
          const delivered = <?= intval($delivered_orders) ?>;
          const pending = <?= intval($pending_orders) ?>;
          const total = <?= intval($total_orders) ?>;
          const cancelled = total - delivered - pending;
          
          orderStatusChart = new Chart(orderStatusCtx, {
            type: 'doughnut',
            data: {
              labels: ['Completed', 'Pending', 'Cancelled'],
              datasets: [{
                data: [delivered, pending, cancelled],
                backgroundColor: ['#22c55e', '#fbbf24', '#ef4444']
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: {
                  position: 'bottom'
                }
              }
            }
          });
        }

        // 4. Revenue Growth Comparison (Bar Chart)
        const revenueGrowthCtx = document.getElementById('revenueGrowthChart');
        if (revenueGrowthCtx) {
          <?php
          $current_rev = isset($growth_metrics['current_period']['current_revenue']) ? floatval($growth_metrics['current_period']['current_revenue']) : 0;
          $prev_rev = isset($growth_metrics['previous_period']['previous_revenue']) ? floatval($growth_metrics['previous_period']['previous_revenue']) : 0;
          ?>
          const current = <?= $current_rev ?>;
          const previous = <?= $prev_rev ?>;
          
          revenueGrowthChart = new Chart(revenueGrowthCtx, {
            type: 'bar',
            data: {
              labels: ['Previous Period', 'Current Period'],
              datasets: [{
                label: 'Revenue (₱)',
                data: [previous, current],
                backgroundColor: ['#ef4444', '#22c55e']
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: {
                  display: false
                }
              },
              scales: {
                y: {
                  beginAtZero: true,
                  ticks: {
                    callback: function(value) {
                      if (value >= 1000000) {
                        return '₱' + (value / 1000000).toFixed(1) + 'M';
                      } else if (value >= 1000) {
                        return '₱' + (value / 1000).toFixed(1) + 'K';
                      }
                      return '₱' + value.toLocaleString();
                    }
                  }
                }
              }
            }
          });
        }
      }
    </script>
  </body>
  </html>
