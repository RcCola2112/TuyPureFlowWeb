  <?php
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);
  session_start();
  if (!isset($_SESSION['distributor_id'])) {
      echo '<script>window.location.replace("../index.html");</script>';
      exit;
  }
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: Sat, 1 Jan 2000 00:00:00 GMT");
  include '../db.php';
  $currentPage = 'orders';

  // Get distributor info from session or database
  $distributor_id = $_SESSION['distributor_id'] ?? 1;
  $stmt = $conn->prepare("SELECT * FROM distributor WHERE distributor_id = ?");
  $stmt->execute([$distributor_id]);
  $distributor = $stmt->fetch();

  $username = $distributor['name'] ?? '';
  $stmtShop = $conn->prepare("SELECT name FROM shop WHERE distributor_id = ? LIMIT 1");
  $stmtShop->execute([$distributor_id]);
  $shopname = $stmtShop->fetchColumn() ?: '';
  $profilePic = isset($distributor['profile_pic']) && $distributor['profile_pic'] ? $distributor['profile_pic'] : "images/profile.jpg";

  // Handle order status update (single or bulk)
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      include_once '../includes/send_notification.php';
      
      // Handle schedule delivery
      if (!empty($_POST['schedule_order_id']) && !empty($_POST['delivery_date']) && !empty($_POST['delivery_time'])) {
        $order_id = intval($_POST['schedule_order_id']);
        $delivery_date = $_POST['delivery_date'];
        $delivery_time = $_POST['delivery_time'];
        $delivery_datetime = $delivery_date . ' ' . $delivery_time . ':00';
        
        // Check if delivery_record exists
        $check = $conn->prepare("SELECT delivery_id FROM delivery_record WHERE order_id = ?");
        $check->execute([$order_id]);
        $existing = $check->fetch();
        
        // Update orders table with scheduled date/time
        $update_order = $conn->prepare("UPDATE orders SET scheduled_date = ?, scheduled_time = ?, is_scheduled = 1 WHERE order_id = ?");
        $update_order->execute([$delivery_date, $delivery_time, $order_id]);
        
        // Also update/create delivery_record
        if ($existing) {
          // Update existing delivery record
          $update_stmt = $conn->prepare("UPDATE delivery_record SET status = 'Scheduled', scheduled_date = ?, scheduled_time = ? WHERE order_id = ?");
          $update_stmt->execute([$delivery_date, $delivery_time, $order_id]);
        } else {
          // Insert new delivery record - check if scheduled_date and scheduled_time columns exist
          // Try with all columns first, if it fails, use minimal columns
          try {
            $ins = $conn->prepare("INSERT INTO delivery_record (order_id, status, scheduled_date, scheduled_time) VALUES (?, 'Scheduled', ?, ?)");
            $ins->execute([$order_id, $delivery_date, $delivery_time]);
          } catch (PDOException $e) {
            // If columns don't exist, just insert with status
            $ins = $conn->prepare("INSERT INTO delivery_record (order_id, status) VALUES (?, 'Scheduled')");
            $ins->execute([$order_id]);
          }
        }
        
        // Update order status to "Out for Delivery" or keep current status
        $stmtOrder = $conn->prepare("SELECT consumer_id FROM orders WHERE order_id = ?");
        $stmtOrder->execute([$order_id]);
        $consumer_id = $stmtOrder->fetchColumn();
        
        $msg = 'Your order #' . $order_id . ' has been scheduled for delivery on ' . date('F j, Y', strtotime($delivery_date)) . ' at ' . date('g:i A', strtotime($delivery_time)) . '.';
        $msgDist = 'Order #' . $order_id . ' scheduled for delivery on ' . date('F j, Y', strtotime($delivery_date)) . ' at ' . date('g:i A', strtotime($delivery_time)) . '.';
        
        if ($consumer_id) {
          sendNotification($conn, $consumer_id, 'Consumer', $msg, 'Delivery Scheduled');
        }
        sendNotification($conn, $distributor_id, 'Didstributor', $msgDist, 'Delivery Scheduled');
        
        header('Location: orders.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
        exit;
      }
      
      // Bulk update
      if (!empty($_POST['bulk_status']) && !empty($_POST['selected_orders']) && is_array($_POST['selected_orders'])) {
        $order_ids = $_POST['selected_orders'];
        $new_status = $_POST['bulk_status'];
        $validation_errors = [];
        $validated_orders = [];
        
        // Validate scheduled orders before completing
        if ($new_status === 'Completed') {
          foreach ($order_ids as $oid) {
            $orderCheck = $conn->prepare("SELECT is_scheduled, scheduled_date, scheduled_time, recurrence_type, next_scheduled_date, parent_order_id, consumer_id, shop_id, address_id, total_amount FROM orders WHERE order_id = ?");
            $orderCheck->execute([$oid]);
            $orderData = $orderCheck->fetch(PDO::FETCH_ASSOC);
            
            if ($orderData && $orderData['is_scheduled'] == 1) {
              // Check if scheduled date has passed
              if (!empty($orderData['scheduled_date'])) {
                $scheduled_datetime = $orderData['scheduled_date'];
                if (!empty($orderData['scheduled_time'])) {
                  $scheduled_datetime .= ' ' . $orderData['scheduled_time'];
                } else {
                  $scheduled_datetime .= ' 00:00:00';
                }
                
                $scheduled_timestamp = strtotime($scheduled_datetime);
                $current_timestamp = time();
                
                // Allow completion if scheduled date is today or in the past
                if ($scheduled_timestamp > $current_timestamp) {
                  $validation_errors[] = "Order #{$oid} cannot be completed yet. Scheduled for " . date('F j, Y g:i A', $scheduled_timestamp);
                  continue;
                }
              }
              
              // Handle recurring orders - create next order if applicable
              if (!empty($orderData['recurrence_type']) && $orderData['recurrence_type'] !== 'none' && !empty($orderData['next_scheduled_date'])) {
                try {
                  $conn->beginTransaction();
                  
                  // Get order items to copy
                  $itemsStmt = $conn->prepare("SELECT container_id, quantity, price, purchase_type FROM order_items WHERE order_id = ?");
                  $itemsStmt->execute([$oid]);
                  $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                  
                  // Create next scheduled order
                  $nextOrderStmt = $conn->prepare("INSERT INTO orders (consumer_id, shop_id, address_id, total_amount, status, order_date, is_scheduled, scheduled_date, scheduled_time, recurrence_type, next_scheduled_date, parent_order_id) VALUES (?, ?, ?, ?, 'Pending', NOW(), 1, ?, ?, ?, ?, ?)");
                  
                  // Calculate next scheduled date after next_scheduled_date
                  $next_scheduled = $orderData['next_scheduled_date'];
                  $next_after_that = null;
                  
                  if ($orderData['recurrence_type'] === 'weekly') {
                    $next_after_that = date('Y-m-d', strtotime($next_scheduled . ' +7 days'));
                  } elseif ($orderData['recurrence_type'] === 'bi-weekly' || $orderData['recurrence_type'] === 'biweekly') {
                    $next_after_that = date('Y-m-d', strtotime($next_scheduled . ' +14 days'));
                  } elseif ($orderData['recurrence_type'] === 'monthly') {
                    $next_after_that = date('Y-m-d', strtotime($next_scheduled . ' +1 month'));
                  }
                  
                  $nextOrderStmt->execute([
                    $orderData['consumer_id'],
                    $orderData['shop_id'],
                    $orderData['address_id'],
                    $orderData['total_amount'],
                    $next_scheduled,
                    $orderData['scheduled_time'],
                    $orderData['recurrence_type'],
                    $next_after_that,
                    $orderData['parent_order_id'] ?: $oid // Use current order as parent if no parent exists
                  ]);
                  
                  $new_order_id = $conn->lastInsertId();
                  
                  // Copy order items
                  foreach ($orderItems as $item) {
                    $itemStmt = $conn->prepare("INSERT INTO order_items (order_id, container_id, quantity, price, purchase_type) VALUES (?, ?, ?, ?, ?)");
                    $itemStmt->execute([$new_order_id, $item['container_id'], $item['quantity'], $item['price'], $item['purchase_type']]);
                  }
                  
                  $conn->commit();
                  
                  // Notify consumer about next scheduled order
                  if ($orderData['consumer_id']) {
                    $recurMsg = 'Your recurring order #' . $oid . ' has been completed. Next delivery scheduled for ' . date('F j, Y', strtotime($next_scheduled)) . '. Order #' . $new_order_id . ' has been created.';
                    sendNotification($conn, $orderData['consumer_id'], 'Consumer', $recurMsg, 'Recurring Order Created');
                  }
                } catch (Exception $e) {
                  $conn->rollBack();
                  $validation_errors[] = "Order #{$oid}: Failed to create next recurring order. " . $e->getMessage();
                  continue;
                }
              }
            }
            
            $validated_orders[] = $oid;
          }
          
          // If there are validation errors, show them and don't proceed
          if (!empty($validation_errors)) {
            $_SESSION['order_validation_errors'] = $validation_errors;
            header('Location: orders.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
            exit;
          }
          
          // Use only validated orders
          $order_ids = $validated_orders;
        }
        
        if (!empty($order_ids)) {
          $in = str_repeat('?,', count($order_ids) - 1) . '?';
          $stmt2 = $conn->prepare("UPDATE orders SET status = ? WHERE order_id IN ($in)");
          $params = array_merge([$new_status], $order_ids);
          $stmt2->execute($params);
          foreach ($order_ids as $oid) {
            $check = $conn->prepare("SELECT delivery_id FROM delivery_record WHERE order_id = ?");
            $check->execute([$oid]);
            if (!$check->fetch()) {
              $ins = $conn->prepare("INSERT INTO delivery_record (order_id, status) VALUES (?, 'Scheduled')");
              $ins->execute([$oid]);
            }
            $stmtOrder = $conn->prepare("SELECT consumer_id FROM orders WHERE order_id = ?");
            $stmtOrder->execute([$oid]);
            $consumer_id = $stmtOrder->fetchColumn();
            $msg = 'Your order has been updated to ' . $new_status . '.';
            $msgDist = 'Order #' . $oid . ' status updated to ' . $new_status . '.';
            if ($consumer_id) {
              sendNotification($conn, $consumer_id, 'Consumer', $msg, 'Order Status Updated');
            }
              sendNotification($conn, $distributor_id, 'Didstributor', $msgDist, 'Order Status Updated');
          }
        }
      }
      // Single update
      if (!empty($_POST['order_id']) && !empty($_POST['single_status'])) {
        $order_id = intval($_POST['order_id']);
        $new_status = $_POST['single_status'];
        
        // Validate scheduled orders before completing
        if ($new_status === 'Completed') {
          $orderCheck = $conn->prepare("SELECT is_scheduled, scheduled_date, scheduled_time, recurrence_type, next_scheduled_date, parent_order_id, consumer_id, shop_id, address_id, total_amount FROM orders WHERE order_id = ?");
          $orderCheck->execute([$order_id]);
          $orderData = $orderCheck->fetch(PDO::FETCH_ASSOC);
          
          if ($orderData && $orderData['is_scheduled'] == 1) {
            // Check if scheduled date has passed
            if (!empty($orderData['scheduled_date'])) {
              $scheduled_datetime = $orderData['scheduled_date'];
              if (!empty($orderData['scheduled_time'])) {
                $scheduled_datetime .= ' ' . $orderData['scheduled_time'];
              } else {
                $scheduled_datetime .= ' 00:00:00';
              }
              
              $scheduled_timestamp = strtotime($scheduled_datetime);
              $current_timestamp = time();
              
              // Allow completion if scheduled date is today or in the past
              if ($scheduled_timestamp > $current_timestamp) {
                $_SESSION['order_validation_error'] = "Order #{$order_id} cannot be completed yet. It is scheduled for " . date('F j, Y g:i A', $scheduled_timestamp) . ". Please wait until the scheduled date.";
                header('Location: orders.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
                exit;
              }
            }
            
            // Handle recurring orders - create next order if applicable
            if (!empty($orderData['recurrence_type']) && $orderData['recurrence_type'] !== 'none' && !empty($orderData['next_scheduled_date'])) {
              try {
                $conn->beginTransaction();
                
                // Get order items to copy
                $itemsStmt = $conn->prepare("SELECT container_id, quantity, price, purchase_type FROM order_items WHERE order_id = ?");
                $itemsStmt->execute([$order_id]);
                $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Create next scheduled order
                $nextOrderStmt = $conn->prepare("INSERT INTO orders (consumer_id, shop_id, address_id, total_amount, status, order_date, is_scheduled, scheduled_date, scheduled_time, recurrence_type, next_scheduled_date, parent_order_id) VALUES (?, ?, ?, ?, 'Pending', NOW(), 1, ?, ?, ?, ?, ?)");
                
                // Calculate next scheduled date after next_scheduled_date
                $next_scheduled = $orderData['next_scheduled_date'];
                $next_after_that = null;
                
                if ($orderData['recurrence_type'] === 'weekly') {
                  $next_after_that = date('Y-m-d', strtotime($next_scheduled . ' +7 days'));
                } elseif ($orderData['recurrence_type'] === 'bi-weekly' || $orderData['recurrence_type'] === 'biweekly') {
                  $next_after_that = date('Y-m-d', strtotime($next_scheduled . ' +14 days'));
                } elseif ($orderData['recurrence_type'] === 'monthly') {
                  $next_after_that = date('Y-m-d', strtotime($next_scheduled . ' +1 month'));
                }
                
                $nextOrderStmt->execute([
                  $orderData['consumer_id'],
                  $orderData['shop_id'],
                  $orderData['address_id'],
                  $orderData['total_amount'],
                  $next_scheduled,
                  $orderData['scheduled_time'],
                  $orderData['recurrence_type'],
                  $next_after_that,
                  $orderData['parent_order_id'] ?: $order_id // Use current order as parent if no parent exists
                ]);
                
                $new_order_id = $conn->lastInsertId();
                
                // Copy order items
                foreach ($orderItems as $item) {
                  $itemStmt = $conn->prepare("INSERT INTO order_items (order_id, container_id, quantity, price, purchase_type) VALUES (?, ?, ?, ?, ?)");
                  $itemStmt->execute([$new_order_id, $item['container_id'], $item['quantity'], $item['price'], $item['purchase_type']]);
                }
                
                $conn->commit();
                
                // Notify consumer about next scheduled order
                if ($orderData['consumer_id']) {
                  $recurMsg = 'Your recurring order #' . $order_id . ' has been completed. Next delivery scheduled for ' . date('F j, Y', strtotime($next_scheduled)) . '. Order #' . $new_order_id . ' has been created.';
                  sendNotification($conn, $orderData['consumer_id'], 'Consumer', $recurMsg, 'Recurring Order Created');
                }
                
                $msgDist = 'Order #' . $order_id . ' completed. Next recurring order #' . $new_order_id . ' created for ' . date('F j, Y', strtotime($next_scheduled)) . '.';
                sendNotification($conn, $distributor_id, 'Didstributor', $msgDist, 'Recurring Order Created');
              } catch (Exception $e) {
                $conn->rollBack();
                $_SESSION['order_validation_error'] = "Failed to create next recurring order: " . $e->getMessage();
                header('Location: orders.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
                exit;
              }
            }
          }
        }
        
        $stmt2 = $conn->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
        $stmt2->execute([$new_status, $order_id]);
        $check = $conn->prepare("SELECT delivery_id FROM delivery_record WHERE order_id = ?");
        $check->execute([$order_id]);
        if (!$check->fetch()) {
          $ins = $conn->prepare("INSERT INTO delivery_record (order_id, status) VALUES (?, 'Scheduled')");
          $ins->execute([$order_id]);
        }
        $stmtOrder = $conn->prepare("SELECT consumer_id FROM orders WHERE order_id = ?");
        $stmtOrder->execute([$order_id]);
        $consumer_id = $stmtOrder->fetchColumn();
        $msg = 'Your order has been updated to ' . $new_status . '.';
        $msgDist = 'Order #' . $order_id . ' status updated to ' . $new_status . '.';
        if ($consumer_id) {
          sendNotification($conn, $consumer_id, 'Consumer', $msg, 'Order Status Updated');
        }
          sendNotification($conn, $distributor_id, 'Didstributor', $msgDist, 'Order Status Updated');
      }
        // SMS Order single update - Create order in orders and order_items tables
        if (!empty($_POST['sms_order_id']) && !empty($_POST['sms_single_status'])) {
          $sms_order_id = intval($_POST['sms_order_id']);
          $new_status = $_POST['sms_single_status'];
          
          // Get SMS order details
          $smsOrderStmt = $conn->prepare("SELECT * FROM sms_orders WHERE sms_order_id = ?");
          $smsOrderStmt->execute([$sms_order_id]);
          $smsOrder = $smsOrderStmt->fetch();
          
          if ($smsOrder) {
            $sender_phone = preg_replace('/[^0-9]/', '', $smsOrder['sender_phone_number'] ?? '');
            $shop_id = $smsOrder['shop_id'];
            $container_type_id = $smsOrder['container_type_id'];
            $quantity = $smsOrder['quantity'] ?? 0;
            $message = $smsOrder['message'] ?? '';
            
            // Find consumer by phone number
            $consumer_stmt = $conn->prepare("
              SELECT c.consumer_id 
              FROM consumer c 
              WHERE REPLACE(REPLACE(REPLACE(c.contact_number, '+', ''), ' ', ''), '-', '') LIKE ?
              LIMIT 1
            ");
            $consumer_stmt->execute(['%' . preg_replace('/^\+?63|^0/', '', $sender_phone)]);
            $consumer = $consumer_stmt->fetch();
            $consumer_id = $consumer ? $consumer['consumer_id'] : null;
            
            // Get consumer's default address
            $address_id = null;
            if ($consumer_id) {
              $addr_stmt = $conn->prepare("SELECT address_id FROM address WHERE consumer_id = ? AND is_default = 1 LIMIT 1");
              $addr_stmt->execute([$consumer_id]);
              $address = $addr_stmt->fetch();
              if (!$address) {
                $addr_stmt = $conn->prepare("SELECT address_id FROM address WHERE consumer_id = ? LIMIT 1");
                $addr_stmt->execute([$consumer_id]);
                $address = $addr_stmt->fetch();
              }
              $address_id = $address ? $address['address_id'] : null;
            }
            
            // Get container_id from container table using container_type_id and shop_id
            $container_id = null;
            $price_per_unit = 0;
            $purchase_type = 'with_container';
            
            if ($container_type_id && $shop_id) {
              // Check message to determine if it's refill or with container
              $message_lower = strtolower($message);
              $is_refill = (strpos($message_lower, 'refill') !== false);
              
              // Get container details
              $container_stmt = $conn->prepare("
                SELECT container_id, price_with_container, price_refill 
                FROM container 
                WHERE container_type_id = ? AND shop_id = ? 
                LIMIT 1
              ");
              $container_stmt->execute([$container_type_id, $shop_id]);
              $container = $container_stmt->fetch();
              
              if ($container) {
                $container_id = $container['container_id'];
                
                if ($is_refill) {
                  $price_per_unit = floatval($container['price_refill'] ?? 0);
                  $purchase_type = 'refill';
                  if ($price_per_unit == 0) {
                    $price_per_unit = floatval($container['price_with_container'] ?? 0);
                    $purchase_type = 'with_container';
                  }
                } else {
                  $price_per_unit = floatval($container['price_with_container'] ?? 0);
                  $purchase_type = 'with_container';
                  if ($price_per_unit == 0) {
                    $price_per_unit = floatval($container['price_refill'] ?? 0);
                    $purchase_type = 'refill';
                  }
                }
              }
            }
            
            // Calculate total amount
            $total_amount = $price_per_unit * $quantity;
            
            // Only create order if we have required data
            if ($consumer_id && $shop_id && $container_id && $quantity > 0 && $price_per_unit > 0) {
              try {
                $conn->beginTransaction();
                
                // Create order in orders table
                $order_stmt = $conn->prepare("
                  INSERT INTO orders (consumer_id, shop_id, address_id, total_amount, status, order_date) 
                  VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $order_stmt->execute([$consumer_id, $shop_id, $address_id, $total_amount, $new_status]);
                $order_id = $conn->lastInsertId();
                
                // Create order item in order_items table
                $order_item_stmt = $conn->prepare("
                  INSERT INTO order_items (order_id, container_id, quantity, price, purchase_type) 
                  VALUES (?, ?, ?, ?, ?)
                ");
                $order_item_stmt->execute([$order_id, $container_id, $quantity, $price_per_unit, $purchase_type]);
                
                // Delete SMS order since it's been converted to a regular order
                // This removes it from the "Order Thru Text" view
                $delete_stmt = $conn->prepare("DELETE FROM sms_orders WHERE sms_order_id = ?");
                $delete_stmt->execute([$sms_order_id]);
                
                $conn->commit();
                
                // Send notifications
                if ($consumer_id) {
                  $msg = 'Your SMS order has been converted to Order #' . $order_id . ' with status: ' . $new_status . '.';
                  sendNotification($conn, $consumer_id, 'Consumer', $msg, 'Order Status Updated');
                }
                $msgDist = 'SMS Order #' . $sms_order_id . ' converted to Order #' . $order_id . ' with status: ' . $new_status . '.';
                sendNotification($conn, $distributor_id, 'Didstributor', $msgDist, 'Order Status Updated');
                
              } catch (Exception $e) {
                $conn->rollBack();
                // Log error but continue
                error_log("Error creating order from SMS: " . $e->getMessage());
              }
            } else {
              // If we can't create order, check if status should remove it from view
              // Statuses that indicate order has been processed should remove from "Order Thru Text" view
              $processing_statuses = ['Pending', 'Processing', 'Out for Delivery', 'Completed'];
              
              if (in_array($new_status, $processing_statuses)) {
                // Delete SMS order since status indicates it's been processed
                // This removes it from the "Order Thru Text" view
                $delete_stmt = $conn->prepare("DELETE FROM sms_orders WHERE sms_order_id = ?");
                $delete_stmt->execute([$sms_order_id]);
              } else {
                // For other statuses (like Cancelled), just update the status
                $stmt2 = $conn->prepare("UPDATE sms_orders SET status = ? WHERE sms_order_id = ?");
                $stmt2->execute([$new_status, $sms_order_id]);
              }
              
              // Send notifications
              if ($consumer_id) {
                $msg = 'Your SMS order status has been updated to ' . $new_status . '.';
                sendNotification($conn, $consumer_id, 'Consumer', $msg, 'Order Status Updated');
              }
              $msgDist = 'SMS Order #' . $sms_order_id . ' status updated to ' . $new_status . '.';
              sendNotification($conn, $distributor_id, 'Didstributor', $msgDist, 'Order Status Updated');
            }
          }
        }
        header('Location: orders.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
      exit;
  }
  
  // AJAX endpoint to get order info for modal
  if (isset($_GET['get_order_info']) && isset($_GET['order_id'])) {
    $order_id = intval($_GET['order_id']);
    $stmt = $conn->prepare("SELECT is_scheduled, scheduled_date, scheduled_time, recurrence_type, next_scheduled_date, parent_order_id FROM orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $orderInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($orderInfo ?: []);
    exit;
  }

  // Add status filter logic
  $status_filter = isset($_GET['status']) ? $_GET['status'] : 'All';
    
    // Year and Month filters
    $filter_year = isset($_GET['year']) ? intval($_GET['year']) : null;
    $filter_month = isset($_GET['month']) ? intval($_GET['month']) : null;
    
    // Get available years and months from orders
    $years_stmt = $conn->prepare("
      SELECT DISTINCT YEAR(order_date) as year 
      FROM orders o 
      WHERE o.shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?) 
      ORDER BY year DESC
    ");
    $years_stmt->execute([$distributor_id]);
    $available_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $months_stmt = $conn->prepare("
      SELECT DISTINCT MONTH(order_date) as month 
      FROM orders o 
      WHERE o.shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?) 
      ORDER BY month ASC
    ");
    $months_stmt->execute([$distributor_id]);
    $available_months = $months_stmt->fetchAll(PDO::FETCH_COLUMN);
    
  $params = [$distributor_id];

  // Pagination setup for all tabs
  $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
  $perPage = 10;
  $offset = ($page - 1) * $perPage;
  
  if ($status_filter === 'Scheduled Deliveries') {
    // Fetch shop_id(s) for this distributor
    $shopStmt = $conn->prepare("SELECT shop_id FROM shop WHERE distributor_id = ?");
    $shopStmt->execute([$distributor_id]);
    $shopIds = $shopStmt->fetchAll(PDO::FETCH_COLUMN);
    $orders = [];
    $total = 0;
    if (!empty($shopIds)) {
      $in = str_repeat('?,', count($shopIds) - 1) . '?';
      // Get total count - check for orders with scheduled_date in orders table or delivery_record with status = 'Scheduled'
      $countStmt = $conn->prepare("
        SELECT COUNT(DISTINCT o.order_id)
        FROM orders o
        LEFT JOIN delivery_record dr ON o.order_id = dr.order_id
        WHERE o.shop_id IN ($in) 
          AND (o.scheduled_date IS NOT NULL OR dr.status = 'Scheduled')
      ");
      $countStmt->execute($shopIds);
      $total = $countStmt->fetchColumn();
      
      // Get paginated scheduled deliveries - prioritize scheduled_date from orders table
      $scheduledStmt = $conn->prepare("
        SELECT o.*, a.name AS customer_name, a.contact_number, a.street, a.city, a.region AS province, a.zip_code,
               dr.delivery_id, dr.status AS delivery_status,
               o.scheduled_date, o.scheduled_time
        FROM orders o
        LEFT JOIN delivery_record dr ON o.order_id = dr.order_id
        LEFT JOIN address a ON o.address_id = a.address_id
        WHERE o.shop_id IN ($in) 
          AND (o.scheduled_date IS NOT NULL OR dr.status = 'Scheduled')
        ORDER BY o.order_date ASC
        LIMIT $perPage OFFSET $offset
      ");
      $scheduledStmt->execute($shopIds);
      $orders = $scheduledStmt->fetchAll();
    }
  } else if ($status_filter === 'Rated') {
    // Fetch shop_id(s) for this distributor
    $shopStmt = $conn->prepare("SELECT shop_id FROM shop WHERE distributor_id = ?");
    $shopStmt->execute([$distributor_id]);
    $shopIds = $shopStmt->fetchAll(PDO::FETCH_COLUMN);
    $orders = [];
    $total = 0;
    if (!empty($shopIds)) {
      $in = str_repeat('?,', count($shopIds) - 1) . '?';
      // Get total count of rated orders
      $countStmt = $conn->prepare("SELECT COUNT(*) FROM orders o INNER JOIN shop_ratings r ON o.order_id = r.order_id WHERE o.shop_id IN ($in)");
      $countStmt->execute($shopIds);
      $total = $countStmt->fetchColumn();
      // Get paginated rated orders with rating details
      $ratingStmt = $conn->prepare("
        SELECT o.*, a.name AS customer_name, a.contact_number, a.street, a.city, a.region AS province, a.zip_code, r.rating, r.comment, r.created_at AS rated_at
        FROM orders o
        INNER JOIN shop_ratings r ON o.order_id = r.order_id
        LEFT JOIN address a ON o.address_id = a.address_id
        WHERE o.shop_id IN ($in)
        ORDER BY r.created_at DESC
        LIMIT $perPage OFFSET $offset");
      $ratingStmt->execute($shopIds);
      $orders = $ratingStmt->fetchAll();
    }
  } else {
      $status_sql = '';
        $date_sql = '';
        
      if ($status_filter !== 'All') {
          $status_sql = " AND o.status = ? ";
          $params[] = $status_filter;
      }
        
        // Add year and month filters
        if ($filter_year !== null && $filter_year > 0) {
            $date_sql .= " AND YEAR(o.order_date) = ? ";
            $params[] = $filter_year;
        }
        if ($filter_month !== null && $filter_month > 0 && $filter_month <= 12) {
            $date_sql .= " AND MONTH(o.order_date) = ? ";
            $params[] = $filter_month;
        }
        
      // Get total count
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM orders o WHERE o.shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?)$status_sql$date_sql");
      $countParams = [$distributor_id];
      if ($status_filter !== 'All') {
        $countParams[] = $status_filter;
      }
        if ($filter_year !== null && $filter_year > 0) {
          $countParams[] = $filter_year;
        }
        if ($filter_month !== null && $filter_month > 0 && $filter_month <= 12) {
          $countParams[] = $filter_month;
      }
      $countStmt->execute($countParams);
      $total = $countStmt->fetchColumn();
        
        // Get count of completed orders for the selected filters
        $completed_count = 0;
        if ($status_filter === 'All' || $status_filter === 'Completed') {
          $completedParams = [$distributor_id];
          $completedSql = " AND o.status = 'Completed' ";
          if ($filter_year !== null && $filter_year > 0) {
            $completedSql .= " AND YEAR(o.order_date) = ? ";
            $completedParams[] = $filter_year;
          }
          if ($filter_month !== null && $filter_month > 0 && $filter_month <= 12) {
            $completedSql .= " AND MONTH(o.order_date) = ? ";
            $completedParams[] = $filter_month;
          }
          $completedStmt = $conn->prepare("SELECT COUNT(*) FROM orders o WHERE o.shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?)$completedSql");
          $completedStmt->execute($completedParams);
          $completed_count = $completedStmt->fetchColumn();
        } else if ($status_filter === 'Completed') {
          $completed_count = $total;
        }
        
      // Get paginated orders
      // For Completed status, join with shop_ratings to get rating/comment
      if ($status_filter === 'Completed') {
        $stmt = $conn->prepare("SELECT o.*, a.name AS customer_name, a.contact_number, a.street, a.city, a.region AS province, a.zip_code, r.rating, r.comment
          FROM orders o
          LEFT JOIN address a ON o.address_id = a.address_id
          LEFT JOIN shop_ratings r ON o.order_id = r.order_id
          WHERE o.shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?)
              $status_sql$date_sql
          ORDER BY o.order_date DESC
          LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        $orders = $stmt->fetchAll();
      } else {
        // First come first serve: oldest orders first (ASC)
        $stmt = $conn->prepare("SELECT o.*, a.name AS customer_name, a.contact_number, a.street, a.city, a.region AS province, a.zip_code
          FROM orders o
          LEFT JOIN address a ON o.address_id = a.address_id
          WHERE o.shop_id IN (SELECT shop_id FROM shop WHERE distributor_id = ?)
              $status_sql$date_sql
          ORDER BY o.order_date ASC
          LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        $orders = $stmt->fetchAll();
      }
  }
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Orders | Tuy PureFlow Distributor</title>
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
      .filter-btn {
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
      }
      .filter-btn::before {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.3);
        transform: translate(-50%, -50%);
        transition: width 0.6s, height 0.6s;
      }
      .filter-btn:hover::before {
        width: 300px;
        height: 300px;
      }
      .table-row {
        transition: all 0.2s ease;
      }
      .table-row:hover {
        background: linear-gradient(90deg, #f8fafc 0%, #ffffff 100%);
        transform: scale(1.01);
      }
    </style>
  </head>
  <body class="flex bg-gray-100 min-h-screen">
    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-h-screen ml-64">

      <!-- Header -->
      <?php include 'header.php'; ?>

      <!-- Page Content -->
      <main class="flex-1 p-6 overflow-x-auto bg-gradient-to-br from-gray-50 to-blue-50">
        <div class="mb-8">
          <h1 class="text-4xl font-bold gradient-text mb-2 flex items-center gap-3">
            <i class="fas fa-shopping-bag text-blue-600"></i>
            Order Management
          </h1>
          <p class="text-gray-600">View and manage all orders</p>
        </div>
        
        <!-- Validation Error Messages -->
        <?php if (isset($_SESSION['order_validation_error'])): ?>
          <div class="mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-lg shadow-md fade-in">
            <div class="flex items-center gap-3">
              <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
              <div class="flex-1">
                <h3 class="font-semibold text-red-800 mb-1">Validation Error</h3>
                <p class="text-red-700"><?= htmlspecialchars($_SESSION['order_validation_error']) ?></p>
              </div>
              <button onclick="this.parentElement.parentElement.remove()" class="text-red-600 hover:text-red-800">
                <i class="fas fa-times"></i>
              </button>
            </div>
          </div>
          <?php unset($_SESSION['order_validation_error']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['order_validation_errors']) && is_array($_SESSION['order_validation_errors'])): ?>
          <div class="mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-lg shadow-md fade-in">
            <div class="flex items-start gap-3">
              <i class="fas fa-exclamation-triangle text-red-600 text-xl mt-1"></i>
              <div class="flex-1">
                <h3 class="font-semibold text-red-800 mb-2">Validation Errors</h3>
                <ul class="list-disc list-inside space-y-1 text-red-700">
                  <?php foreach ($_SESSION['order_validation_errors'] as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
              <button onclick="this.parentElement.parentElement.remove()" class="text-red-600 hover:text-red-800">
                <i class="fas fa-times"></i>
              </button>
            </div>
          </div>
          <?php unset($_SESSION['order_validation_errors']); ?>
        <?php endif; ?>
        
        <!-- Success Message -->
        <?php if (isset($_SESSION['order_success_message'])): ?>
          <div class="mb-6 bg-green-50 border-l-4 border-green-500 p-4 rounded-lg shadow-md fade-in">
            <div class="flex items-center gap-3">
              <i class="fas fa-check-circle text-green-600 text-xl"></i>
              <div class="flex-1">
                <p class="text-green-700 font-medium"><?= htmlspecialchars($_SESSION['order_success_message']) ?></p>
              </div>
              <button onclick="this.parentElement.parentElement.remove()" class="text-green-600 hover:text-green-800">
                <i class="fas fa-times"></i>
              </button>
            </div>
          </div>
          <?php unset($_SESSION['order_success_message']); ?>
        <?php endif; ?>
        <!-- Status Filter -->
        <div class="mb-6 flex flex-wrap gap-3">
          <?php
          $statuses = ['All', 'Pending', 'Processing', 'Order Thru Text', 'Out for Delivery', 'Scheduled Deliveries', 'Completed', 'Rated', 'Cancelled'];
          $statusIcons = [
            'All' => 'fa-list',
            'Pending' => 'fa-clock',
            'Processing' => 'fa-cog',
            'Order Thru Text' => 'fa-sms',
            'Out for Delivery' => 'fa-truck',
            'Scheduled Deliveries' => 'fa-calendar-check',
            'Completed' => 'fa-check-circle',
            'Rated' => 'fa-star',
            'Cancelled' => 'fa-times-circle'
          ];
          foreach ($statuses as $status) {
            $active = $status_filter == $status ? 'bg-gradient-to-r from-blue-600 to-blue-700 text-white shadow-md hover:shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-50 border border-gray-200 hover:border-blue-300';
            $icon = $statusIcons[$status] ?? 'fa-circle';
              // Build URL with current filters
              $urlParams = ['status' => $status];
              if ($filter_year) $urlParams['year'] = $filter_year;
              if ($filter_month) $urlParams['month'] = $filter_month;
              $url = '?' . http_build_query($urlParams);
              echo '<a href="' . htmlspecialchars($url) . '" class="filter-btn px-5 py-2.5 rounded-lg ' . $active . ' font-medium transition-all flex items-center gap-2 relative">';
            echo '<i class="fas ' . $icon . '"></i>';
            echo '<span>' . htmlspecialchars($status) . '</span>';
            echo '</a>';
          }
          ?>
        </div>
          
          <!-- Year and Month Filter -->
          <div class="mb-6 bg-white rounded-xl shadow-sm border border-gray-200 p-4 fade-in">
            <div class="flex flex-wrap items-center gap-4">
              <div class="flex items-center gap-2">
                <i class="fas fa-calendar text-blue-600"></i>
                <label class="text-sm font-semibold text-gray-700">Filter by:</label>
              </div>
              <form method="GET" class="flex flex-wrap items-center gap-3">
                <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                
                <div class="flex items-center gap-2">
                  <label class="text-sm text-gray-600">Year:</label>
                  <select name="year" class="border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" onchange="this.form.submit()">
                    <option value="">All Years</option>
                    <?php foreach ($available_years as $year): ?>
                      <option value="<?= $year ?>" <?= $filter_year == $year ? 'selected' : '' ?>><?= $year ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                
                <div class="flex items-center gap-2">
                  <label class="text-sm text-gray-600">Month:</label>
                  <select name="month" class="border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" onchange="this.form.submit()">
                    <option value="">All Months</option>
                    <?php 
                    $monthNames = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                    foreach ($available_months as $month): ?>
                      <option value="<?= $month ?>" <?= $filter_month == $month ? 'selected' : '' ?>><?= $monthNames[$month] ?? $month ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                
                <?php if ($filter_year || $filter_month): ?>
                <a href="?status=<?= urlencode($status_filter) ?>" class="px-3 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">
                  <i class="fas fa-times"></i> Clear Filters
                </a>
                <?php endif; ?>
              </form>
              
              <?php if (isset($completed_count) && ($status_filter === 'All' || $status_filter === 'Completed')): ?>
              <div class="ml-auto flex items-center gap-2 bg-green-50 px-4 py-2 rounded-lg border border-green-200">
                <i class="fas fa-check-circle text-green-600"></i>
                <span class="text-sm font-semibold text-green-700">
                  Completed Orders: <span class="text-green-800"><?= number_format($completed_count) ?></span>
                  <?php if ($filter_year || $filter_month): ?>
                    <span class="text-xs text-green-600">
                      (<?= $filter_year ? $filter_year : 'All Years' ?><?= $filter_month ? ' - ' . ($monthNames[$filter_month] ?? $filter_month) : '' ?>)
                    </span>
                  <?php endif; ?>
                </span>
              </div>
              <?php endif; ?>
            </div>
        </div>
        <?php if ($status_filter === 'Order Thru Text'): ?>
            <form method="POST">
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden fade-in">
          <table class="min-w-full">
            <thead class="bg-gradient-to-r from-blue-500 to-blue-600 text-white">
              <tr>
                  <th class="px-4 py-3 text-center w-12">
                    <input type="checkbox" id="selectAllSmsOrders" class="cursor-pointer" title="Select All">
                  </th>
                  <th class="px-4 py-3 text-left">Consumer</th>
                  <th class="px-4 py-3 text-left">Items/Containers</th>
                  <th class="px-4 py-3 text-left">Contact Number</th>
                  <th class="px-4 py-3 text-left">Delivery Address</th>
                  <th class="px-4 py-3 text-right">Total</th>
                  <th class="px-4 py-3 text-left">Date</th>
                  <?php if ($status_filter === 'Rated'): ?>
                  <th class="px-4 py-3 text-left">Comment</th>
                  <?php else: ?>
                  <th class="px-4 py-3 text-center w-24">Actions</th>
                  <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php
                // Get shop_id(s) for this distributor
                $shopStmt = $conn->prepare("SELECT shop_id FROM shop WHERE distributor_id = ?");
                $shopStmt->execute([$distributor_id]);
                $shopIds = $shopStmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($shopIds)) {
                  $in = str_repeat('?,', count($shopIds) - 1) . '?';
                  
                  // Get total count for pagination
                  $countStmt = $conn->prepare("SELECT COUNT(*) FROM sms_orders WHERE shop_id IN ($in)");
                  $countStmt->execute($shopIds);
                  $totalSms = $countStmt->fetchColumn();
                  
                  // Get paginated SMS orders - First come first serve: oldest orders first
                  $smsStmt = $conn->prepare("SELECT s.* FROM sms_orders s WHERE s.shop_id IN ($in) ORDER BY s.created_at ASC LIMIT $perPage OFFSET $offset");
                  $smsStmt->execute($shopIds);
                  $smsOrders = $smsStmt->fetchAll();
                  
                  foreach ($smsOrders as $sms):
                    // Normalize phone number for matching (remove +, spaces, dashes)
                    $sender_phone = preg_replace('/[^0-9]/', '', $sms['sender_phone_number'] ?? '');
                    $normalized_phone = preg_replace('/^\+?63|^0/', '', $sender_phone);
                    
                    // First, try to find consumer by matching phone number in consumer table
                    $consumer_stmt = $conn->prepare("
                      SELECT c.consumer_id, c.full_name, c.contact_number 
                      FROM consumer c 
                      WHERE REPLACE(REPLACE(REPLACE(c.contact_number, '+', ''), ' ', ''), '-', '') LIKE ?
                      LIMIT 1
                    ");
                    $consumer_stmt->execute(['%' . $normalized_phone]);
                    $consumer = $consumer_stmt->fetch();
                    
                    $consumer_id = null;
                    $consumer_name = 'Unknown Consumer';
                    $consumer_contact = $sms['sender_phone_number'] ?? 'N/A';
                    
                    if ($consumer) {
                      // Found in consumer table
                      $consumer_id = $consumer['consumer_id'];
                      $consumer_name = $consumer['full_name'];
                      $consumer_contact = $consumer['contact_number'];
                    } else {
                      // If not found in consumer table, try to find in address table by contact_number
                      $address_stmt = $conn->prepare("
                        SELECT a.consumer_id, a.name, a.contact_number, a.street, a.city, a.region, a.zip_code
                        FROM address a 
                        WHERE REPLACE(REPLACE(REPLACE(a.contact_number, '+', ''), ' ', ''), '-', '') LIKE ?
                        LIMIT 1
                      ");
                      $address_stmt->execute(['%' . $normalized_phone]);
                      $address = $address_stmt->fetch();
                      
                      if ($address) {
                        // Found in address table
                        $consumer_id = $address['consumer_id'];
                        $consumer_name = $address['name'] ? $address['name'] : 'Unknown Consumer';
                        $consumer_contact = $address['contact_number'] ? $address['contact_number'] : $sms['sender_phone_number'];
                      }
                    }
                    
                    // Get consumer's default address (or first address)
                    $address_text = 'No address';
                    if ($consumer_id) {
                      $addr_stmt = $conn->prepare("SELECT * FROM address WHERE consumer_id = ? AND is_default = 1 LIMIT 1");
                      $addr_stmt->execute([$consumer_id]);
                      $address = $addr_stmt->fetch();
                      if (!$address) {
                        $addr_stmt = $conn->prepare("SELECT * FROM address WHERE consumer_id = ? LIMIT 1");
                        $addr_stmt->execute([$consumer_id]);
                        $address = $addr_stmt->fetch();
                      }
                      
                      if ($address) {
                        $address_parts = [];
                        if (!empty($address['street'])) $address_parts[] = $address['street'];
                        if (!empty($address['city'])) $address_parts[] = $address['city'];
                        if (!empty($address['region'])) $address_parts[] = $address['region'];
                        if (!empty($address['zip_code'])) $address_parts[] = $address['zip_code'];
                        $address_text = implode("\n", $address_parts);
                      }
                    } else {
                      // If no consumer_id found, try to get address directly by phone number
                      $addr_stmt = $conn->prepare("
                        SELECT street, city, region, zip_code 
                        FROM address 
                        WHERE REPLACE(REPLACE(REPLACE(contact_number, '+', ''), ' ', ''), '-', '') LIKE ?
                        LIMIT 1
                      ");
                      $addr_stmt->execute(['%' . $normalized_phone]);
                      $address = $addr_stmt->fetch();
                      
                      if ($address) {
                        $address_parts = [];
                        if (!empty($address['street'])) $address_parts[] = $address['street'];
                        if (!empty($address['city'])) $address_parts[] = $address['city'];
                        if (!empty($address['region'])) $address_parts[] = $address['region'];
                        if (!empty($address['zip_code'])) $address_parts[] = $address['zip_code'];
                        $address_text = implode("\n", $address_parts);
                      }
                    }
                    
                    // Get container type name and calculate total price based on actual container price
                    $items_text = 'No items';
                    $total_price = 0;
                    $quantity = $sms['quantity'] ?? 0;
                    
                    if (!empty($sms['container_type_id']) && !empty($sms['shop_id'])) {
                      // Get container type name
                      $container_type_stmt = $conn->prepare("SELECT type FROM container_type WHERE container_type_id = ?");
                      $container_type_stmt->execute([$sms['container_type_id']]);
                      $container_type = $container_type_stmt->fetch();
                      
                      if ($container_type) {
                        $items_text = htmlspecialchars($container_type['type']) . ' x' . $quantity;
                        
                        // Get actual container price from container table
                        // Check message to determine if it's refill or with container
                        $message_lower = strtolower($sms['message'] ?? '');
                        $is_refill = (strpos($message_lower, 'refill') !== false);
                        
                        $container_price_stmt = $conn->prepare("
                          SELECT price_with_container, price_refill 
                          FROM container 
                          WHERE container_type_id = ? AND shop_id = ? 
                          LIMIT 1
                        ");
                        $container_price_stmt->execute([$sms['container_type_id'], $sms['shop_id']]);
                        $container_price = $container_price_stmt->fetch();
                        
                        if ($container_price) {
                          // Use actual container price: price_with_container for new orders, price_refill for refills
                          if ($is_refill) {
                            $price_per_unit = floatval($container_price['price_refill'] ?? 0);
                            // If refill price is 0, use with_container price
                            if ($price_per_unit == 0) {
                              $price_per_unit = floatval($container_price['price_with_container'] ?? 0);
                            }
              } else {
                            // Default to price_with_container (actual container price)
                            $price_per_unit = floatval($container_price['price_with_container'] ?? 0);
                            // If with_container price is 0, use refill price
                            if ($price_per_unit == 0) {
                              $price_per_unit = floatval($container_price['price_refill'] ?? 0);
                            }
              }
                          $total_price = $price_per_unit * $quantity;
                        }
                      }
                    }
                    
                    $status = $sms['status'] ?? 'Pending';
                ?>
                  <tr class="table-row border-t hover:bg-gray-50">
                    <td class="px-4 py-3 text-center align-middle">
                      <input type="checkbox" name="selected_sms_orders[]" value="<?= $sms['sms_order_id'] ?>" class="sms-order-checkbox cursor-pointer w-4 h-4">
                    </td>
                    <td class="px-4 py-3 text-left align-middle"><?= htmlspecialchars($consumer_name) ?></td>
                    <td class="px-4 py-3 text-left align-middle" style="white-space:pre-line;"><?= htmlspecialchars($items_text) ?></td>
                    <td class="px-4 py-3 text-left align-middle"><?= htmlspecialchars($consumer_contact) ?></td>
                    <td class="px-4 py-3 text-left align-top" style="white-space:pre-line;"><?= htmlspecialchars($address_text) ?></td>
                    <td class="px-4 py-3 text-right align-middle font-semibold">₱<?= number_format($total_price, 2) ?></td>
                    <td class="px-4 py-3 text-left align-middle">
                      <div class="text-sm"><?= htmlspecialchars($sms['created_at'] ?? '-') ?></div>
                    </td>
                    <td class="px-4 py-3 text-center align-middle">
                      <button type="button" onclick="event.preventDefault(); event.stopPropagation(); openSmsStatusModal(<?= $sms['sms_order_id'] ?>, '<?= htmlspecialchars(addslashes($status)) ?>', event); return false;" 
                              class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm hover:bg-blue-700 transition-colors flex items-center gap-1">
                        <i class="fas fa-edit text-xs"></i> <span>Update</span>
                      </button>
                    </td>
                </tr>
                <?php 
                  endforeach;
                } else {
                  echo '<tr><td colspan="8" class="px-4 py-3 text-center text-gray-500">No SMS orders found for your shop.</td></tr>';
                }
                ?>
            </tbody>
            <tfoot>
              <tr>
                  <td colspan="8" class="px-4 py-3 text-center bg-gray-50">
                  <?php
                    if (!empty($shopIds)) {
                  $totalPages = !empty($totalSms) ? ceil($totalSms / $perPage) : 1;
                  if ($totalPages > 1) {
                    $prevPage = $page > 1 ? $page - 1 : 1;
                    $nextPage = $page < $totalPages ? $page + 1 : $totalPages;
                    $queryString = '?status=Order+Thru+Text&page=';
                    if ($page > 1) {
                          echo '<a href="' . $queryString . $prevPage . '" class="px-3 py-1 bg-gray-200 rounded mr-2 hover:bg-gray-300">Previous</a>';
                    }
                    echo 'Page ' . $page . ' of ' . $totalPages;
                    if ($page < $totalPages) {
                          echo '<a href="' . $queryString . $nextPage . '" class="px-3 py-1 bg-gray-200 rounded ml-2 hover:bg-gray-300">Next</a>';
                        }
                    }
                  }
                  ?>
                </td>
              </tr>
            </tfoot>
          </table>
          </div>
            </form>
        <?php else: ?>
            <form method="POST">
          <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden fade-in">
          <table class="min-w-full">
            <thead class="bg-gradient-to-r from-blue-500 to-blue-600 text-white">
              <tr>
                  <th class="px-4 py-3 text-center w-12">
                    <?php if ($status_filter !== 'Scheduled Deliveries'): ?>
                    <input type="checkbox" id="selectAllOrders" class="cursor-pointer" title="Select All">
                    <?php endif; ?>
                  </th>
                  <th class="px-4 py-3 text-left">Consumer</th>
                  <th class="px-4 py-3 text-left">Items/Containers</th>
                  <th class="px-4 py-3 text-left">Delivery Address</th>
                  <th class="px-4 py-3 text-right">Total</th>
                  <th class="px-4 py-3 text-left">Status</th>
                  <?php if ($status_filter === 'Scheduled Deliveries'): ?>
                  <th class="px-4 py-3 text-left">Scheduled Date</th>
                  <th class="px-4 py-3 text-left">Scheduled Time</th>
                  <?php else: ?>
                  <th class="px-4 py-3 text-left">Date</th>
                  <?php endif; ?>
                  <th class="px-4 py-3 text-center w-24">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              if (empty($orders)): 
                $colspan = ($status_filter === 'Scheduled Deliveries') ? '9' : '8';
              ?>
                <tr>
                  <td colspan="<?php echo $colspan; ?>" class="px-4 py-8 text-center text-gray-500">
                    <?php if ($status_filter === 'Scheduled Deliveries'): ?>
                      No scheduled deliveries found.
                    <?php else: ?>
                      No orders found.
                    <?php endif; ?>
                  </td>
                </tr>
              <?php else: ?>
              <?php foreach ($orders as $order): ?>
              <?php
                  // Use address name (customer_name) from the query, fallback to 'Unknown Customer' if not available
                  $consumer_name = !empty($order['customer_name']) ? $order['customer_name'] : 'Unknown Customer';
                  
                  // Get order items
                  $items_stmt = $conn->prepare("
                    SELECT oi.*, c.container_name 
                    FROM order_items oi 
                    JOIN container c ON oi.container_id = c.container_id 
                    WHERE oi.order_id = ?
                  ");
                  $items_stmt->execute([$order['order_id']]);
                  $items = $items_stmt->fetchAll();
                  
                  // Build items text
                  $items_text = '';
                  foreach ($items as $item) {
                    $items_text .= ($item['container_name'] ?? 'Unknown') . ' x' . ($item['quantity'] ?? 0) . "\n";
                  }
                  $items_text = trim($items_text);
                  
                  // Build address text (exclude address label/name, just show actual address)
                  $address_text = '';
                  if (isset($order['street']) && $order['street']) {
                    $address_text .= $order['street'] . "\n";
                  }
                  $city_province = [];
                  if (isset($order['city']) && $order['city']) {
                    $city_province[] = $order['city'];
                  }
                  if (isset($order['province']) && $order['province']) {
                    $city_province[] = $order['province'];
                  }
                  if (isset($order['zip_code']) && $order['zip_code']) {
                    $city_province[] = $order['zip_code'];
                  }
                  if (!empty($city_province)) {
                    $address_text .= implode(', ', $city_province);
                  }
                  $address_text = trim($address_text);
                ?>
                <tr class="table-row border-t hover:bg-gray-50">
                  <td class="px-4 py-3 text-center align-middle">
                    <?php if ($status_filter !== 'Scheduled Deliveries'): ?>
                    <input type="checkbox" name="selected_orders[]" value="<?= $order['order_id'] ?>" class="order-checkbox cursor-pointer w-4 h-4">
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-3 text-left align-middle"><?= htmlspecialchars($consumer_name) ?></td>
                  <td class="px-4 py-3 text-left align-middle" style="white-space:pre-line;"><?= htmlspecialchars($items_text ?: 'No items') ?></td>
                  <td class="px-4 py-3 text-left align-middle" style="white-space:pre-line;"><?= htmlspecialchars($address_text ?: 'No address') ?></td>
                  <td class="px-4 py-3 text-right align-middle font-semibold">₱<?= number_format($order['total_amount'] ?? 0, 2) ?></td>
                  <td class="px-4 py-3 text-left align-middle">
                    <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold 
                    <?php
                      $status = $order['status'] ?? '';
                      echo $status === 'Completed' ? 'bg-green-100 text-green-700' : 
                          ($status === 'Pending' ? 'bg-yellow-100 text-yellow-700' : 
                          ($status === 'Cancelled' ? 'bg-red-100 text-red-700' : 
                          ($status === 'Out for Delivery' ? 'bg-blue-100 text-blue-700' : 
                          ($status === 'Processing' ? 'bg-purple-100 text-purple-700' :
                          'bg-gray-100 text-gray-700'))));
                      ?>
                    ">
                      <?= htmlspecialchars($status ?: '-') ?>
                    </span>
                  </td>
                  <?php if ($status_filter === 'Scheduled Deliveries'): ?>
                  <td class="px-4 py-3 text-left align-middle">
                    <div class="text-sm font-semibold text-blue-700">
                      <?= $order['scheduled_date'] ? date('F j, Y', strtotime($order['scheduled_date'])) : '-' ?>
                    </div>
                  </td>
                  <td class="px-4 py-3 text-left align-middle">
                    <div class="text-sm font-semibold text-blue-700">
                      <?= $order['scheduled_time'] ? date('g:i A', strtotime($order['scheduled_time'])) : '-' ?>
                    </div>
                  </td>
                  <?php else: ?>
                  <td class="px-4 py-3 text-left align-middle">
                    <div class="text-sm"><?= htmlspecialchars($order['order_date'] ?? '-') ?></div>
                    <?php if (($status_filter === 'Completed' || $status_filter === 'Rated') && isset($order['rating'])): ?>
                      <div class="mt-2">
                        <div class="flex items-center gap-1">
                          <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star text-xs <?= $i <= ($order['rating'] ?? 0) ? 'text-yellow-400' : 'text-gray-300' ?>"></i>
                          <?php endfor; ?>
                          <span class="text-xs text-gray-600 ml-1">(<?= $order['rating'] ?? 0 ?>)</span>
                        </div>
                        <?php if (!empty($order['comment'])): ?>
                          <p class="text-xs text-gray-600 mt-1 italic"><?= htmlspecialchars($order['comment']) ?></p>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <?php endif; ?>
                  <?php if ($status_filter === 'Rated'): ?>
                  <td class="px-4 py-3 text-left align-middle">
                    <span class="text-xs text-gray-700 italic">
                      <?= !empty($order['comment']) ? htmlspecialchars($order['comment']) : 'No comment' ?>
                    </span>
                  </td>
                  <?php else: ?>
                  <td class="px-4 py-3 text-center align-middle">
                    <div class="flex flex-col gap-2 items-center">
                      <button type="button" onclick="event.preventDefault(); event.stopPropagation(); openStatusModal(<?= $order['order_id'] ?>, '<?= htmlspecialchars(addslashes($order['status'] ?? '')) ?>', event); return false;" 
                              class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm hover:bg-blue-700 transition-colors flex items-center gap-1">
                        <i class="fas fa-edit text-xs"></i> <span>Update</span>
                      </button>
                      <?php if ($status_filter === 'Scheduled Deliveries'): ?>
                      <button type="button" onclick="event.preventDefault(); event.stopPropagation(); openScheduleModal(<?= $order['order_id'] ?>, '<?= htmlspecialchars($order['scheduled_date'] ?? '') ?>', '<?= htmlspecialchars($order['scheduled_time'] ?? '') ?>', event); return false;" 
                              class="bg-orange-600 text-white px-3 py-1.5 rounded text-sm hover:bg-orange-700 transition-colors flex items-center gap-1">
                        <i class="fas fa-edit text-xs"></i> <span>Reschedule</span>
                      </button>
                      <?php else: ?>
                      <button type="button" onclick="event.preventDefault(); event.stopPropagation(); openScheduleModal(<?= $order['order_id'] ?>, event); return false;" 
                              class="bg-green-600 text-white px-3 py-1.5 rounded text-sm hover:bg-green-700 transition-colors flex items-center gap-1">
                        <i class="fas fa-calendar text-xs"></i> <span>Schedule</span>
                      </button>
                      <?php endif; ?>
                    </div>
                  </td>
                  <?php endif; ?>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <tfoot>
              <tr>
                  <td colspan="8" class="px-4 py-3 text-center bg-gray-50">
                  <?php
                  $totalPages = !empty($total) ? ceil($total / $perPage) : 1;
                  if ($totalPages > 1) {
                    $prevPage = $page > 1 ? $page - 1 : 1;
                    $nextPage = $page < $totalPages ? $page + 1 : $totalPages;
                      $queryParams = ['status' => $status_filter];
                      if ($filter_year) $queryParams['year'] = $filter_year;
                      if ($filter_month) $queryParams['month'] = $filter_month;
                      $baseUrl = '?' . http_build_query($queryParams);
                      $queryString = $baseUrl . '&page=';
                      
                      // For Scheduled Deliveries, use different pagination
                      if ($status_filter === 'Scheduled Deliveries') {
                        $queryString = '?status=Scheduled+Deliveries&page=';
                      }
                    if ($page > 1) {
                        echo '<a href="' . htmlspecialchars($queryString . $prevPage) . '" class="px-3 py-1 bg-gray-200 rounded mr-2 hover:bg-gray-300">Previous</a>';
                    }
                    echo 'Page ' . $page . ' of ' . $totalPages;
                    if ($page < $totalPages) {
                        echo '<a href="' . htmlspecialchars($queryString . $nextPage) . '" class="px-3 py-1 bg-gray-200 rounded ml-2 hover:bg-gray-300">Next</a>';
                    }
                  }
                  ?>
                </td>
              </tr>
            </tfoot>
          </table>
            </div>
            
            <!-- Bulk Actions -->
            <?php if ($status_filter !== 'Rated' && $status_filter !== 'Order Thru Text' && $status_filter !== 'Scheduled Deliveries'): ?>
          <div class="mt-4 bg-white p-4 rounded-xl shadow-sm border border-gray-200 fade-in">
            <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-200 fade-in">
            <h2 class="text-lg font-semibold mb-2 flex items-center gap-2 text-gray-800">
              <i class="fas fa-tasks text-blue-600"></i>
              Bulk Actions
            </h2>
            <div class="flex items-center gap-4">
              <select name="bulk_status" class="border rounded p-2">
                <option value="">Change Status</option>
                  <option value="Processing">Processing</option>
                <option value="Out for Delivery">Out for Delivery</option>
                  <option value="Completed">Mark as Completed</option>
                <option value="Cancelled">Cancel</option>
                <option value="Pending">Pending</option>
              </select>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Apply Changes</button>
            </div>
              <p class="text-xs text-gray-500 mt-2">Select orders using checkboxes in the table above</p>
          </div>
            <?php endif; ?>
        </form>
          <?php endif; ?>

        <!-- Status Modal -->
          <div id="statusModal" class="fixed inset-0 bg-black bg-opacity-40 backdrop-blur-sm flex items-center justify-center z-50 hidden" onclick="if(event.target.id === 'statusModal') closeStatusModal();">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-6 relative fade-in" onclick="event.stopPropagation();">
            <button onclick="closeStatusModal()" class="absolute top-2 right-2 text-gray-500 hover:text-red-600 text-xl w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center transition-all">&times;</button>
            <h2 class="text-xl font-bold mb-4 flex items-center gap-2 text-gray-800">
              <i class="fas fa-edit text-blue-600"></i>
              Update Order Status
            </h2>
            
            <!-- Scheduled Order Info -->
            <div id="scheduledOrderInfo" class="hidden mb-4 p-3 bg-cyan-50 border border-cyan-200 rounded-lg">
              <div class="flex items-center gap-2 mb-2">
                <i class="fas fa-calendar-check text-cyan-600"></i>
                <span class="font-semibold text-cyan-800">Scheduled Order</span>
              </div>
              <div class="text-sm text-cyan-700 space-y-1">
                <div id="scheduledDateInfo"></div>
                <div id="scheduledTimeInfo"></div>
                <div id="recurrenceInfo" class="hidden"></div>
              </div>
            </div>
            
            <!-- Validation Warning -->
            <div id="scheduledWarning" class="hidden mb-4 p-3 bg-yellow-50 border-l-4 border-yellow-500 rounded">
              <div class="flex items-start gap-2">
                <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5"></i>
                <div class="text-sm text-yellow-800">
                  <strong>Warning:</strong> This order is scheduled for a future date. You can only complete it on or after the scheduled date.
                </div>
              </div>
            </div>
            
              <form method="POST" id="statusForm" onsubmit="return validateScheduledOrderCompletion();">
              <input type="hidden" name="order_id" id="modal_order_id">
              <input type="hidden" id="modal_is_scheduled" value="0">
              <input type="hidden" id="modal_scheduled_datetime" value="">
                <select name="single_status" id="modal_status" class="border border-gray-300 rounded-lg px-4 py-2 w-full mb-4 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required onchange="checkScheduledValidation()">
                  <option value="">Select Status</option>
                  <option value="Pending">Pending</option>
                  <option value="Processing">Processing</option>
                  <option value="Out for Delivery">Out for Delivery</option>
                  <option value="Completed">Completed</option>
                  <option value="Cancelled">Cancelled</option>
                </select>
                <div class="flex gap-2">
                  <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded w-full hover:bg-blue-700 transition-colors">Update</button>
                  <button type="button" onclick="closeStatusModal()" class="bg-gray-200 text-gray-700 px-4 py-2 rounded w-full hover:bg-gray-300 transition-colors">Cancel</button>
                </div>
              </form>
            </div>
          </div>

          <!-- Schedule Delivery Modal -->
          <div id="scheduleModal" class="fixed inset-0 bg-black bg-opacity-40 backdrop-blur-sm flex items-center justify-center z-50 hidden" onclick="if(event.target.id === 'scheduleModal') closeScheduleModal();">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-md p-6 relative fade-in" onclick="event.stopPropagation();">
              <button onclick="closeScheduleModal()" class="absolute top-2 right-2 text-gray-500 hover:text-red-600 text-xl w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center transition-all">&times;</button>
              <h2 class="text-xl font-bold mb-4 flex items-center gap-2 text-gray-800">
                <i class="fas fa-calendar-check text-green-600"></i>
                Schedule Delivery
              </h2>
              <form method="POST" id="scheduleForm" onsubmit="return validateScheduleForm();" onclick="event.stopPropagation();">
                <input type="hidden" name="schedule_order_id" id="modal_schedule_order_id" value="">
                <div class="mb-4">
                  <label class="block text-sm font-semibold text-gray-700 mb-2">Delivery Date</label>
                  <input type="date" name="delivery_date" id="modal_delivery_date" 
                         class="border border-gray-300 rounded-lg px-4 py-2 w-full focus:ring-2 focus:ring-green-500 focus:border-green-500" 
                         required min="<?= date('Y-m-d') ?>" onclick="event.stopPropagation();">
                </div>
                <div class="mb-4">
                  <label class="block text-sm font-semibold text-gray-700 mb-2">Delivery Time</label>
                  <input type="time" name="delivery_time" id="modal_delivery_time" 
                         class="border border-gray-300 rounded-lg px-4 py-2 w-full focus:ring-2 focus:ring-green-500 focus:border-green-500" 
                         required onclick="event.stopPropagation();">
                </div>
                <div class="flex gap-2">
                  <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded w-full hover:bg-green-700 transition-colors" onclick="event.stopPropagation();">
                    <i class="fas fa-calendar-check mr-2"></i>Schedule Delivery
                  </button>
                  <button type="button" onclick="closeScheduleModal(); event.stopPropagation();" class="bg-gray-200 text-gray-700 px-4 py-2 rounded w-full hover:bg-gray-300 transition-colors">Cancel</button>
                </div>
              </form>
            </div>
          </div>

          <!-- SMS Order Status Modal -->
          <div id="smsStatusModal" class="fixed inset-0 bg-black bg-opacity-40 backdrop-blur-sm flex items-center justify-center z-50 hidden" onclick="if(event.target.id === 'smsStatusModal') closeSmsStatusModal();">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm p-6 relative fade-in" onclick="event.stopPropagation();">
              <button onclick="closeSmsStatusModal()" class="absolute top-2 right-2 text-gray-500 hover:text-red-600 text-xl w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center transition-all">&times;</button>
              <h2 class="text-xl font-bold mb-4 flex items-center gap-2 text-gray-800">
                <i class="fas fa-edit text-blue-600"></i>
                Update SMS Order Status
              </h2>
              <form method="POST" id="smsStatusForm" onsubmit="return validateSmsStatusForm();" onclick="event.stopPropagation();">
                <input type="hidden" name="sms_order_id" id="modal_sms_order_id" value="">
                <select name="sms_single_status" id="modal_sms_status" class="border border-gray-300 rounded-lg px-4 py-2 w-full mb-4 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required onclick="event.stopPropagation();" onchange="event.stopPropagation();">
                  <option value="">Select Status</option>
                <option value="Pending">Pending</option>
                <option value="Processing">Processing</option>
                <option value="Out for Delivery">Out for Delivery</option>
                <option value="Completed">Completed</option>
                <option value="Cancelled">Cancelled</option>
              </select>
                <div class="flex gap-2">
                  <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded w-full hover:bg-blue-700 transition-colors" onclick="event.stopPropagation();">Update</button>
                  <button type="button" onclick="closeSmsStatusModal(); event.stopPropagation();" class="bg-gray-200 text-gray-700 px-4 py-2 rounded w-full hover:bg-gray-300 transition-colors">Cancel</button>
                </div>
            </form>
          </div>
        </div>
      </main>
    </div>
    <script>
        const selectAllOrdersEl = document.getElementById('selectAllOrders');
        if (selectAllOrdersEl) {
          selectAllOrdersEl.addEventListener('change', function() {
        document.querySelectorAll('.order-checkbox').forEach(cb => cb.checked = this.checked);
      });
        }
        const selectAllSmsOrdersEl = document.getElementById('selectAllSmsOrders');
        if (selectAllSmsOrdersEl) {
          selectAllSmsOrdersEl.addEventListener('change', function() {
            document.querySelectorAll('.sms-order-checkbox').forEach(cb => cb.checked = this.checked);
          });
        }
      function openStatusModal(orderId, status, event) {
        if (event) {
          event.preventDefault();
          event.stopPropagation();
        }
        
        document.getElementById('modal_order_id').value = orderId;
        document.getElementById('modal_status').value = status;
        
        // Fetch order details to check if it's scheduled
        fetch('orders.php?get_order_info=1&order_id=' + orderId)
          .then(res => res.json())
          .then(data => {
            if (data && data.is_scheduled == 1) {
              document.getElementById('modal_is_scheduled').value = '1';
              document.getElementById('scheduledOrderInfo').classList.remove('hidden');
              
              if (data.scheduled_date) {
                const scheduledDate = new Date(data.scheduled_date);
                document.getElementById('scheduledDateInfo').innerHTML = 
                  '<strong>Date:</strong> ' + scheduledDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
              }
              
              if (data.scheduled_time) {
                const timeParts = data.scheduled_time.split(':');
                const timeStr = timeParts[0] + ':' + timeParts[1];
                const timeDate = new Date('2000-01-01 ' + timeStr);
                document.getElementById('scheduledTimeInfo').innerHTML = 
                  '<strong>Time:</strong> ' + timeDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
              }
              
              if (data.recurrence_type && data.recurrence_type !== 'none') {
                document.getElementById('recurrenceInfo').classList.remove('hidden');
                const recurTypes = {
                  'weekly': 'Weekly',
                  'bi-weekly': 'Bi-weekly',
                  'biweekly': 'Bi-weekly',
                  'monthly': 'Monthly'
                };
                document.getElementById('recurrenceInfo').innerHTML = 
                  '<strong>Recurrence:</strong> ' + (recurTypes[data.recurrence_type] || data.recurrence_type);
              }
              
              // Store scheduled datetime for validation
              if (data.scheduled_date) {
                let scheduledDatetime = data.scheduled_date;
                if (data.scheduled_time) {
                  scheduledDatetime += ' ' + data.scheduled_time;
                } else {
                  scheduledDatetime += ' 00:00:00';
                }
                document.getElementById('modal_scheduled_datetime').value = scheduledDatetime;
              }
              
              // Check validation on load
              checkScheduledValidation();
            } else {
              document.getElementById('modal_is_scheduled').value = '0';
              document.getElementById('scheduledOrderInfo').classList.add('hidden');
              document.getElementById('scheduledWarning').classList.add('hidden');
            }
          })
          .catch(err => {
            console.error('Error fetching order info:', err);
            document.getElementById('modal_is_scheduled').value = '0';
            document.getElementById('scheduledOrderInfo').classList.add('hidden');
            document.getElementById('scheduledWarning').classList.add('hidden');
          });
        
        document.getElementById('statusModal').classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
      }
      
      function checkScheduledValidation() {
        const isScheduled = document.getElementById('modal_is_scheduled').value == '1';
        const selectedStatus = document.getElementById('modal_status').value;
        const scheduledDatetime = document.getElementById('modal_scheduled_datetime').value;
        
        if (isScheduled && selectedStatus === 'Completed' && scheduledDatetime) {
          const scheduledTimestamp = new Date(scheduledDatetime).getTime();
          const currentTimestamp = new Date().getTime();
          
          if (scheduledTimestamp > currentTimestamp) {
            document.getElementById('scheduledWarning').classList.remove('hidden');
            return false;
          } else {
            document.getElementById('scheduledWarning').classList.add('hidden');
            return true;
          }
        } else {
          document.getElementById('scheduledWarning').classList.add('hidden');
          return true;
        }
      }
      
      function validateScheduledOrderCompletion() {
        if (!checkScheduledValidation()) {
          alert('Cannot complete this order yet. It is scheduled for a future date. Please wait until the scheduled date.');
          return false;
        }
        return true;
      }
        function openSmsStatusModal(smsOrderId, status, event) {
          if (event) {
            event.preventDefault();
            event.stopPropagation();
          }
          
          const modal = document.getElementById('smsStatusModal');
          const orderIdInput = document.getElementById('modal_sms_order_id');
          const statusSelect = document.getElementById('modal_sms_status');
          
          if (!modal || !orderIdInput || !statusSelect) {
            console.error('Modal elements not found');
            return false;
          }
          
          orderIdInput.value = smsOrderId;
          
          // Set status value, default to 'Pending' if empty or invalid
          const validStatuses = ['Pending', 'Processing', 'Out for Delivery', 'Completed', 'Cancelled'];
          let statusValue = 'Pending';
          if (status && typeof status === 'string' && status.trim() !== '' && validStatuses.includes(status.trim())) {
            statusValue = status.trim();
          }
          
          // Set the select value and ensure it's visible
          statusSelect.value = statusValue;
          
          // Force update the display by setting selectedIndex
          const options = Array.from(statusSelect.options);
          const index = options.findIndex(opt => opt.value === statusValue);
          if (index >= 0) {
            statusSelect.selectedIndex = index;
          }
          
          // Show modal
          modal.classList.remove('hidden');
          document.body.classList.add('overflow-hidden');
          
          return false;
        }
        function closeSmsStatusModal() {
          const modal = document.getElementById('smsStatusModal');
          if (modal) {
            modal.classList.add('hidden');
          }
          document.body.classList.remove('overflow-hidden');
        }
        
        function validateSmsStatusForm() {
          const statusSelect = document.getElementById('modal_sms_status');
          if (!statusSelect || !statusSelect.value) {
            alert('Please select a status');
            return false;
          }
          return true;
        }
        
        // Prevent form submission from parent form when clicking Update button
        document.addEventListener('DOMContentLoaded', function() {
          const smsStatusForm = document.getElementById('smsStatusForm');
          if (smsStatusForm) {
            smsStatusForm.addEventListener('submit', function(e) {
              // Ensure the form submits correctly
              const statusSelect = document.getElementById('modal_sms_status');
              if (!statusSelect || !statusSelect.value) {
                e.preventDefault();
                alert('Please select a status');
                return false;
              }
            });
          }
        });
      function closeStatusModal() {
        document.getElementById('statusModal').classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
      }
      
      function openScheduleModal(orderId, scheduledDate = '', scheduledTime = '', event) {
        if (event) {
          event.preventDefault();
          event.stopPropagation();
        }
        
        const modal = document.getElementById('scheduleModal');
        const orderIdInput = document.getElementById('modal_schedule_order_id');
        const dateInput = document.getElementById('modal_delivery_date');
        const timeInput = document.getElementById('modal_delivery_time');
        
        if (!modal || !orderIdInput || !dateInput || !timeInput) {
          console.error('Schedule modal elements not found');
          return false;
        }
        
        orderIdInput.value = orderId;
        
        // Set default date to tomorrow if not provided
        if (scheduledDate) {
          dateInput.value = scheduledDate;
        } else {
          const tomorrow = new Date();
          tomorrow.setDate(tomorrow.getDate() + 1);
          dateInput.value = tomorrow.toISOString().split('T')[0];
        }
        
        // Set default time to 9:00 AM if not provided
        if (scheduledTime) {
          // Convert time format if needed (HH:MM:SS to HH:MM)
          const timeParts = scheduledTime.split(':');
          timeInput.value = timeParts[0] + ':' + timeParts[1];
        } else {
          timeInput.value = '09:00';
        }
        
        // Show modal
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        
        return false;
      }
      
      function closeScheduleModal() {
        const modal = document.getElementById('scheduleModal');
        if (modal) {
          modal.classList.add('hidden');
        }
        document.body.classList.remove('overflow-hidden');
      }
      
      function validateScheduleForm() {
        const dateInput = document.getElementById('modal_delivery_date');
        const timeInput = document.getElementById('modal_delivery_time');
        
        if (!dateInput || !dateInput.value) {
          alert('Please select a delivery date');
          return false;
        }
        
        if (!timeInput || !timeInput.value) {
          alert('Please select a delivery time');
          return false;
        }
        
        // Check if date is in the past
        const selectedDate = new Date(dateInput.value + ' ' + timeInput.value);
        const now = new Date();
        if (selectedDate < now) {
          alert('Please select a future date and time');
          return false;
        }
        
        return true;
      }
    </script>

        <!-- Single Detail Card (Order ID hidden) -->
        <div id="detail-card" class="mt-4 hidden bg-white p-4 rounded shadow border-t">
          <h2 class="text-lg font-semibold mb-2">Order Details</h2>
          <div class="grid grid-cols-2 gap-4">
            <!-- <div><strong>Order ID:</strong> ORD001</div> -->
            <div><strong>Date:</strong> June 4, 2025</div>
            <div><strong>Customer:</strong> Maria Santos</div>
            <div><strong>Phone:</strong> 09171234567</div>
            <div><strong>Email:</strong> maria@example.com</div>
            <div><strong>Address:</strong> Brgy. 1, Tuy, Batangas</div>
            <div class="col-span-2">
              <strong>Status:</strong>
              <select class="border rounded p-1 ml-2">
                <option>Pending</option>
                <option>Approved</option>
                <option>Delivered</option>
                <option>Cancelled</option>
              </select>
            </div>
            <div class="col-span-2 mt-4 flex gap-2">
              <button class="bg-blue-600 text-white px-4 py-2 rounded">Update</button>
              <button class="bg-gray-600 text-white px-4 py-2 rounded">Print</button>
              <button class="bg-red-600 text-white px-4 py-2 rounded">Cancel Order</button>
            </div>
          </div>
        </div>
      </main>
    </div>
  </body>
  </html>
