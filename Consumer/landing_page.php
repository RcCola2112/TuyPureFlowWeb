<?php
// landing_page.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include '../db.php';

// Handle filter
$filter = $_GET['filter'] ?? 'nearme';
$orderBy = '';
$where = "WHERE d.status = 'Approved'";
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($search !== '') {
  $where .= " AND (s.name LIKE :search_name OR s.location LIKE :search_location)";
}
$use_distance = false;
$user_lat = null;
$user_lng = null;


if ($filter === 'nearme' && isset($_SESSION['consumer_id'])) {
  $consumer_id = $_SESSION['consumer_id'];
  $loc_stmt = $conn->prepare("SELECT latitude, longitude FROM address WHERE consumer_id = ? LIMIT 1");
  $loc_stmt->execute([$consumer_id]);
  $user_location = $loc_stmt->fetch(PDO::FETCH_ASSOC);
  if ($user_location && !empty($user_location['latitude']) && !empty($user_location['longitude'])) {
    $user_lat = $user_location['latitude'];
    $user_lng = $user_location['longitude'];
    $use_distance = true;
  }
}

switch ($filter) {
  case 'highest_sale':
    $orderBy = 'ORDER BY total_sales DESC';
    break;
  case 'top_rated':
    $orderBy = 'ORDER BY avg_rating DESC';
    break;
  case 'open_now':
    // Remove is_open filter since column does not exist
    break;
  case 'most_affordable':
    $orderBy = 'ORDER BY min_price ASC';
    break;
  case 'nearme':
  default:
    $orderBy = $use_distance ? 'ORDER BY distance_km ASC' : 'ORDER BY s.name ASC';
    break;
}

