<?php
session_start();

// Check admin authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

/* ----------------------- Filters & Options ----------------------- */
$orderTypes      = ['Delivery','Dine-in','Takeaway'];
$orderStatuses   = ['Pending','Preparing','Ready','Served','Delivered','Handover','Cancelled'];
$paymentStatuses = ['Unpaid','Paid','Refunded'];

$q        = trim($_GET['q'] ?? '');
$type     = $_GET['type'] ?? '';
$status   = $_GET['status'] ?? '';
$payment  = $_GET['payment'] ?? '';
$dateFrom = $_GET['from'] ?? '';
$dateTo   = $_GET['to'] ?? '';
$perPage  = (int)($_GET['limit'] ?? 20);
$perPage  = $perPage > 0 && $perPage <= 200 ? $perPage : 20;
$page     = (int)($_GET['p'] ?? 1);
$page     = $page >= 1 ? $page : 1;

/* ----------------------- Build WHERE safely ---------------------- */
$where  = ["u.is_admin = 0"];
$params = [];

if ($q !== '') {
    // IMPORTANT: use unique placeholders; PDO MySQL can't reuse the same :q multiple times
    $where[] = "(u.restaurant_name LIKE :q1 OR u.name LIKE :q2 OR o.name LIKE :q3 OR o.phone LIKE :q4 OR o.address LIKE :q5)";
    $like = "%{$q}%";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
    $params[':q5'] = $like;
}
if ($type && in_array($type, $orderTypes, true)) {
    $where[] = "o.order_type = :otype";
    $params[':otype'] = $type;
}
if ($status && in_array($status, $orderStatuses, true)) {
    $where[] = "o.order_status = :ostatus";
    $params[':ostatus'] = $status;
}
if ($payment && in_array($payment, $paymentStatuses, true)) {
    $where[] = "o.payment_status = :pstatus";
    $params[':pstatus'] = $payment;
}
if ($dateFrom !== '') {
    $from = date('Y-m-d 00:00:00', strtotime($dateFrom));
    $where[] = "o.created_at >= :from";
    $params[':from'] = $from;
}
if ($dateTo !== '') {
    $to = date('Y-m-d 23:59:59', strtotime($dateTo));
    $where[] = "o.created_at <= :to";
    $params[':to'] = $to;
}

$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* --------------------- Count + Pagination math ------------------- */
$countSql = "
    SELECT COUNT(*) AS cnt
    FROM orders o
    INNER JOIN users u ON u.id = o.user_id
    $whereSql
";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalOrders = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalOrders / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

/* --------------------- Fetch paginated orders -------------------- */
$listSql = "
    SELECT 
      o.*,
      u.name AS restaurant_owner,
      u.restaurant_name
    FROM orders o
    INNER JOIN users u ON u.id = o.user_id
    $whereSql
    ORDER BY o.created_at DESC
    LIMIT :lim OFFSET :off
