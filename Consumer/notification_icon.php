<?php
// notification_icon.php
// Blue notification icon + popup for logged-in consumer

// AJAX endpoint for notifications - MUST BE FIRST to prevent HTML output
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Check if user is logged in
    if (!isset($_SESSION['consumer_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not logged in']);
        exit;
    }
    
    try {
        include '../db.php';
        $recipient_id = $_SESSION['consumer_id'];
        
        function timeAgo($datetime) {
            if (empty($datetime)) return 'Just now';
            $timestamp = strtotime($datetime);
            if (!$timestamp) return 'Just now';
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
        
        $stmt = $conn->prepare("SELECT notification_id, message, status, created_at FROM notifications WHERE recipient_id = ? AND recipient_type = 'Consumer' ORDER BY created_at DESC LIMIT 20");
        $stmt->execute([$recipient_id]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($notifications as $n) {
            $result[] = [
                'notification_id' => isset($n['notification_id']) ? intval($n['notification_id']) : 0,
                'message' => isset($n['message']) ? $n['message'] : '',
                'status' => isset($n['status']) ? $n['status'] : 'Unread',
                'time_ago' => timeAgo(isset($n['created_at']) ? $n['created_at'] : null)
            ];
        }
        
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        exit;
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database connection error']);
        exit;
    }
}

// Regular display mode - check session and show icon
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['consumer_id'])) return;
?>
<!-- Notification Icon -->
<div class="relative">
  <button id="notifBtn" class="relative p-2 rounded-full hover:bg-white hover:bg-opacity-20 transition-all focus:outline-none" title="Notifications">
    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
    </svg>
    <span id="notifBadge" class="hidden absolute -top-1 -right-1 bg-red-500 text-white text-xs w-5 h-5 flex items-center justify-center rounded-full font-bold shadow-lg"></span>
  </button>
  <div id="notifPopup" class="hidden absolute right-0 mt-2 w-96 bg-white shadow-2xl rounded-xl z-50 p-5 border border-gray-200" style="max-height:500px;overflow-y:auto;min-width:380px;"></div>
