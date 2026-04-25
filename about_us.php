<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>About Us - Tuy PureFlow</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white text-gray-800">
  <!-- Header -->
  <header class="shadow-md">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex justify-between items-center py-4">
        <div class="flex items-center space-x-2">
          <img src="images/logo.png" alt="Tuy PureFlow Logo" class="h-10 w-auto">
          <span class="font-bold text-xl text-blue-600">Tuy PureFlow</span>
        </div>
        <nav class="hidden md:flex space-x-8 items-center text-gray-700">
          <a href="index.html" class="hover:text-blue-600 font-medium">Home</a>
          <!-- Services Dropdown -->
          <div class="relative group">
            <button class="flex items-center gap-1 hover:text-blue-600 font-medium focus:outline-none">
              Services
              <span class="transform transition-transform group-hover:rotate-180">▼</span>
            </button>
            <div class="absolute left-0 mt-2 w-44 bg-white shadow-lg rounded-md opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10">
              <a href="Consumer/landing_page.php" class="block px-4 py-2 text-sm hover:bg-blue-50">Order Water</a>
              <a href="distributor/signup.php" class="block px-4 py-2 text-sm hover:bg-blue-50">Distributor Portal</a>
            </div>
          </div>
          <a href="about_us.php" class="text-blue-600 font-bold">About Us</a>
          <a href="contact_us.php" class="hover:text-blue-600 font-medium">Contact Us</a>
          <div class="relative group">
            <button class="ml-4 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 font-medium flex items-center gap-1 focus:outline-none">
              Account
              <span class="transform transition-transform group-hover:rotate-180">▼</span>
            </button>
            <div class="absolute right-0 mt-2 w-36 bg-white shadow-lg rounded-md opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10">
              <a href="Consumer/signup.php" class="block px-4 py-2 text-sm hover:bg-blue-50">Sign Up</a>
              <a href="Consumer/login.php" class="block px-4 py-2 text-sm hover:bg-blue-50">Sign In</a>
            </div>
          </div>
        </nav>
        <div class="md:hidden">
          <button class="text-gray-700 focus:outline-none">☰</button>
        </div>
      </div>
    </div>
  </header>
  <!-- About Us Section -->
  <section class="py-16 bg-blue-50">
    <div class="max-w-4xl mx-auto px-4 text-center">
      <h2 class="text-3xl font-bold text-blue-700 mb-4">About Tuy PureFlow</h2>
      <p class="text-lg text-gray-700 mb-6">
        <span class="font-semibold text-blue-600">Tuy PureFlow</span> is a digital platform dedicated to making purified water delivery in Tuy, Batangas clean, convenient, and reliable. Our mission is to provide every household with easy access to safe drinking water, while supporting local businesses and improving delivery efficiency.
      </p>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-8 text-left mb-8">
        <div>
          <h3 class="text-xl font-bold text-blue-700 mb-2">Our Mission</h3>
          <p class="text-gray-700 mb-4">Provide clean, accessible, and convenient water delivery to every household in Tuy.</p>
          <h3 class="text-xl font-bold text-blue-700 mb-2">Our Vision</h3>
          <p class="text-gray-700 mb-4">Become the leading digital water distribution platform in Batangas.</p>
        </div>
        <div>
          <h3 class="text-xl font-bold text-blue-700 mb-2">Our Commitments</h3>
          <ul class="list-disc list-inside text-gray-700">
            <li>Clean and safe water</li>
            <li>Verified distributors</li>
            <li>Secure ordering system</li>
            <li>Transparent pricing</li>
            <li>Fast and reliable delivery</li>
            <li>Local availability</li>
          </ul>
        </div>
      </div>
      <div class="mb-8 text-left">
        <h3 class="text-xl font-bold text-blue-700 mb-2">Our Story</h3>
        <p class="text-gray-700 mb-4">
          Tuy PureFlow began as a capstone research project to address water delivery inefficiencies in Tuy. We saw local water shops struggling to manage orders and consumers facing delays and uncertainty. Our team designed PureFlow to help shops streamline operations and give consumers a fast, reliable, and easy way to order purified water.
        </p>
      </div>
      <div class="mb-8 text-left">
        <h3 class="text-xl font-bold text-blue-700 mb-2">How It Works</h3>
        <ul class="list-decimal list-inside text-gray-700">
          <li>Users order water through our website</li>
          <li>Nearby verified distributors accept the order</li>
          <li>Riders deliver water directly to the customer</li>
          <li>System provides real-time tracking and updates</li>
        </ul>
      </div>
      <div class="mb-8 text-left">
        <h3 class="text-xl font-bold text-blue-700 mb-2">For the Community</h3>
        <ul class="list-disc list-inside text-gray-700">
          <li>Supporting local businesses</li>
          <li>Ensuring water quality</li>
          <li>Improving delivery efficiency</li>
          <li>Helping Tuy adopt digital solutions</li>
        </ul>
      </div>
      <div class="mb-8 text-left">
        <h3 class="text-xl font-bold text-blue-700 mb-2">Meet the Team</h3>
        <p class="text-gray-700 mb-2">Tuy PureFlow Development Team (BSIT-BA)</p>
        <ul class="list-disc list-inside text-gray-700">
          <li>Project Manager</li>
          <li>Backend Developer</li>
          <li>Frontend Developer</li>
          <li>UX/UI Designer</li>
          <li>Researcher</li>
          <li>Data Analytics</li>
        </ul>
      </div>
      <div class="mb-8 text-left">
        <h3 class="text-xl font-bold text-blue-700 mb-2">Looking Ahead</h3>
        <p class="text-gray-700">We are committed to continuous improvement, adding new features, and expanding our reach to serve more communities in Batangas and beyond.</p>
      </div>
    </div>
  </section>
</body>
</html>
