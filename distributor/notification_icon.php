<?php
// notification_icon.php
// Notification icon + popup for logged-in distributor
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['distributor_id'])) return;

include '../db.php';
$distributor_id = $_SESSION['distributor_id'];

// AJAX endpoint for notifications
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    $stmt = $conn->prepare("SELECT notification_id, message, status, created_at FROM notifications WHERE recipient_id = ? AND recipient_type = 'Didstributor' ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$distributor_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    
    $result = [];
    foreach ($notifications as $n) {
        $result[] = [
            'notification_id' => $n['notification_id'],
            'message' => $n['message'],
            'status' => $n['status'],
            'time_ago' => timeAgo($n['created_at'])
        ];
    }
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Get unread count for badge
$stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE recipient_id = ? AND recipient_type = 'Didstributor' AND status = 'Unread'");
$stmt->execute([$distributor_id]);
$unread = $stmt->fetch();
$unreadCount = $unread['unread_count'] ?? 0;
?>
<!-- Notification Icon -->
<div class="relative">
  <button id="notifBtn" class="relative focus:outline-none" title="Notifications">
    <svg class="w-6 h-6 text-gray-600 hover:text-blue-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 00-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
    </svg>
    <?php if ($unreadCount > 0): ?>
      <span class="absolute -top-1 -right-1 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-white bg-red-600 rounded-full min-w-[18px]">
        <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
      </span>
    <?php endif; ?>
  </button>
  <div id="notifPopup" class="hidden absolute right-0 mt-2 w-80 bg-white shadow-xl rounded-lg z-50 border border-gray-200" style="max-height:400px;overflow-y:auto;">
    <div class="p-4 border-b border-gray-200 flex justify-between items-center bg-blue-50">
      <div class="font-bold text-gray-800">Notifications</div>
      <a href="notification.php" class="text-xs text-blue-600 hover:text-blue-800 font-semibold">View All</a>
    </div>
    <div id="notifContent" class="p-2">
      <div class="flex items-center justify-center h-32">
        <div class="text-gray-400">Loading...</div>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var notifBtn = document.getElementById('notifBtn');
  var notifPopup = document.getElementById('notifPopup');
  var notifContent = document.getElementById('notifContent');
  
  if (notifBtn && notifPopup && notifContent) {
    notifBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      if (notifPopup.classList.contains('hidden')) {
        // Fetch notifications
        fetch('notification_icon.php?ajax=1')
          .then(res => res.json())
          .then(data => {
            let html = '';
            if (data.length > 0) {
              data.forEach(n => {
                const bgClass = n.status === 'Read' ? 'bg-white' : 'bg-blue-50';
                const borderClass = n.status === 'Read' ? 'border-gray-200' : 'border-blue-200';
                html += `<div class="flex items-start gap-3 p-3 rounded-lg border mb-2 ${bgClass} ${borderClass}">
                  <div class="text-2xl text-blue-600 flex-shrink-0">🔔</div>
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                      <span class="font-semibold text-sm text-gray-800">Notification</span>
                      ${n.status !== 'Read' ? '<span class="inline-block w-1.5 h-1.5 bg-blue-500 rounded-full"></span>' : ''}
                    </div>
                    <div class="text-sm text-gray-700 mb-1 break-words">${n.message}</div>
                    <div class="text-xs text-gray-500">${n.time_ago}</div>
                  </div>
                </div>`;
              });
            } else {
              html = '<div class="flex flex-col items-center justify-center h-32"><div class="text-4xl text-blue-600 mb-2">🔔</div><div class="text-sm text-gray-500 font-medium">No notifications yet.</div></div>';
            }
            notifContent.innerHTML = html;
            notifPopup.classList.remove('hidden');
          })
          .catch(err => {
            console.error('Error fetching notifications:', err);
            notifContent.innerHTML = '<div class="flex items-center justify-center h-32 text-red-500">Error loading notifications</div>';
            notifPopup.classList.remove('hidden');
          });
      } else {
        notifPopup.classList.add('hidden');
      }
    });
    
    // Close popup when clicking outside
    document.addEventListener('click', function(e) {
      if (!notifBtn.contains(e.target) && !notifPopup.contains(e.target)) {
        notifPopup.classList.add('hidden');
      }
    });
  }
});
</script>