</div>
<script>
// Helper function to escape HTML
function escapeHtml(text) {
  var map = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  };
  return text ? text.replace(/[&<>"']/g, function(m) { return map[m]; }) : '';
}

document.addEventListener('DOMContentLoaded', function() {
  var notifBtn = document.getElementById('notifBtn');
  var notifPopup = document.getElementById('notifPopup');
  var notifBadge = document.getElementById('notifBadge');
  
  // Load unread count and update badge
  function updateBadge() {
    if (!notifBadge) return;
    
    fetch('notification_icon.php?ajax=1')
      .then(res => {
        if (!res.ok) {
          throw new Error('Network response was not ok');
        }
        return res.json();
      })
      .then(data => {
        if (data && Array.isArray(data)) {
          var unreadCount = data.filter(n => {
            var status = (n.status || '').toLowerCase();
            return status === 'unread' || (status !== 'read' && status !== 'read');
          }).length;
          if (unreadCount > 0) {
            notifBadge.textContent = unreadCount > 9 ? '9+' : unreadCount;
            notifBadge.classList.remove('hidden');
          } else {
            notifBadge.classList.add('hidden');
          }
        }
      })
      .catch(err => {
        console.error('Error loading notification count:', err);
        // Don't show error to user for badge update
      });
  }
  
  // Update badge on load
  updateBadge();
  
  if (notifBtn && notifPopup) {
    notifBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      e.preventDefault();
      
      if (notifPopup.classList.contains('hidden')) {
        // Show loading state
        notifPopup.innerHTML = '<div class="flex items-center justify-center h-32"><div class="text-blue-600"><i class="fas fa-spinner fa-spin text-2xl"></i></div></div>';
        notifPopup.classList.remove('hidden');
        
        fetch('notification_icon.php?ajax=1')
          .then(res => {
            if (!res.ok) {
              throw new Error('Network response was not ok: ' + res.status);
            }
            return res.json();
          })
          .then(data => {
            // Check if response has error
            if (data && data.error) {
              throw new Error(data.error);
            }
            
            // Ensure data is an array
            if (!Array.isArray(data)) {
              data = [];
            }
            
            let html = '';
            if (data && data.length > 0) {
              html += '<div class="mb-4 flex justify-between items-center border-b pb-3">' +
                '<h3 class="text-lg font-semibold text-gray-800">All Notifications</h3>' +
                '<a href="notification.php" class="text-xs text-blue-600 hover:text-blue-800 font-medium">View All</a>' +
                '</div>';
              
              html += '<div class="space-y-3 max-h-96 overflow-y-auto">';
              data.forEach(n => {
                const status = (n.status || 'Unread').toLowerCase();
                const isUnread = status === 'unread';
                html += `<div class="flex items-start gap-4 p-4 rounded-xl shadow-md border-2 transition-all cursor-pointer hover:shadow-lg notification-item ${isUnread ? 'bg-cyan-50 border-cyan-300' : 'bg-white border-gray-200'}" ` +
                  `data-notification-id="${n.notification_id || ''}" onclick="markNotificationAsRead(${n.notification_id || 0}, this)">` +
                  `<div class="w-12 h-12 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center flex-shrink-0">` +
                  `<i class="fas fa-bell text-white text-lg"></i>` +
                  `</div>` +
                  `<div class="flex-1 min-w-0">` +
                  `<div class="flex items-center gap-2 mb-2">` +
                  `<span class="font-bold text-gray-800">Notification</span>` +
                  (isUnread ? `<span class="inline-block w-2.5 h-2.5 bg-cyan-500 rounded-full animate-pulse"></span>` : '') +
                  `</div>` +
                  `<div class="text-gray-700 mb-2 leading-relaxed text-sm">${escapeHtml(n.message || 'No message')}</div>` +
                  `<div class="flex items-center gap-2 text-xs text-gray-500">` +
                  `<i class="fas fa-clock"></i>` +
                  `<span>${escapeHtml(n.time_ago || 'Just now')}</span>` +
                  `</div>` +
                  `</div>` +
                  `</div>`;
              });
              html += '</div>';
            } else {
              html = '<div class="flex flex-col items-center justify-center py-12">' +
                '<div class="w-16 h-16 rounded-full bg-gradient-to-br from-cyan-400 to-blue-500 flex items-center justify-center mb-4">' +
                '<i class="fas fa-bell text-white text-2xl"></i>' +
                '</div>' +
                '<div class="text-lg text-gray-700 font-semibold mb-2">No notifications yet</div>' +
                '<div class="text-sm text-gray-500">You\'ll see your notifications here when they arrive</div>' +
                '</div>';
            }
            notifPopup.innerHTML = html;
            // Update badge after opening (in case notifications were read)
            updateBadge();
          })
          .catch(err => {
            console.error('Error fetching notifications:', err);
            notifPopup.innerHTML = '<div class="flex flex-col items-center justify-center py-12 text-red-600">' +
              '<div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center mb-4">' +
              '<i class="fas fa-exclamation-triangle text-2xl"></i>' +
              '</div>' +
              '<div class="text-lg font-semibold mb-2">Error loading notifications</div>' +
              '<div class="text-sm text-gray-600 mb-4">' + escapeHtml(err.message || 'Please try again later') + '</div>' +
              '<button onclick="location.reload()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">Retry</button>' +
              '</div>';
          });
      } else {
        notifPopup.classList.add('hidden');
      }
    });
    document.addEventListener('click', function(e) {
      if (!notifBtn.contains(e.target) && !notifPopup.contains(e.target)) {
        notifPopup.classList.add('hidden');
      }
    });
  }
  
  // Mark notification as read
  window.markNotificationAsRead = function(notificationId, element) {
    if (!notificationId || notificationId === 0) return;
    
    // Update visually immediately
    if (element) {
      element.classList.remove('bg-cyan-50', 'border-cyan-300');
      element.classList.add('bg-white', 'border-gray-200');
      var dot = element.querySelector('.animate-pulse');
      if (dot) {
        dot.remove();
      }
    }
    
    // Send AJAX request to mark as read
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'notification.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.send('mark_read=' + notificationId);
    
    // Update badge count
    if (notifBadge) {
      updateBadge();
    }
  };
});
</script>
