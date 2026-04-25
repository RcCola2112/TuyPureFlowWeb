<?php
// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure $username, $shopname are set
$username = $username ?? "User Name";
$shopname = $shopname ?? "Shop Name";

// Fetch shop logo from database
$shopLogo = "images/default-profile.jpg"; // Default fallback
if (isset($_SESSION['distributor_id'])) {
    try {
        // Check if $conn is available, if not include db.php
        if (!isset($conn)) {
            include_once '../db.php';
        }
        
        if (isset($conn)) {
            $distributor_id = $_SESSION['distributor_id'];
            $shop_stmt = $conn->prepare("SELECT logo_image FROM shop WHERE distributor_id = ? LIMIT 1");
            $shop_stmt->execute([$distributor_id]);
            $shop = $shop_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($shop && !empty($shop['logo_image'])) {
                // If logo_image is a blob, convert to base64
                $logo_blob = $shop['logo_image'];
                // Check if it's binary data (blob)
                if (is_string($logo_blob) && strlen($logo_blob) > 0) {
                    // Try to detect image type
                    $imgType = 'png';
                    if (substr($logo_blob, 0, 2) === "\xFF\xD8") {
                        $imgType = 'jpeg';
                    } elseif (substr($logo_blob, 0, 4) === "\x89PNG") {
                        $imgType = 'png';
                    } elseif (substr($logo_blob, 0, 4) === "RIFF" && substr($logo_blob, 8, 4) === "WEBP") {
                        $imgType = 'webp';
                    }
                    $shopLogo = 'data:image/' . $imgType . ';base64,' . base64_encode($logo_blob);
                }
            }
        }
    } catch (Exception $e) {
        // If error, use default
        error_log("Error fetching shop logo: " . $e->getMessage());
    }
}
?>
<style>
  .header-gradient {
    background: linear-gradient(135deg, #3FE0E8 0%, #3578C9 100%);
    border-bottom: 1px solid rgba(53, 120, 201, 0.2);
  }
  .brand-link {
    color: #ffffff;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
  }
  .brand-link:hover {
    transform: scale(1.05);
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
  }
  .greeting-text {
    position: relative;
    padding-left: 1rem;
  }
  .greeting-text::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 24px;
    background: #ffffff;
    border-radius: 2px;
    opacity: 0.8;
  }
  .profile-container {
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    padding: 0.5rem 1rem;
    border-radius: 2rem;
    transition: all 0.3s ease;
    border: 1px solid rgba(255, 255, 255, 0.3);
  }
  .profile-container:hover {
    background: rgba(255, 255, 255, 0.3);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transform: translateY(-1px);
  }
  .profile-img {
    border: 2px solid #ffffff;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    transition: all 0.3s ease;
  }
  .profile-img:hover {
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
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
  }
  .logout-btn:hover {
    background: rgba(255, 255, 255, 0.35);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
  }
</style>

<header class="header-gradient shadow-sm px-6 py-4 flex items-center justify-between sticky top-0 z-40">
  <!-- Left: Tuy PureFlow clickable -->
  <div class="flex items-center gap-6">
    <a href="dashboard.php" class="brand-link text-2xl font-bold flex items-center gap-2">
      <i class="fas fa-tint text-white"></i>
      <span>Tuy PureFlow</span>
    </a>
    <div class="greeting-text">
      <h2 class="text-base font-medium text-white">
        Hello, <span class="font-semibold text-white"><?php echo htmlspecialchars($username); ?></span> of 
        <span class="font-semibold text-white"><?php echo htmlspecialchars($shopname); ?></span>
      </h2>
    </div>
  </div>
  <!-- Right: Notification + Profile + Logout -->
  <div class="flex items-center gap-3">
    <!-- Notification Icon -->
    <?php include 'notification_icon.php'; ?>

    <!-- Shop Logo/Profile -->
    <div class="profile-container flex items-center gap-3">
      <img src="<?php echo htmlspecialchars($shopLogo); ?>" alt="Shop Logo" class="profile-img w-10 h-10 rounded-full object-cover">
      <span class="text-gray-700 font-medium text-sm hidden md:block"><?php echo htmlspecialchars($shopname); ?></span>
    </div>

    <!-- Logout Button -->
    <a href="logout.php" class="logout-btn px-5 py-2.5 text-white rounded-lg font-medium flex items-center gap-2">
      <i class="fas fa-sign-out-alt text-sm"></i>
      <span class="hidden sm:inline">Logout</span>
    </a>
  </div>
</header>
