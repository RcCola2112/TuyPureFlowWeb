<?php include('includes/header.php'); ?>

<!-- Description Section -->
<section class="bg-blue-800 py-16 text-white text-center">
  <div class="max-w-4xl mx-auto px-4">
    <h2 class="text-3xl font-bold mb-4">What is PureFlow?</h2>
    <p class="text-lg leading-relaxed">
      Tuy PureFlow is an innovative digital platform designed for efficient and hassle-free purified water distribution. 
      Whether you're a consumer looking for a seamless ordering experience or a distributor managing deliveries, PureFlow optimizes every step. 
      With AI-powered analytics, real-time tracking, and smart inventory management, we ensure clean water reaches you when you need it, without the wait.
    </p>
  </div>
</section>

<!-- Why Use PureFlow Section -->
<section class="py-20 bg-white">
  <div class="max-w-7xl mx-auto px-4">
    <h2 class="text-3xl font-bold text-center text-blue-700 mb-12">Why Use PureFlow?</h2>
    <div class="lg:flex gap-12">
      <div class="lg:w-1/3 mb-10">
        <img src="images/container.png" alt="Water Container" class="mx-auto w-full h-auto max-w-sm">
      </div>
      <div class="lg:w-2/3 grid grid-cols-1 md:grid-cols-2 gap-8">
        <?php
        $features = [
          ['icon-convenience.png', 'Convenience at Your Fingertips', 'Order purified water anytime, anywhere with just a few clicks.'],
          ['icon-delivery.png', 'Fast & Reliable Delivery', 'Connect with trusted water distributors in your area.'],
          ['icon-map.png', 'Find the Best Shops Near You', 'Compare shops based on reviews, delivery time, and pricing.'],
          ['icon-tracking.png', 'Real-Time Order Tracking', 'Track your delivery in real-time.'],
          ['icon-price.png', 'No Hidden Fees', 'Transparent pricing—pay only for what you order.'],
          ['icon-trusted.png', 'Sustainable & Trusted', 'Verified distributors ensure quality while supporting local businesses.'],
        ];
        foreach ($features as $f) {
          echo "<div class='flex items-start gap-4'>
                  <img src='images/{$f[0]}' class='w-12 h-12' alt='{$f[1]} Icon'>
                  <div>
                    <h3 class='text-xl font-semibold text-blue-700'>{$f[1]}</h3>
                    <p class='text-sm mt-1 text-gray-700'>{$f[2]}</p>
                  </div>
                </div>";
        }
        ?>
      </div>
    </div>
  </div>
</section>

<!-- Join as a Distributor Section -->
<section class="py-20 bg-white">
  <div class="max-w-6xl mx-auto px-4">
    <h2 class="text-3xl font-bold text-center text-gray-900 mb-6">Join us as a Distributor</h2>
    <div class="text-center max-w-3xl mx-auto mb-12">
      <h3 class="text-xl font-semibold text-gray-800">Expand Your Business with PureFlow!</h3>
      <p class="text-gray-600 mt-2">
        Become a part of Tuy PureFlow and grow your water distribution business with our smart platform.
        Get access to a large customer base, automated order management, and real-time analytics.
      </p>
    </div>

    <!-- Why Partner -->
    <div class="mb-12">
      <h3 class="text-xl font-semibold text-gray-800 mb-6">Why Partner with Us?</h3>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <?php
        $benefits = [
          ['icon-sales.png', 'Increase Your Sales', 'Reach more customers looking for purified water.'],
          ['icon-management.png', 'Efficient Order Management', 'Process and track orders with ease.'],
          ['icon-delivery-routes.png', 'Optimized Delivery Routes', 'Reduce costs with AI-powered delivery optimization.'],
          ['icon-insights.png', 'Real-Time Insights', 'Monitor sales, inventory, and customer trends.'],
        ];
        foreach ($benefits as $b) {
          echo "<div class='flex items-start gap-4'>
                  <img src='images/{$b[0]}' class='w-10 h-10' alt='{$b[1]} Icon'>
                  <div>
                    <h4 class='font-semibold text-gray-800'>{$b[1]}</h4>
                    <p class='text-sm text-gray-600'>{$b[2]}</p>
                  </div>
                </div>";
        }
        ?>
      </div>
    </div>

    <!-- How It Works -->
    <div class="mb-12">
      <h3 class="text-xl font-semibold text-gray-800 mb-6">How It Works?</h3>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <?php
        $steps = [
          ['icon-signup.png', 'Sign Up', 'Register your business and submit verification documents.'],
          ['icon-start-selling.png', 'Start Selling', 'List products, set delivery options, and receive orders.'],
          ['icon-approved.png', 'Get Approved', 'Process and track orders with ease.'],
        ];
        foreach ($steps as $s) {
          echo "<div class='flex items-start gap-4'>
                  <img src='images/{$s[0]}' class='w-10 h-10' alt='{$s[1]} Icon'>
                  <div>
                    <h4 class='font-semibold text-gray-800'>{$s[1]}</h4>
                    <p class='text-sm text-gray-600'>{$s[2]}</p>
                  </div>
                </div>";
        }
        ?>
      </div>
    </div>

    <div class="text-right">
      <a href="distributor/signup.php" class="bg-cyan-400 hover:bg-cyan-500 text-white font-semibold py-2 px-6 rounded inline-block">
        Continue
      </a>
    </div>
  </div>
</section>