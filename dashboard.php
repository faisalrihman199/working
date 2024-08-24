<?php
session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
  header("Location: index.php");
  exit();
}

$db = (new Database())->getConnection();
$userId         = (int)$_SESSION['user_id'];
$restaurantName = $_SESSION['restaurant_name'] ?? 'Restaurant';

// initial recent orders (server-side render, JS will refresh/poll)
$recentOrders = [];
try {
  $stmt = $db->prepare("
    SELECT id, name, order_details, address, phone, table_number, order_type, order_status, created_at
    FROM orders
    WHERE user_id = :uid
    ORDER BY created_at DESC
    LIMIT 500
  ");
  $stmt->execute([':uid' => $userId]);
  $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  $recentOrders = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard - Zicbot</title>
  <link rel="stylesheet" href="./assets//css//style.css" />
  <link rel="icon" href="/icon.png" type="image/x-icon">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <style>
    /* --- PAGINATION minimal CSS (kept tiny; blends with your theme) --- */
    .table-footer {
      display:flex; align-items:center; justify-content:space-between;
      padding:10px 12px; gap:12px; flex-wrap:wrap;
      border-top: 1px solid rgba(0,0,0,.08);
      background: rgba(0,0,0,.02);
    }
    .rows-per-page { display:flex; align-items:center; gap:8px; font-size:14px; }
    .rows-per-page select { padding:6px 8px; }
    .pagination-controls { display:flex; align-items:center; gap:6px; }
    .pagination-controls .btn-page {
      border: 1px solid rgba(0,0,0,.12);
      background:#fff; padding:6px 10px; border-radius:6px; cursor:pointer;
    }
    .pagination-controls .btn-page[disabled] { opacity:.5; cursor:not-allowed; }
    .pagination-controls .page-info { margin:0 8px; font-size:14px; opacity:.8; }
    .pagination-controls .range-info { margin-left:8px; font-size:12px; opacity:.7; }
  </style>
</head>

<body>
  <div class="mobile-overlay" id="mobileOverlay"></div>

  <div class="dashboard">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <div class="sidebar-logo">
          <img src="./assets/images/zicbot_logo.svg" alt="Zicbot">
        </div>
        <div class="sidebar-subtitle"><?php echo htmlspecialchars($restaurantName); ?></div>
      </div>
      <nav>
        <ul class="nav-menu">
          <li class="nav-item"><a href="dashboard.php" class="nav-link active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
          <li class="nav-item"><a href="memberships.php" class="nav-link "><i class="fas fa-crown"></i> Memberships</a></li>
          <li class="nav-item"><a href="billing.php" class="nav-link "><i class="fas fa-credit-card"></i> Billing</a></li>
          <li class="nav-item"><a href="credits.php" class="nav-link"><i class="fas fa-coins"></i> Credits</a></li>
          <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user"></i> Profile</a></li>
          <li class="nav-item"><a href="#" class="nav-link" onclick="contactSupport()"><i class="fas fa-question-circle"></i> Help</a></li>
          <li class="nav-item" style="margin-top:20px;"><a href="logout.php" class="nav-link" style="color:#ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
      </nav>
    </aside>

    <main class="main-content">
      <div class="page-header">
        <button class="hamburger" id="hamburgerBtn" style="color:white;" onclick="toggleSidebar()">
          <i class="fas fa-bars"></i>
        </button>

        <h1 class="page-title">Dashboard</h1>
        <div class="header-actions">
          <button class="btn btn-primary" onclick="refreshOrders()"><i class="fas fa-sync-alt"></i> Refresh</button>
        </div>
      </div>

      <!-- New-order sound (single, no duplicate IDs) -->
      <audio id="newOrderAudio" preload="auto" playsinline>
        <source src="assets/sounds/new_order.mp3" type="audio/mpeg">
      </audio>

      <div class="stats-grid" id="orders-stats">
        <div class="stat-card">
          <div class="stat-number" id="stat-total">0</div>
          <div class="stat-label">Total Orders</div>
        </div>
        <div class="stat-card">
          <div class="stat-number" id="stat-pending">0</div>
          <div class="stat-label">Pending Orders</div>
        </div>
        <div class="stat-card">
          <div class="stat-number" id="stat-completed">0</div>
          <div class="stat-label">Completed Orders</div>
        </div>
        <div class="stat-card">
          <div class="stat-number" id="stat-today">0</div>
          <div class="stat-label">Today's Orders</div>
        </div>
      </div>

      <div class="card" style="padding:20px 6px;">
        <div class="card-header">
          <h2 class="card-title">Recent Orders</h2>
          <div class="header-actions">
            <button class="btn btn-primary" onclick="exportToCSV()"><i class="fas fa-download"></i> Export CSV</button>
          </div>
        </div>

        <div class="table-header">
          <div class="filters">
            <div class="filter-group">
              <label>Date:</label>
              <input type="date" id="dateFilter" class="filter-input" onchange="filterOrders()" />
            </div>
            <div class="filter-group">
              <label>Status:</label>
              <select id="statusFilter" class="filter-input" onchange="filterOrders()">
                <option value="">All Status</option>
                <option value="Pending">Pending</option>
                <option value="Preparing">Preparing</option>
                <option value="Ready">Ready</option>
                <option value="Served">Served</option>
                <option value="Delivered">Delivered</option>
                <option value="Handover">Handover</option>
                <option value="Cancelled">Cancelled</option>
              </select>
            </div>
            <div class="filter-group">
              <label>Order Type:</label>
              <select id="typeFilter" class="filter-input" onchange="filterOrders()">
                <option value="">All Types</option>
                <option value="Delivery">Delivery</option>
                <option value="Dine-in">Dine-in</option>
                <option value="Takeaway">Takeaway</option>
              </select>
            </div>
          </div>
        </div>

        <div class="orders-table">
          <table id="ordersTable">
            <thead>
              <tr>
                <th>Name</th>
                <th>Order Details</th>
                <th>Phone</th>
                <th>Table #</th>
                <th>Type</th>
                <th>Status</th>
                <th>Date &amp; Time</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="ordersTableBody">
              <?php if (empty($recentOrders)): ?>
                <tr>
                  <td colspan="8" style="text-align:center;opacity:.7">No orders yet.</td>
                </tr>
                <?php else: foreach ($recentOrders as $o): ?>
                  <tr data-id="<?= (int)$o['id'] ?>">
                    <td><?= htmlspecialchars($o['name']) ?></td>
                    <td><?= htmlspecialchars($o['order_details']) ?></td>
                    <td><?= htmlspecialchars($o['phone']) ?></td>
                    <td><?= htmlspecialchars($o['table_number'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($o['order_type']) ?></td>
                    <td><?= htmlspecialchars($o['order_status']) ?></td>
                    <td><?= htmlspecialchars($o['created_at']) ?></td>
                    <td class="icons-cell" >
                      <button class="icon-btn edit" title="Edit" onclick="openEditModal(<?= (int)$o['id'] ?>)">
                        <i class="fas fa-pen"></i>
                      </button>
                      <button class="icon-btn danger" title="Delete" onclick="openDeleteConfirm(<?= (int)$o['id'] ?>)">
                        <i class="fas fa-trash"></i>
                      </button>
                    </td>
                  </tr>
              <?php endforeach;
              endif; ?>
            </tbody>
          </table>
        </div>

        <!-- PAGINATION UI -->
        <div class="table-footer" id="tableFooter">
          <div class="rows-per-page">
            <label for="rowsPerPage">Rows per page:</label>
            <select id="rowsPerPage">
              <option value="10">10</option>
              <option value="25" selected>25</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </select>
          </div>
          <div class="pagination-controls">
            <button class="btn-page" id="firstPageBtn" title="First">&laquo;</button>
            <button class="btn-page" id="prevPageBtn" title="Previous">&lsaquo;</button>
            <span class="page-info" id="pageInfo">1 / 1</span>
            <button class="btn-page" id="nextPageBtn" title="Next">&rsaquo;</button>
            <button class="btn-page" id="lastPageBtn" title="Last">&raquo;</button>
            <span class="range-info" id="rangeInfo">0–0 of 0</span>
          </div>
        </div>
        <!-- /PAGINATION UI -->

      </div>
    </main>
  </div>

  <!-- Notification toast -->
  <div id="notification" class="notification"></div>

  <!-- EDIT MODAL -->
  <div id="editModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Edit Order</h3>
        <button class="close-btn" onclick="closeEditModal()">&times;</button>
      </div>
      <form onsubmit="return submitUpdateOrder(event)">
        <input type="hidden" id="edit_id" />

        <div class="form-row">
          <label>Name</label>
          <input id="edit_name" class="form-control" required />
        </div>

        <div class="form-row">
          <label>Phone</label>
          <input id="edit_phone" class="form-control" required />
        </div>

        <div class="form-row">
          <label>Address</label>
          <textarea id="edit_address" class="form-control" rows="2"></textarea>
        </div>

        <div class="form-row">
          <label>Table #</label>
          <input id="edit_table_number" class="form-control" />
        </div>

        <div class="form-row two">
          <div>
            <label>Order Type</label>
            <select id="edit_order_type" class="form-control">
              <option>Delivery</option>
              <option>Dine-in</option>
              <option>Takeaway</option>
            </select>
          </div>
          <div>
            <label>Status</label>
            <select id="edit_order_status" class="form-control">
              <option>Pending</option>
              <option>Preparing</option>
              <option>Ready</option>
              <option>Served</option>
              <option>Delivered</option>
              <option>Handover</option>
              <option>Cancelled</option>
            </select>
          </div>
        </div>

        <div class="form-row">
          <label>Order details</label>
          <textarea id="edit_order_details" class="form-control" rows="2"></textarea>
        </div>

        <div class="form-row">
          <label>Note</label>
          <textarea id="edit_note" class="form-control" rows="2"></textarea>
        </div>

        <div class="form-row">
          <label>Payment status</label>
          <select id="edit_payment_status" class="form-control">
            <option>Unpaid</option>
            <option>Paid</option>
            <option>Refunded</option>
          </select>
        </div>

        <div class="modal-actions">
          <button type="button" class="btn" onclick="closeEditModal()">Cancel</button>
          <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
      </form>
    </div>
  </div>

  <!-- DELETE CONFIRM -->
  <div id="confirmDialog" class="modal">
    <div class="modal-content small">
      <div class="modal-header">
        <h3>Delete order?</h3>
        <button class="close-btn" onclick="closeDeleteConfirm()">&times;</button>
      </div>
      <div class="modal-body">
        This action cannot be undone. Are you sure you want to delete this order?
      </div>
      <div class="modal-actions">
        <button class="btn" onclick="closeDeleteConfirm()">Cancel</button>
        <button class="btn btn-danger" onclick="confirmDelete()"><i class="fas fa-trash"></i> Delete</button>
      </div>
    </div>
  </div>

  <script src="assets/js/dashboard.js?v=5"></script>

  <script>
    function contactSupport() {
      const subject = encodeURIComponent('Support Request from ' + window.location.hostname);
      const body = encodeURIComponent('Hello,\\n\\nI need help with...\\n\\nBest regards');
      window.location.href = `mailto:support@zicbot.com?subject=${subject}&body=${body}`;
    }

    // ---------------- PAGINATION JS (client-side) ----------------
    (function () {
      const tbody = document.getElementById('ordersTableBody');
      const noDataSelector = 'td[colspan]'; // "No orders yet." row

      const rowsPerPageSelect = document.getElementById('rowsPerPage');
      const firstBtn = document.getElementById('firstPageBtn');
      const prevBtn  = document.getElementById('prevPageBtn');
      const nextBtn  = document.getElementById('nextPageBtn');
      const lastBtn  = document.getElementById('lastPageBtn');
      const pageInfo = document.getElementById('pageInfo');
      const rangeInfo = document.getElementById('rangeInfo');

      const dateFilter   = document.getElementById('dateFilter');
      const statusFilter = document.getElementById('statusFilter');
      const typeFilter   = document.getElementById('typeFilter');

      let currentPage = 1;
      let rowsPerPage = parseInt(rowsPerPageSelect.value, 10);

      function getDataRows() {
        const rows = Array.from(tbody.querySelectorAll('tr'));
        // Exclude placeholder "No orders yet." row from pagination if we have real rows
        const data = rows.filter(r => !r.querySelector(noDataSelector));
        return { rows, data };
      }

      function updateControls(totalRows, totalPages, startIdx, endIdx) {
        pageInfo.textContent = `${totalPages === 0 ? 0 : currentPage} / ${Math.max(totalPages, 1)}`;

        if (totalRows === 0) {
          rangeInfo.textContent = `0–0 of 0`;
        } else {
          rangeInfo.textContent = `${startIdx + 1}–${endIdx} of ${totalRows}`;
        }

        firstBtn.disabled = currentPage <= 1;
        prevBtn.disabled  = currentPage <= 1;
        nextBtn.disabled  = currentPage >= totalPages || totalPages === 0;
        lastBtn.disabled  = currentPage >= totalPages || totalPages === 0;
      }

      function renderPage() {
        const { rows, data } = getDataRows();
        const totalRows = data.length;
        const totalPages = Math.ceil(totalRows / rowsPerPage) || 0;

        // Clamp current page
        if (currentPage < 1) currentPage = 1;
        if (totalPages > 0 && currentPage > totalPages) currentPage = totalPages;

        // Hide all rows first
        rows.forEach(r => r.style.display = 'none');

        if (totalRows === 0) {
          // Show "No orders yet." row if present
          const empty = rows.find(r => r.querySelector(noDataSelector));
          if (empty) empty.style.display = '';
          updateControls(0, 0, 0, 0);
          return;
        }

        const start = (currentPage - 1) * rowsPerPage;
        const end   = Math.min(start + rowsPerPage, totalRows);

        data.slice(start, end).forEach(r => r.style.display = '');

        updateControls(totalRows, totalPages, start, end);
      }

      function goFirst(){ currentPage = 1; renderPage(); }
      function goPrev(){ if (currentPage > 1) { currentPage--; renderPage(); } }
      function goNext(){
        const totalPages = Math.ceil(getDataRows().data.length / rowsPerPage) || 0;
        if (currentPage < totalPages) { currentPage++; renderPage(); }
      }
      function goLast(){
        const totalPages = Math.ceil(getDataRows().data.length / rowsPerPage) || 0;
        currentPage = Math.max(totalPages, 1);
        renderPage();
      }

      // Events
      rowsPerPageSelect.addEventListener('change', () => {
        rowsPerPage = parseInt(rowsPerPageSelect.value, 10) || 25;
        currentPage = 1;
        renderPage();
      });
      firstBtn.addEventListener('click', goFirst);
      prevBtn.addEventListener('click', goPrev);
      nextBtn.addEventListener('click', goNext);
      lastBtn.addEventListener('click', goLast);

      // Re-run pagination when filters change (your filterOrders runs on change too)
      [dateFilter, statusFilter, typeFilter].forEach(el => {
        if (el) el.addEventListener('change', () => {
          // small delay in case external JS re-renders tbody
          setTimeout(() => { currentPage = 1; renderPage(); }, 0);
        });
      });

      // Re-run when external JS (refreshOrders, etc.) replaces tbody rows
      const mo = new MutationObserver(() => {
        currentPage = 1;
        renderPage();
      });
      mo.observe(tbody, { childList: true });

      // Init
      document.addEventListener('DOMContentLoaded', renderPage);
      // If DOMContentLoaded already fired (because script is at bottom), render now:
      renderPage();
    })();
    // ---------------- /PAGINATION JS ----------------
  </script>
</body>
</html>
