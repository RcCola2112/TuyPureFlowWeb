<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Contact Us - Tuy PureFlow</title>
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
          <a href="about_us.php" class="hover:text-blue-600 font-medium">About Us</a>
          <a href="contact_us.php" class="text-blue-600 font-bold">Contact Us</a>
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
  <!-- Contact Us Section -->
  <section class="py-16 bg-blue-50">
    <div class="max-w-4xl mx-auto px-4">
      <h2 class="text-3xl font-bold text-blue-700 text-center mb-4">Contact Us</h2>
      <p class="text-lg text-gray-700 text-center mb-8">We respond within 24–48 hours. Your questions and concerns matter to us!</p>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-12">
        <!-- Contact Info -->
        <div class="bg-white rounded-lg shadow p-6">
          <h3 class="text-xl font-bold text-blue-700 mb-4">Contact Information</h3>
          <ul class="text-gray-700 mb-4">
            <li><span class="font-semibold">Email:</span> tuypureflow@tuypureflow.com </li>
            <li><span class="font-semibold">Phone:</span> 0936-269-5356</li>
            <li><span class="font-semibold">Facebook:</span> Tuy PureFlow Official</li>
            <li><span class="font-semibold">Office Hours:</span> 8:00 AM – 6:00 PM (Mon–Sat)</li>
          </ul>
          <div class="mb-2">
            <h4 class="font-semibold text-blue-700">Consumer Support</h4>
            <p class="text-sm text-gray-600">For order, delivery, and account concerns.</p>
          </div>
          <div>
            <h4 class="font-semibold text-blue-700">Distributor Support</h4>
            <p class="text-sm text-gray-600">For shop management, delivery, and system help.</p>
          </div>
        </div>
        <!-- Contact Form -->
        <form class="bg-white rounded-lg shadow p-6 flex flex-col" method="post" action="#">
          <h3 class="text-xl font-bold text-blue-700 mb-4">Send Us a Message</h3>
          <label class="mb-2 font-medium text-gray-700">Full Name</label>
          <input type="text" name="name" class="mb-4 px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-400" required>
          <label class="mb-2 font-medium text-gray-700">Email Address</label>
          <input type="email" name="email" class="mb-4 px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-400" required>
          <label class="mb-2 font-medium text-gray-700">Phone Number (optional)</label>
          <input type="text" name="phone" class="mb-4 px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-400">
          <label class="mb-2 font-medium text-gray-700">Message</label>
          <textarea name="message" rows="4" class="mb-4 px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-400" required></textarea>
          <button type="submit" class="bg-blue-600 text-white font-semibold py-2 px-6 rounded hover:bg-blue-700">Submit</button>
        </form>
      </div>
      <div class="text-center text-gray-600 text-sm mt-8">
        <p>We are committed to providing fast, reliable, and friendly support for all users.</p>
      </div>
    </div>
  </section>
</body>
</html>
