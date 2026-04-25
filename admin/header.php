<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
$adminName = isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'Admin';
$adminProfilePic = '../images/admin_avatar.png'; // default
if (!empty($_SESSION['admin_profile_picture'])) {
  $adminProfilePic = htmlspecialchars($_SESSION['admin_profile_picture']);
} elseif (isset($_SESSION['admin_id'])) {
  require_once '../db.php';
  $stmt = $conn->prepare("SELECT profile_picture FROM admin WHERE admin_id = ? LIMIT 1");
  $stmt->execute([$_SESSION['admin_id']]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row && !empty($row['profile_picture'])) {
    $adminProfilePic = htmlspecialchars($row['profile_picture']);
  }
}
?>
<style>
  .header-gradient {
    background: linear-gradient(135deg, #3FE0E8 0%, #3578C9 100%);
    border-bottom: 1px solid rgba(53, 120, 201, 0.2);
  }
  .profile-container {
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    padding: 0.5rem 1rem;
    border-radius: 2rem;
    transition: all 0.3s ease;
    border: 1px solid rgba(255, 255, 255, 0.3);
  }
  .profile-container span {
    color: #ffffff;
  }
  .logout-btn {
    background: rgba(255, 255, 255, 0.25);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.4);
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    color: #fff;
  }
  .logout-btn:hover {
    background: rgba(255, 255, 255, 0.35);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
  }
</style>
<header class="header-gradient shadow-sm px-6 py-4 flex items-center justify-between fixed top-0 left-0 right-0 z-40" style="height:72px; min-height:72px;">
  <!-- Left: Tuy PureFlow clickable -->
  <div class="flex items-center gap-6">
    <a href="dashboard.php" class="text-2xl font-bold flex items-center gap-2 text-white">
      <i class="fas fa-tint"></i>
      <span>Tuy PureFlow Admin</span>
    </a>
    <div class="greeting-text">
      <h2 class="text-base font-medium text-white">
        Hello, <span class="font-semibold text-white"><?php echo htmlspecialchars($adminName); ?></span>
      </h2>
    </div>
  </div>
  <!-- Right: Profile + Logout -->
  <div class="flex items-center gap-3">
    <div class="profile-container flex items-center gap-3">
      <img src="<?= $adminProfilePic ?>" alt="Profile" class="w-10 h-10 rounded-full object-cover border-2 border-white">
      <span class="text-white font-medium text-sm hidden md:block"><?php echo htmlspecialchars($adminName); ?></span>
    </div>
    <a href="logout.php" class="logout-btn px-5 py-2.5 rounded-lg font-medium flex items-center gap-2">
      <i class="fas fa-sign-out-alt text-sm"></i>
      <span class="hidden sm:inline">Logout</span>
    </a>
  </div>
</header>
