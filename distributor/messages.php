<?php
session_start();
if (!isset($_SESSION['distributor_id'])) {
    echo '<script>window.location.replace("../index.html");</script>';
    exit;
}
$distributor_id = $_SESSION['distributor_id'];
$currentPage = 'messages';
include '../db.php';

// Fetch distributor info for header
$stmt = $conn->prepare("SELECT * FROM distributor WHERE distributor_id = ?");
$stmt->execute([$distributor_id]);
$distributor = $stmt->fetch();

$username = $distributor['name'] ?? '';
$stmtShop = $conn->prepare("SELECT name FROM shop WHERE distributor_id = ? LIMIT 1");
$stmtShop->execute([$distributor_id]);
$shopname = $stmtShop->fetchColumn() ?: '';
$profilePic = isset($distributor['profile_pic']) && $distributor['profile_pic'] ? $distributor['profile_pic'] : "images/profile.jpg";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Messages | Tuy PureFlow Distributor</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
    .message-row {
      transition: all 0.2s ease;
      border-left: 3px solid transparent;
    }
    .message-row:hover {
      background: linear-gradient(90deg, #eff6ff 0%, #ffffff 100%);
      border-left-color: #3b82f6;
      transform: translateX(4px);
    }
    .btn-tab {
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }
    .btn-tab::before {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      width: 0;
      height: 0;
      border-radius: 50%;
      background: rgba(59, 130, 246, 0.2);
      transform: translate(-50%, -50%);
      transition: width 0.6s, height 0.6s;
    }
    .btn-tab:hover::before {
      width: 300px;
      height: 300px;
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
    <main class="flex-1 p-6 overflow-x-auto bg-gradient-to-br from-gray-50 to-blue-50">
      <div class="mb-8">
        <h1 class="text-4xl font-bold gradient-text mb-2 flex items-center gap-3">
          <i class="fas fa-envelope text-blue-600"></i>
          Messages
        </h1>
        <p class="text-gray-600">Manage your messages and communications</p>
      </div>
      <div class="w-full max-w-3xl mx-auto">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8 mb-8 fade-in">
          <div class="flex flex-col md:flex-row md:items-center w-full mb-6 gap-4 justify-between">
            <div class="flex gap-3">
              <button id="inboxBtn" class="btn-tab bg-blue-100 text-blue-700 font-semibold px-5 py-2.5 rounded-lg hover:bg-blue-200 transition-all flex items-center gap-2 shadow-sm hover:shadow-md relative">
                <i class="fas fa-inbox"></i> Inbox
              </button>
              <button id="sentBtn" class="btn-tab bg-blue-100 text-blue-700 font-semibold px-5 py-2.5 rounded-lg hover:bg-blue-200 transition-all flex items-center gap-2 shadow-sm hover:shadow-md relative">
                <i class="fas fa-paper-plane"></i> Sent
              </button>
              <button id="allBtn" class="btn-tab bg-blue-100 text-blue-700 font-semibold px-5 py-2.5 rounded-lg hover:bg-blue-200 transition-all flex items-center gap-2 shadow-sm hover:shadow-md relative">
                <i class="fas fa-folder"></i> All Mail
              </button>
            </div>
            <button id="composeBtn" class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-semibold py-2.5 px-6 rounded-lg flex items-center gap-2 shadow-md hover:shadow-lg transition-all transform hover:scale-105">
              <i class="fas fa-edit"></i> Compose
            </button>
          </div>
          <div id="messageList" class="w-full divide-y">
            <!-- Messages will be loaded here -->
          </div>
        </div>
      </div>
      <!-- Compose Modal -->
      <div id="composeModal" class="fixed inset-0 bg-black bg-opacity-30 backdrop-blur-sm flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 relative fade-in">
          <button id="closeCompose" class="absolute top-2 right-2 text-gray-500 hover:text-red-600 text-2xl w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center transition-all">&times;</button>
          <h2 class="text-xl font-bold mb-4 flex items-center gap-2 text-gray-800">
            <i class="fas fa-edit text-blue-600"></i>
            New Message
          </h2>
          <form id="composeForm" class="space-y-4">
            <input type="hidden" name="distributor_id" value="<?= $distributor_id ?>">
            <input type="hidden" name="sender_type" value="distributor">
            <div>
              <label class="block text-gray-700 mb-1 font-semibold">To (Username)</label>
              <input type="text" name="to_username" placeholder="Enter username (consumer/admin)" class="border rounded-lg px-4 py-2 w-full focus:ring-2 focus:ring-blue-300" required>
            </div>
            <div>
              <label class="block text-gray-700 mb-1 font-semibold">Message</label>
              <textarea name="message" placeholder="Type your message..." class="border rounded-lg px-4 py-2 w-full focus:ring-2 focus:ring-blue-300" required></textarea>
            </div>
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded w-full">Send</button>
          </form>
        </div>
      </div>
    </main>
  </div>
  <script>
    function loadMessages(type) {
      let params = {};
      params.distributor_id = <?= $distributor_id ?>;
      $.get('../messages/fetch_messages.php', params, function(data) {
        let html = '';
        try {
          const res = JSON.parse(data);
          let filtered = [];
          if (res.success && res.messages.length > 0) {
            if (type === 'inbox') {
              filtered = res.messages.filter(msg => msg.sender_type !== 'distributor');
            } else if (type === 'sent') {
              filtered = res.messages.filter(msg => msg.sender_type === 'distributor');
            } else {
              filtered = res.messages;
            }
            filtered.reverse().forEach(msg => {
              let shopLabel = msg.shop_name ? msg.shop_name : `Distributor #${msg.distributor_id}`;
              let senderLabel = msg.sender_type === 'consumer' ? (msg.consumer_username ? msg.consumer_username : `Consumer #${msg.consumer_id}`) : shopLabel;
              let receiverLabel = msg.sender_type === 'consumer' ? shopLabel : (msg.consumer_username ? msg.consumer_username : `Consumer #${msg.consumer_id}`);
              html += `<div class='flex items-center px-5 py-4 cursor-pointer message-row rounded-lg mb-2' data-message='${encodeURIComponent(msg.message)}' data-consumer-id='${msg.consumer_id}' data-distributor-id='${msg.distributor_id}' data-sender-type='${msg.sender_type}' data-sent-at='${msg.sent_at || ''}' data-consumer-username='${msg.consumer_username || ''}' data-shop-name='${msg.shop_name || ''}'>
                <div class='w-1/4 font-semibold text-gray-700' title='From: ${senderLabel}'>${senderLabel}</div>
                <div class='w-2/4 text-gray-800 truncate' title='To: ${receiverLabel}'>${msg.message}</div>
                <div class='w-1/4 text-right text-xs text-gray-500'>${msg.sent_at || ''}</div>
              </div>`;
            });
          } else {
            html = '<div class="p-6 text-gray-500 text-center">No messages found.</div>';
          }
        } catch(e) { html = '<div class="p-6 text-red-600 text-center">Error loading messages.</div>'; }
        $('#messageList').html(html);
      });
    }
    $(function() {
      // Default to inbox
      loadMessages('inbox');
      $('#inboxBtn').on('click', function(){ loadMessages('inbox'); });
      $('#sentBtn').on('click', function(){ loadMessages('sent'); });
      $('#allBtn').on('click', function(){ loadMessages('all'); });
      $('#composeBtn').on('click', function(){ $('#composeModal').removeClass('hidden'); });
      $('#closeCompose').on('click', function(){ $('#composeModal').addClass('hidden'); });
      $('#composeForm').on('submit', function(e) {
        e.preventDefault();
        $.post('../messages/send_message.php', $(this).serialize(), function(data) {
          $('#composeModal').addClass('hidden');
          loadMessages('sent');
        });
      });

      // Modal for viewing and replying to messages
      $('body').on('click', '.message-row', function() {
        var msg = decodeURIComponent($(this).data('message'));
        var consumerId = $(this).data('consumer-id');
        var distributorId = $(this).data('distributor-id');
        var senderType = $(this).data('sender-type');
        var sentAt = $(this).data('sent-at');
        var consumerUsername = $(this).data('consumer-username');
        $('#viewMessageModal .modal-message').text(msg);
        $('#viewMessageModal .modal-meta').text('From: ' + (consumerUsername ? consumerUsername : 'Consumer #' + consumerId) + ' | Sent: ' + sentAt);
        $('#replyConsumerId').val(consumerId);
        $('#replyDistributorId').val(distributorId);
        $('#viewMessageModal').removeClass('hidden');
      });
      $('#closeViewMessage').on('click', function(){ $('#viewMessageModal').addClass('hidden'); });
      $('#replyForm').on('submit', function(e) {
        e.preventDefault();
        $.post('../messages/send_message.php', $(this).serialize(), function(data) {
          $('#viewMessageModal').addClass('hidden');
          loadMessages('sent');
        });
      });
    });
  </script>
      <!-- View/Reply Modal -->
      <div id="viewMessageModal" class="fixed inset-0 bg-black bg-opacity-30 backdrop-blur-sm flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-6 relative fade-in">
          <button id="closeViewMessage" class="absolute top-2 right-2 text-gray-500 hover:text-red-600 text-2xl w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center transition-all">&times;</button>
          <h2 class="text-xl font-bold mb-4 flex items-center gap-2 text-gray-800">
            <i class="fas fa-eye text-blue-600"></i>
            View Message
          </h2>
          <div class="modal-message text-gray-800 mb-2"></div>
          <div class="modal-meta text-gray-500 mb-4 text-sm"></div>
          <form id="replyForm" class="space-y-3">
            <input type="hidden" name="distributor_id" value="<?= $distributor_id ?>">
            <input type="hidden" name="sender_type" value="distributor">
            <input type="hidden" id="replyConsumerId" name="consumer_id" value="">
            <input type="hidden" id="replyDistributorId" name="distributor_id" value="">
            <textarea name="message" placeholder="Type your reply..." class="border rounded px-3 py-2 w-full" required></textarea>
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded w-full">Send Reply</button>
          </form>
        </div>
      </div>
</body>
</html>
