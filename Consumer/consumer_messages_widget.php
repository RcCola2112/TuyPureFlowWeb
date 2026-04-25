<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
if (!isset($_SESSION['consumer_id'])) {
    exit;
}
$consumer_id = $_SESSION['consumer_id'];

// Database connection (assuming $conn is your PDO connection)
$shopContacts = [];
$currentShop = null;

// Check if we're on a shop page and get current shop info
if (isset($_GET['shop_id']) && intval($_GET['shop_id']) > 0) {
    $current_shop_id = intval($_GET['shop_id']);
    $currentShopStmt = $conn->prepare("SELECT shop_id, name, distributor_id FROM shop WHERE shop_id = ?");
    $currentShopStmt->execute([$current_shop_id]);
    $currentShop = $currentShopStmt->fetch(PDO::FETCH_ASSOC);
    if ($currentShop) {
        // Add current shop to contacts (at the beginning so it's prioritized)
        $shopContacts[$currentShop['shop_id']] = [
            'shop_id' => $currentShop['shop_id'],
            'name' => $currentShop['name'],
            'distributor_id' => $currentShop['distributor_id']
        ];
    }
}

// Shops the user has ordered from
$orderStmt = $conn->prepare("SELECT DISTINCT s.shop_id, s.name, s.distributor_id FROM shop s JOIN orders o ON s.shop_id = o.shop_id WHERE o.consumer_id = ?");
$orderStmt->execute([$consumer_id]);
while ($row = $orderStmt->fetch(PDO::FETCH_ASSOC)) {
    // Don't overwrite current shop if it's already added
    if (!isset($shopContacts[$row['shop_id']])) {
        $shopContacts[$row['shop_id']] = [
            'shop_id' => $row['shop_id'],
            'name' => $row['name'],
            'distributor_id' => $row['distributor_id']
        ];
    }
}
// Shops the user has messaged
$msgStmt = $conn->prepare("SELECT DISTINCT s.shop_id, s.name, s.distributor_id FROM shop s JOIN distributor d ON s.distributor_id = d.distributor_id JOIN messages m ON m.distributor_id = d.distributor_id AND m.consumer_id = ?");
$msgStmt->execute([$consumer_id]);
while ($row = $msgStmt->fetch(PDO::FETCH_ASSOC)) {
    // Don't overwrite current shop if it's already added
    if (!isset($shopContacts[$row['shop_id']])) {
        $shopContacts[$row['shop_id']] = [
            'shop_id' => $row['shop_id'],
            'name' => $row['name'],
            'distributor_id' => $row['distributor_id']
        ];
    }
}
?>
<link rel="stylesheet" href="https://cdn.tailwindcss.com">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<style>
  #messageWidget {
    font-family: 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Roboto', sans-serif;
    border-radius: 16px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    width: 420px;
    height: 650px;
    right: 24px;
    bottom: 24px;
    position: fixed;
    background: #fff;
    display: flex;
    flex-direction: column;
    z-index: 9999;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  }
  #messageWidget.translate-x-full { 
    transform: translateX(calc(100% + 24px)); 
  }
  
  .msg-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    height: 100%;
    background: #ffffff;
  }
  
  .msg-header {
    background: linear-gradient(135deg, #3FE0E8 0%, #3578C9 100%);
    color: #fff;
    padding: 18px 20px;
    font-weight: 700;
    font-size: 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  }
  
  .msg-header button {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: #ffffff;
    font-size: 24px;
    line-height: 1;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
  }
  
  .msg-header button:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: scale(1.1);
  }
  
  .msg-chat {
    flex: 1;
    padding: 20px;
    background: #f9fafb;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 12px;
  }
  
  .msg-chat::-webkit-scrollbar {
    width: 6px;
  }
  
  .msg-chat::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
  }
  
  .msg-chat::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
  }
  
  .msg-bubble {
    max-width: 75%;
    padding: 12px 16px;
    border-radius: 18px;
    font-size: 15px;
    line-height: 1.5;
    background: #e0f2fe;
    color: #0369a1;
    align-self: flex-start;
    position: relative;
    word-wrap: break-word;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
  }
  
  .msg-bubble.me {
    background: linear-gradient(135deg, #3FE0E8 0%, #3578C9 100%);
    color: #fff;
    align-self: flex-end;
  }
  
  .msg-meta {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.8);
    margin-top: 6px;
    text-align: right;
    font-weight: 400;
  }
  
  .msg-bubble:not(.me) .msg-meta {
    color: #64748b;
  }
  
  .msg-input {
    display: flex;
    gap: 10px;
    padding: 16px 20px;
    background: #fff;
    border-top: 1px solid #e5e7eb;
    align-items: center;
  }
  
  .msg-input input {
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 10px 16px;
    font-size: 15px;
    flex: 1;
    transition: all 0.2s ease;
    font-family: inherit;
  }
  
  .msg-input input:focus {
    outline: none;
    border-color: #3FE0E8;
    box-shadow: 0 0 0 3px rgba(63, 224, 232, 0.1);
  }
  
  .msg-input button {
    background: linear-gradient(135deg, #3FE0E8 0%, #3578C9 100%);
    color: #fff;
    border-radius: 12px;
    padding: 10px 24px;
    font-weight: 600;
    font-size: 15px;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 4px 6px rgba(53, 120, 201, 0.3);
    font-family: inherit;
  }
  
  .msg-input button:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 12px rgba(53, 120, 201, 0.4);
  }
  
  .msg-input button:active {
    transform: translateY(0);
  }
  
  #messageToggleBtn {
    background: linear-gradient(135deg, #3FE0E8 0%, #3578C9 100%);
    color: #fff;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    box-shadow: 0 8px 16px rgba(53, 120, 201, 0.4);
    border: none;
    cursor: pointer;
    font-size: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    z-index: 9998;
  }
  
  #messageToggleBtn:hover {
    transform: scale(1.1);
    box-shadow: 0 12px 24px rgba(53, 120, 201, 0.5);
  }
  
  .empty-state {
    text-align: center;
    color: #94a3b8;
    font-size: 14px;
    padding: 40px 20px;
  }
