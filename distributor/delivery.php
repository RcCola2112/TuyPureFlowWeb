<?php
session_start();
if (!isset($_SESSION['distributor_id'])) {
    echo '<script>window.location.replace("../index.html");</script>';
    exit;
}
include '../db.php';
$currentPage = 'delivery';
$distributor_id = $_SESSION['distributor_id'];

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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Delivery Management | Tuy PureFlow Distributor</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .order-card {
      transition: all 0.3s ease;
      background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
      border-left: 4px solid transparent;
    }
    .order-card:hover {
      transform: translateY(-4px) scale(1.01);
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
    }
    .order-card.waiting {
      border-left-color: #f59e0b;
    }
    .order-card.ongoing {
      border-left-color: #10b981;
    }
    .loading-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.4);
      backdrop-filter: blur(4px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
    }
    .spinner {
      border: 4px solid #f3f3f3;
      border-top: 4px solid #3b82f6;
      border-radius: 50%;
      width: 50px;
      height: 50px;
      animation: spin 1s linear infinite;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .section-header {
      background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
      padding: 1rem 1.5rem;
      border-radius: 0.75rem;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
      margin-bottom: 1.5rem;
    }
    .status-badge {
      padding: 0.5rem 1rem;
      border-radius: 9999px;
      font-weight: 600;
      font-size: 0.875rem;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }
    .count-badge {
      padding: 0.25rem 0.75rem;
      border-radius: 9999px;
      font-weight: 700;
      font-size: 0.875rem;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
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
    .gradient-text {
      background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .rider-select {
      transition: all 0.3s ease;
    }
    .rider-select:focus {
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
      border-color: #3b82f6;
    }
  </style>
</head>
<body class="flex bg-gray-100 min-h-screen">
  <?php include 'sidebar.php'; ?>
  <div class="flex-1 flex flex-col min-h-screen ml-64">
    <?php include 'header.php'; ?>
    <main class="flex-1 p-6 overflow-x-auto bg-gradient-to-br from-gray-50 to-blue-50">
      <div class="mb-8">
        <h1 class="text-4xl font-bold gradient-text mb-2 flex items-center gap-3">
          <i class="fas fa-truck text-blue-600"></i>
          Delivery Management
        </h1>
        <p class="text-gray-600">Manage today's delivery orders and assign riders</p>
      </div>

      <!-- Loading Overlay -->
      <div id="loading-overlay" class="loading-overlay hidden">
        <div class="bg-white rounded-2xl p-8 flex flex-col items-center gap-4 shadow-2xl">
          <div class="spinner"></div>
          <p class="text-gray-700 font-medium text-lg">Loading orders and riders...</p>
        </div>
      </div>

      <!-- Refresh Button -->
      <div class="mb-6 flex justify-end">
        <button onclick="refreshData()" class="px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 transition-all flex items-center gap-2 shadow-md hover:shadow-lg font-medium">
          <i class="fas fa-sync-alt"></i>
          Refresh
        </button>
      </div>

      <!-- Section 1: Waiting to be Delivered -->
      <div class="mb-8">
        <div class="section-header flex items-center justify-between p-6 mb-6">
          <div class="flex items-center gap-4">
            <div class="w-2 h-10 bg-gradient-to-b from-orange-400 to-orange-600 rounded-full shadow-lg"></div>
            <div>
              <h2 class="text-xl font-bold text-gray-800 flex items-center gap-3">
                <i class="fas fa-clock text-orange-500"></i>
                Waiting to be Delivered
              </h2>
              <p class="text-sm text-gray-500 mt-1">Orders ready for rider assignment</p>
            </div>
          </div>
          <span id="waiting-count" class="count-badge bg-orange-100 text-orange-700">0</span>
        </div>
        <div id="waiting-orders" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <!-- Orders will be loaded here -->
          <div class="text-center py-8 text-gray-500">
            <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
            <p>Loading orders...</p>
          </div>
        </div>
      </div>

      <!-- Section 2: On-Going Delivery -->
      <div>
        <div class="section-header flex items-center justify-between p-6 mb-6">
          <div class="flex items-center gap-4">
            <div class="w-2 h-10 bg-gradient-to-b from-green-400 to-green-600 rounded-full shadow-lg"></div>
            <div>
              <h2 class="text-xl font-bold text-gray-800 flex items-center gap-3">
                <i class="fas fa-shipping-fast text-green-500"></i>
                On-Going Delivery
              </h2>
              <p class="text-sm text-gray-500 mt-1">Orders currently being delivered</p>
            </div>
          </div>
          <span id="ongoing-count" class="count-badge bg-green-100 text-green-700">0</span>
        </div>
        <div id="ongoing-orders" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <!-- Orders will be loaded here -->
          <div class="text-center py-8 text-gray-500">
            <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
            <p>Loading orders...</p>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
    let shopId = null;
    let allRiders = [];
    const distributorId = <?php echo $distributor_id; ?>;
    const backendUrl = '../pureflowBackend';

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
      initializeDeliveryManagement();
    });

    async function initializeDeliveryManagement() {
      showLoading();
      try {
        // Step 1: Get shop_id
        shopId = await getShopId();
        if (!shopId) {
          alert('Error: Could not find shop for this distributor.');
          hideLoading();
          return;
        }

        // Step 2: Load orders and riders in parallel
        await Promise.all([
          loadOrders(),
          loadRiders()
        ]);

        hideLoading();
      } catch (error) {
        console.error('Initialization error:', error);
        alert('Error loading delivery management data. Please refresh the page.');
        hideLoading();
      }
    }

    async function getShopId() {
      try {
        const response = await fetch(`${backendUrl}/get_shop_id.php?distributor_id=${distributorId}`);
        const data = await response.json();
        if (data.success) {
          return data.shop_id;
        }
        return null;
      } catch (error) {
        console.error('Error getting shop ID:', error);
        return null;
      }
    }

    async function loadOrders() {
      if (!shopId) return;

      try {
        // Make 3 parallel API calls for different statuses
        const [pendingOrders, processingOrders, outForDeliveryOrders] = await Promise.all([
          fetch(`${backendUrl}/get_orders.php?shop_id=${shopId}&filter_status=Pending`).then(r => r.json()),
          fetch(`${backendUrl}/get_orders.php?shop_id=${shopId}&filter_status=Processing`).then(r => r.json()),
          fetch(`${backendUrl}/get_orders.php?shop_id=${shopId}&filter_status=Out for Delivery`).then(r => r.json())
        ]);

        // Combine all orders
        const allOrders = [
          ...(pendingOrders.success ? pendingOrders.orders : []),
          ...(processingOrders.success ? processingOrders.orders : []),
          ...(outForDeliveryOrders.success ? outForDeliveryOrders.orders : [])
        ];

        // Separate into two groups
        const waitingOrders = allOrders.filter(o => 
          o.status === 'Pending' || o.status === 'Processing'
        );
        const ongoingOrders = allOrders.filter(o => 
          o.status === 'Out for Delivery'
        );

        // Display orders
        displayWaitingOrders(waitingOrders);
        displayOngoingOrders(ongoingOrders);

        // Update counts
        document.getElementById('waiting-count').textContent = waitingOrders.length;
        document.getElementById('ongoing-count').textContent = ongoingOrders.length;
      } catch (error) {
        console.error('Error loading orders:', error);
        alert('Error loading orders. Please try again.');
      }
    }

    async function loadRiders() {
      try {
        const response = await fetch(`${backendUrl}/get_riders.php?distributor_id=${distributorId}`);
        const data = await response.json();
        if (data.success) {
          allRiders = data.riders || [];
        }
      } catch (error) {
        console.error('Error loading riders:', error);
      }
    }

    function displayWaitingOrders(orders) {
      const container = document.getElementById('waiting-orders');
      
      if (orders.length === 0) {
        container.innerHTML = `
          <div class="col-span-full text-center py-12 bg-white rounded-xl shadow-sm border border-gray-200 fade-in">
            <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gradient-to-br from-orange-100 to-orange-200 flex items-center justify-center" style="box-shadow: 0 4px 10px rgba(249, 115, 22, 0.2);">
              <i class="fas fa-inbox text-4xl text-orange-500"></i>
            </div>
            <p class="text-lg font-semibold text-gray-700 mb-2">No orders waiting to be delivered</p>
            <p class="text-sm text-gray-500">All orders have been assigned or are already in delivery</p>
          </div>
        `;
        return;
      }

      container.innerHTML = orders.map(order => {
        const address = order.delivery_address || 'Address not available';
        const truncatedAddress = address.length > 60 ? address.substring(0, 60) + '...' : address;
        const statusClass = order.status === 'Pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-orange-100 text-orange-700';
        const statusIcon = order.status === 'Pending' ? 'fa-clock' : 'fa-hourglass-half';
        const currentRiderId = order.rider_id || null;

        return `
          <div class="order-card waiting rounded-xl p-5 shadow-sm border border-gray-200 fade-in">
            <div class="flex justify-between items-start mb-4">
              <div class="flex-1">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-12 h-12 rounded-full bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center text-white font-bold text-lg shadow-md">
                    ${(order.consumer_name || 'C')[0].toUpperCase()}
                  </div>
                  <div>
                    <div class="font-bold text-lg text-gray-800">${order.consumer_name || 'Unknown Customer'}</div>
                    <div class="text-xs text-gray-500 flex items-center gap-1">
                      <i class="fas fa-hashtag text-gray-400"></i>
                      Order #${order.order_id}
                    </div>
                  </div>
                </div>
                <div class="mb-3 p-3 bg-gray-50 rounded-lg">
                  <div class="text-sm text-gray-600 flex items-start gap-2">
                    <i class="fas fa-map-marker-alt text-red-500 mt-0.5"></i>
                    <span class="line-clamp-2">${truncatedAddress}</span>
                  </div>
                </div>
                <span class="status-badge ${statusClass}">
                  <i class="fas ${statusIcon}"></i>
                  ${order.status}
                </span>
              </div>
            </div>
            <div class="mt-4 pt-4 border-t border-gray-200">
              <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
                <i class="fas fa-user-tie text-blue-600"></i>
                Assign Rider
              </label>
              <select 
                onchange="assignRider(${order.order_id}, this.value)" 
                class="rider-select w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
                id="rider-select-${order.order_id}"
              >
                <option value="">Select Rider</option>
                ${allRiders.map(rider => `
                  <option value="${rider.rider_id}" ${rider.rider_id == currentRiderId ? 'selected' : ''}>
                    ${rider.name} ${rider.phone ? `(${rider.phone})` : ''}
                  </option>
                `).join('')}
              </select>
            </div>
          </div>
        `;
      }).join('');
    }

    function displayOngoingOrders(orders) {
      const container = document.getElementById('ongoing-orders');
      
      if (orders.length === 0) {
        container.innerHTML = `
          <div class="col-span-full text-center py-12 bg-white rounded-xl shadow-sm border border-gray-200 fade-in">
            <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gradient-to-br from-green-100 to-green-200 flex items-center justify-center" style="box-shadow: 0 4px 10px rgba(16, 185, 129, 0.2);">
              <i class="fas fa-check-circle text-4xl text-green-500"></i>
            </div>
            <p class="text-lg font-semibold text-gray-700 mb-2">No ongoing deliveries</p>
            <p class="text-sm text-gray-500">All deliveries have been completed</p>
          </div>
        `;
        return;
      }

      container.innerHTML = orders.map(order => {
        const address = order.delivery_address || 'Address not available';
        const truncatedAddress = address.length > 60 ? address.substring(0, 60) + '...' : address;
        const riderName = order.rider_id ? 
          (allRiders.find(r => r.rider_id == order.rider_id)?.name || 'Unknown Rider') : 
          'No rider assigned';
        const riderPhone = order.rider_id ? 
          (allRiders.find(r => r.rider_id == order.rider_id)?.phone || '') : 
          '';

        return `
          <div class="order-card ongoing rounded-xl p-5 shadow-sm border border-gray-200 fade-in">
            <div class="flex justify-between items-start mb-4">
              <div class="flex-1">
                <div class="flex items-center gap-3 mb-3">
                  <div class="w-12 h-12 rounded-full bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center text-white font-bold text-lg shadow-md">
                    ${(order.consumer_name || 'C')[0].toUpperCase()}
                  </div>
                  <div>
                    <div class="font-bold text-lg text-gray-800">${order.consumer_name || 'Unknown Customer'}</div>
                    <div class="text-xs text-gray-500 flex items-center gap-1">
                      <i class="fas fa-hashtag text-gray-400"></i>
                      Order #${order.order_id}
                    </div>
                  </div>
                </div>
                <div class="mb-3 p-3 bg-gray-50 rounded-lg">
                  <div class="text-sm text-gray-600 flex items-start gap-2">
                    <i class="fas fa-map-marker-alt text-red-500 mt-0.5"></i>
                    <span class="line-clamp-2">${truncatedAddress}</span>
                  </div>
                </div>
                <div class="mb-3">
                  <span class="status-badge bg-green-100 text-green-700">
                    <i class="fas fa-shipping-fast"></i>
                    ${order.status}
                  </span>
                </div>
                <div class="p-3 bg-blue-50 rounded-lg border border-blue-100">
                  <div class="text-sm text-gray-600 flex items-center gap-2 mb-1">
                    <i class="fas fa-user-tie text-blue-600"></i>
                    <span class="font-semibold text-gray-700">Assigned Rider:</span>
                  </div>
                  <div class="text-base font-bold text-blue-700 ml-6">${riderName}</div>
                  ${riderPhone ? `<div class="text-xs text-gray-500 ml-6 mt-1">${riderPhone}</div>` : ''}
                </div>
              </div>
            </div>
          </div>
        `;
      }).join('');
    }

    async function assignRider(orderId, riderId) {
      if (!orderId) {
        alert('Error: Invalid order ID');
        return;
      }

      // Convert empty string to null
      const riderIdToAssign = riderId === '' ? null : parseInt(riderId);

      try {
        const response = await fetch(`${backendUrl}/assign_rider_to_order.php`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            order_id: orderId,
            rider_id: riderIdToAssign
          })
        });

        const data = await response.json();
        
        if (data.success) {
          // Refresh orders to show updated assignment
          await loadOrders();
        } else {
          alert('Error assigning rider: ' + (data.error || 'Unknown error'));
          // Reload orders to reset the dropdown
          await loadOrders();
        }
      } catch (error) {
        console.error('Error assigning rider:', error);
        alert('Error assigning rider. Please try again.');
        // Reload orders to reset the dropdown
        await loadOrders();
      }
    }

    async function refreshData() {
      showLoading();
      try {
        await Promise.all([
          loadOrders(),
          loadRiders()
        ]);
        hideLoading();
      } catch (error) {
        console.error('Error refreshing data:', error);
        alert('Error refreshing data. Please try again.');
        hideLoading();
      }
    }

    function showLoading() {
      document.getElementById('loading-overlay').classList.remove('hidden');
    }

    function hideLoading() {
      document.getElementById('loading-overlay').classList.add('hidden');
    }
  </script>
</body>
</html>
