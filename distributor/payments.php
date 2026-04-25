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
  $currentPage = 'payments';

  // Get distributor info
  $distributor_id = $_SESSION['distributor_id'];
  $stmt = $conn->prepare("SELECT * FROM distributor WHERE distributor_id = ?");
  $stmt->execute([$distributor_id]);
  $distributor = $stmt->fetch();

  $username = $distributor['name'] ?? '';
  $stmtShop = $conn->prepare("SELECT name FROM shop WHERE distributor_id = ? LIMIT 1");
  $stmtShop->execute([$distributor_id]);
  $shopname = $stmtShop->fetchColumn() ?: '';
  $profilePic = "images/profile.jpg";
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments | Tuy PureFlow Distributor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
      .payment-card {
        transition: all 0.3s ease;
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border-left: 4px solid transparent;
      }
      .payment-card:hover {
        transform: translateY(-4px) scale(1.01);
        border-left-color: #3b82f6;
      }
      .payment-card.paid:hover {
        box-shadow: 0 8px 20px rgba(16, 185, 129, 0.15);
        border-left-color: #10b981;
      }
      .payment-card.unpaid:hover {
        box-shadow: 0 8px 20px rgba(239, 68, 68, 0.15);
        border-left-color: #ef4444;
      }
      .payment-card.outstanding:hover {
        box-shadow: 0 8px 20px rgba(245, 158, 11, 0.15);
        border-left-color: #f59e0b;
      }
      .payment-card:hover:not(.paid):not(.unpaid):not(.outstanding) {
        box-shadow: 0 8px 20px rgba(59, 130, 246, 0.15);
      }
      .flatpickr-input {
        cursor: pointer;
      }
      .summary-box {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
      }
      .summary-box::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        opacity: 0;
        transition: opacity 0.3s ease;
      }
      .summary-box.border-green-500::before {
        background: linear-gradient(90deg, transparent, rgba(16, 185, 129, 0.3), rgba(16, 185, 129, 0.6), rgba(16, 185, 129, 0.3), transparent);
      }
      .summary-box.border-red-500::before {
        background: linear-gradient(90deg, transparent, rgba(239, 68, 68, 0.3), rgba(239, 68, 68, 0.6), rgba(239, 68, 68, 0.3), transparent);
      }
      .summary-box.border-blue-500::before {
        background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.3), rgba(59, 130, 246, 0.6), rgba(59, 130, 246, 0.3), transparent);
      }
      .summary-box:hover::before {
        opacity: 1;
      }
      .summary-box:hover {
        transform: translateY(-4px);
      }
      .summary-box.border-green-500:hover {
        box-shadow: 0 8px 20px rgba(16, 185, 129, 0.15);
      }
      .summary-box.border-red-500:hover {
        box-shadow: 0 8px 20px rgba(239, 68, 68, 0.15);
      }
      .summary-box.border-blue-500:hover {
        box-shadow: 0 8px 20px rgba(59, 130, 246, 0.15);
      }
      .filter-button {
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
      }
      .filter-button::before {
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
      .filter-button:hover::before {
        width: 300px;
        height: 300px;
      }
      .filter-button.active {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
      }
      .filter-button.active.green {
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
      }
      .filter-button.active.red {
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
      }
      .date-filter-section {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        box-shadow: 0 2px 8px rgba(59, 130, 246, 0.08);
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
      .status-badge.bg-green-100 {
        box-shadow: 0 2px 6px rgba(16, 185, 129, 0.2);
      }
      .status-badge.bg-red-100 {
        box-shadow: 0 2px 6px rgba(239, 68, 68, 0.2);
      }
      .status-badge.bg-orange-100 {
        box-shadow: 0 2px 6px rgba(249, 115, 22, 0.2);
      }
      .status-badge.bg-yellow-100 {
        box-shadow: 0 2px 6px rgba(245, 158, 11, 0.2);
      }
      .icon-wrapper {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, currentColor, transparent);
        opacity: 0.1;
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
    </style>
  </head>
  <body class="flex bg-gray-100">
    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="ml-64 flex flex-col flex-1 min-h-screen">
      <!-- Header -->
      <?php include 'header.php'; ?>

      <!-- Payments Content -->
      <main class="flex-1 p-6 overflow-y-auto bg-gradient-to-br from-gray-50 to-blue-50">
        <div class="mb-8">
          <h1 class="text-4xl font-bold gradient-text mb-2 flex items-center gap-3">
            <i class="fas fa-credit-card text-blue-600"></i>
            Payments Management
          </h1>
          <p class="text-gray-600">Track and manage all payment transactions</p>
        </div>

        <!-- Summary Boxes -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          <div class="summary-box rounded-xl border-2 border-green-500 p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
              <div class="icon-wrapper text-green-600">
                <i class="fas fa-check-circle text-2xl"></i>
              </div>
              <i class="fas fa-arrow-up-right text-green-600 opacity-50"></i>
            </div>
            <div class="text-sm font-semibold text-gray-600 mb-2 uppercase tracking-wide">Paid</div>
            <div id="paid-count" class="text-4xl font-bold text-green-600 mb-1">0</div>
            <div class="text-xs text-gray-500">Successfully paid transactions</div>
          </div>
          <div class="summary-box rounded-xl border-2 border-red-500 p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
              <div class="icon-wrapper text-red-600">
                <i class="fas fa-times-circle text-2xl"></i>
              </div>
              <i class="fas fa-arrow-up-right text-red-600 opacity-50"></i>
            </div>
            <div class="text-sm font-semibold text-gray-600 mb-2 uppercase tracking-wide">Unpaid</div>
            <div id="unpaid-count" class="text-4xl font-bold text-red-600 mb-1">0</div>
            <div class="text-xs text-gray-500">Pending payment transactions</div>
          </div>
          <div class="summary-box rounded-xl border-2 border-blue-500 p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
              <div class="icon-wrapper text-blue-600">
                <i class="fas fa-peso-sign text-2xl"></i>
              </div>
              <i class="fas fa-arrow-up-right text-blue-600 opacity-50"></i>
            </div>
            <div class="text-sm font-semibold text-gray-600 mb-2 uppercase tracking-wide">Total</div>
            <div id="total-amount" class="text-4xl font-bold text-blue-600 mb-1">₱0.00</div>
            <div class="text-xs text-gray-500">Total revenue collected</div>
          </div>
        </div>

        <!-- Date Filter Section -->
        <div class="date-filter-section rounded-xl p-6 mb-6 border border-gray-200">
          <div class="flex items-center gap-3 mb-6">
            <div class="icon-wrapper text-blue-600">
              <i class="fas fa-calendar-alt text-xl"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800">Filter by Date</h2>
          </div>
          <div class="flex flex-wrap items-end gap-4">
            <div class="flex-1 min-w-[250px]">
              <label class="block text-sm font-medium text-gray-700 mb-2">Select Date</label>
              <input type="text" id="date-picker" placeholder="Click to select date" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white cursor-pointer" readonly>
            </div>
            <div>
              <button onclick="applyDateFilter()" class="px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 transition-all font-medium flex items-center gap-2" style="box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);">
                <i class="fas fa-filter"></i>
                Apply Filter
              </button>
            </div>
            <div>
              <button onclick="clearDateFilter()" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-all font-medium shadow-sm hover:shadow-md flex items-center gap-2">
                <i class="fas fa-times"></i>
                Clear
              </button>
            </div>
          </div>
          <!-- Alternative: Year and Month Dropdowns (kept for compatibility) -->
          <div class="mt-4 pt-4 border-t border-gray-200">
            <p class="text-sm text-gray-600 mb-2">Or use dropdowns:</p>
            <div class="flex flex-wrap items-end gap-4">
              <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700 mb-2">Year</label>
                <select id="filter-year" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                  <option value="">Select Year</option>
                </select>
              </div>
              <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700 mb-2">Month</label>
                <select id="filter-month" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                  <option value="">Select Month</option>
                  <option value="01">January</option>
                  <option value="02">February</option>
                  <option value="03">March</option>
                  <option value="04">April</option>
                  <option value="05">May</option>
                  <option value="06">June</option>
                  <option value="07">July</option>
                  <option value="08">August</option>
                  <option value="09">September</option>
                  <option value="10">October</option>
                  <option value="11">November</option>
                  <option value="12">December</option>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- Payment Status Filter Buttons -->
        <div class="bg-white rounded-xl p-4 shadow-sm mb-6 border border-gray-200">
          <div class="flex items-center gap-2 mb-4">
            <i class="fas fa-filter text-blue-600"></i>
            <span class="font-semibold text-gray-700">Filter by Status</span>
          </div>
          <div class="flex flex-wrap gap-3">
            <button onclick="filterPayments('all')" id="filter-all" class="filter-button active px-6 py-3 rounded-full bg-gradient-to-r from-blue-500 to-blue-600 text-white font-medium shadow-md flex items-center gap-2">
              <i class="fas fa-list"></i>
              All
            </button>
            <button onclick="filterPayments('paid')" id="filter-paid" class="filter-button px-6 py-3 rounded-full bg-gray-200 text-gray-700 font-medium hover:bg-gray-300 transition-all shadow-sm flex items-center gap-2">
              <i class="fas fa-check-circle text-green-600"></i>
              Paid
            </button>
            <button onclick="filterPayments('unpaid')" id="filter-unpaid" class="filter-button px-6 py-3 rounded-full bg-gray-200 text-gray-700 font-medium hover:bg-gray-300 transition-all shadow-sm flex items-center gap-2">
              <i class="fas fa-times-circle text-red-600"></i>
              Unpaid
            </button>
            <button onclick="filterPayments('outstanding')" id="filter-outstanding" class="filter-button px-6 py-3 rounded-full bg-gray-200 text-gray-700 font-medium hover:bg-gray-300 transition-all shadow-sm flex items-center gap-2">
              <i class="fas fa-clock text-orange-600"></i>
              Outstanding
            </button>
          </div>
        </div>

        <!-- Payment List -->
        <div id="payment-list" class="space-y-4">
          <!-- Payment items will be loaded here -->
          <div class="text-center text-gray-500 py-8">
            <p class="text-lg">No data loaded. Please select a date to filter payments.</p>
          </div>
        </div>
      </main>
    </div>

    <!-- Edit Payment Modal -->
    <div id="edit-modal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-30 z-50 flex items-center justify-center p-4 backdrop-blur-sm">
      <div class="bg-white rounded-2xl p-6 w-full max-w-md shadow-2xl transform transition-all" style="box-shadow: 0 20px 60px rgba(59, 130, 246, 0.15);">
        <div class="flex justify-between items-center mb-6 pb-4 border-b border-gray-200">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white">
              <i class="fas fa-edit"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-800">Edit Payment Status</h2>
          </div>
          <button onclick="closeModal()" class="w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 hover:text-gray-800 transition-all flex items-center justify-center">
            <i class="fas fa-times"></i>
          </button>
        </div>
        
        <form id="edit-payment-form" onsubmit="updatePayment(event)">
          <input type="hidden" id="edit-delivery-id" name="delivery_id">
          <input type="hidden" id="edit-distributor-id" name="distributor_id" value="<?php echo $distributor_id; ?>">
          
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-2">Payment Status</label>
            <select id="edit-payment-status" name="payment_status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
              <option value="paid">Paid</option>
              <option value="unpaid">Unpaid</option>
              <option value="outstanding">Outstanding</option>
            </select>
          </div>

          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-2">Payment Amount</label>
            <input type="number" id="edit-payment-amount" name="payment_amount" step="0.01" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
          </div>

          <div id="partial-payment-container" class="mb-4 hidden">
            <label class="block text-sm font-medium text-gray-700 mb-2">Partial Payment Received</label>
            <input type="number" id="edit-partial-payment" name="partial_payment" step="0.01" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>

          <div id="unpaid-reason-container" class="mb-4 hidden">
            <label class="block text-sm font-medium text-gray-700 mb-2">Unpaid Reason (Optional)</label>
            <textarea id="edit-unpaid-reason" name="unpaid_reason" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
          </div>

          <div class="flex gap-3 mt-6">
            <button type="submit" class="flex-1 bg-gradient-to-r from-blue-600 to-blue-700 text-white px-4 py-3 rounded-lg hover:from-blue-700 hover:to-blue-800 transition-all font-medium flex items-center justify-center gap-2" style="box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);">
              <i class="fas fa-save"></i>
              Update Payment
            </button>
            <button type="button" onclick="closeModal()" class="flex-1 bg-gray-200 text-gray-700 px-4 py-3 rounded-lg hover:bg-gray-300 transition-all font-medium shadow-sm hover:shadow-md flex items-center justify-center gap-2">
              <i class="fas fa-times"></i>
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>

    <script>
      let allPayments = [];
      let filteredPayments = [];
      let allConsumersWithOutstanding = []; // Store ALL consumers with outstanding from backend (no date filter)
      let consumersWithOutstanding = []; // Store filtered consumers with outstanding (if date filter applied)
      let currentFilter = 'all';
      let currentYear = '';
      let currentMonth = '';
      let currentDay = '';
      let currentPage = 1;
      const itemsPerPage = 10;
      const distributorId = <?php echo $distributor_id; ?>;

      // Load payments on page load
      document.addEventListener('DOMContentLoaded', function() {
        populateYearDropdown();
        initializeDatePicker();
        
        // Sync date picker with dropdowns
        document.getElementById('filter-year').addEventListener('change', syncDatePickerFromDropdowns);
        document.getElementById('filter-month').addEventListener('change', syncDatePickerFromDropdowns);
        
        // Handle payment status change in modal
        document.getElementById('edit-payment-status').addEventListener('change', function() {
          const status = this.value;
          const partialContainer = document.getElementById('partial-payment-container');
          const unpaidContainer = document.getElementById('unpaid-reason-container');
          
          if (status === 'outstanding') {
            partialContainer.classList.remove('hidden');
            unpaidContainer.classList.remove('hidden');
          } else if (status === 'unpaid') {
            partialContainer.classList.add('hidden');
            unpaidContainer.classList.remove('hidden');
          } else {
            partialContainer.classList.add('hidden');
            unpaidContainer.classList.add('hidden');
          }
        });
        
        // Load all payments on page load (without date filter) to get all consumers with outstanding
        loadPayments('', '', '', null, false);
      });

      function initializeDatePicker() {
        const datePicker = flatpickr("#date-picker", {
          dateFormat: "Y-m-d",
          defaultDate: null,
          maxDate: "today",
          minDate: "2020-01-01",
          mode: "single",
          enableTime: false,
          allowInput: false,
          clickOpens: true,
          locale: {
            firstDayOfWeek: 1
          },
          onChange: function(selectedDates, dateStr, instance) {
            if (selectedDates.length > 0) {
              // When date picker is used, clear dropdowns to avoid confusion
              // This ensures date picker filters by exact date, dropdowns filter by month
              document.getElementById('filter-year').value = '';
              document.getElementById('filter-month').value = '';
            }
          }
        });
        
        // Store reference for later use
        document.getElementById('date-picker')._flatpickr = datePicker;
      }

      function syncDatePickerFromDropdowns() {
        // When dropdowns are changed, clear the date picker to avoid confusion
        // This ensures that if user uses dropdowns, it filters by month
        // If user uses date picker, it filters by exact date
        const datePicker = document.getElementById('date-picker')._flatpickr;
        if (datePicker) {
          datePicker.clear();
        }
      }

      function populateYearDropdown() {
        const yearSelect = document.getElementById('filter-year');
        const currentYearNum = new Date().getFullYear();
        
        // Add years from 2020 to current year + 1
        for (let year = 2020; year <= currentYearNum + 1; year++) {
          const option = document.createElement('option');
          option.value = year;
          option.textContent = year;
          yearSelect.appendChild(option);
        }
      }

      function applyDateFilter() {
        const datePicker = document.getElementById('date-picker')._flatpickr;
        const yearDropdown = document.getElementById('filter-year').value;
        const monthDropdown = document.getElementById('filter-month').value;
        
        let year = '';
        let month = '';
        let day = '';
        let filterByExactDate = false;
        let selectedDateStr = null;
        
        // Check if date picker has a selected date
        if (datePicker && datePicker.selectedDates.length > 0) {
          // Using date picker - filter by exact date
          const selectedDate = datePicker.selectedDates[0];
          year = selectedDate.getFullYear().toString();
          month = String(selectedDate.getMonth() + 1).padStart(2, '0');
          day = String(selectedDate.getDate()).padStart(2, '0');
          selectedDateStr = `${year}-${month}-${day}`;
          filterByExactDate = true;
        } else if (yearDropdown && monthDropdown) {
          // Using dropdowns - filter by entire month
          year = yearDropdown;
          month = monthDropdown;
          day = '';
          selectedDateStr = null;
          filterByExactDate = false;
        } else {
          alert('Please select a date using the date picker, or select both year and month using the dropdowns.');
          return;
        }
        
        currentYear = year;
        currentMonth = month;
        currentDay = day;
        
        // Show loading state
        document.getElementById('payment-list').innerHTML = `
          <div class="text-center text-gray-500 py-8">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto"></div>
            <p class="mt-2">Loading payments...</p>
          </div>
        `;
        
        loadPayments(year, month, day, selectedDateStr, filterByExactDate);
      }

      function clearDateFilter() {
        // Clear date picker
        const datePicker = document.getElementById('date-picker')._flatpickr;
        if (datePicker) {
          datePicker.clear();
        }
        
        // Clear dropdowns
        document.getElementById('filter-year').value = '';
        document.getElementById('filter-month').value = '';
        
        currentYear = '';
        currentMonth = '';
        currentDay = '';
        allPayments = [];
        filteredPayments = [];
        document.getElementById('payment-list').innerHTML = `
          <div class="text-center text-gray-500 py-8">
            <p class="text-lg">No data loaded. Please select a date to filter payments.</p>
          </div>
        `;
        updateSummary({ paid_count: 0, unpaid_count: 0, grand_total: 0 });
      }

      async function loadPayments(year, month, day, selectedDateStr, filterByExactDate) {
        try {
          const response = await fetch(`../pureflowBackend/get_payment_status.php?distributor_id=${distributorId}`);
          const data = await response.json();
          
          if (data.success) {
            let records = data.records || [];
            
            // Store ALL consumers with outstanding from backend (no date filter) - for outstanding view
            allConsumersWithOutstanding = data.consumers_with_outstanding || [];
            consumersWithOutstanding = allConsumersWithOutstanding; // Default to all consumers
            
            // Apply filtering based on the method used
            if (year && month) {
              records = records.filter(payment => {
                const paymentDate = new Date(payment.delivery_date || payment.order_date || '');
                if (isNaN(paymentDate.getTime())) return false;
                
                const paymentYear = paymentDate.getFullYear().toString();
                const paymentMonth = String(paymentDate.getMonth() + 1).padStart(2, '0');
                
                if (filterByExactDate && selectedDateStr && day) {
                  // Filter by exact date (from date picker)
                  const paymentDay = String(paymentDate.getDate()).padStart(2, '0');
                  const paymentDateStr = `${paymentYear}-${paymentMonth}-${paymentDay}`;
                  return paymentDateStr === selectedDateStr;
                } else {
                  // Filter by entire month (from dropdowns)
                  return paymentYear === year && paymentMonth === month;
                }
              });
              
              // Only filter consumers_with_outstanding if NOT viewing outstanding filter
              // For outstanding view, show ALL consumers regardless of date
              if (currentFilter !== 'outstanding' && allConsumersWithOutstanding.length > 0) {
                consumersWithOutstanding = allConsumersWithOutstanding.map(consumer => {
                  const filteredOrders = consumer.orders.filter(order => {
                    const orderDate = new Date(order.delivery_date || '');
                    if (isNaN(orderDate.getTime())) return false;
                    
                    const orderYear = orderDate.getFullYear().toString();
                    const orderMonth = String(orderDate.getMonth() + 1).padStart(2, '0');
                    
                    if (filterByExactDate && selectedDateStr && day) {
                      const orderDay = String(orderDate.getDate()).padStart(2, '0');
                      const orderDateStr = `${orderYear}-${orderMonth}-${orderDay}`;
                      return orderDateStr === selectedDateStr;
                    } else {
                      return orderYear === year && orderMonth === month;
                    }
                  });
                  
                  // Recalculate total_outstanding for filtered orders
                  const filteredTotalOutstanding = filteredOrders.reduce((sum, order) => {
                    return sum + parseFloat(order.outstanding_balance || 0);
                  }, 0);
                  
                  return {
                    ...consumer,
                    orders: filteredOrders,
                    order_count: filteredOrders.length,
                    total_outstanding: filteredTotalOutstanding
                  };
                }).filter(consumer => consumer.total_outstanding > 0 && consumer.orders.length > 0)
                  .sort((a, b) => b.total_outstanding - a.total_outstanding); // Re-sort after filtering
              } else {
                // For outstanding view, use all consumers (no date filter)
                consumersWithOutstanding = allConsumersWithOutstanding;
              }
            }
            
            allPayments = records;
            filteredPayments = records;
            currentPage = 1; // Reset to first page when loading new data
            
            // Recalculate stats for filtered data
            const stats = calculateStats(records);
            updateSummary(stats);
            
            // Apply current status filter if any
            if (currentFilter !== 'all') {
              // For outstanding, use allConsumersWithOutstanding (no date filter)
              if (currentFilter === 'outstanding') {
                displayOutstandingCustomers([]);
              } else {
                // Pass the records to filterPayments so it uses the date-filtered data
                filterPayments(currentFilter, records);
              }
            } else {
              displayPayments(records);
            }
          } else {
            console.error('Error loading payments:', data.message);
            document.getElementById('payment-list').innerHTML = '<div class="text-center text-red-500 py-8">Error loading payments: ' + data.message + '</div>';
          }
        } catch (error) {
          console.error('Error:', error);
          document.getElementById('payment-list').innerHTML = '<div class="text-center text-red-500 py-8">Error loading payments. Please try again.</div>';
        }
      }

      function calculateStats(payments) {
        const stats = {
          paid_count: 0,
          unpaid_count: 0,
          outstanding_count: 0,
          paid_total: 0,
          unpaid_total: 0,
          outstanding_total: 0,
          grand_total: 0
        };

        payments.forEach(payment => {
          const status = (payment.payment_status || '').toLowerCase();
          const amount = parseFloat(payment.amount_due || payment.payment_amount || payment.total_amount || 0);

          if (status === 'paid') {
            stats.paid_count += 1;
            stats.paid_total += amount;
            stats.grand_total += amount;
          } else if (status === 'outstanding') {
            stats.outstanding_count += 1;
            stats.outstanding_total += parseFloat(payment.outstanding_balance || amount);
          } else {
            stats.unpaid_count += 1;
            stats.unpaid_total += amount;
          }
        });

        return stats;
      }

      function updateSummary(stats) {
        document.getElementById('paid-count').textContent = stats.paid_count || 0;
        document.getElementById('unpaid-count').textContent = stats.unpaid_count || 0;
        const total = parseFloat(stats.grand_total || 0);
        document.getElementById('total-amount').textContent = '₱' + total.toFixed(2);
      }

      function displayPayments(payments) {
        const container = document.getElementById('payment-list');
        
        if (payments.length === 0) {
          container.innerHTML = `
            <div class="text-center py-12 bg-white rounded-xl shadow-sm border border-gray-200">
              <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
                <i class="fas fa-inbox text-4xl text-gray-400"></i>
              </div>
              <p class="text-lg font-semibold text-gray-700 mb-2">No payments found</p>
              <p class="text-sm text-gray-500">Try adjusting your filters to see more results</p>
            </div>
          `;
          return;
        }

        // If filtering by outstanding, group by customer
        if (currentFilter === 'outstanding') {
          displayOutstandingCustomers(payments);
          return;
        }

        // Pagination: Calculate start and end indices
        const totalPages = Math.ceil(payments.length / itemsPerPage);
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;
        const paginatedPayments = payments.slice(startIndex, endIndex);

        // Build payment cards HTML
        const paymentsHTML = paginatedPayments.map(payment => {
          const status = payment.payment_status || 'unpaid';
          const statusClass = status === 'paid' ? 'bg-green-100 text-green-700' : 
                            status === 'outstanding' ? 'bg-orange-100 text-orange-700' : 
                            'bg-red-100 text-red-700';
          const statusText = status.charAt(0).toUpperCase() + status.slice(1);
          const amount = parseFloat(payment.amount_due || payment.payment_amount || payment.total_amount || 0);
          const partialPayment = parseFloat(payment.partial_payment || 0);
          const outstandingBalanceFromDB = parseFloat(payment.outstanding_balance || 0);
          
          // Calculate outstanding balance properly:
          // If outstanding_balance is provided from DB, use it
          // Otherwise, calculate: total_amount - partial_payment
          let outstandingBalance = outstandingBalanceFromDB;
          if (outstandingBalance === 0 && (status === 'unpaid' || status === 'outstanding')) {
            // Calculate outstanding balance: total amount minus any partial payment
            outstandingBalance = Math.max(0, amount - partialPayment);
          }
          
          // For display purposes, use the calculated outstanding balance
          const remainingBalance = outstandingBalance;
          const date = new Date(payment.delivery_date || payment.order_date || '');
          const formattedDate = date.toLocaleString('en-US', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
          });

          return `
            <div class="payment-card ${status} rounded-xl p-5 shadow-sm border border-gray-200 fade-in" data-status="${status}">
              <div class="flex justify-between items-start mb-4">
                <div class="flex-1">
                  <div class="flex items-center gap-3 mb-3">
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold text-lg" style="box-shadow: 0 4px 10px rgba(59, 130, 246, 0.25);">
                      ${(payment.consumer_name || 'U')[0].toUpperCase()}
                    </div>
                    <div>
                      <div class="font-bold text-lg text-gray-800">${payment.consumer_name || 'Unknown Customer'}</div>
                      <div class="text-xs text-gray-500">Order #${payment.order_id}</div>
                    </div>
                  </div>
                  <div class="space-y-2 ml-15">
                    <div class="text-sm text-gray-600 flex items-center gap-2">
                      <i class="fas fa-calendar text-blue-500 w-4"></i>
                      <span>${formattedDate}</span>
                    </div>
                    <div class="text-sm text-gray-600 flex items-center gap-2">
                      <i class="fas fa-phone text-green-500 w-4"></i>
                      <span>${payment.consumer_phone || 'N/A'}</span>
                    </div>
                    ${status === 'outstanding' && outstandingBalance > 0 ? `
                    <div class="text-sm text-orange-600 flex items-center gap-2 font-semibold mt-2">
                      <i class="fas fa-exclamation-triangle w-4"></i>
                      <span>Outstanding Balance: ₱${outstandingBalance.toFixed(2)}</span>
                    </div>
                    ` : ''}
                    ${status === 'unpaid' && remainingBalance > 0 ? `
                    <div class="text-sm text-orange-600 flex items-center gap-2 font-semibold mt-2">
                      <i class="fas fa-exclamation-triangle w-4"></i>
                      <span>Outstanding Balance: ₱${remainingBalance.toFixed(2)}</span>
                    </div>
                    ` : ''}
                  </div>
                </div>
                <div class="flex flex-col items-end gap-3">
                  <span class="status-badge ${statusClass}">
                    <i class="fas ${status === 'paid' ? 'fa-check' : status === 'outstanding' ? 'fa-clock' : 'fa-exclamation'}"></i>
                    ${statusText}
                  </span>
                  <button onclick="openEditModal(${payment.delivery_id}, '${status}', ${amount}, ${outstandingBalance}, '${payment.unpaid_reason || ''}')" class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 hover:bg-blue-200 transition-all flex items-center justify-center" style="box-shadow: 0 2px 6px rgba(59, 130, 246, 0.2);">
                    <i class="fas fa-edit"></i>
                  </button>
                </div>
              </div>
              <div class="mt-4 pt-4 border-t border-gray-200">
                ${status === 'unpaid' ? `
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <div class="text-sm text-gray-500 mb-1">Amount</div>
                    <div class="text-xl font-bold text-blue-600">₱0.00</div>
                    <div class="text-sm text-red-600 mt-2">Reason: ${payment.unpaid_reason || 'Pending collection'}</div>
                  </div>
                  <div>
                    <div class="text-sm text-gray-500 mb-1">Outstanding Balance</div>
                    <div class="text-xl font-bold text-orange-600">₱${remainingBalance.toFixed(2)}</div>
                  </div>
                </div>
                ` : status === 'outstanding' && outstandingBalance > 0 ? `
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <div class="text-sm text-gray-500 mb-1">Amount</div>
                    <div class="text-xl font-bold text-gray-800">₱${amount.toFixed(2)}</div>
                  </div>
                  <div>
                    <div class="text-sm text-gray-500 mb-1">Outstanding Balance</div>
                    <div class="text-xl font-bold text-orange-600">₱${outstandingBalance.toFixed(2)}</div>
                  </div>
                </div>
                ` : `
                <div class="flex justify-between items-center">
                  <span class="text-sm text-gray-500">Total Amount</span>
                  <span class="text-xl font-bold text-gray-800">₱${amount.toFixed(2)}</span>
                </div>
                `}
              </div>
            </div>
          `;
        }).join('');

        // Add pagination controls
        let paginationHTML = '';
        if (totalPages > 1) {
          paginationHTML = `
            <div class="mt-6 flex items-center justify-center gap-4 bg-white rounded-xl p-4 shadow-sm border border-gray-200">
              <button onclick="changePage(${currentPage - 1})" 
                      ${currentPage === 1 ? 'disabled' : ''} 
                      class="px-4 py-2 rounded-lg ${currentPage === 1 ? 'bg-gray-200 text-gray-400 cursor-not-allowed' : 'bg-blue-600 text-white hover:bg-blue-700'} transition-all font-medium flex items-center gap-2">
                <i class="fas fa-chevron-left"></i>
                Previous
              </button>
              <span class="text-gray-700 font-semibold">Page ${currentPage} of ${totalPages}</span>
              <button onclick="changePage(${currentPage + 1})" 
                      ${currentPage === totalPages ? 'disabled' : ''} 
                      class="px-4 py-2 rounded-lg ${currentPage === totalPages ? 'bg-gray-200 text-gray-400 cursor-not-allowed' : 'bg-blue-600 text-white hover:bg-blue-700'} transition-all font-medium flex items-center gap-2">
                Next
                <i class="fas fa-chevron-right"></i>
              </button>
            </div>
          `;
        }

        container.innerHTML = paymentsHTML + paginationHTML;
      }

      function displayOutstandingCustomers(payments) {
        const container = document.getElementById('payment-list');
        
        // Use consumers_with_outstanding from backend (already grouped and sorted highest to lowest)
        // For outstanding view, show ALL consumers with outstanding balance (ignore date filters)
        // This matches the mobile app logic exactly
        let customers = allConsumersWithOutstanding.length > 0 ? allConsumersWithOutstanding : (consumersWithOutstanding || []);
        
        // If no backend data available, fall back to calculating from payments
        if (customers.length === 0) {
          // Filter only outstanding payments - include all payments with outstanding balance > 0
          const outstandingPayments = payments.filter(p => {
            const status = (p.payment_status || '').toLowerCase();
            const outstandingBalance = parseFloat(p.outstanding_balance || 0);
            
            // Skip paid payments
            if (status === 'paid') {
              return false;
            }
            
            // Include if there's an outstanding balance > 0 (using backend calculation)
            return outstandingBalance > 0;
          });
          
          if (outstandingPayments.length === 0) {
            container.innerHTML = `
              <div class="text-center py-12 bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
                  <i class="fas fa-inbox text-4xl text-gray-400"></i>
                </div>
                <p class="text-lg font-semibold text-gray-700 mb-2">No outstanding balances found</p>
                <p class="text-sm text-gray-500">All customers are up to date with their payments for the selected date range</p>
              </div>
            `;
            return;
          }

          // Group by customer (fallback calculation)
          const customerMap = {};
          
          outstandingPayments.forEach(payment => {
            const customerId = payment.consumer_id;
            const customerName = payment.consumer_name || 'Unknown Customer';
            const customerPhone = payment.consumer_phone || 'N/A';
            const outstandingBalance = parseFloat(payment.outstanding_balance || 0);
            
            if (!customerMap[customerId]) {
              customerMap[customerId] = {
                consumer_id: customerId,
                consumer_name: customerName,
                consumer_phone: customerPhone,
                orders_count: 0,
                total_outstanding: 0,
                orders: []
              };
            }
            
            customerMap[customerId].orders_count += 1;
            customerMap[customerId].total_outstanding += outstandingBalance;
            customerMap[customerId].orders.push(payment);
          });

          // Convert to array and sort by total outstanding (highest to lowest)
          customers = Object.values(customerMap)
            .filter(customer => customer.total_outstanding > 0)
            .sort((a, b) => b.total_outstanding - a.total_outstanding);
        }
        
        if (customers.length === 0) {
          container.innerHTML = `
            <div class="text-center py-12 bg-white rounded-xl shadow-sm border border-gray-200">
              <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
                <i class="fas fa-inbox text-4xl text-gray-400"></i>
              </div>
              <p class="text-lg font-semibold text-gray-700 mb-2">No outstanding balances found</p>
              <p class="text-sm text-gray-500">All customers are up to date with their payments for the selected date range</p>
            </div>
          `;
          return;
        }

        // Pagination: Calculate start and end indices
        const totalPages = Math.ceil(customers.length / itemsPerPage);
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;
        const paginatedCustomers = customers.slice(startIndex, endIndex);

        // Build customer cards HTML
        const customersHTML = paginatedCustomers.map(customer => {
          return `
            <div class="payment-card outstanding rounded-xl p-5 shadow-sm border border-gray-200 fade-in">
              <div class="flex justify-between items-start mb-4">
                <div class="flex-1">
                  <div class="flex items-center gap-3 mb-3">
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center text-white font-bold text-lg" style="box-shadow: 0 4px 10px rgba(249, 115, 22, 0.25);">
                      ${(customer.consumer_name || 'U')[0].toUpperCase()}
                    </div>
                    <div>
                      <div class="font-bold text-lg text-gray-800">${customer.consumer_name}</div>
                      <div class="text-xs text-gray-500">${customer.orders_count} ${customer.orders_count === 1 ? 'order' : 'orders'} with outstanding balance</div>
                    </div>
                  </div>
                  <div class="space-y-2 ml-15">
                    <div class="text-sm text-gray-600 flex items-center gap-2">
                      <i class="fas fa-phone text-green-500 w-4"></i>
                      <span>${customer.consumer_phone}</span>
                    </div>
                  </div>
                </div>
                <div class="flex flex-col items-end gap-3">
                  <span class="status-badge bg-orange-100 text-orange-700">
                    <i class="fas fa-clock"></i>
                    OUTSTANDING
                  </span>
                </div>
              </div>
              <div class="mt-4 pt-4 border-t border-gray-200">
                <div class="flex justify-between items-center bg-orange-50 p-4 rounded-lg">
                  <span class="text-sm font-semibold text-orange-700 flex items-center gap-2">
                    <i class="fas fa-money-bill-wave"></i>
                    Total Outstanding Balance (Kulang na Bayad)
                  </span>
                  <span class="text-2xl font-bold text-orange-600">₱${customer.total_outstanding.toFixed(2)}</span>
                </div>
              </div>
            </div>
          `;
        }).join('');

        // Add pagination controls
        let paginationHTML = '';
        if (totalPages > 1) {
          paginationHTML = `
            <div class="mt-6 flex items-center justify-center gap-4 bg-white rounded-xl p-4 shadow-sm border border-gray-200">
              <button onclick="changePage(${currentPage - 1})" 
                      ${currentPage === 1 ? 'disabled' : ''} 
                      class="px-4 py-2 rounded-lg ${currentPage === 1 ? 'bg-gray-200 text-gray-400 cursor-not-allowed' : 'bg-blue-600 text-white hover:bg-blue-700'} transition-all font-medium flex items-center gap-2">
                <i class="fas fa-chevron-left"></i>
                Previous
              </button>
              <span class="text-gray-700 font-semibold">Page ${currentPage} of ${totalPages}</span>
              <button onclick="changePage(${currentPage + 1})" 
                      ${currentPage === totalPages ? 'disabled' : ''} 
                      class="px-4 py-2 rounded-lg ${currentPage === totalPages ? 'bg-gray-200 text-gray-400 cursor-not-allowed' : 'bg-blue-600 text-white hover:bg-blue-700'} transition-all font-medium flex items-center gap-2">
                Next
                <i class="fas fa-chevron-right"></i>
              </button>
            </div>
          `;
        }

        container.innerHTML = customersHTML + paginationHTML;
      }

      function filterPayments(filter, paymentsToFilter = null) {
        currentFilter = filter;
        
        // For outstanding filter, use consumers_with_outstanding from backend (all consumers, no date filter needed)
        if (filter === 'outstanding') {
          // Load all payments if not already loaded (to get consumers_with_outstanding)
          if (allConsumersWithOutstanding.length === 0) {
            // Load payments without date filter to get all consumers with outstanding
            loadPayments('', '', '', null, false);
            // Note: displayOutstandingCustomers will be called after loadPayments completes
            return;
          }
          // Display all consumers with outstanding balance (no date filter)
          displayOutstandingCustomers([]);
          return;
        }
        
        // Use provided payments or fall back to allPayments
        const paymentsToUse = paymentsToFilter || allPayments;
        
        // Don't filter if no data is loaded (except for outstanding which is handled above)
        if (paymentsToUse.length === 0) {
          if (paymentsToFilter === null) {
            alert('Please apply date filters first to load payments.');
          }
          return;
        }
        
        // Update button styles
        document.getElementById('filter-all').className = filter === 'all' 
          ? 'filter-button active px-6 py-3 rounded-full bg-gradient-to-r from-blue-500 to-blue-600 text-white font-medium shadow-md flex items-center gap-2'
          : 'filter-button px-6 py-3 rounded-full bg-gray-200 text-gray-700 font-medium hover:bg-gray-300 transition-all shadow-sm flex items-center gap-2';
        document.getElementById('filter-paid').className = filter === 'paid' 
          ? 'filter-button active px-6 py-3 rounded-full bg-gradient-to-r from-green-500 to-green-600 text-white font-medium shadow-md flex items-center gap-2'
          : 'filter-button px-6 py-3 rounded-full bg-gray-200 text-gray-700 font-medium hover:bg-gray-300 transition-all shadow-sm flex items-center gap-2';
        document.getElementById('filter-unpaid').className = filter === 'unpaid' 
          ? 'filter-button active px-6 py-3 rounded-full bg-gradient-to-r from-red-500 to-red-600 text-white font-medium shadow-md flex items-center gap-2'
          : 'filter-button px-6 py-3 rounded-full bg-gray-200 text-gray-700 font-medium hover:bg-gray-300 transition-all shadow-sm flex items-center gap-2';
        document.getElementById('filter-outstanding').className = filter === 'outstanding' 
          ? 'filter-button active px-6 py-3 rounded-full bg-gradient-to-r from-orange-500 to-orange-600 text-white font-medium shadow-md flex items-center gap-2'
          : 'filter-button px-6 py-3 rounded-full bg-gray-200 text-gray-700 font-medium hover:bg-gray-300 transition-all shadow-sm flex items-center gap-2';

        // Filter payments
        let filtered = paymentsToUse;
        if (filter === 'paid') {
          filtered = paymentsToUse.filter(p => (p.payment_status || '').toLowerCase() === 'paid');
        } else if (filter === 'unpaid') {
          filtered = paymentsToUse.filter(p => (p.payment_status || '').toLowerCase() === 'unpaid');
        } else if (filter === 'outstanding') {
          // Outstanding filter is handled separately above - this should not be reached
          // But keep as fallback
          filtered = paymentsToUse.filter(p => {
            const status = (p.payment_status || '').toLowerCase();
            const outstandingBalance = parseFloat(p.outstanding_balance || 0);
            
            // Skip paid payments
            if (status === 'paid') {
              return false;
            }
            
            // Include if there's an outstanding balance > 0 (using backend calculation)
            return outstandingBalance > 0;
          });
        }

        filteredPayments = filtered;
        currentPage = 1; // Reset to first page when filtering
        displayPayments(filtered);
      }

      function changePage(page) {
        const totalPages = Math.ceil(filteredPayments.length / itemsPerPage);
        if (page < 1 || page > totalPages) return;
        currentPage = page;
        displayPayments(filteredPayments);
        // Scroll to top of payment list
        document.getElementById('payment-list').scrollIntoView({ behavior: 'smooth', block: 'start' });
      }

      function openEditModal(deliveryId, status, amount, outstanding, unpaidReason) {
        document.getElementById('edit-delivery-id').value = deliveryId;
        document.getElementById('edit-payment-status').value = status;
        document.getElementById('edit-payment-amount').value = amount;
        document.getElementById('edit-partial-payment').value = outstanding > 0 ? (amount - outstanding) : '';
        document.getElementById('edit-unpaid-reason').value = unpaidReason || '';
        
        // Show/hide fields based on status
        const partialContainer = document.getElementById('partial-payment-container');
        const unpaidContainer = document.getElementById('unpaid-reason-container');
        
        if (status === 'outstanding') {
          partialContainer.classList.remove('hidden');
          unpaidContainer.classList.remove('hidden');
        } else if (status === 'unpaid') {
          partialContainer.classList.add('hidden');
          unpaidContainer.classList.remove('hidden');
        } else {
          partialContainer.classList.add('hidden');
          unpaidContainer.classList.add('hidden');
        }
        
        document.getElementById('edit-modal').classList.remove('hidden');
      }

      function closeModal() {
        document.getElementById('edit-modal').classList.add('hidden');
      }

      async function updatePayment(event) {
        event.preventDefault();
        
        const formData = {
          delivery_id: document.getElementById('edit-delivery-id').value,
          distributor_id: distributorId,
          payment_status: document.getElementById('edit-payment-status').value,
          payment_amount: parseFloat(document.getElementById('edit-payment-amount').value),
          partial_payment: document.getElementById('edit-partial-payment').value ? parseFloat(document.getElementById('edit-partial-payment').value) : null,
          unpaid_reason: document.getElementById('edit-unpaid-reason').value || null
        };

        try {
          const response = await fetch('../pureflowBackend/update_payment_status.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify(formData)
          });

          const data = await response.json();
          
          if (data.success) {
            alert('Payment status updated successfully!');
            closeModal();
            // Reload payments with current filters if they exist
            if (currentYear && currentMonth) {
              const datePicker = document.getElementById('date-picker')._flatpickr;
              let selectedDateStr = null;
              let filterByExactDate = false;
              
              if (datePicker && datePicker.selectedDates.length > 0 && currentDay) {
                selectedDateStr = `${currentYear}-${currentMonth}-${currentDay}`;
                filterByExactDate = true;
              }
              loadPayments(currentYear, currentMonth, currentDay, selectedDateStr, filterByExactDate);
            }
          } else {
            alert('Error updating payment: ' + (data.message || 'Unknown error'));
          }
        } catch (error) {
          console.error('Error:', error);
          alert('Error updating payment. Please try again.');
        }
      }
    </script>
  </body>
  </html>