if ($filter === 'nearme' && !empty($user_lat) && !empty($user_lng)) {
      $query = "
      SELECT 
        s.shop_id,
        s.name,
        s.latitude,
        s.longitude,
        s.logo_image,
        s.contact_number,
        d.distributor_id,
        d.status,
        (SELECT SUM(total_amount) 
         FROM orders 
         WHERE orders.shop_id = s.shop_id 
         AND orders.status = 'Completed') AS total_sales,
        (SELECT AVG(sr.rating) FROM shop_ratings sr WHERE sr.shop_id = s.shop_id) AS avg_rating,
        (SELECT MIN(c.price_refill) FROM container c JOIN container_type ct ON c.container_type_id = ct.container_type_id WHERE c.shop_id = s.shop_id) AS min_price,
    (6371 * ACOS(
      COS(RADIANS(:lat1)) *
      COS(RADIANS(s.latitude)) *
      COS(RADIANS(s.longitude) - RADIANS(:lng1)) +
      SIN(RADIANS(:lat2)) *
      SIN(RADIANS(s.latitude))
    )) AS distance_km
      FROM shop s
      JOIN distributor d ON s.distributor_id = d.distributor_id
      $where
      $orderBy
    ";
  $stmt = $conn->prepare($query);
  $stmt->bindValue(':lat1', $user_lat);
  $stmt->bindValue(':lat2', $user_lat);
  $stmt->bindValue(':lng1', $user_lng);
  if ($search !== '') {
    $stmt->bindValue(':search_name', "%$search%", PDO::PARAM_STR);
    $stmt->bindValue(':search_location', "%$search%", PDO::PARAM_STR);
  }
  $stmt->execute();
  $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $query = "
      SELECT 
        s.shop_id,
        s.name,
        s.latitude,
        s.longitude,
        s.logo_image,
        s.contact_number,
        d.distributor_id,
        d.status,
        (SELECT SUM(total_amount) 
         FROM orders 
         WHERE orders.shop_id = s.shop_id 
         AND orders.status = 'Completed') AS total_sales,
        (SELECT AVG(sr.rating) FROM shop_ratings sr WHERE sr.shop_id = s.shop_id) AS avg_rating,
        (SELECT MIN(c.price_refill) FROM container c WHERE c.shop_id = s.shop_id) AS min_price
      FROM shop s
      JOIN distributor d ON s.distributor_id = d.distributor_id
      $where
      $orderBy
    ";
    $stmt = $conn->prepare($query);
    if ($search !== '') {
      $stmt->bindValue(':search_name', "%$search%", PDO::PARAM_STR);
      $stmt->bindValue(':search_location', "%$search%", PDO::PARAM_STR);
    }
    $stmt->execute();
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tuy PureFlow - Find Your Water Station</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="style.css">
</head>
<body class="bg-gray-50 font-sans">
  <?php
    $successMsg = '';
    if (isset($_GET['order']) && $_GET['order'] === 'success') {
      $successMsg = 'Your order has been made successfully.';
    } elseif (isset($_GET['success'])) {
      $successMsg = htmlspecialchars($_GET['success']);
    }
  ?>
  <?php if ($successMsg): ?>
    <div id="actionSuccessMsg" class="fixed inset-0 flex items-center justify-center z-50 bg-black bg-opacity-50 backdrop-blur-sm">
      <div class="bg-white rounded-2xl shadow-2xl px-8 py-8 text-center max-w-md mx-4 fade-in">
        <div class="mb-4">
          <div class="mx-auto w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
            <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
          </div>
        </div>
        <h2 class="text-2xl font-bold text-gray-800 mb-2">Order Successful!</h2>
        <p class="text-gray-600 mb-6"><?= $successMsg ?><br>Thank you for choosing Tuy PureFlow!</p>
        <button onclick="document.getElementById('actionSuccessMsg').style.display='none'" class="btn-primary w-full">Continue Shopping</button>
      </div>
    </div>
    <script>
      setTimeout(function(){
        var msg = document.getElementById('actionSuccessMsg');
        if(msg) msg.style.display = 'none';
      }, 5000);
    </script>
  <?php endif; ?>
  <!-- Navbar -->
  <header class="header-gradient sticky top-0 z-50">
    <div class="container mx-auto px-4 py-4 flex flex-wrap justify-between items-center gap-4">
      <a href="landing_page.php" class="brand-link flex items-center gap-2 text-xl">
        <img src="../images/logo.png" alt="Tuy PureFlow Logo" class="h-10 w-auto">
        <span>Tuy PureFlow</span>
      </a>
      <form method="get" action="landing_page.php" class="flex-1 max-w-md mx-4" id="searchForm">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <div class="flex gap-2">
          <input type="text" name="search" id="searchInput" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Search water stations..." class="flex-1 px-4 py-2.5 rounded-full text-gray-800 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-cyan-400" />
          <button type="submit" class="px-6 py-2.5 rounded-full font-semibold bg-white bg-opacity-25 backdrop-blur-sm text-white hover:bg-opacity-35 transition-all">
            <i class="fas fa-search"></i>
          </button>
        </div>
      </form>
      <div class="flex gap-4 items-center">
        <?php
          $pending_count = 0;
          $consumer_profile = null;
          if (isset($_SESSION['consumer_id'])) {
              $cart_stmt = $conn->prepare("SELECT COUNT(*) FROM cart WHERE consumer_id = ?");
              $cart_stmt->execute([$_SESSION['consumer_id']]);
              $pending_count = $cart_stmt->fetchColumn();
              
              // Fetch consumer profile picture
              $profile_stmt = $conn->prepare("SELECT profile_pic FROM consumer WHERE consumer_id = ? LIMIT 1");
              $profile_stmt->execute([$_SESSION['consumer_id']]);
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
          }
        ?>
        <?php if (isset($_SESSION['consumer_id'])): ?>
          <?php include 'notification_icon.php'; ?>
        <?php endif; ?>
        <a href="cart.php" class="relative p-2 rounded-full hover:bg-white hover:bg-opacity-20 transition-all">
          <i class="fas fa-shopping-bag text-white text-xl"></i>
          <?php if ($pending_count > 0): ?>
            <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs w-5 h-5 flex items-center justify-center rounded-full font-bold shadow-lg"><?= $pending_count ?></span>
          <?php endif; ?>
        </a>
        <?php if (isset($_SESSION['consumer_id'])): ?>
          <a href="account.php" class="flex items-center gap-2 text-white font-semibold hover:underline px-3 py-2 rounded-lg hover:bg-white hover:bg-opacity-20 transition-all">
            <img src="<?= htmlspecialchars($consumer_profile) ?>" alt="Profile" class="w-8 h-8 rounded-full object-cover border-2 border-white border-opacity-30">
            <span><?= htmlspecialchars($_SESSION['consumer_name']) ?></span>
          </a>
          <a href="logout.php" class="text-white hover:bg-white hover:bg-opacity-20 px-4 py-2 rounded-lg transition-all font-medium">Logout</a>
        <?php else: ?>
          <div class="relative group">
            <button class="px-5 py-2.5 bg-white bg-opacity-25 backdrop-blur-sm text-white rounded-lg hover:bg-opacity-35 font-semibold flex items-center gap-2 focus:outline-none transition-all border border-white border-opacity-30">
              Account
              <svg class="w-4 h-4 transform transition-transform group-hover:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
              </svg>
            </button>
            <div class="absolute right-0 mt-2 w-40 bg-white rounded-lg shadow-xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-10 overflow-hidden">
              <a href="signup.php" class="block px-4 py-3 text-sm text-gray-700 hover:bg-blue-50 font-medium transition-colors">Sign Up</a>
              <a href="login.php" class="block px-4 py-3 text-sm text-gray-700 hover:bg-blue-50 font-medium transition-colors border-t border-gray-100">Sign In</a>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <!-- Categories (Tabs) -->
  <nav class="filter-tabs sticky top-[73px] z-40">
    <div class="container mx-auto px-4 flex gap-3 overflow-x-auto text-sm font-medium whitespace-nowrap py-3 scrollbar-hide">
      <a href="?filter=nearme<?= $search ? '&search=' . urlencode($search) : '' ?>">
        <button type="button" class="<?= $filter==='nearme' ? 'active' : '' ?> px-5 py-2.5">
          <i class="fas fa-map-marker-alt mr-2"></i>Near Me
        </button>
      </a>
      <a href="?filter=highest_sale<?= $search ? '&search=' . urlencode($search) : '' ?>">
        <button type="button" class="<?= $filter==='highest_sale' ? 'active' : '' ?> px-5 py-2.5">
          <i class="fas fa-chart-line mr-2"></i>Highest Sale
        </button>
      </a>
      <a href="?filter=top_rated<?= $search ? '&search=' . urlencode($search) : '' ?>">
        <button type="button" class="<?= $filter==='top_rated' ? 'active' : '' ?> px-5 py-2.5">
          <i class="fas fa-star mr-2"></i>Top Rated
        </button>
      </a>
      <a href="?filter=open_now<?= $search ? '&search=' . urlencode($search) : '' ?>">
        <button type="button" class="<?= $filter==='open_now' ? 'active' : '' ?> px-5 py-2.5">
          <i class="fas fa-clock mr-2"></i>Open Now
        </button>
      </a>
      <a href="?filter=most_affordable<?= $search ? '&search=' . urlencode($search) : '' ?>">
        <button type="button" class="<?= $filter==='most_affordable' ? 'active' : '' ?> px-5 py-2.5">
          <i class="fas fa-tag mr-2"></i>Most Affordable
        </button>
      </a>
    </div>
  </nav>
  <!-- Shop Grid -->
  <main class="container mx-auto px-4 py-8">
    <?php if (empty($stations)): ?>
      <div class="text-center py-16">
        <div class="mx-auto w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mb-4">
          <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
          </svg>
        </div>
        <h3 class="text-xl font-semibold text-gray-700 mb-2">No shops found</h3>
        <p class="text-gray-500">Try adjusting your search or filters</p>
      </div>
    <?php else: ?>
      <div class="grid gap-6 grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
        <?php foreach ($stations as $station): ?>
        <?php
          // Convert logo_image (longblob) to base64 for display
          $logoSrc = !empty($station['logo_image'])
            ? 'data:image/png;base64,' . base64_encode($station['logo_image'])
            : '../images/logo.png';

          // Fetch average rating and total ratings for this shop (shop_ratings)
          $rating_stmt = $conn->prepare("SELECT AVG(rating) AS avg_rating, COUNT(*) AS total_ratings FROM shop_ratings WHERE shop_id = ?");
          $rating_stmt->execute([$station['shop_id']]);
          $ratingData = $rating_stmt->fetch(PDO::FETCH_ASSOC);

          $avg_rating = $ratingData['avg_rating'] ? round($ratingData['avg_rating'], 2) : 0;
          $total_ratings = $ratingData['total_ratings'];
        ?>
        <!-- Shop Card -->
        <div class="shop-card fade-in">
          <div class="relative overflow-hidden">
            <img src="<?= htmlspecialchars($logoSrc) ?>" alt="<?= htmlspecialchars($station['name'] ?? 'Shop') ?>" class="w-full" />
            <?php if (isset($station['distance_km'])): ?>
              <div class="absolute top-3 right-3 bg-white bg-opacity-90 backdrop-blur-sm px-3 py-1 rounded-full text-xs font-semibold text-gray-700 shadow-md">
                <i class="fas fa-map-marker-alt text-blue-600 mr-1"></i><?= round($station['distance_km'], 2) ?> km
              </div>
            <?php endif; ?>
          </div>
          <div class="p-4 flex-1 flex flex-col">
            <h2 class="text-lg font-semibold text-gray-800 mb-2 line-clamp-1"><?= htmlspecialchars($station['name'] ?? 'Shop') ?></h2>
            <div class="mb-3">
              <div class="flex items-center gap-2 mb-2">
                <div class="flex items-center">
                  <?php
                    $fullStars = floor($avg_rating);
                    $hasHalfStar = ($avg_rating - $fullStars) >= 0.5;
                    for ($i = 0; $i < 5; $i++):
                  ?>
                    <?php if ($i < $fullStars): ?>
                      <svg class="w-4 h-4 text-yellow-400 fill-current" viewBox="0 0 20 20"><path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>
                    <?php elseif ($i === $fullStars && $hasHalfStar): ?>
                      <svg class="w-4 h-4 text-yellow-400 fill-current" viewBox="0 0 20 20"><path d="M10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545L10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0z"/></svg>
                    <?php else: ?>
                      <svg class="w-4 h-4 text-gray-300 fill-current" viewBox="0 0 20 20"><path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545L10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0z"/></svg>
                    <?php endif; ?>
                  <?php endfor; ?>
                </div>
                <span class="text-sm text-gray-600 font-medium">
                  <?php if ($total_ratings > 0): ?>
                    <?= $avg_rating ?>/5 <span class="text-gray-400">(<?= $total_ratings ?>)</span>
                  <?php else: ?>
                    <span class="text-gray-400">No ratings</span>
                  <?php endif; ?>
                </span>
              </div>
              <div class="flex items-center gap-2 text-gray-600">
                <i class="fas fa-phone text-cyan-600 text-xs"></i>
                <span class="text-xs"><?= htmlspecialchars($station['contact_number'] ?? 'N/A') ?></span>
              </div>
            </div>
            <a href="shop_page.php?shop_id=<?= urlencode($station['shop_id']) ?>" class="view-button mt-auto">
              View Shop <i class="fas fa-arrow-right ml-2"></i>
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
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
    // Auto-reload when search field is cleared
    document.addEventListener('DOMContentLoaded', function() {
      const searchInput = document.getElementById('searchInput');
      const searchForm = document.getElementById('searchForm');
      let searchTimeout;

      searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        
        // If search field is empty, automatically reload page without search parameter
        if (this.value.trim() === '') {
          searchTimeout = setTimeout(function() {
            const filter = '<?= htmlspecialchars($filter) ?>';
            const url = new URL(window.location.href);
            url.searchParams.delete('search');
            url.searchParams.set('filter', filter);
            window.location.href = url.toString();
          }, 500); // Wait 500ms after user stops typing
        }
      });

      // Also handle when user clears the field completely
      searchInput.addEventListener('keyup', function(e) {
        if (e.key === 'Backspace' || e.key === 'Delete') {
          if (this.value.trim() === '') {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
              const filter = '<?= htmlspecialchars($filter) ?>';
              const url = new URL(window.location.href);
              url.searchParams.delete('search');
              url.searchParams.set('filter', filter);
              window.location.href = url.toString();
            }, 300);
          }
        }
      });
    });
  </script>
</body>
</html>
