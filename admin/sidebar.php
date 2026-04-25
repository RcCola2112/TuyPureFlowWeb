<?php
if (!function_exists('isActive')) {
  function isActive($page, $current = '') {
    $currentPage = basename($_SERVER['PHP_SELF'], '.php');
    return $page === $currentPage ? 'bg-blue-100 text-blue-700 font-semibold' : '';
  }
}
?>
<div class="fixed left-0 top-[72px] h-[calc(100vh-72px)] w-64 bg-white shadow-lg z-30">
  <div class="flex flex-col h-full">
    <!-- Logo -->
    <div class="p-6 text-center border-b border-gray-200">
      <img src="../images/logo.png" class="h-20 mx-auto mb-2" alt="Logo">
      <span class="font-bold text-blue-600 text-xl">Tuy PureFlow</span>
    </div>
    <!-- Navigation -->
    <nav class="flex-1 p-4 space-y-2 text-gray-700 overflow-y-auto">
      <a href="dashboard.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('dashboard') ?>">
        🏠 <span>Dashboard</span>
      </a>
      <a href="distributor_approvals.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('distributor_approvals') ?>">
        ✅ <span>Distributor Approvals</span>
      </a>
      <a href="violations.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('violations') ?>">
        🚩 <span>User Violations</span>
      </a>
      <a href="disputes.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('disputes') ?>">
        ⚖️ <span>Disputes & Escalations</span>
      </a>
      <a href="settings.php" class="flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= isActive('settings') ?>">
        ⚙️ <span>System Settings</span>
      </a>
      <a href="logout.php" class="flex items-center gap-3 p-2 rounded hover:bg-red-100 transition text-red-600 <?= isActive('logout') ?>">
        🚪 <span>Logout</span>
      </a>
    </nav>
  </div>
</div>