";
$listStmt = $db->prepare($listSql);
foreach ($params as $k => $v) $listStmt->bindValue($k, $v);
$listStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$orders = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------- Helpers ------------------------------ */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function status_class($status){ return 'status-'.preg_replace('/[^a-z0-9]+/','-', strtolower($status ?? '')); }
function pretty_json_or_text(?string $raw): string {
    if ($raw === null || $raw === '') return '';
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $raw;
}
function b64($s){ return base64_encode((string)$s); }
function query_keep(array $overrides = []): string {
    $keep = [
        'q' => $_GET['q'] ?? '',
        'type' => $_GET['type'] ?? '',
        'status' => $_GET['status'] ?? '',
        'payment' => $_GET['payment'] ?? '',
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
        'limit' => $_GET['limit'] ?? '',
    ];
    $q = array_merge($keep, $overrides);
    return http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>All Orders - Zicbot Admin</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

  <style>
    :root{
      /* neutral/dark theme — no purple */
      --accent:#2d2d2d;
      --accent-2:#1f1f1f;
      --surface:#1f1f23;
      --surface-2:#15151a;
      --muted:#9ca3af;
      --text:#e5e7eb;
      --text-strong:#f9fafb;
      --border:#2b2b31;
      --chip-bg:#26262c;
    }
    /* Card */
    .card{
      background: var(--surface);
      border:1px solid var(--border);
      border-radius:16px;
      box-shadow: 0 10px 24px rgba(0,0,0,.25);
      overflow: hidden;
    }
    .card-header{
      padding:18px 20px;
      border-bottom:1px solid var(--border);
      background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(0,0,0,0));
    }
    .card-title{ color:var(--text-strong); letter-spacing:.3px; }

    /* Filters */
    .filters{ display:flex; flex-wrap:wrap; gap:12px; align-items:end; padding: 16px 20px; }
    .filters .field{ display:flex; flex-direction:column; gap:6px; }
    .filters input,.filters select{
      padding:10px 12px; border-radius:10px; border:1px solid var(--border);
      background:var(--surface-2); color:var(--text); outline:none;
    }
    .filters input:focus,.filters select:focus{ border-color:#3f3f3f; box-shadow:0 0 0 2px rgba(61,61,61,.25); }
    .filters .actions{ margin-left:auto; display:flex; gap:8px; }
    .btn{
      background:linear-gradient(180deg,var(--accent),var(--accent-2));
      color:white; border:1px solid #3b3b3b; padding:10px 14px; border-radius:10px; cursor:pointer;
    }
    .btn.secondary{
      background:transparent; color:var(--text); border:1px solid var(--border);
    }

    /* Spacing between banner/filters and the table */
    .section-gap-top { margin-top: 14px; }

    /* Pagination */
    .pagination{ display:flex; gap:6px; align-items:center; justify-content:flex-end; padding: 12px 16px; }
    .pagination a,.pagination span{
      padding:7px 12px; border-radius:10px; border:1px solid var(--border);
      background:var(--surface-2); color:var(--text); text-decoration:none;
    }
    .pagination .active{ background:#3f3f3f; border-color:#3f3f3f; color:#fff; }
    .pagination .disabled{ opacity:.45; pointer-events:none; }

    /* Table */
    .table-wrap{ overflow:auto; }
    table{ width:100%; border-collapse:separate; border-spacing:0; }
    thead th{
      position:sticky; top:0; z-index:2;
      background: var(--surface-2);
      color:#eaeaf0; text-align:left; padding:14px 16px; font-weight:600; border-bottom:1px solid var(--border);
    }
    tbody td{
      padding:14px 16px; border-bottom:1px solid var(--border); color:var(--text);
    }
    tbody tr:hover{ background:rgba(255,255,255,0.04); }
    tbody tr:nth-child(2n){ background: rgba(255,255,255,0.01); }

    /* Chips / Pills */
    .chip{ display:inline-flex; align-items:center; gap:8px; border-radius:999px; padding:6px 10px; background:var(--chip-bg); color:var(--text); font-size:12px; }
    .chip .dot{ width:8px; height:8px; border-radius:50%; background:#9ca3af; }
    .chip.type{ background: rgba(255,255,255,.06); color:#e5e7eb; border:1px solid rgba(255,255,255,.1); }
    .chip.payment-unpaid{ background:#3b1f20; color:#fca5a5; border:1px solid #7f1d1d; }
    .chip.payment-paid{ background:#1d3727; color:#86efac; border:1px solid #14532d; }
    .chip.payment-refunded{ background:#2a1f35; color:#c4b5fd; border:1px solid #5b21b6; }

    .status-badge{ padding:6px 10px; border-radius:999px; font-size:12px; border:1px solid transparent; }
    .status-pending{ background:#2b2118; color:#f59e0b; border-color:#7c2d12; }
    .status-preparing{ background:#152238; color:#60a5fa; border-color:#1e40af; }
    .status-ready{ background:#0f2022; color:#67e8f9; border-color:#0e7490; }
    .status-served, .status-delivered{ background:#0f1f15; color:#86efac; border-color:#14532d; }
    .status-handover{ background:#221534; color:#c084fc; border-color:#6b21a8; }
    .status-cancelled{ background:#2a1212; color:#fca5a5; border-color:#7f1d1d; }

    /* Modal */
    .modal{ position:fixed; inset:0; display:none; align-items:center; justify-content:center; background: rgba(0,0,0,.65); backdrop-filter: blur(3px); z-index: 50; }
    .modal.open{ display:flex; }
    .modal-card{
      width:min(920px, 92vw); max-height: 88vh; overflow:auto;
      background: var(--surface); border:1px solid var(--border); border-radius:16px;
      box-shadow: 0 24px 60px rgba(0,0,0,.5); animation: pop .14s ease-out;
    }
    @keyframes pop{ from{ transform:scale(.98); opacity:0 } to{ transform:scale(1); opacity:1 } }
    .modal-head{ display:flex; justify-content:space-between; align-items:center; padding:16px 18px; border-bottom:1px solid var(--border); background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(0,0,0,0)); }
    .modal-title{ color:var(--text-strong); font-weight:700; letter-spacing:.3px; }
    .modal-body{ padding:16px 18px; display:grid; gap:14px; }
    .grid{ display:grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap:12px; }
    .kv{ background:var(--surface-2); border:1px solid var(--border); border-radius:12px; padding:12px; }
    .kv .k{ color:var(--muted); font-size:12px; margin-bottom:6px; }
    .kv .v{ color:var(--text); }
    pre.json{ margin:0; white-space:pre-wrap; word-break:break-word; background:var(--surface-2); border:1px solid var(--border); border-radius:12px; padding:12px; font-size:12px; color:#d1d5db; }
    .modal-foot{ display:flex; justify-content:flex-end; gap:10px; padding:14px 18px; border-top:1px solid var(--border); }
    .icon-btn{ background:transparent; border:1px solid var(--border); color:var(--text); padding:8px 10px; border-radius:10px; cursor:pointer; }
    .icon-btn:hover{ border-color:#3f3f3f; color:#fff; }
  </style>
</head>
<body>
  <div class="dashboard">
    <aside class="sidebar">
      <div class="sidebar-header">
        <div class="sidebar-logo">Zicbot Admin</div>
        <div class="sidebar-subtitle">Administration Panel</div>
      </div>
      <nav>
        <ul class="nav-menu">
          <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
          <li class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i> Manage Users</a></li>
          <li class="nav-item"><a href="plans.php" class="nav-link"><i class="fas fa-crown"></i> Membership Plans</a></li>
          <li class="nav-item"><a href="orders.php" class="nav-link active"><i class="fas fa-list"></i> All Orders</a></li>
          <li class="nav-item"><a href="credits.php" class="nav-link"><i class="fas fa-coins"></i> Credits Management</a></li>
          <li class="nav-item" style="margin-top: 20px;"><a href="logout.php" class="nav-link" style="color: #ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
      </nav>
    </aside>

    <main class="main-content">
      <div class="page-header">
        <h1 class="page-title">All Restaurant Orders</h1>
        <div class="header-actions" style="display:flex; align-items:center; gap:14px;">
          <span style="color:#9aa0a6;">Total Orders: <?php echo number_format($totalOrders); ?></span>
        </div>
      </div>

      <div class="card">
        <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
          <h2 class="card-title">Orders from All Restaurants</h2>
        </div>

        <!-- Filters -->
        <form method="get" class="filters">
          <div class="field">
            <label style="color:#bdbec3;">Search</label>
            <input type="text" name="q" placeholder="Restaurant / Owner / Customer / Phone / Address" value="<?php echo h($q); ?>">
          </div>
          <div class="field">
            <label style="color:#bdbec3;">Type</label>
            <select name="type">
              <option value="">All</option>
              <?php foreach ($orderTypes as $t): ?>
                <option value="<?php echo h($t); ?>" <?php echo $t===$type?'selected':''; ?>><?php echo h($t); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label style="color:#bdbec3;">Status</label>
            <select name="status">
              <option value="">All</option>
              <?php foreach ($orderStatuses as $s): ?>
                <option value="<?php echo h($s); ?>" <?php echo $s===$status?'selected':''; ?>><?php echo h($s); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label style="color:#bdbec3;">Payment</label>
            <select name="payment">
              <option value="">All</option>
              <?php foreach ($paymentStatuses as $p): ?>
                <option value="<?php echo h($p); ?>" <?php echo $p===$payment?'selected':''; ?>><?php echo h($p); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label style="color:#bdbec3;">From</label>
            <input type="date" name="from" value="<?php echo h($dateFrom); ?>">
          </div>
          <div class="field">
            <label style="color:#bdbec3;">To</label>
            <input type="date" name="to" value="<?php echo h($dateTo); ?>">
          </div>
          <div class="field">
            <label style="color:#bdbec3;">Per Page</label>
            <select name="limit">
              <?php foreach ([10,20,50,100,200] as $opt): ?>
                <option value="<?php echo $opt; ?>" <?php echo $opt===$perPage?'selected':''; ?>><?php echo $opt; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="actions">
            <button class="btn" title="Apply filters">Apply</button>
            <a class="btn secondary" href="orders.php" title="Reset filters">Reset</a>
          </div>
        </form>

        <?php if ($totalOrders === 0): ?>
          <p style="text-align: center; color: #9aa0a6; padding: 40px;">
            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 20px; display: block;"></i>
            No orders found with current filters.
          </p>
        <?php else: ?>
          <!-- Pagination (top) -->
          <div class="pagination section-gap-top">
            <?php
              $prev = max(1, $page - 1);
              $next = min($totalPages, $page + 1);
              $window = 2;
              $start = max(1, $page - $window);
              $end   = min($totalPages, $page + $window);
            ?>
            <a href="?<?php echo h(query_keep(['p'=>1])); ?>" class="<?php echo $page===1?'disabled':''; ?>">« First</a>
            <a href="?<?php echo h(query_keep(['p'=>$prev])); ?>" class="<?php echo $page===1?'disabled':''; ?>">‹ Prev</a>
            <?php if ($start > 1): ?>
              <a href="?<?php echo h(query_keep(['p'=>1])); ?>">1</a><span>…</span>
            <?php endif; ?>
            <?php for ($i=$start; $i<=$end; $i++): ?>
              <?php if ($i === $page): ?>
                <span class="active"><?php echo $i; ?></span>
              <?php else: ?>
                <a href="?<?php echo h(query_keep(['p'=>$i])); ?>"><?php echo $i; ?></a>
              <?php endif; ?>
            <?php endfor; ?>
            <?php if ($end < $totalPages): ?>
              <span>…</span><a href="?<?php echo h(query_keep(['p'=>$totalPages])); ?>"><?php echo $totalPages; ?></a>
            <?php endif; ?>
            <a href="?<?php echo h(query_keep(['p'=>$next])); ?>" class="<?php echo $page===$totalPages?'disabled':''; ?>">Next ›</a>
            <a href="?<?php echo h(query_keep(['p'=>$totalPages])); ?>" class="<?php echo $page===$totalPages?'disabled':''; ?>">Last »</a>
          </div>

          <div class="table-wrap section-gap-top">
            <table>
              <thead>
                <tr>
                  <th>Restaurant</th>
                  <th>Owner</th>
                  <th>Customer</th>
                  <th>Address</th>
                  <th>Phone</th>
                  <th>Table #</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Payment</th>
                  <th>Date & Time</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($orders as $o): 
                $detailsPretty = pretty_json_or_text($o['order_details'] ?? '');
                $paymentClass = 'payment-'.strtolower($o['payment_status']);
              ?>
                <tr>
                  <td><strong><?php echo h($o['restaurant_name']); ?></strong></td>
                  <td><?php echo h($o['restaurant_owner']); ?></td>
                  <td><?php echo h($o['name']); ?></td>
                  <td><?php echo h($o['address']); ?></td>
                  <td><?php echo h($o['phone']); ?></td>
                  <td><?php echo h($o['table_number'] ?: 'N/A'); ?></td>
                  <td>
                    <span class="chip type"><span class="dot"></span><?php echo h($o['order_type']); ?></span>
                  </td>
                  <td><span class="status-badge <?php echo status_class($o['order_status']); ?>"><?php echo h($o['order_status']); ?></span></td>
                  <td><span class="chip <?php echo h($paymentClass); ?>"><i class="fas fa-money-bill-wave"></i> <?php echo h($o['payment_status']); ?></span></td>
                  <td><?php echo date('M j, Y H:i', strtotime($o['created_at'])); ?></td>
                  <td>
                    <button 
                      class="icon-btn open-modal"
                      title="Show details"
                      data-id="<?php echo (int)$o['id']; ?>"
                      data-user="<?php echo (int)$o['user_id']; ?>"
                      data-restaurant="<?php echo h($o['restaurant_name']); ?>"
                      data-owner="<?php echo h($o['restaurant_owner']); ?>"
                      data-customer="<?php echo h($o['name']); ?>"
                      data-phone="<?php echo h($o['phone']); ?>"
                      data-address="<?php echo h($o['address']); ?>"
                      data-table="<?php echo h($o['table_number'] ?: 'N/A'); ?>"
                      data-type="<?php echo h($o['order_type']); ?>"
                      data-status="<?php echo h($o['order_status']); ?>"
                      data-payment="<?php echo h($o['payment_status']); ?>"
                      data-created="<?php echo h(date('M j, Y H:i', strtotime($o['created_at']))); ?>"
                      data-updated="<?php echo h(date('M j, Y H:i', strtotime($o['updated_at']))); ?>"
                      data-details="<?php echo h(b64($detailsPretty)); ?>"
                      data-note="<?php echo h($o['note'] ?? ''); ?>"
                    >
                      <i class="fas fa-eye"></i> Show
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Pagination (bottom) -->
          <div class="pagination section-gap-top">
            <?php
              $prev = max(1, $page - 1);
              $next = min($totalPages, $page + 1);
              $start = max(1, $page - 2);
              $end   = min($totalPages, $page + 2);
            ?>
            <a href="?<?php echo h(query_keep(['p'=>1])); ?>" class="<?php echo $page===1?'disabled':''; ?>">« First</a>
            <a href="?<?php echo h(query_keep(['p'=>$prev])); ?>" class="<?php echo $page===1?'disabled':''; ?>">‹ Prev</a>
            <?php if ($start > 1): ?>
              <a href="?<?php echo h(query_keep(['p'=>1])); ?>">1</a><span>…</span>
            <?php endif; ?>
            <?php for ($i=$start; $i<=$end; $i++): ?>
              <?php if ($i === $page): ?>
                <span class="active"><?php echo $i; ?></span>
              <?php else: ?>
                <a href="?<?php echo h(query_keep(['p'=>$i])); ?>"><?php echo $i; ?></a>
              <?php endif; ?>
            <?php endfor; ?>
            <?php if ($end < $totalPages): ?>
              <span>…</span><a href="?<?php echo h(query_keep(['p'=>$totalPages])); ?>"><?php echo $totalPages; ?></a>
            <?php endif; ?>
            <a href="?<?php echo h(query_keep(['p'=>$next])); ?>" class="<?php echo $page===$totalPages?'disabled':''; ?>">Next ›</a>
            <a href="?<?php echo h(query_keep(['p'=>$totalPages])); ?>" class="<?php echo $page===$totalPages?'disabled':''; ?>">Last »</a>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <!-- Modal -->
  <div id="orderModal" class="modal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
      <div class="modal-head">
        <div class="modal-title" id="modalTitle">Order</div>
        <button class="icon-btn" id="modalClose"><i class="fas fa-times"></i> Close</button>
      </div>
      <div class="modal-body">
        <div class="grid">
          <div class="kv"><div class="k">Order ID</div><div class="v" id="m_id">—</div></div>
          <div class="kv"><div class="k">User ID</div><div class="v" id="m_user">—</div></div>
          <div class="kv"><div class="k">Restaurant</div><div class="v" id="m_restaurant">—</div></div>
          <div class="kv"><div class="k">Owner</div><div class="v" id="m_owner">—</div></div>
          <div class="kv"><div class="k">Customer</div><div class="v" id="m_customer">—</div></div>
          <div class="kv"><div class="k">Phone</div><div class="v" id="m_phone">—</div></div>
          <div class="kv"><div class="k">Address</div><div class="v" id="m_address">—</div></div>
          <div class="kv"><div class="k">Table #</div><div class="v" id="m_table">—</div></div>
          <div class="kv"><div class="k">Type</div><div class="v" id="m_type">—</div></div>
          <div class="kv"><div class="k">Status</div><div class="v" id="m_status">—</div></div>
          <div class="kv"><div class="k">Payment</div><div class="v" id="m_payment">—</div></div>
          <div class="kv"><div class="k">Created</div><div class="v" id="m_created">—</div></div>
          <div class="kv"><div class="k">Updated</div><div class="v" id="m_updated">—</div></div>
        </div>

        <div id="m_details_wrap" style="display:none;">
          <div class="k" style="color:#9ca3af; margin-top:6px;">Order Details</div>
          <pre class="json" id="m_details"></pre>
        </div>

        <div id="m_note_wrap" style="display:none;">
          <div class="k" style="color:#9ca3af; margin-top:6px;">Note</div>
          <div class="kv"><div class="v" id="m_note" style="white-space:pre-wrap;"></div></div>
        </div>
      </div>
      <div class="modal-foot">
        <button class="icon-btn" id="copyAddress"><i class="fas fa-copy"></i> Copy Address</button>
        <button class="icon-btn" id="copyPhone"><i class="fas fa-copy"></i> Copy Phone</button>
        <button class="btn" id="modalDone">Done</button>
      </div>
    </div>
  </div>

  <script>
    // Modal logic
    const modal      = document.getElementById('orderModal');
    const modalClose = document.getElementById('modalClose');
    const modalDone  = document.getElementById('modalDone');

    const fields = {
      id:       document.getElementById('m_id'),
      user:     document.getElementById('m_user'),
      restaurant:document.getElementById('m_restaurant'),
      owner:    document.getElementById('m_owner'),
      customer: document.getElementById('m_customer'),
      phone:    document.getElementById('m_phone'),
      address:  document.getElementById('m_address'),
      table:    document.getElementById('m_table'),
      type:     document.getElementById('m_type'),
      status:   document.getElementById('m_status'),
      payment:  document.getElementById('m_payment'),
      created:  document.getElementById('m_created'),
      updated:  document.getElementById('m_updated'),
      detailsWrap: document.getElementById('m_details_wrap'),
      details:  document.getElementById('m_details'),
      noteWrap: document.getElementById('m_note_wrap'),
      note:     document.getElementById('m_note'),
    };

    function openModalFromBtn(btn){
      const d = (attr) => btn.getAttribute(attr) || '';

      fields.id.textContent         = d('data-id');
      fields.user.textContent       = d('data-user');
      fields.restaurant.textContent = d('data-restaurant');
      fields.owner.textContent      = d('data-owner');
      fields.customer.textContent   = d('data-customer');
      fields.phone.textContent      = d('data-phone');
      fields.address.textContent    = d('data-address');
      fields.table.textContent      = d('data-table');
      fields.type.textContent       = d('data-type');
      fields.status.textContent     = d('data-status');
      fields.payment.textContent    = d('data-payment');
      fields.created.textContent    = d('data-created');
      fields.updated.textContent    = d('data-updated');

      const detailsB64 = d('data-details');
      if (detailsB64) {
        try {
          const raw = atob(detailsB64);
          fields.details.textContent = raw || '';
          fields.detailsWrap.style.display = raw ? 'block' : 'none';
        } catch(e) {
          fields.details.textContent = '';
          fields.detailsWrap.style.display = 'none';
        }
      } else {
        fields.details.textContent = '';
        fields.detailsWrap.style.display = 'none';
      }

      const note = d('data-note');
      fields.note.textContent = note || '';
      fields.noteWrap.style.display = note ? 'block' : 'none';

      document.getElementById('modalTitle').textContent =
        `Order #${fields.id.textContent} — ${fields.restaurant.textContent}`;

      modal.classList.add('open');
      modal.setAttribute('aria-hidden', 'false');
    }

    document.addEventListener('click', (e)=>{
      const openBtn = e.target.closest('.open-modal');
      if (openBtn) { openModalFromBtn(openBtn); return; }

      const outside = e.target === modal;
      const closeBtn = e.target === modalClose || e.target === modalDone;
      if (outside || closeBtn) {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
      }
    });

    document.addEventListener('keydown', (e)=>{
      if (e.key === 'Escape' && modal.classList.contains('open')) {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
      }
    });

    // Copy helpers
    function copyText(text){
      if (!text) return;
      navigator.clipboard.writeText(text).catch(()=>{});
    }
    document.getElementById('copyAddress').addEventListener('click', ()=> copyText(fields.address.textContent));
    document.getElementById('copyPhone').addEventListener('click', ()=> copyText(fields.phone.textContent));
  </script>
</body>
</html>
