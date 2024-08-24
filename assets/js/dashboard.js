// Dashboard JavaScript functionality (shared orders table + polling + edit/delete)
let ordersData = [];
let isLoading = false;
let lastSeenId = 0;

// Unlock audio on first user interaction (for notification sound)
let _audioUnlocked = false;
document.addEventListener('DOMContentLoaded', () => {
  unlockAudioOnFirstInteraction();
  loadOrders();
  setInterval(checkForNewOrders, 10000); // poll every 10s
});

function unlockAudioOnFirstInteraction() {
  if (_audioUnlocked) return;
  const tryUnlock = () => {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      ctx.resume().catch(() => {});
    } catch (_) {}
    _audioUnlocked = true;
    document.removeEventListener('click', tryUnlock);
    document.removeEventListener('touchstart', tryUnlock);
    document.removeEventListener('keydown', tryUnlock);
  };
  document.addEventListener('click', tryUnlock, { once: true });
  document.addEventListener('touchstart', tryUnlock, { once: true });
  document.addEventListener('keydown', tryUnlock, { once: true });
}

// ========== Load initial ==========
function loadOrders() {
  if (isLoading) return;
  isLoading = true;

  const refreshBtn = document.querySelector('button[onclick="refreshOrders()"]');
  if (refreshBtn) { refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...'; refreshBtn.disabled = true; }

  const tbody = document.getElementById('ordersTableBody');
  if (tbody) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--text-secondary);padding:40px;"><i class="fas fa-spinner fa-spin"></i> Loading orders...</td></tr>';
  }

  fetch('api/get_orders.php', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        ordersData = (data.orders || []).slice().sort((a, b) => b.id - a.id);
        displayOrders(ordersData);
        lastSeenId = ordersData.length ? Number(ordersData[0].id) : 0;
        refreshStats();
      } else {
        showErrorInTable('Error loading orders: ' + (data.message || 'Unknown error'));
        showNotification('Failed to load orders: ' + (data.message || 'Unknown error'), 'error');
      }
    })
    .catch(err => {
      console.error('Error loading orders:', err);
      showErrorInTable('Network error loading orders');
      showNotification('Network error loading orders', 'error');
    })
    .finally(() => {
      isLoading = false;
      if (refreshBtn) { refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh'; refreshBtn.disabled = false; }
    });
}

// ========== Poll new ==========
function checkForNewOrders() {
  if (isLoading) return;
  const since = Number(lastSeenId || 0);

  fetch(`api/orders_since.php?since=${since}`, { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
      if (!data.success) return;
      const newOrders = data.orders || [];
      if (!newOrders.length) return;

      if (typeof data.max_id === 'number' && data.max_id > lastSeenId) lastSeenId = data.max_id;
      else {
        const localMax = Math.max(...newOrders.map(o => Number(o.id)));
        if (localMax > lastSeenId) lastSeenId = localMax;
      }

      newOrders.forEach(o => ordersData.unshift(o));
      displayOrders(ordersData);
      refreshStats();

      showNotification(`${newOrders.length} new order${newOrders.length > 1 ? 's' : ''} received!`);
      playNotificationSound();
      maybeNotifyBrowser(`${newOrders.length} new order${newOrders.length > 1 ? 's' : ''}`);
    })
    .catch(() => {});
}

// ========== Stats ==========
function refreshStats() {
  fetch('api/order_stats.php', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
      if (!data.success) return;
      const s = data.stats || {};
      setText('#stat-total', parseInt(s.total_orders || 0, 10));
      setText('#stat-pending', parseInt(s.pending_orders || 0, 10));
      setText('#stat-completed', parseInt(s.completed_orders || 0, 10));
      setText('#stat-today', parseInt(s.today_orders || 0, 10));
    })
    .catch(() => {});
}

// ========== Render ==========
function displayOrders(orders) {
  const tbody = document.getElementById('ordersTableBody');
  if (!tbody) return;

  if (!orders || !orders.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--text-secondary);padding:40px;">No orders found</td></tr>';
    return;
  }

  const rows = [];
  for (const order of orders) {
    rows.push(`
      <tr data-id="${Number(order.id)}">
        <td>${escapeHtml(order.name || '')}</td>
        <td>${escapeHtml(order.order_details || '')}</td>
        <td>${escapeHtml(order.phone || '')}</td>
        <td>${escapeHtml(order.table_number || 'N/A')}</td>
        <td>${escapeHtml(order.order_type || '')}</td>
        <td><span class="status-badge status-${(order.order_status || '').toLowerCase()}">${escapeHtml(order.order_status || '')}</span></td>
        <td>${formatDateTime(order.created_at)}</td>
        <td>
          <button class="icon-btn edit" title="Edit" onclick="openEditModal(${Number(order.id)})"><i class="fas fa-pen"></i></button>
          <button class="icon-btn danger" title="Delete" onclick="openDeleteConfirm(${Number(order.id)})"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
    `);
  }
  tbody.innerHTML = rows.join('');
}

