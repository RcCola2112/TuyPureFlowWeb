<?php
if (!function_exists('isActive')) {
  function isActive($page, $current) {
    return $page === $current ? 'bg-blue-100 text-blue-700 font-semibold' : '';
  }
}
?>


<div class="fixed top-0 left-0 h-full w-64 bg-white shadow-lg z-10">
  <div class="flex flex-col h-full">
    <!-- Logo -->
    <div class="p-6 text-center border-b border-gray-200">
      <img src="../images/logo.png" class="h-20 mx-auto mb-2" alt="Logo">
      <span class="font-bold text-blue-600 text-xl">Tuy PureFlow</span>
    </div>
    <!-- Navigation -->
    <nav class="flex-1 p-4 space-y-2 text-gray-700 overflow-y-auto">
      <a href="dashboard.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('dashboard', $currentPage) ?>">
        🏠 <span>Dashboard</span>
      </a>
      <a href="order_text.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('ordertext', $currentPage) ?>">
        📲 <span>Text</span>
      </a>
      <a href="orders.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('orders', $currentPage) ?>">
        📦 <span>Orders</span>
      </a>
      <a href="onsite.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('onsite', $currentPage) ?>">
        🏪 <span>Onsite Ordering</span>
      </a>
      <a href="inventory.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('inventory', $currentPage) ?>">
        📊 <span>Inventory</span>
      </a>
      <a href="delivery.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('delivery', $currentPage) ?>">
        🚚 <span>Delivery</span>
      </a>
      <a href="delivery_riders.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('delivery_riders', $currentPage) ?>">
        🏍️ <span>Delivery Riders</span>
      </a>
      <a href="messages.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('messages', $currentPage) ?>">
        💬 <span>Messages</span>
      </a>
      <a href="notification.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('notification', $currentPage) ?>">
        🔔 <span>Notifications</span>
      </a>
      <a href="analytics.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('analytics', $currentPage) ?>">
        📈 <span>Analytics</span>
      </a>
      <a href="payments.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('payments', $currentPage) ?>">
        💳 <span>Payments</span>
      </a>
      <a href="customers.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('customers', $currentPage) ?>">
        👥 <span>Customers</span>
      </a>
      <a href="certificates.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('certificates', $currentPage) ?>">
        📜 <span>Certificates</span>
      </a>
      <a href="settings.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('settings', $currentPage) ?>">
        ⚙️ <span>Settings</span>
      </a>
      <a href="logout.php" class="flex items-center gap-3 p-2 rounded text-red-600 hover:bg-red-100 transition">
        🚪 <span>Log Out</span>
      </a>
    </nav>
  </div>
</div>
