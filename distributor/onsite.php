<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();
session_start();
if (!isset($_SESSION['distributor_id'])) {
    echo '<script>window.location.replace("../index.html");</script>';
    exit;
}
include '../db.php';
$currentPage = 'onsite';

$distributor_id = $_SESSION['distributor_id'];

// Fetch distributor info for header
try {
    $stmt = $conn->prepare("SELECT * FROM distributor WHERE distributor_id = ?");
    $stmt->execute([$distributor_id]);
    $distributor = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log("DB error fetching distributor: " . $e->getMessage());
    $distributor = [];
}

$username = $distributor['name'] ?? '';
try {
    $stmtShop = $conn->prepare("SELECT name FROM shop WHERE distributor_id = ? LIMIT 1");
    $stmtShop->execute([$distributor_id]);
    $shopname = $stmtShop->fetchColumn() ?: '';
} catch (PDOException $e) {
    error_log("DB error fetching shop: " . $e->getMessage());
    $shopname = '';
}
$profilePic = isset($distributor['profile_pic']) && $distributor['profile_pic'] ? $distributor['profile_pic'] : "images/profile.jpg";

// Fetch full container data for onsite ordering
try {
    $stmt = $conn->prepare(
      "SELECT c.container_id, c.shop_id, ct.type, c.container_image, c.stock_quantity,
        c.damaged_container, c.missing_container, c.price_with_container, c.price_refill
      FROM container c
      JOIN container_type ct ON c.container_type_id = ct.container_type_id
      JOIN shop s ON c.shop_id = s.shop_id
      WHERE s.distributor_id = ?"
    );
    $stmt->execute([$distributor_id]);
    $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("DB error fetching containers: " . $e->getMessage());
    $containers = [];
}

// Build maps for JS: price map and full container data map
$container_prices = [];
$container_map = [];
foreach ($containers as $container) {
    $id = (string)$container['container_id'];
    $price_with = isset($container['price_with_container']) && $container['price_with_container'] !== null
        ? (float)$container['price_with_container']
        : 0.0;
    $price_refill = isset($container['price_refill']) && $container['price_refill'] !== null
        ? (float)$container['price_refill']
        : 0.0;
    
    $container_prices[$id] = $price_with;
    $container_map[$id] = [
        'container_id' => (int)$container['container_id'],
        'shop_id' => (int)$container['shop_id'],
        'type' => $container['type'],
        'price_with_container' => $price_with,
        'price_refill' => $price_refill,
        'stock_quantity' => (int)($container['stock_quantity'] ?? 0),
    ];
}

$success = '';
$error = '';

// Fetch onsite orders for this distributor (pagination)
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

try {
    $shopStmt = $conn->prepare("SELECT shop_id FROM shop WHERE distributor_id = ?");
    $shopStmt->execute([$distributor_id]);
    $shopIds = $shopStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("DB error fetching shop IDs: " . $e->getMessage());
    $shopIds = [];
}

$totalOnsiteOrders = 0;
$onsiteOrders = [];