// ========== Update status ==========
function updateOrderStatus(orderId, newStatus) {
  fetch('api/update_order_status.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ order_id: orderId, status: newStatus }),
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showNotification('Order status updated successfully!');
        const target = ordersData.find(o => Number(o.id) === Number(orderId));
        if (target) target.order_status = newStatus;
        displayOrders(ordersData);
        refreshStats();
      } else {
        showNotification(data.message || 'Failed to update order status', 'error');
      }
    })
    .catch(() => showNotification('Error updating order status', 'error'));
}

// ========== Filters / Export ==========
function filterOrders() {
  const dateFilter = document.getElementById('dateFilter')?.value || '';
  const statusFilter = document.getElementById('statusFilter')?.value || '';
  const typeFilter = document.getElementById('typeFilter')?.value || '';

  let filtered = ordersData.slice();
  if (dateFilter) filtered = filtered.filter(o => (o.created_at || '').startsWith(dateFilter));
  if (statusFilter) filtered = filtered.filter(o => (o.order_status || '') === statusFilter);
  if (typeFilter) filtered = filtered.filter(o => (o.order_type || '') === typeFilter);
  displayOrders(filtered);
}

function exportToCSV() {
  if (!ordersData.length) return showNotification('No orders to export', 'error');

  // Updated headers and mapping to use order_details instead of address
  const headers = ['Name','Order Details','Phone','Table Number','Order Type','Status','Date & Time'];
  const rows = ordersData.map(o => [
    o.name || '',
    o.order_details || '',
    o.phone || '',
    o.table_number || 'N/A',
    o.order_type || '',
    o.order_status || '',
    formatDateTime(o.created_at)
  ]);
  rows.unshift(headers);

  const csv = rows.map(r => r.map(f => `"${String(f).replaceAll('"','""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = `orders_${new Date().toISOString().split('T')[0]}.csv`; a.click();
  URL.revokeObjectURL(url);
}

// ========== Edit Modal (ALWAYS fetch fresh) ==========
let _editingOrderId = null;

async function openEditModal(id) {
  _editingOrderId = Number(id);

  try {
    const r = await fetch(`api/get_order.php?id=${_editingOrderId}`, { credentials: 'same-origin' });
    const data = await r.json();

    if (!data.success || !data.order) {
      showNotification('Order not found', 'error');
      return;
    }

    const order = data.order;

    // Update/insert into local cache so the table stays in sync
    const idx = ordersData.findIndex(o => Number(o.id) === _editingOrderId);
    if (idx >= 0) ordersData[idx] = order;
    else ordersData.unshift(order);

    // Fill the form
    setValue('edit_id', order.id);
    setValue('edit_name', order.name || '');
    setValue('edit_phone', order.phone || '');
    setValue('edit_address', order.address || '');
    setValue('edit_table_number', order.table_number || '');
    setValue('edit_order_type', order.order_type || 'Delivery');
    setValue('edit_order_status', order.order_status || 'Pending');
    setValue('edit_order_details', order.order_details || '');
    setValue('edit_note', order.note || '');
    setValue('edit_payment_status', order.payment_status || 'Unpaid');

    showModal('editModal');
  } catch (e) {
    console.error(e);
    showNotification('Error fetching order', 'error');
  }
}
function closeEditModal(){ hideModal('editModal'); _editingOrderId = null; }

function submitUpdateOrder(e) {
  e.preventDefault();
  const payload = {
    id: Number(getValue('edit_id')),
    name: getValue('edit_name'),
    phone: getValue('edit_phone'),
    address: getValue('edit_address'),
    table_number: getValue('edit_table_number') || null,
    order_type: getValue('edit_order_type'),
    order_status: getValue('edit_order_status'),
    order_details: getValue('edit_order_details') || null,
    note: getValue('edit_note') || null,
    payment_status: getValue('edit_payment_status')
  };

  fetch('api/update_order.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(payload)
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showNotification('Order updated successfully!');
        const idx = ordersData.findIndex(o => Number(o.id) === payload.id);
        if (idx >= 0) ordersData[idx] = { ...ordersData[idx], ...payload };
        displayOrders(ordersData);
        refreshStats();
        closeEditModal();
      } else {
        showNotification(data.message || 'Failed to update order', 'error');
      }
    })
    .catch(() => showNotification('Network error', 'error'));

  return false;
}

// ========== Delete Confirm ==========
let _deletingOrderId = null;
function openDeleteConfirm(id){ _deletingOrderId = Number(id); showModal('confirmDialog'); }
function closeDeleteConfirm(){ _deletingOrderId = null; hideModal('confirmDialog'); }
function confirmDelete() {
  if (!_deletingOrderId) return;
  fetch('api/delete_order.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ id: _deletingOrderId })
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showNotification('Order deleted');
        ordersData = ordersData.filter(o => Number(o.id) !== _deletingOrderId);
        displayOrders(ordersData);
        refreshStats();
        closeDeleteConfirm();
      } else {
        showNotification(data.message || 'Failed to delete order', 'error');
      }
    })
    .catch(() => showNotification('Network error', 'error'));
}

// ========== Notifications / helpers ==========
function showNotification(message, type = 'success') {
  const el = document.getElementById('notification');
  if (!el) return;
  el.textContent = message;
  el.className = `notification ${type} show`;
  setTimeout(() => el.classList.remove('show'), 4000);
}
function showErrorInTable(msg) {
  const tbody = document.getElementById('ordersTableBody');
  if (tbody) tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--danger-red);padding:40px;">${escapeHtml(msg)}</td></tr>`;
}

function playNotificationSound() {
  const el = document.getElementById('newOrderAudio');
  if (el) { try { el.currentTime = 0; el.play().catch(()=>playNewOrderTune()); return; } catch(_){} }
  playNewOrderTune();
}
function playNewOrderTune() {
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    const ctx = new AudioCtx();
    const notes = [
      { f: 523.25, d: 0.18 }, { f: 659.25, d: 0.18 }, { f: 783.99, d: 0.22 }, { f: 987.77, d: 0.20 }, { f: 783.99, d: 0.24 },
    ];
    let t = ctx.currentTime;
    const master = ctx.createGain(); master.gain.value = 0.0001; master.connect(ctx.destination);
    master.gain.exponentialRampToValueAtTime(0.15, t + 0.02);
    notes.forEach(n => {
      const osc = ctx.createOscillator(), g = ctx.createGain();
      osc.type = 'sine'; osc.frequency.value = n.f;
      g.gain.setValueAtTime(0.0001, t);
      g.gain.exponentialRampToValueAtTime(0.3, t + 0.01);
      g.gain.exponentialRampToValueAtTime(0.0001, t + n.d - 0.06);
      osc.connect(g); g.connect(master);
      osc.start(t); osc.stop(t + n.d);
      t += n.d + 0.02;
    });
    master.gain.exponentialRampToValueAtTime(0.0001, t + 0.05);
    setTimeout(() => ctx.close().catch(()=>{}), Math.ceil((t - ctx.currentTime + 0.2) * 1000));
  } catch (_) {}
}
function maybeNotifyBrowser(msg) {
  if (!('Notification' in window)) return;
  if (Notification.permission === 'granted') new Notification('New order', { body: msg });
  else if (Notification.permission !== 'denied') {
    Notification.requestPermission().then(perm => { if (perm === 'granted') new Notification('New order', { body: msg }); });
  }
}

function refreshOrders(){ if (!isLoading) loadOrders(); }
function setText(sel, v){ const el = document.querySelector(sel); if (el) el.textContent = v; }
function setValue(id, v){ const el = document.getElementById(id); if (el) el.value = v ?? ''; }
function getValue(id){ const el = document.getElementById(id); return el ? el.value : ''; }
function escapeHtml(s){ return (s==null?'':String(s)).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
function formatDateTime(d){
  if (!d) return '';
  const x = new Date(d);
  return Number.isNaN(x.getTime()) ? d : (x.toLocaleDateString() + ' ' + x.toLocaleTimeString());
}

/* ===== Modal helpers ===== */
function showModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.add('show');
  document.documentElement.style.overflow = 'hidden';
  document.body.style.overflow = 'hidden';
}
function hideModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove('show');
  document.documentElement.style.overflow = '';
  document.body.style.overflow = '';
}

