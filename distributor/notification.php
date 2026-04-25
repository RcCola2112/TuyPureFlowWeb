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
$currentPage = 'notification';

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

// Mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE notifications SET status = 'Read' WHERE recipient_id = ? AND recipient_type = 'Didstributor'");
    $stmt->execute([$distributor_id]);
    header("Location: notification.php");
    exit;
}

// Mark single notification as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $notification_id = intval($_POST['notification_id']);
    $stmt = $conn->prepare("UPDATE notifications SET status = 'Read' WHERE notification_id = ? AND recipient_id = ? AND recipient_type = 'Didstributor'");
    $stmt->execute([$notification_id, $distributor_id]);
    header("Location: notification.php");
    exit;
}

// Time ago function
function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    if ($diff < 60) return $diff . " seconds ago";
    $diff = floor($diff / 60);
    if ($diff < 60) return $diff . " minutes ago";
    $diff = floor($diff / 60);
    if ($diff < 24) return $diff . " hours ago";
    $diff = floor($diff / 24);
    if ($diff == 1) return "Yesterday";
    if ($diff < 7) return $diff . " days ago";
    if ($diff < 30) return floor($diff / 7) . " weeks ago";
    if ($diff < 365) return floor($diff / 30) . " months ago";
    return floor($diff / 365) . " years ago";
}

// Fetch notifications
$stmt = $conn->prepare("SELECT notification_id, recipient_id, message, status, created_at, recipient_type FROM notifications WHERE recipient_id = ? AND recipient_type = 'Didstributor' ORDER BY created_at DESC");
$stmt->execute([$distributor_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread notifications
$unreadCount = 0;
foreach ($notifications as $notif) {
    if ($notif['status'] !== 'Read') {
        $unreadCount++;
    }
}

// Default icon for notifications
$default_icon = '🔔';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Notifications | Tuy PureFlow Distributor</title>
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
          <i class="fas fa-bell text-blue-600"></i>
          Notifications
        </h1>
        <p class="text-gray-600">View and manage your notifications</p>
      </div>

      <!-- Notifications Section -->
      <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200 fade-in">
        <?php if (count($notifications) > 0): ?>
          <div class="flex justify-between items-center mb-6">
            <div class="flex items-center gap-3">
              <span class="text-lg font-semibold text-gray-700">Total Notifications: <?= count($notifications) ?></span>
              <?php if ($unreadCount > 0): ?>
                <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-sm font-semibold">
                  <?= $unreadCount ?> unread
                </span>
              <?php endif; ?>
            </div>
            <form method="post" class="inline">
              <button type="submit" name="mark_all_read" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold">
                <i class="fas fa-check-double mr-2"></i>Mark all as read
              </button>
            </form>
          </div>
          <div class="space-y-4">
            <?php foreach ($notifications as $notif): ?>
              <div class="flex items-start gap-4 p-4 rounded-lg border transition-all
                  <?= ($notif['status'] === 'Read') ? 'bg-white border-gray-200' : 'bg-blue-50 border-blue-200' ?>">
                <div class="text-3xl flex-shrink-0"><?= $default_icon ?></div>
                <div class="flex-1">
                  <div class="flex items-center gap-2 mb-2">
                    <span class="font-bold text-lg text-gray-800">Notification</span>
                    <?php if ($notif['status'] !== 'Read'): ?>
                      <span class="inline-block w-2 h-2 bg-blue-500 rounded-full"></span>
                      <span class="text-xs text-blue-600 font-semibold">NEW</span>
                    <?php endif; ?>
                  </div>
                  <div class="text-gray-700 mb-2"><?= htmlspecialchars($notif['message']) ?></div>
                  <div class="flex items-center justify-between">
                    <div class="text-xs text-gray-500">
                      <i class="far fa-clock mr-1"></i><?= timeAgo($notif['created_at']) ?>
                    </div>
                    <?php if ($notif['status'] !== 'Read'): ?>
                      <form method="post" class="inline">
                        <input type="hidden" name="notification_id" value="<?= $notif['notification_id'] ?>">
                        <button type="submit" name="mark_read" class="text-xs text-blue-600 hover:text-blue-800 font-semibold">
                          <i class="fas fa-check mr-1"></i>Mark as read
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="flex flex-col items-center justify-center h-64">
            <div class="text-6xl mb-4 text-blue-600">🔔</div>
            <div class="text-lg text-gray-500 font-medium mb-2">No notifications yet.</div>
            <div class="text-sm text-gray-400">You'll see notifications here when there are updates.</div>
          </div>
        <?php endif; ?>
      </div>

    </main>
  </div>
</body>
</html>

