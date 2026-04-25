<?php
// ...existing code to fetch orders...
// For each order, check if rating exists
foreach ($orders as $order) {
    $shop_id = $order['shop_id'];
    $order_id = $order['order_id'];
    $consumer_id = $_SESSION['consumer_id'];
    $rating_stmt = $conn->prepare("SELECT * FROM ratings WHERE consumer_id=? AND shop_id=? AND order_id=?");
    $rating_stmt->execute([$consumer_id, $shop_id, $order_id]);
    $already_rated = $rating_stmt->rowCount() > 0;
    // ...display order info...
    if ($order['status'] == 'Completed' && !$already_rated) {
        echo '<button onclick="openRatingModal(' . $shop_id . ',' . $order_id . ')">Rate Shop</button>';
    } elseif ($already_rated) {
        echo '<span class="text-green-600">Rated</span>';
    }
    // ...rest of order display...
}
?>
<!-- Rating Modal -->
<div id="ratingModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
  <form method="POST" class="bg-white p-6 rounded shadow w-full max-w-md">
    <h2 class="text-lg font-bold mb-4">Rate Shop</h2>
    <input type="hidden" name="shop_id" id="modalShopId">
    <input type="hidden" name="order_id" id="modalOrderId">
    <div class="mb-4">
      <label class="block mb-2">Rating:</label>
      <select name="rating" required class="border p-2 w-full">
        <option value="">Select rating</option>
        <option value="1">1 - Poor</option>
        <option value="2">2 - Fair</option>
        <option value="3">3 - Good</option>
        <option value="4">4 - Very Good</option>
        <option value="5">5 - Excellent</option>
      </select>
    </div>
    <div class="mb-4">
      <label class="block mb-2">Comment:</label>
      <textarea name="comment" class="border p-2 w-full" rows="3"></textarea>
    </div>
    <div class="flex justify-end gap-2">
      <button type="button" onclick="closeRatingModal()" class="bg-gray-500 text-white px-4 py-2 rounded">Cancel</button>
      <button type="submit" name="submit_rating" class="bg-blue-600 text-white px-4 py-2 rounded">Submit</button>
    </div>
  </form>
</div>
<script>
function openRatingModal(shopId, orderId) {
  document.getElementById('ratingModal').classList.remove('hidden');
  document.getElementById('modalShopId').value = shopId;
  document.getElementById('modalOrderId').value = orderId;
}
function closeRatingModal() {
  document.getElementById('ratingModal').classList.add('hidden');
}
</script>
<?php
// Handle rating submission
if (isset($_POST['submit_rating'])) {
    $shop_id = intval($_POST['shop_id']);
    $order_id = intval($_POST['order_id']);
    $consumer_id = $_SESSION['consumer_id'];
    $rating = intval($_POST['rating']);
    $comment = trim($_POST['comment']);
    // Prevent duplicate rating
    $check = $conn->prepare("SELECT * FROM ratings WHERE consumer_id=? AND shop_id=? AND order_id=?");
    $check->execute([$consumer_id, $shop_id, $order_id]);
    if ($check->rowCount() == 0) {
        $stmt = $conn->prepare("INSERT INTO ratings (consumer_id, shop_id, order_id, rating, comment, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$consumer_id, $shop_id, $order_id, $rating, $comment]);
        echo '<script>alert("Thank you for your rating!");location.reload();</script>';
    } else {
        echo '<script>alert("You have already rated this shop for this order.");closeRatingModal();</script>';
    }
}
?>