function toggleSidebar() {
  const sb = document.getElementById('sidebar');
  const ov = document.getElementById('mobileOverlay');
  const willOpen = !sb.classList.contains('open');
  sb.classList.toggle('open', willOpen);
  ov.classList.toggle('show', willOpen);
  document.body.style.overflow = willOpen ? 'hidden' : '';
}

// Close on overlay click
document.getElementById('mobileOverlay')?.addEventListener('click', () => {
  const sb = document.getElementById('sidebar');
  const ov = document.getElementById('mobileOverlay');
  sb.classList.remove('open');
  ov.classList.remove('show');
  document.body.style.overflow = '';
});

// Close when a nav link is tapped on mobile
document.querySelectorAll('.nav-link').forEach(a => {
  a.addEventListener('click', () => {
    if (window.innerWidth <= 900) {
      const sb = document.getElementById('sidebar');
      const ov = document.getElementById('mobileOverlay');
      sb.classList.remove('open');
      ov.classList.remove('show');
      document.body.style.overflow = '';
    }
  });
});

// Optional: close on ESC
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    const sb = document.getElementById('sidebar');
    const ov = document.getElementById('mobileOverlay');
    if (sb.classList.contains('open')) {
      sb.classList.remove('open');
      ov.classList.remove('show');
      document.body.style.overflow = '';
    }
  }
});

/* ===== Export to global for inline onclick="" ===== */
window.openEditModal = openEditModal;
window.closeEditModal = closeEditModal;
window.submitUpdateOrder = submitUpdateOrder;
window.openDeleteConfirm = openDeleteConfirm;
window.closeDeleteConfirm = closeDeleteConfirm;
window.confirmDelete = confirmDelete;
window.refreshOrders = refreshOrders;
window.filterOrders = filterOrders;
window.exportToCSV = exportToCSV;
window.updateOrderStatus = updateOrderStatus;
window.showModal = showModal;
window.hideModal = hideModal;
window.toggleSidebar = toggleSidebar;