</style>
<div id="messageWidget" class="translate-x-full">
  <div class="msg-main">
    <div class="msg-header">
      <span id="chatTitle">Select a shop</span>
      <button onclick="toggleMessages()" type="button" aria-label="Close">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div id="messages" class="msg-chat">
      <div class="empty-state">
        <i class="fas fa-comments" style="font-size: 32px; margin-bottom: 12px; opacity: 0.5;"></i>
        <p>Select a shop to start messaging</p>
      </div>
    </div>
    <form id="sendForm" class="msg-input">
      <input type="hidden" name="consumer_id" value="<?= $consumer_id ?>">
      <input type="hidden" name="distributor_id" id="distributor_id" required>
      <input type="text" name="message" placeholder="Type a message..." required autocomplete="off">
      <button type="submit">
        <i class="fas fa-paper-plane mr-2"></i>Send
      </button>
    </form>
  </div>
</div>
<button id="messageToggleBtn" onclick="toggleMessages()" class="fixed bottom-6 right-6" aria-label="Toggle messages">
  <i class="fas fa-comments"></i>
</button>
<script>
  function toggleMessages() {
    const widget = document.getElementById('messageWidget');
    const btn = document.getElementById('messageToggleBtn');
    widget.classList.toggle('translate-x-full');
    // Hide button when widget is open
    if (widget.classList.contains('translate-x-full')) {
      btn.style.display = 'flex';
    } else {
      btn.style.display = 'none';
    }
  }
  
  function fetchMessages() {
    const distributor_id = $('#distributor_id').val();
    if (!distributor_id) {
      $('#messages').html('<div class="empty-state"><i class="fas fa-comments" style="font-size: 32px; margin-bottom: 12px; opacity: 0.5;"></i><p>Select a shop to start messaging</p></div>');
      return;
    }
    $.get('../messages/fetch_messages.php', {
      consumer_id: <?= $consumer_id ?>,
      distributor_id: distributor_id
    }, function(data) {
      let html = '';
      try {
        const res = JSON.parse(data);
        if (res.success) {
          if (res.messages.length === 0) {
            html = '<div class="empty-state"><i class="fas fa-comments" style="font-size: 32px; margin-bottom: 12px; opacity: 0.5;"></i><p>No messages yet. Start the conversation!</p></div>';
          } else {
            res.messages.forEach(msg => {
              const isMe = msg.sender_type === 'consumer' && msg.consumer_id == <?= $consumer_id ?>;
              const escapedMsg = $('<div>').text(msg.message).html();
              html += `<div class='msg-bubble${isMe ? " me" : ""}'>${escapedMsg}<div class='msg-meta'>${msg.sent_at}</div></div>`;
            });
          }
        } else {
          html = '<div class="empty-state" style="color: #ef4444;"><i class="fas fa-exclamation-circle" style="font-size: 32px; margin-bottom: 12px;"></i><p>' + (res.error || 'Error loading messages') + '</p></div>';
        }
      } catch(e) { 
        html = '<div class="empty-state" style="color: #ef4444;"><i class="fas fa-exclamation-circle" style="font-size: 32px; margin-bottom: 12px;"></i><p>Error loading messages.</p></div>'; 
      }
      $('#messages').html(html);
      // Scroll to bottom
      const chat = document.getElementById('messages');
      if (chat) chat.scrollTop = chat.scrollHeight;
    });
  }
  
  $(function() {
    // Auto-select shop - prioritize current shop if on shop page, otherwise first available
    <?php if (!empty($shopContacts)): ?>
      <?php if ($currentShop): ?>
        // Use current shop from URL
        const currentShop = <?= json_encode($currentShop) ?>;
        $('#chatTitle').text(currentShop.name);
        $('#distributor_id').val(currentShop.distributor_id);
      <?php else: ?>
        // Use first shop from contacts
        const firstShop = <?= json_encode(reset($shopContacts)) ?>;
        $('#chatTitle').text(firstShop.name);
        $('#distributor_id').val(firstShop.distributor_id);
      <?php endif; ?>
      fetchMessages();
      // Auto-refresh messages every 3 seconds
      if (window.messageInterval) clearInterval(window.messageInterval);
      window.messageInterval = setInterval(fetchMessages, 3000);
    <?php else: ?>
      // Disable form if no shops available
      $('#sendForm input[name="message"]').prop('disabled', true).attr('placeholder', 'No shops available');
      $('#sendForm button').prop('disabled', true);
    <?php endif; ?>
    
    // Send message
    $('#sendForm').on('submit', function(e) {
      e.preventDefault();
      const distributorId = $('#distributor_id').val();
      const messageInput = $("input[name='message']");
      const message = messageInput.val().trim();
      
      // Validate fields
      if (!distributorId) {
        alert('Please select a shop first.');
        return;
      }
      
      if (!message) {
        messageInput.focus();
        return;
      }
      
      // Disable form during submission
      const submitBtn = $(this).find('button[type="submit"]');
      const originalText = submitBtn.html();
      submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Sending...');
      
      $.post('../messages/send_message.php', {
        consumer_id: <?= $consumer_id ?>,
        distributor_id: distributorId,
        message: message,
        sender_type: 'consumer'
      }, function(data) {
        try {
          const res = typeof data === 'string' ? JSON.parse(data) : data;
          if (res.success) {
            messageInput.val('');
            fetchMessages();
          } else {
            alert('Error: ' + (res.error || 'Failed to send message'));
          }
        } catch(e) {
          console.error('Parse error:', e, 'Data:', data);
          alert('Error sending message. Please try again.');
        } finally {
          submitBtn.prop('disabled', false).html(originalText);
        }
      }).fail(function(xhr, status, error) {
        console.error('AJAX error:', status, error);
        alert('Network error. Please check your connection and try again.');
        submitBtn.prop('disabled', false).html(originalText);
      });
    });
    
    // Enter key to send
    $("input[name='message']").on('keypress', function(e) {
      if (e.which === 13 && !e.shiftKey) {
        e.preventDefault();
        $('#sendForm').submit();
      }
    });
  });
</script>