if (!empty($shopIds)) {
    $in = str_repeat('?,', count($shopIds) - 1) . '?';
    try {
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM onsite_order WHERE shop_id IN ($in)");
        $countStmt->execute($shopIds);
        $totalOnsiteOrders = (int)$countStmt->fetchColumn();

        $ordersStmt = $conn->prepare("
            SELECT oo.*, 
                   ct.type AS container_type,
                   s.name AS shop_name
            FROM onsite_order oo
            LEFT JOIN container c ON oo.container_id = c.container_id
            LEFT JOIN container_type ct ON c.container_type_id = ct.container_type_id
            LEFT JOIN shop s ON oo.shop_id = s.shop_id
            WHERE oo.shop_id IN ($in)
            ORDER BY oo.order_date DESC
            LIMIT ? OFFSET ?
        ");
        $params = array_merge($shopIds, [$perPage, $offset]);
        $ordersStmt->execute($params);
        $onsiteOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("DB error fetching onsite orders: " . $e->getMessage());
        $onsiteOrders = [];
    }
}

// Handle POST (create new onsite order)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $container_id = isset($_POST['container_id']) && $_POST['container_id'] !== '' ? intval($_POST['container_id']) : null;
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    $with_container = isset($_POST['with_container']) ? intval($_POST['with_container']) : 1;

    $shop_id = null;
    $price = 0.0;
    $container_param = null;

    // Validation
    if ($with_container && !$container_id) {
        $error = "Please select a container when ordering with container.";
    } elseif (!$quantity || $quantity < 1) {
        $error = "Please enter a valid quantity (minimum 1).";
    } elseif ($quantity > 1000) {
        $error = "Quantity cannot exceed 1000.";
    } else {
        if ($with_container) {
            // Get shop_id and price_with_container, stock
            try {
                $stmt = $conn->prepare("SELECT shop_id, price_with_container, stock_quantity FROM container WHERE container_id = ?");
                $stmt->execute([$container_id]);
                $container = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $container = false;
                error_log("DB error fetching single container: " . $e->getMessage());
            }

            if (!$container) {
                $error = "Container not found. Please select a valid container.";
            } else {
                $shop_id = (int)$container['shop_id'];
                $price = isset($container['price_with_container']) ? (float)$container['price_with_container'] : 0.0;
                $current_stock = isset($container['stock_quantity']) ? (int)$container['stock_quantity'] : 0;

                if ($price <= 0) {
                    $error = "Container price is invalid. Please contact support.";
                } elseif ($current_stock < $quantity) {
                    $error = "Insufficient stock. Available: {$current_stock}, Requested: {$quantity}";
                } else {
                    $container_param = $container_id;
                }
            }
        } else {
            // Without container: try to use selected container's refill price
            try {
                if ($container_id) {
                    $stmt = $conn->prepare("
                        SELECT c.container_id, c.shop_id, c.price_refill
                        FROM container c
                        JOIN shop s ON c.shop_id = s.shop_id
                        WHERE c.container_id = ? AND s.distributor_id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$container_id, $distributor_id]);
                    $cont = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $cont = false;
                }
            } catch (PDOException $e) {
                error_log("DB error fetching refill container: " . $e->getMessage());
                $cont = false;
            }

            if ($cont) {
                $container_param = (int)$cont['container_id'];
                $shop_id = (int)$cont['shop_id'];
                $price = isset($cont['price_refill']) ? (float)$cont['price_refill'] : 0.0;
            } else {
                // fallback to first container under this distributor
                try {
                    $stmt = $conn->prepare("
                        SELECT c.container_id, c.shop_id, c.price_refill
                        FROM container c
                        JOIN shop s ON c.shop_id = s.shop_id
                        WHERE s.distributor_id = ?
                        ORDER BY c.container_id ASC
                        LIMIT 1
                    ");
                    $stmt->execute([$distributor_id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    error_log("DB error fetching first container fallback: " . $e->getMessage());
                    $row = false;
                }

                $container_param = isset($row['container_id']) ? (int)$row['container_id'] : null;
                $shop_id = isset($row['shop_id']) ? (int)$row['shop_id'] : null;
                $price = isset($row['price_refill']) ? (float)$row['price_refill'] : 0.0;
            }

            if (!$shop_id) {
                $error = "No shop found for this distributor. Please contact support.";
            } elseif ($price <= 0) {
                $error = "Refill price is not set. Please set a refill price for your containers.";
            } elseif (!$container_param) {
                $error = "No container found. Please add containers to your shop.";
            }
        }

        // Final validation before insert
        if (!$error && $shop_id && $price > 0) {
            if (!$container_param && $with_container) {
                $error = "Container information is missing. Please select a container or contact support.";
            } else {
                $total_amount = round($price * $quantity, 2);

                if ($total_amount <= 0) {
                    $error = "Total amount is invalid. Please check quantity and price.";
                } else {
                    try {
                        $conn->beginTransaction();

                        // Insert order
                        $stmt = $conn->prepare("INSERT INTO onsite_order (shop_id, container_id, quantity, price, total_amount, order_date) VALUES (?, ?, ?, ?, ?, NOW())");
                        $result = $stmt->execute([$shop_id, $container_param, $quantity, $price, $total_amount]);

                        if ($result) {
                            // If ordering with container, decrement stock
                            if ($with_container && $container_id) {
                                $update_stmt = $conn->prepare("UPDATE container SET stock_quantity = stock_quantity - ? WHERE container_id = ? AND stock_quantity >= ?");
                                $update_stmt->execute([$quantity, $container_id, $quantity]);

                                if ($update_stmt->rowCount() <= 0) {
                                    throw new Exception("Failed to update inventory. Stock may be insufficient.");
                                }
                            }

                            $conn->commit();

                            if (ob_get_level() > 0) {
                                ob_end_clean();
                            }
                            header('Location: onsite.php?success=1');
                            exit;
                        } else {
                            $conn->rollBack();
                            $error = "Failed to save transaction. Please try again.";
                        }
                    } catch (PDOException $e) {
                        if ($conn->inTransaction()) {
                            $conn->rollBack();
                        }
                        $error = "Database error: " . htmlspecialchars($e->getMessage());
                        error_log("Onsite order error (PDO): " . $e->getMessage());
                    } catch (Exception $e) {
                        if ($conn->inTransaction()) {
                            $conn->rollBack();
                        }
                        $error = htmlspecialchars($e->getMessage());
                        error_log("Onsite order error: " . $e->getMessage());
                    }
                }
            }
        }
    }
}

// Check for success message from redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = "Onsite transaction completed successfully!";
}

// Prepare JS JSON payloads
$container_prices_json = json_encode($container_prices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_NUMERIC_CHECK);
$container_map_json = json_encode($container_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_NUMERIC_CHECK);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Onsite Ordering | Tuy PureFlow Distributor</title>
  <script src="https://cdn.tailwindcss.com"></script>
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
  </style>
</head>
<body class="flex bg-gray-100 min-h-screen">
  <?php include 'sidebar.php'; ?>
  <div class="ml-64 flex flex-col flex-1 min-h-screen">
    <?php include 'header.php'; ?>
    <main class="flex-1 p-6 overflow-x-auto bg-gradient-to-br from-gray-50 to-blue-50">
      <div class="mb-8">
        <h1 class="text-4xl font-bold gradient-text mb-2 flex items-center gap-3">
          <i class="fas fa-store text-blue-600"></i>
          Onsite Ordering
        </h1>
        <p class="text-gray-600">Process orders directly at your shop</p>
      </div>
      <div class="w-full max-w-4xl mx-auto">
        <!-- Toasts -->
        <div id="toast-success" class="fixed top-6 right-6 z-50 transition-opacity duration-500 opacity-0 pointer-events-none">
          <?php if ($success): ?>
            <div class="bg-green-600 text-white px-4 py-2 rounded shadow-lg" role="status">
              <?= htmlspecialchars($success) ?>
            </div>
          <?php endif; ?>
        </div>
        <div id="toast-error" class="fixed top-6 right-6 z-50 transition-opacity duration-500 opacity-0 pointer-events-none">
          <?php if ($error): ?>
            <div class="bg-red-600 text-white px-4 py-2 rounded shadow-lg" role="alert">
              <?= htmlspecialchars($error) ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Order Form -->
        <form method="POST" class="bg-white p-6 rounded-xl shadow-lg border border-gray-200 max-w-lg mx-auto fade-in mb-8" id="onsiteForm">
          <?php if ($error): ?>
            <div class="mb-4 bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
              <div class="flex items-center gap-2">
                <i class="fas fa-exclamation-circle text-red-600"></i>
                <p class="text-red-700 font-medium"><?= htmlspecialchars($error) ?></p>
              </div>
            </div>
          <?php endif; ?>

          <div class="mb-4">
            <label class="block font-semibold text-gray-700 mb-2">
              <i class="fas fa-shopping-cart text-blue-600 mr-2"></i>Order Mode
            </label>
            <div class="flex gap-4 items-center">
              <label class="inline-flex items-center cursor-pointer">
                <input type="radio" name="with_container" value="1" checked class="mr-2">
                <span class="text-gray-700">With container</span>
              </label>
              <label class="inline-flex items-center cursor-pointer">
                <input type="radio" name="with_container" value="0" class="mr-2">
                <span class="text-gray-700">Without container (Refill only)</span>
              </label>
            </div>
          </div>

          <div class="mb-4">
            <label class="block font-semibold text-gray-700 mb-2">
              <i class="fas fa-box text-blue-600 mr-2"></i>Container Type
            </label>
            <select name="container_id" id="container_id" class="border border-gray-300 rounded-lg px-4 py-2 w-full focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
              <option value="">Select Container</option>
              <?php foreach ($containers as $container): 
                $cid = htmlspecialchars($container['container_id']);
                $stock = intval($container['stock_quantity'] ?? 0);
                $priceWith = isset($container['price_with_container']) && $container['price_with_container'] !== null ? (float)$container['price_with_container'] : 0;
                $priceRefill = isset($container['price_refill']) && $container['price_refill'] !== null ? (float)$container['price_refill'] : 0;
                $priceWithFormatted = number_format($priceWith, 2, '.', '');
                $priceRefillFormatted = number_format($priceRefill, 2, '.', '');
              ?>
                <option
                  value="<?= $cid ?>"
                  data-shop="<?= htmlspecialchars($container['shop_id']) ?>"
                  data-type="<?= htmlspecialchars($container['type']) ?>"
                  data-stock="<?= $stock ?>"
                  data-price="<?= $priceWithFormatted ?>"
                  data-refill="<?= $priceRefillFormatted ?>"
                >
                  <?= htmlspecialchars($container['type']) ?> 
                  <?php if ($stock > 0): ?>
                    (Stock: <?= $stock ?>) - ₱<?= number_format($priceWith, 2) ?>
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-gray-500 mt-1" id="containerHint">Select a container type to proceed</p>
          </div>

          <div class="mb-4">
            <label class="block font-semibold text-gray-700 mb-2">
              <i class="fas fa-hashtag text-blue-600 mr-2"></i>Quantity
            </label>
            <input type="number" name="quantity" id="quantity" min="1" max="1000" value="1" required class="border border-gray-300 rounded-lg px-4 py-2 w-full focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            <p class="text-xs text-gray-500 mt-1">Minimum: 1, Maximum: 1000</p>
          </div>

          <div class="mb-6 p-4 bg-blue-50 rounded-lg border border-blue-200">
            <label class="block font-semibold text-gray-700 mb-2">
              <i class="fas fa-calculator text-blue-600 mr-2"></i>Total Price
            </label>
            <div id="totalPrice" class="font-bold text-2xl text-blue-700">₱0.00</div>
            <div id="priceBreakdown" class="text-sm text-gray-600 mt-2"></div>
          </div>

          <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-blue-700 text-white px-6 py-3 rounded-lg font-semibold hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg hover:shadow-xl flex items-center justify-center gap-2">
            <i class="fas fa-check-circle"></i>
            Complete Transaction
          </button>
        </form>

        <!-- Onsite Orders List -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden fade-in">
          <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white px-6 py-4">
            <h2 class="text-xl font-bold flex items-center gap-2">
              <i class="fas fa-list"></i>
              Recent Onsite Orders
            </h2>
            <p class="text-sm text-blue-100 mt-1">Total: <?= number_format($totalOnsiteOrders) ?> orders</p>
          </div>

          <?php if (empty($onsiteOrders)): ?>
            <div class="p-12 text-center">
              <i class="fas fa-inbox text-gray-300 text-5xl mb-4"></i>
              <p class="text-gray-500 text-lg">No onsite orders yet</p>
              <p class="text-gray-400 text-sm mt-2">Orders will appear here after you complete transactions</p>
            </div>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="min-w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                  <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Order ID</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Shop</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Container Type</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider">Quantity</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider">Price</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider">Total Amount</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Order Date</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                  <?php foreach ($onsiteOrders as $order): ?>
                    <tr class="hover:bg-gray-50 transition-colors">
                      <td class="px-4 py-3 text-sm font-medium text-gray-900">
                        #<?= htmlspecialchars($order['onsite_order_id'] ?? '') ?>
                      </td>
                      <td class="px-4 py-3 text-sm text-gray-700">
                        <?= htmlspecialchars($order['shop_name'] ?? ('Shop #' . ($order['shop_id'] ?? ''))) ?>
                      </td>
                      <td class="px-4 py-3 text-sm text-gray-700">
                        <?php if (!empty($order['container_id']) && !empty($order['container_type'])): ?>
                          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            <?= htmlspecialchars($order['container_type']) ?>
                          </span>
                        <?php else: ?>
                          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                            Refill Only
                          </span>
                        <?php endif; ?>
                      </td>
                      <td class="px-4 py-3 text-sm text-right font-semibold text-gray-900">
                        <?= htmlspecialchars($order['quantity']) ?>
                      </td>
                      <td class="px-4 py-3 text-sm text-right text-gray-700">
                        ₱<?= number_format((float)($order['price'] ?? 0), 2) ?>
                      </td>
                      <td class="px-4 py-3 text-sm text-right font-bold text-blue-700">
                        ₱<?= number_format((float)($order['total_amount'] ?? 0), 2) ?>
                      </td>
                      <td class="px-4 py-3 text-sm text-gray-600">
                        <div class="flex items-center gap-2">
                          <i class="fas fa-clock text-gray-400 text-xs"></i>
                          <?= isset($order['order_date']) ? date('M j, Y g:i A', strtotime($order['order_date'])) : '' ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-50 border-t border-gray-200">
                  <tr>
                    <td colspan="7" class="px-4 py-3">
                      <?php
                        $totalPages = max(1, (int)ceil($totalOnsiteOrders / $perPage));
                        if ($totalPages > 1):
                      ?>
                        <div class="flex items-center justify-between">
                          <div class="text-sm text-gray-700">
                            Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalOnsiteOrders) ?> of <?= number_format($totalOnsiteOrders) ?> orders
                          </div>
                          <div class="flex gap-2">
                            <?php if ($page > 1): ?>
                              <a href="?page=<?= $page - 1 ?>" class="px-3 py-1 bg-white border border-gray-300 rounded text-sm text-gray-700 hover:bg-gray-50">
                                <i class="fas fa-chevron-left"></i> Previous
                              </a>
                            <?php endif; ?>

                            <span class="px-3 py-1 bg-blue-600 text-white rounded text-sm">
                              Page <?= $page ?> of <?= $totalPages ?>
                            </span>

                            <?php if ($page < $totalPages): ?>
                              <a href="?page=<?= $page + 1 ?>" class="px-3 py-1 bg-white border border-gray-300 rounded text-sm text-gray-700 hover:bg-gray-50">
                                Next <i class="fas fa-chevron-right"></i>
                              </a>
                            <?php endif; ?>
                          </div>
                        </div>
                      <?php else: ?>
                        <div class="text-sm text-gray-700 text-center">
                          Showing all <?= $totalOnsiteOrders ?> orders
                        </div>
                      <?php endif; ?>
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>

  <script>
    // JSON payloads from PHP
    const containerPrices = <?= $container_prices_json ?>;
    const containerData = <?= $container_map_json ?>;

    function updateTotalPrice() {
      const withContainer = document.querySelector('input[name="with_container"]:checked')?.value === '1';
      const containerSelect = document.getElementById('container_id');
      const quantityInput = document.getElementById('quantity');
      const quantity = quantityInput ? Math.max(1, parseInt(quantityInput.value) || 1) : 1;
      const containerHint = document.getElementById('containerHint');
      const priceBreakdown = document.getElementById('priceBreakdown');
      const totalPriceEl = document.getElementById('totalPrice');

      if (!totalPriceEl) return;

      let price = 0;
      let priceType = '';

      if (withContainer) {
        if (containerSelect && containerSelect.value) {
          const selectedOption = containerSelect.options[containerSelect.selectedIndex];
          if (selectedOption) {
            const dataPrice = selectedOption.getAttribute('data-price');
            if (dataPrice) {
              price = parseFloat(dataPrice) || 0;
              priceType = 'With Container';
            } else if (containerPrices) {
              const containerId = containerSelect.value;
              price = parseFloat(containerPrices[containerId]) || 0;
              priceType = 'With Container';
            }
          }
          
          if (containerHint) {
            if (price > 0) {
              containerHint.textContent = `Price: ₱${price.toFixed(2)} per container`;
              containerHint.className = 'text-xs text-green-600 mt-1 font-medium';
            } else {
              containerHint.textContent = 'Price not available for this container.';
              containerHint.className = 'text-xs text-red-500 mt-1';
            }
          }
        } else {
          if (containerHint) {
            containerHint.textContent = 'Please select a container type';
            containerHint.className = 'text-xs text-gray-500 mt-1';
          }
        }
      } else {
        if (containerSelect && containerSelect.value) {
          const selectedOption = containerSelect.options[containerSelect.selectedIndex];
          if (selectedOption) {
            const dataRefill = selectedOption.getAttribute('data-refill');
            if (dataRefill) {
              price = parseFloat(dataRefill) || 0;
              priceType = 'Refill Only';
            }
          }
        }
        
        if (price === 0 && containerData) {
          const keys = Object.keys(containerData);
          if (keys.length > 0) {
            const first = containerData[keys[0]];
            if (first && first.price_refill) {
              price = parseFloat(first.price_refill) || 0;
              priceType = 'Refill Only';
            }
          }
        }
        
        if (containerHint) {
          if (price > 0) {
            containerHint.textContent = `Refill price: ₱${price.toFixed(2)}`;
            containerHint.className = 'text-xs text-blue-600 mt-1 font-medium';
          } else {
            containerHint.textContent = 'Refill price not set for containers.';
            containerHint.className = 'text-xs text-red-500 mt-1';
          }
        }
      }

      // Calculate and display total
      const total = price * quantity;
      const formattedTotal = total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      
      totalPriceEl.textContent = '₱' + formattedTotal;
      
      if (priceBreakdown) {
        if (price > 0) {
          const formattedPrice = price.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
          priceBreakdown.innerHTML = `${quantity} × ₱${formattedPrice} = <strong>₱${formattedTotal}</strong> (${priceType})`;
        } else {
          priceBreakdown.innerHTML = withContainer ? 'Select container to see price' : 'Select container or enter quantity';
        }
      }
    }

    function toggleContainerOption() {
      const withContainer = document.querySelector('input[name="with_container"]:checked')?.value === '1';
      const containerSelect = document.getElementById('container_id');
      if (containerSelect) {
        containerSelect.required = withContainer;
        containerSelect.classList.toggle('opacity-50', !withContainer);
      }
      updateTotalPrice();
    }

    document.addEventListener('DOMContentLoaded', function(){
      const containerSelect = document.getElementById('container_id');
      const quantityInput = document.getElementById('quantity');

      if (containerSelect) {
        containerSelect.addEventListener('change', updateTotalPrice);
        containerSelect.addEventListener('input', updateTotalPrice);
      }
      
      if (quantityInput) {
        quantityInput.addEventListener('input', updateTotalPrice);
        quantityInput.addEventListener('change', updateTotalPrice);
      }
      
      const radioButtons = document.querySelectorAll('input[name="with_container"]');
      radioButtons.forEach(radio => {
        radio.addEventListener('change', toggleContainerOption);
      });
      
      toggleContainerOption();
      updateTotalPrice();

      // Show toasts
      function showToastIfPresent(id) {
        const wrapper = document.getElementById(id);
        if (!wrapper) return;
        const msg = wrapper.firstElementChild;
        if (!msg || !msg.textContent.trim()) return;

        wrapper.classList.remove('pointer-events-none', 'opacity-0');
        wrapper.classList.add('opacity-100');

        setTimeout(() => {
          wrapper.classList.remove('opacity-100');
          wrapper.classList.add('opacity-0');
          setTimeout(() => {
            if (wrapper.parentNode) wrapper.parentNode.removeChild(wrapper);
            if (id === 'toast-success') {
              const form = document.getElementById('onsiteForm');
              if (form) form.reset();
              const withContainerRadio = document.querySelector('input[name="with_container"][value="1"]');
              if (withContainerRadio) withContainerRadio.checked = true;
              toggleContainerOption();
              updateTotalPrice();
            }
          }, 600);
        }, 5000);
      }

      showToastIfPresent('toast-success');
      showToastIfPresent('toast-error');
    });
  </script>
</body>
</html>
