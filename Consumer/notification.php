<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include '../db.php';

if (!isset($_SESSION['consumer_id'])) {
    header("Location: ../index.html");
    exit;
}

$recipient_id = $_SESSION['consumer_id'];

// Mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE notifications SET status = 'Read' WHERE recipient_id = ? AND recipient_type = 'Consumer'");
    $stmt->execute([$recipient_id]);
    header("Location: notification.php");
    exit;
}

// Mark single notification as read (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $notification_id = intval($_POST['mark_read']);
    $stmt = $conn->prepare("UPDATE notifications SET status = 'Read' WHERE notification_id = ? AND recipient_id = ? AND recipient_type = 'Consumer'");
    $stmt->execute([$notification_id, $recipient_id]);
    echo json_encode(['success' => true]);
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
$stmt = $conn->prepare("SELECT notification_id, recipient_id, message, status, created_at, recipient_type FROM notifications WHERE recipient_id = ? AND recipient_type = 'Consumer' ORDER BY created_at DESC");
$stmt->execute([$recipient_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Default icon for notifications
$default_icon = '🔔';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notifications - Tuy PureFlow</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-50 font-sans">
    <!-- Header -->
    <header class="header-gradient sticky top-0 z-50">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <a href="landing_page.php" class="brand-link flex items-center gap-2 text-xl">
                <img src="../images/logo.png" alt="Tuy PureFlow Logo" class="h-10 w-auto">
                <span>Tuy PureFlow</span>
            </a>
            <div class="flex items-center gap-4">
                <?php
                  // Fetch consumer profile picture
                  $consumer_profile = null;
                  $profile_stmt = $conn->prepare("SELECT profile_pic FROM consumer WHERE consumer_id = ? LIMIT 1");
                  $profile_stmt->execute([$recipient_id]);
                  $profile_data = $profile_stmt->fetch(PDO::FETCH_ASSOC);
                  if ($profile_data && isset($profile_data['profile_pic'])) {
                      $profile_blob = $profile_data['profile_pic'];
                      
                      // Check if it's NULL or empty
                      if ($profile_blob === null || $profile_blob === '') {
                          $consumer_profile = '../images/default-profile.jpg';
                      } else {
                          // Handle both string and binary data
                          if (is_resource($profile_blob)) {
                              $profile_blob = stream_get_contents($profile_blob);
                          }
                          
                          // Ensure it's a string and has content
                          if (is_string($profile_blob) && strlen($profile_blob) > 10) {
                              // Detect image type from magic bytes
                              $imgType = 'png'; // default
                              $firstBytes = substr($profile_blob, 0, 12);
                              
                              if (substr($firstBytes, 0, 2) === "\xFF\xD8") {
                                  $imgType = 'jpeg';
                              } elseif (substr($firstBytes, 0, 4) === "\x89PNG") {
                                  $imgType = 'png';
                              } elseif (substr($firstBytes, 0, 4) === "RIFF" && substr($firstBytes, 8, 4) === "WEBP") {
                                  $imgType = 'webp';
                              }
                              
                              $consumer_profile = 'data:image/' . $imgType . ';base64,' . base64_encode($profile_blob);
                          } else {
                              $consumer_profile = '../images/default-profile.jpg';
                          }
                      }
                  } else {
                      $consumer_profile = '../images/default-profile.jpg';
                  }
                ?>
                <a href="account.php" class="flex items-center gap-2 text-white font-semibold hover:underline px-3 py-2 rounded-lg hover:bg-white hover:bg-opacity-20 transition-all">
                  <img src="<?= htmlspecialchars($consumer_profile) ?>" alt="Profile" class="w-8 h-8 rounded-full object-cover border-2 border-white border-opacity-30">
                  <span><?= htmlspecialchars($_SESSION['consumer_name'] ?? 'User') ?></span>
                </a>
                <a href="logout.php" class="text-white hover:bg-white hover:bg-opacity-20 px-4 py-2 rounded-lg transition-all font-medium">Logout</a>
            </div>
        </div>
    </header>
    <main class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold mb-2 text-gradient">Notifications</h1>
        <p class="text-gray-600 mb-6">Stay updated with your orders</p>
        <div class="grid md:grid-cols-4 gap-6">
            <!-- Sidebar -->
            <aside class="card md:col-span-1">
                <nav class="space-y-2">
                    <a href="account.php" class="block px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                        <i class="fas fa-user-circle mr-2"></i>Account Info
                    </a>
                    <a href="my_purchases.php" class="block px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                        <i class="fas fa-shopping-bag mr-2"></i>My Purchases
                    </a>
                    <a href="notification.php" class="block px-4 py-3 rounded-lg bg-gradient-to-r from-cyan-400 to-blue-600 text-white font-semibold">
                        <i class="fas fa-bell mr-2"></i>Notifications
                    </a>
                    <a href="logout.php" class="block px-4 py-3 rounded-lg text-red-600 hover:bg-red-50 transition-colors">
                        <i class="fas fa-sign-out-alt mr-2"></i>Logout
                    </a>
                </nav>
            </aside>
            <!-- Content -->
            <section class="md:col-span-3">
                <?php if (count($notifications) > 0): ?>
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-lg font-semibold text-gray-800">All Notifications</h3>
                        <form method="post" class="inline">
                            <button type="submit" name="mark_all_read" class="bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-600 hover:to-blue-600 text-white px-6 py-2.5 rounded-lg font-semibold shadow-md hover:shadow-lg transition-all flex items-center gap-2">
                                <i class="fas fa-check-double"></i> Mark all as read
                            </button>
                        </form>
                    </div>
                    <div class="space-y-3">
                        <?php foreach ($notifications as $notif): ?>
                            <div class="flex items-start gap-4 p-5 rounded-xl shadow-md border-2 transition-all cursor-pointer hover:shadow-lg notification-item <?= ($notif['status'] === 'Read') ? 'bg-white border-gray-200' : 'bg-cyan-50 border-cyan-300' ?>" 
                                 data-notification-id="<?= $notif['notification_id'] ?>"
                                 onclick="markAsRead(<?= $notif['notification_id'] ?>)">
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-bell text-white text-lg"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="font-bold text-gray-800">Notification</span>
                                        <?php if ($notif['status'] !== 'Read'): ?>
                                            <span class="inline-block w-2.5 h-2.5 bg-cyan-500 rounded-full animate-pulse"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-gray-700 mb-2 leading-relaxed"><?= htmlspecialchars($notif['message']) ?></div>
                                    <div class="flex items-center gap-2 text-xs text-gray-500">
                                        <i class="fas fa-clock"></i>
                                        <span><?= timeAgo($notif['created_at']) ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="flex flex-col items-center justify-center py-16 bg-white rounded-xl border border-gray-200">
                        <div class="w-20 h-20 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center mb-4">
                            <i class="fas fa-bell text-white text-3xl"></i>
                        </div>
                        <div class="text-xl text-gray-700 font-semibold mb-2">No notifications yet</div>
                        <div class="text-sm text-gray-500">You'll see your notifications here when they arrive</div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
    <!-- Footer -->
    <footer class="mt-16 bg-gray-900 text-white py-12">
      <div class="container mx-auto px-4">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8">
          <div class="md:col-span-2">
            <h3 class="text-xl font-bold mb-4 flex items-center gap-2">
              <i class="fas fa-tint text-cyan-400"></i>
              Tuy PureFlow
            </h3>
            <p class="text-gray-400 text-sm mb-4">Your trusted source for clean, pure water. Delivered fresh to your doorstep.</p>
            <div class="flex gap-4">
              <a href="#" class="w-10 h-10 rounded-full bg-gray-800 hover:bg-cyan-500 flex items-center justify-center transition-colors">
                <i class="fab fa-facebook-f"></i>
              </a>
              <a href="#" class="w-10 h-10 rounded-full bg-gray-800 hover:bg-cyan-500 flex items-center justify-center transition-colors">
                <i class="fab fa-twitter"></i>
              </a>
              <a href="#" class="w-10 h-10 rounded-full bg-gray-800 hover:bg-cyan-500 flex items-center justify-center transition-colors">
                <i class="fab fa-instagram"></i>
              </a>
            </div>
          </div>
          <div>
            <h4 class="font-semibold mb-4">Quick Links</h4>
            <ul class="space-y-2 text-sm text-gray-400">
              <li><a href="landing_page.php" class="hover:text-cyan-400 transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-xs"></i>Browse Shops</a></li>
              <li><a href="account.php" class="hover:text-cyan-400 transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-xs"></i>My Account</a></li>
              <li><a href="my_purchases.php" class="hover:text-cyan-400 transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-xs"></i>My Orders</a></li>
              <li><a href="cart.php" class="hover:text-cyan-400 transition-colors flex items-center gap-2"><i class="fas fa-chevron-right text-xs"></i>Shopping Cart</a></li>
            </ul>
          </div>
          <div>
            <h4 class="font-semibold mb-4">Contact Us</h4>
            <ul class="space-y-3 text-sm text-gray-400">
              <li class="flex items-center gap-2">
                <i class="fas fa-envelope text-cyan-400"></i>
                <span>support@tuypureflow.com</span>
              </li>
              <li class="flex items-center gap-2">
                <i class="fas fa-phone text-cyan-400"></i>
                <span>+63 XXX XXX XXXX</span>
              </li>
              <li class="flex items-start gap-2">
                <i class="fas fa-map-marker-alt text-cyan-400 mt-1"></i>
                <span>Tuy, Batangas, Philippines</span>
              </li>
            </ul>
          </div>
        </div>
        <div class="border-t border-gray-800 pt-8">
          <div class="flex flex-col md:flex-row justify-between items-center gap-4">
            <p class="text-sm text-gray-400">&copy; <?= date('Y') ?> Tuy PureFlow. All rights reserved.</p>
            <div class="flex gap-6 text-sm text-gray-400">
              <a href="#" class="hover:text-cyan-400 transition-colors">Privacy Policy</a>
              <a href="#" class="hover:text-cyan-400 transition-colors">Terms of Service</a>
              <a href="#" class="hover:text-cyan-400 transition-colors">About Us</a>
            </div>
          </div>
        </div>
      </div>
    </footer>
    <script>
    // Mark notification as read on click
    function markAsRead(notificationId) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'notification.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                // Update the notification item visually
                var item = document.querySelector('[data-notification-id="' + notificationId + '"]');
                if (item) {
                    item.classList.remove('bg-cyan-50', 'border-cyan-300');
                    item.classList.add('bg-white', 'border-gray-200');
                    var dot = item.querySelector('.animate-pulse');
                    if (dot) {
                        dot.remove();
                    }
                }
            }
        };
        xhr.send('mark_read=' + notificationId);
    }
    
    // Auto-refresh notifications every 30 seconds
    setInterval(function() {
        if (document.visibilityState === 'visible') {
            location.reload();
        }
    }, 30000);
    </script>
</body>
</html>