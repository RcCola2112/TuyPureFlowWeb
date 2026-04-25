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
$currentPage = 'delivery_riders';

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

// Handle rider status update (approve/reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    include_once '../includes/send_notification.php';
    
    $rider_id = intval($_POST['rider_id']);
    $action = $_POST['action']; // 'approve' or 'reject'
    
    if ($action === 'approve') {
        $new_status = 'Approved';
    } elseif ($action === 'reject') {
        $new_status = 'Rejected';
    } else {
        header('Location: delivery_riders.php');
        exit;
    }
    
    // Update rider status
    $stmt = $conn->prepare("UPDATE rider SET status = ? WHERE rider_id = ? AND distributor_id = ?");
    $stmt->execute([$new_status, $rider_id, $distributor_id]);
    
    // Send notification to rider
    try {
        $stmtRider = $conn->prepare("SELECT name, email FROM rider WHERE rider_id = ?");
        $stmtRider->execute([$rider_id]);
        $rider = $stmtRider->fetch();
        
        if ($rider && function_exists('sendNotification')) {
            $msg = 'Your rider application has been ' . strtolower($new_status) . '.';
            sendNotification($conn, $rider_id, 'rider', $msg, 'Rider Application ' . $new_status);
        }
    } catch (Exception $e) {
        // Notification error - non-critical, but log it
        error_log("Notification error for rider {$rider_id}: " . $e->getMessage());
    }
    
    header('Location: delivery_riders.php');
    exit;
}

// Handle account status update (active/inactive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account_status'])) {
    include_once '../includes/send_notification.php';
    
    $rider_id = intval($_POST['rider_id']);
    $is_active = $_POST['is_active']; // 'Active' or 'Inactive'
    
    // Get rider info before update
    $stmtRider = $conn->prepare("SELECT name, email, is_active FROM rider WHERE rider_id = ? AND distributor_id = ?");
    $stmtRider->execute([$rider_id, $distributor_id]);
    $rider = $stmtRider->fetch();
    
    if ($rider) {
        $old_status = $rider['is_active'];
        
        // Update account status
        $stmt = $conn->prepare("UPDATE rider SET is_active = ? WHERE rider_id = ? AND distributor_id = ?");
        $stmt->execute([$is_active, $rider_id, $distributor_id]);
        
        // Send notification if status changed
        if ($old_status !== $is_active && function_exists('sendNotification')) {
            try {
                $msg = 'Your account status has been changed to ' . strtolower($is_active) . '.';
                sendNotification($conn, $rider_id, 'rider', $msg, 'Account Status Updated');
            } catch (Exception $e) {
                error_log("Notification error for rider {$rider_id}: " . $e->getMessage());
            }
        }
    }
    
    header('Location: delivery_riders.php');
    exit;
}

// Get search query
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get rider counts
$countStmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected_count
    FROM rider 
    WHERE distributor_id = ?
");
$countStmt->execute([$distributor_id]);
$counts = $countStmt->fetch();
$pending_count = $counts['pending_count'] ?? 0;
$approved_count = $counts['approved_count'] ?? 0;
$rejected_count = $counts['rejected_count'] ?? 0;

// Fetch riders based on search and status
$whereClause = "WHERE distributor_id = ?";
$params = [$distributor_id];

if (!empty($search_query)) {
    $whereClause .= " AND (
        name LIKE ? OR 
        email LIKE ? OR 
        phone LIKE ? OR 
        vehicle_type LIKE ? OR 
        plate_number LIKE ?
    )";
    $searchParam = "%{$search_query}%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}

// Fetch all riders
$stmt = $conn->prepare("
    SELECT rider_id, name, email, phone, vehicle_type, plate_number, status, is_active, created_at
    FROM rider 
    $whereClause
    ORDER BY 
        CASE status 
            WHEN 'Pending' THEN 1 
            WHEN 'Approved' THEN 2 
            WHEN 'Rejected' THEN 3 
        END,
        created_at DESC
");
$stmt->execute($params);
$all_riders = $stmt->fetchAll();

// Separate riders by status
$pending_riders = array_filter($all_riders, function($r) { return $r['status'] === 'Pending'; });
$approved_riders = array_filter($all_riders, function($r) { return $r['status'] === 'Approved'; });
$rejected_riders = array_filter($all_riders, function($r) { return $r['status'] === 'Rejected'; });
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Delivery Riders | Tuy PureFlow Distributor</title>
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
    .rider-card {
      transition: all 0.2s ease;
    }
    .rider-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
          <i class="fas fa-motorcycle text-blue-600"></i>
          Delivery Riders
        </h1>
        <p class="text-gray-600">Manage and approve delivery riders for your shop</p>
      </div>

      <!-- Rider Management Section -->
      <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200 mb-6 fade-in">
        <h2 class="text-xl font-semibold text-blue-600 mb-4">Rider Management</h2>
        <p class="text-gray-600 mb-6">Manage and approve delivery riders for your shop.</p>
        
        <!-- Status Cards -->
        <div class="grid grid-cols-3 gap-4 mb-6">
          <div class="bg-white border-2 border-yellow-200 rounded-lg p-4 text-center shadow-sm">
            <div class="text-4xl font-bold text-yellow-600 mb-2"><?= $pending_count ?></div>
            <div class="text-sm font-semibold text-gray-700">Pending</div>
          </div>
          <div class="bg-white border-2 border-green-200 rounded-lg p-4 text-center shadow-sm">
            <div class="text-4xl font-bold text-green-600 mb-2"><?= $approved_count ?></div>
            <div class="text-sm font-semibold text-gray-700">Approved</div>
          </div>
          <div class="bg-white border-2 border-red-200 rounded-lg p-4 text-center shadow-sm">
            <div class="text-4xl font-bold text-red-600 mb-2"><?= $rejected_count ?></div>
            <div class="text-sm font-semibold text-gray-700">Rejected</div>
          </div>
        </div>

        <!-- Search Bar -->
        <form method="GET" class="mb-6">
          <div class="relative">
            <input 
              type="text" 
              name="search" 
              value="<?= htmlspecialchars($search_query) ?>"
              placeholder="Search by name, email, phone, vehicle..." 
              class="w-full px-4 py-3 pl-12 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            >
            <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
            <?php if (!empty($search_query)): ?>
              <a href="delivery_riders.php" class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
              </a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- Pending Riders Section -->
      <?php if (!empty($pending_riders)): ?>
        <div class="mb-6 fade-in">
          <h3 class="text-lg font-semibold text-blue-600 mb-4">Pending Riders (<?= count($pending_riders) ?>)</h3>
          <div class="space-y-4">
            <?php foreach ($pending_riders as $rider): ?>
              <div class="rider-card bg-white rounded-xl p-5 shadow-sm border border-gray-200">
                <div class="flex items-start justify-between">
                  <div class="flex-1">
                    <div class="flex items-center gap-3 mb-3">
                      <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                        <i class="fas fa-user text-blue-600 text-xl"></i>
                      </div>
                      <div>
                        <h4 class="font-bold text-lg text-gray-800"><?= htmlspecialchars($rider['name']) ?></h4>
                        <span class="inline-block px-3 py-1 rounded-full bg-yellow-100 text-yellow-700 text-xs font-semibold mt-1">
                          Pending
                        </span>
                      </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4 mt-4">
                      <div class="flex items-center gap-2 text-gray-700">
                        <i class="fas fa-envelope text-gray-400"></i>
                        <span class="text-sm"><?= htmlspecialchars($rider['email']) ?></span>
                      </div>
                      <div class="flex items-center gap-2 text-gray-700">
                        <i class="fas fa-phone text-gray-400"></i>
                        <span class="text-sm"><?= htmlspecialchars($rider['phone']) ?></span>
                      </div>
                      <div class="flex items-center gap-2 text-gray-700">
                        <i class="fas fa-motorcycle text-gray-400"></i>
                        <span class="text-sm"><?= htmlspecialchars($rider['vehicle_type']) ?></span>
                      </div>
                      <div class="flex items-center gap-2 text-gray-700">
                        <i class="fas fa-id-card text-gray-400"></i>
                        <span class="text-sm"><?= htmlspecialchars($rider['plate_number']) ?></span>
                      </div>
                    </div>
                  </div>
                  <div class="flex gap-2 ml-4">
                    <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to approve this rider?');">
                      <input type="hidden" name="rider_id" value="<?= $rider['rider_id'] ?>">
                      <input type="hidden" name="action" value="approve">
                      <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-semibold">
                        <i class="fas fa-check mr-2"></i>Approve
                      </button>
                    </form>
                    <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to reject this rider?');">
                      <input type="hidden" name="rider_id" value="<?= $rider['rider_id'] ?>">
                      <input type="hidden" name="action" value="reject">
                      <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-semibold">
                        <i class="fas fa-times mr-2"></i>Reject
                      </button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Approved Riders Section -->
      <?php if (!empty($approved_riders)): ?>
        <div class="mb-6 fade-in">
          <h3 class="text-lg font-semibold text-blue-600 mb-4">Approved Riders (<?= count($approved_riders) ?>)</h3>
          <div class="space-y-4">
            <?php foreach ($approved_riders as $rider): ?>
              <div class="rider-card bg-white rounded-xl p-5 shadow-sm border border-gray-200">
                <div class="flex items-start justify-between">
                  <div class="flex-1">
                    <div class="flex items-center gap-3 mb-3">
                      <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                        <i class="fas fa-user text-blue-600 text-xl"></i>
                      </div>
                      <div>
                        <h4 class="font-bold text-lg text-gray-800"><?= htmlspecialchars($rider['name']) ?></h4>
                        <span class="inline-block px-3 py-1 rounded-full bg-green-100 text-green-700 text-xs font-semibold mt-1">
                          Approved
                        </span>
                      </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4 mt-4">
                      <div class="flex items-center gap-2 text-gray-700">
                        <i class="fas fa-envelope text-gray-400"></i>
                        <span class="text-sm"><?= htmlspecialchars($rider['email']) ?></span>
                      </div>
                      <div class="flex items-center gap-2 text-gray-700">
                        <i class="fas fa-phone text-gray-400"></i>
                        <span class="text-sm"><?= htmlspecialchars($rider['phone']) ?></span>
                      </div>
                      <div class="flex items-center gap-2 text-gray-700">
                        <i class="fas fa-motorcycle text-gray-400"></i>
                        <span class="text-sm"><?= htmlspecialchars($rider['vehicle_type']) ?></span>
                      </div>
                      <div class="flex items-center gap-2 text-gray-700">
                        <i class="fas fa-id-card text-gray-400"></i>
                        <span class="text-sm"><?= htmlspecialchars($rider['plate_number']) ?></span>
                      </div>
                    </div>
                    <div class="mt-4">
                      <div class="flex items-center gap-2 mb-2">
                        <i class="fas fa-user-circle text-gray-400"></i>
                        <span class="text-sm font-semibold text-gray-700">Account:</span>
                        <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $rider['is_active'] === 'Active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                          <?= htmlspecialchars($rider['is_active']) ?>
                        </span>
                      </div>
                      <form method="POST" class="mt-2">
                        <input type="hidden" name="rider_id" value="<?= $rider['rider_id'] ?>">
                        <div class="flex items-center gap-2">
                          <label class="text-sm font-semibold text-gray-700">Account Status:</label>
                          <select name="is_active" onchange="this.form.submit()" class="px-3 py-1.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <option value="Active" <?= $rider['is_active'] === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $rider['is_active'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                          </select>
                          <input type="hidden" name="update_account_status" value="1">
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Rejected Riders Section -->
      <?php if (!empty($rejected_riders)): ?>
        <div class="mb-6 fade-in">
          <h3 class="text-lg font-semibold text-blue-600 mb-4">Rejected Riders (<?= count($rejected_riders) ?>)</h3>
          <div class="space-y-4">
            <?php foreach ($rejected_riders as $rider): ?>
              <div class="rider-card bg-white rounded-xl p-5 shadow-sm border border-gray-200 opacity-75">
                <div class="flex items-start justify-between">
                  <div class="flex-1">
                    <div class="flex items-center gap-3 mb-3">
                      <div class="w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center">
                        <i class="fas fa-user text-gray-400 text-xl"></i>
                      </div>
                      <div>
                        <h4 class="font-bold text-lg text-gray-600"><?= htmlspecialchars($rider['name']) ?></h4>
                        <span class="inline-block px-3 py-1 rounded-full bg-red-100 text-red-700 text-xs font-semibold mt-1">
                          Rejected
                        </span>
                      </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4 mt-4">
                      <div class="flex items-center gap-2 text-gray-600">
                        <i class="fas fa-envelope text-gray-400"></i>
                        <span class="text-sm"><?= htmlspecialchars($rider['email']) ?></span>
                      </div>
                      <div class="flex items-center gap-2 text-gray-600">
                        <i class="fas fa-phone text-gray-400"></i>
                        <span class="text-sm"><?= htmlspecialchars($rider['phone']) ?></span>
                      </div>
                      <div class="flex items-center gap-2 text-gray-600">
                        <i class="fas fa-motorcycle text-gray-400"></i>
                        <span class="text-sm"><?= htmlspecialchars($rider['vehicle_type']) ?></span>
                      </div>
                      <div class="flex items-center gap-2 text-gray-600">
                        <i class="fas fa-id-card text-gray-400"></i>
                        <span class="text-sm"><?= htmlspecialchars($rider['plate_number']) ?></span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Empty State -->
      <?php if (empty($all_riders)): ?>
        <div class="bg-white rounded-xl p-12 text-center shadow-sm border border-gray-200 fade-in">
          <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
            <i class="fas fa-motorcycle text-4xl text-gray-400"></i>
          </div>
          <p class="text-lg font-semibold text-gray-700 mb-2">No riders found</p>
          <p class="text-sm text-gray-500">
            <?php if (!empty($search_query)): ?>
              No riders match your search criteria.
            <?php else: ?>
              No riders have registered yet.
            <?php endif; ?>
          </p>
        </div>
      <?php endif; ?>

    </main>
  </div>
</body>
</html>

