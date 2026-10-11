/**
 * Coopers Farm — Admin JS
 * Works with original sidebar.php (.sidebar, .nav-item)
 * and all rebuilt admin pages.
 */

/* ── Sidebar toggle (mobile) ─────────────────────────────────── */
(function () { try {
  const btn     = document.querySelector('.topbar-menu-btn');
  const sidebar = document.querySelector('.sidebar');
  if (!btn || !sidebar) return;

  let overlay = document.getElementById('sidebarOverlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'sidebarOverlay';
    overlay.style.cssText =
      'display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:299;';
    document.body.appendChild(overlay);
  }

  function openSidebar()  { sidebar.classList.add('open');    overlay.style.display = 'block'; }
  function closeSidebar() { sidebar.classList.remove('open'); overlay.style.display = 'none';  }

  btn.addEventListener('click', function () {
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
  });
  overlay.addEventListener('click', closeSidebar);
} catch (e) { console.error('Coopers admin:', e); } })();

/* ── Active nav link (matches original .nav-item class) ─────── */
document.addEventListener('DOMContentLoaded', function () {
  var path = window.location.pathname.split('/').pop().split('?')[0];
  document.querySelectorAll('.nav-item').forEach(function (a) {
    var href = (a.getAttribute('href') || '').split('?')[0];
    if (href && href === path) a.classList.add('active');
  });
});

/* ── Escape key: close modals and drawers ────────────────────── */
document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape') return;
  document.querySelectorAll('.modal-overlay.open').forEach(function (m) {
    m.classList.remove('open');
  });
  document.querySelectorAll('.drawer.open').forEach(function (d) {
    d.classList.remove('open');
  });
  document.querySelectorAll('.drawer-overlay.open').forEach(function (d) {
    d.classList.remove('open');
  });
});

/* ── Click outside modal to close ───────────────────────────── */
document.addEventListener('click', function (e) {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('open');
  }
});

/* ── Toast notification ──────────────────────────────────────── */
/**
 * showToast(message, type, duration)
 * type: 'success' | 'error' | 'warning' | 'info' | 'warn'
 * duration: ms (default 2600)
 */
function showToast(msg, type, duration) {
  type     = type     || 'success';
  duration = duration || 2600;

  var icons = {
    success: 'fa-check-circle',
    error:   'fa-times-circle',
    warning: 'fa-exclamation-triangle',
    warn:    'fa-exclamation-triangle',
    info:    'fa-info-circle',
  };

  var t = document.createElement('div');
  t.className = 'toast' + (type !== 'success' ? ' ' + type : '');
  t.innerHTML =
    '<i class="fas ' + (icons[type] || icons.success) + '"></i> ' + msg;
  document.body.appendChild(t);

  setTimeout(function () {
    t.style.transition = 'opacity .3s, transform .3s';
    t.style.opacity    = '0';
    t.style.transform  = 'translateY(8px)';
    setTimeout(function () { if (t.parentNode) t.remove(); }, 320);
  }, duration);
}

/* Alias — some pages use toast() instead of showToast() */
function toast(msg, type, duration) { showToast(msg, type, duration); }

/* ── Image upload preview ────────────────────────────────────── */
/**
 * previewImg(inputEl, previewId)
 * Renders a selected image file into <img id="previewId">
 */
function previewImg(input, previewId) {
  var preview = document.getElementById(previewId);
  if (!preview) return;
  var file = input.files && input.files[0];
  if (!file) { preview.style.display = 'none'; return; }
  if (file.size > 3 * 1024 * 1024) {
    showToast('Image too large — max 3 MB', 'warning');
    input.value = '';
    preview.style.display = 'none';
    return;
  }
  var reader = new FileReader();
  reader.onload = function (e) {
    preview.src = e.target.result;
    preview.style.display = 'block';
  };
  reader.readAsDataURL(file);
}

/* ── Image drop zone (auto-applied to all .img-drop elements) ── */
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.img-drop').forEach(function (drop) {
    drop.addEventListener('dragover', function (e) {
      e.preventDefault();
      drop.style.borderColor = 'var(--primary)';
      drop.style.background  = 'var(--primary-light)';
    });
    drop.addEventListener('dragleave', function () {
      drop.style.borderColor = '';
      drop.style.background  = '';
    });
    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      drop.style.borderColor = '';
      drop.style.background  = '';
      var inp = drop.querySelector('input[type=file]');
      if (!inp || !e.dataTransfer.files.length) return;
      try { inp.files = e.dataTransfer.files; } catch (err) {}
      var pid = inp.dataset.preview;
      if (pid) previewImg(inp, pid);
    });
  });
});

/* ── Confirm wrapper ─────────────────────────────────────────── */
function confirmAction(msg, callback) {
  if (window.confirm(msg)) callback();
}

/* ── Currency formatter (GHS) ────────────────────────────────── */
function formatGHS(amount) {
  return '₵' + parseFloat(amount || 0)
    .toFixed(2)
    .replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/* ── Page data reader (PHP passes data via #pageData data-page JSON) ── */
function pageData() {
  var el = document.getElementById('pageData');
  try { return el ? JSON.parse(el.dataset.page || '{}') : {}; } catch (e) { return {}; }
}


/* ════════════════════════════════════════════════════════════
   WALKIN_ORDER — point of sale: products, customer, pick-up or delivery, checkout, receipt
   ════════════════════════════════════════════════════════════ */
(function () { try {
  var PD = pageData();
  var products = PD.products || [], customers = PD.customers || [], recent = PD.recent || [];
  var DISC_MAX = PD.discountMax || 5, IS_MGR = !!PD.isManager, HELD_KEY = 'coopers_pos_held';
  var DEL_FEE = (PD.delivery && PD.delivery.fee) || 10, WAIVE = (PD.delivery && PD.delivery.waiveUnits) || 10;
  var PAY = { cash: 'Cash', momo_mtn: 'MTN MoMo', momo_vodafone: 'Telecel Cash', momo_airteltigo: 'AirtelTigo Money' };
  var CAT = { eggs: 'Eggs', broiler: 'Broilers', layer: 'Layers', byproduct: 'Byproducts', other: 'Other' };
  var ICON = { eggs: '🥚', broiler: '🍗', layer: '🐔', byproduct: '🌾', other: '📦' };
  var byId = {}; products.forEach(function (p) { byId[p.id] = p; });

  // cust: null = walk-in; {id, name, phone, email, location} = saved customer; id 0 = new customer from the form
  var state = { cart: {}, cust: null, cat: 'all', filter: 'all', q: '', pay: 'cash', dType: 'none', dVal: 0,
                ful: 'pickup', timing: 'now', address: '' };

  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); };
  var money = function (n) { return 'GH₵' + Number(n || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var stockState = function (s) { return s <= 0 ? 'out' : (s <= 3 ? 'critical' : (s <= 10 ? 'low' : 'ok')); };
  var inCart = function (id) { return state.cart[id] ? state.cart[id].qty : 0; };

  // ── Totals (display only; the server works out the real figures) ──
  function subtotal() { return Object.keys(state.cart).reduce(function (s, id) { return s + byId[id].price * state.cart[id].qty; }, 0); }
  function units() { return Object.keys(state.cart).reduce(function (n, id) { return n + state.cart[id].qty; }, 0); }
  function discountAmt() {
    var s = subtotal();
    if (state.dType === 'percent') return Math.min(s, s * state.dVal / 100);
    if (state.dType === 'fixed') return Math.min(s, state.dVal);
    return 0;
  }
  function deliveryFee() {
    if (state.ful !== 'delivery') return 0;
    return units() >= WAIVE ? 0 : DEL_FEE;
  }
  function total() { return Math.max(0, subtotal() - discountAmt() + deliveryFee()); }
  function cartCount() { return units(); }

  // ── Products grid ─────────────────────────────────────────────
  function visible() {
    return products.filter(function (p) {
      if (state.cat !== 'all') {
        var ok = state.cat === 'birds' ? (p.category === 'broiler' || p.category === 'layer') : p.category === state.cat;
        if (!ok) return false;
      }
      var st = stockState(p.stock);
      if (state.filter === 'low' && st !== 'low' && st !== 'critical') return false;
      if (state.filter === 'out' && st !== 'out') return false;
      if (state.q && (p.name + ' ' + p.unit + ' ' + (CAT[p.category] || '')).toLowerCase().indexOf(state.q) < 0) return false;
      return true;
    });
  }
  function card(p) {
    var st = stockState(p.stock), n = inCart(p.id);
    var img = p.image ? '<img src="../public/' + esc(p.image) + '" alt="">' : '<span class="sp-ico">' + (ICON[p.category] || '📦') + '</span>';
    var stockTxt = st === 'out' ? 'Out of stock' : (st === 'ok' ? p.stock + ' in stock' : p.stock + ' left');
    return '<button type="button" class="sp-card ' + st + (n ? ' in' : '') + '" data-add="' + p.id + '"' + (st === 'out' ? ' disabled' : '') + '>'
      + (n ? '<span class="sp-qbadge">' + n + '</span>' : '')
      + '<div class="sp-img">' + img + '</div>'
      + '<div class="sp-name">' + esc(p.name) + '</div>'
      + '<div class="sp-unit">' + esc(p.unit) + '</div>'
      + '<div class="sp-price">' + money(p.price) + '</div>'
      + '<div class="sp-stock"><i></i>' + stockTxt + '</div></button>';
  }
  function renderGrid() {
    var list = visible();
    $('spGrid').innerHTML = list.length ? list.map(card).join('') : '<div class="sp-empty">No products match. Try another category or search.</div>';
  }
  function renderCounts() {
    var low = products.filter(function (p) { return stockState(p.stock) === 'low' || stockState(p.stock) === 'critical'; }).length;
    var out = products.filter(function (p) { return p.stock <= 0; }).length;
    document.querySelectorAll('.sp-chip[data-filter="low"] b').forEach(function (b) { b.textContent = low; });
    document.querySelectorAll('.sp-chip[data-filter="out"] b').forEach(function (b) { b.textContent = out; });
  }

  // ── Cart ──────────────────────────────────────────────────────
  function renderCart() {
    var ids = Object.keys(state.cart);
    $('spLines').innerHTML = ids.length ? ids.map(function (id) {
      var p = byId[id], c = state.cart[id];
      return '<div class="sp-line">'
        + '<div class="sp-line-info"><div class="sp-line-name">' + esc(p.name) + '</div>'
        + '<div class="sp-line-sub">' + money(p.price) + ' × ' + c.qty + '</div></div>'
        + '<div class="sp-qty"><button type="button" data-dec="' + id + '" aria-label="Less">−</button>'
        + '<span>' + c.qty + '</span><button type="button" data-inc="' + id + '" aria-label="More">+</button></div>'
        + '<div class="sp-line-total">' + money(p.price * c.qty) + '</div>'
        + '<button type="button" class="sp-line-rm" data-rm="' + id + '" aria-label="Remove"><i class="fas fa-times"></i></button></div>';
    }).join('') : '<div class="sp-empty-cart"><i class="fas fa-shopping-basket"></i><div>Tap a product to add it</div></div>';

    var sub = subtotal(), disc = discountAmt(), fee = deliveryFee(), tot = total();
    $('spSub').textContent = money(sub);
    $('spDisc').textContent = '−' + money(disc);
    $('spFeeRow').style.display = state.ful === 'delivery' ? 'flex' : 'none';
    $('spFee').textContent = fee > 0 ? money(fee) : 'Waived';
    $('spTotal').textContent = money(tot);
    $('spCheckAmt').textContent = money(tot);
    $('spCheckout').disabled = ids.length === 0;
    $('spCustLabel').textContent = customerName();
    $('spFulLabel').textContent = state.ful === 'delivery'
      ? 'Delivery · ' + (state.timing === 'later' ? 'pay on delivery' : 'paid now')
      : 'Pick up';
  }
  function customerName() {
    if (!state.cust) return 'Walk-in customer';
    return state.cust.name + (state.cust.phone ? ' · ' + state.cust.phone : '');
  }
  function renderAll() { renderGrid(); renderCart(); renderCounts(); renderHeldCount(); }

  function addToCart(id) {
    var p = byId[id]; if (!p) return;
    var have = inCart(id);
    if (have + 1 > p.stock) { showToast('Only ' + p.stock + ' of ' + p.name + ' in stock', 'warning'); return; }
    state.cart[id] = { qty: have + 1 };
    renderAll();
  }
  function setQty(id, q) {
    var p = byId[id]; if (!state.cart[id] || !p) return;
    if (q <= 0) delete state.cart[id];
    else if (q > p.stock) { showToast('Only ' + p.stock + ' of ' + p.name + ' in stock', 'warning'); return; }
    else state.cart[id].qty = q;
    renderAll();
  }
  function clearSale() {
    state.cart = {}; state.cust = null; state.dType = 'none'; state.dVal = 0;
    state.ful = 'pickup'; state.timing = 'now'; state.address = '';
    fillCustFields(null);
    renderAll();
  }

  // ── Search ────────────────────────────────────────────────────
  var search = $('spSearch');
  if (!search) return;   // not the point of sale page
  search.addEventListener('input', function () { state.q = search.value.trim().toLowerCase(); renderGrid(); });

  // ── Customer fields in the sale panel ─────────────────────────
  // Typing a phone that matches a saved customer attaches that customer. Other typed details make a new customer.
  function custFromFields() {
    var name = $('pcName').value.trim(), phone = $('pcPhone').value.trim(), email = $('pcEmail').value.trim();
    var addr = $('pcAddress').value.trim(), notes = $('pcNotes').value.trim();
    var digits = phone.replace(/\D/g, ''), msg = '';
    var match = digits.length >= 9 ? customers.filter(function (c) {
      return (c.phone || '').replace(/\D/g, '').slice(-9) === digits.slice(-9);
    })[0] : null;
    if (match) { state.cust = match; msg = 'Existing customer: ' + match.name; }
    else if (name || phone || email) { state.cust = { id: 0, name: name, phone: phone, email: email, location: addr, notes: notes }; msg = name && digits.length >= 9 ? 'New customer' : ''; }
    else state.cust = null;
    if (addr) state.address = addr;
    $('pcMatch').textContent = msg;
    $('coCustSearch').value = state.cust ? customerName() : '';
    renderCart();
  }
  function fillCustFields(c) {
    $('pcName').value = c ? (c.name || '') : '';
    $('pcPhone').value = c ? (c.phone || '') : '';
    $('pcEmail').value = c ? (c.email || '') : '';
    $('pcAddress').value = c ? (c.location || '') : '';
    $('pcNotes').value = c ? (c.notes || '') : '';
    $('pcMatch').textContent = c && c.id ? 'Existing customer' : '';
  }
  ['pcName', 'pcPhone', 'pcEmail', 'pcAddress', 'pcNotes'].forEach(function (id) {
    $(id).addEventListener('input', custFromFields);
  });

  // ── Customer: existing list, or the new-customer form ─────────
  function renderCustList() {
    var q = ($('spCustSearch').value || '').trim().toLowerCase();
    var list = customers.filter(function (c) { return !q || (c.name + ' ' + c.phone).toLowerCase().indexOf(q) >= 0; }).slice(0, 60);
    $('spCustList').innerHTML = '<button type="button" class="sp-custrow walkin" data-cust="0"><strong>Walk-in customer</strong><span>No details needed</span></button>'
      + list.map(function (c) {
        return '<button type="button" class="sp-custrow" data-cust="' + c.id + '"><strong>' + esc(c.name) + '</strong><span>' + esc(c.phone || c.email || '') + '</span></button>';
      }).join('');
  }
  $('spCustSearch').addEventListener('input', renderCustList);

  function useNewCustomer() {
    var name = $('ncName').value.trim(), phone = $('ncPhone').value.trim();
    var err = '';
    if (!name) err = 'Enter the customer\'s name.';
    else if (phone.replace(/\D/g, '').length < 9) err = 'Enter a phone number (at least 9 digits).';
    $('ncError').style.display = err ? 'block' : 'none';
    $('ncError').textContent = err;
    if (err) return;
    state.cust = { id: 0, name: name, phone: phone, email: $('ncEmail').value.trim(), location: $('ncAddress').value.trim(), notes: $('ncNotes').value.trim() };
    $('coCustSearch').value = customerName(); $('coCustHits').innerHTML = '';
    if (state.cust.location && !state.address) state.address = state.cust.location;
    fillCustFields(state.cust);
    $('spCustModal').classList.remove('open');
    renderAll();
  }
  $('ncUse').addEventListener('click', useNewCustomer);

  // ── Checkout ──────────────────────────────────────────────────
  function discountError() {
    var s = subtotal();
    if (state.dType === 'none') return null;
    var pct = state.dType === 'percent' ? state.dVal : (s > 0 ? discountAmt() / s * 100 : 0);
    if (state.dType === 'percent' && (state.dVal < 0 || state.dVal > 100)) return 'Percentage must be between 0 and 100.';
    if (state.dType === 'fixed' && state.dVal < 0) return 'Discount cannot be negative.';
    if (!IS_MGR && pct > DISC_MAX + 0.0001) return 'Discounts above ' + DISC_MAX + '% need a manager to approve.';
    return null;
  }
  function checkoutValid() {
    var err = discountError();
    if (!cartCount()) err = err || 'Add a product to the sale.';
    if (!err && state.ful === 'delivery') {
      if (!state.cust || !state.cust.name || !state.cust.phone) err = 'A delivery needs the customer\'s name and phone. Use Customer to add them.';
      else if (!$('coAddress').value.trim()) err = 'Enter the delivery address.';
    }
    var t = total();
    if (!err && !(state.ful === 'delivery' && state.timing === 'later') && state.pay === 'cash') {
      var rcv = parseFloat($('coReceived').value || '0');
      if (rcv < t) err = 'Amount received is less than the total.';
    }
    $('coError').style.display = err ? 'block' : 'none';
    $('coError').textContent = err || '';
    $('coComplete').disabled = !!err;
    return !err;
  }
  function refreshCheckout() {
    var t = total();
    $('coTotal').textContent = money(t);
    var u = units();
    var delivery = state.ful === 'delivery';
    $('coDeliveryBox').style.display = delivery ? 'block' : 'none';
    $('coFeeHint').textContent = delivery
      ? (deliveryFee() > 0 ? 'Standard delivery fee ' + money(DEL_FEE) + '. Waived for ' + WAIVE + ' units or more (this sale has ' + u + ').' : 'Delivery fee waived: ' + u + ' units.')
      : '';
    var later = delivery && state.timing === 'later';
    $('coPayBox').style.display = later ? 'none' : 'block';
    $('coLaterNote').style.display = later ? 'block' : 'none';
    $('coDType').value = state.dType;
    $('coDVal').disabled = state.dType === 'none';
    $('coDHint').textContent = state.dType === 'none' ? '' : (IS_MGR ? 'Manager: no discount limit.' : 'Up to ' + DISC_MAX + '% without a manager.');
    var cashBox = $('coCashBox'), momoBox = $('coMomoBox');
    cashBox.style.display = state.pay === 'cash' ? 'block' : 'none';
    momoBox.style.display = state.pay === 'cash' ? 'none' : 'block';
    var rcv = parseFloat($('coReceived').value || '0');
    $('coChange').textContent = money(Math.max(0, rcv - t));
    $('coSubtotal').textContent = money(subtotal());
    $('coFeeRow').style.display = delivery ? 'flex' : 'none';
    $('coFeeAmt').textContent = deliveryFee() > 0 ? money(deliveryFee()) : 'Waived';
    var dsc = discountAmt();
    $('coDiscRow').style.display = dsc > 0 ? 'flex' : 'none';
    $('coDiscAmt').textContent = '−' + money(dsc);
    $('coBtnAmt').textContent = money(t);
    $('coSub').textContent = cartCount() + ' item' + (cartCount() === 1 ? '' : 's') + ' · ' + (delivery ? 'Delivery' : 'Pickup');
    if (state.cust && !$('coCustSearch').value) $('coCustSearch').value = customerName();
    document.querySelectorAll('[data-ful]').forEach(function (b) { b.classList.toggle('active', b.dataset.ful === state.ful); });
    document.querySelectorAll('[data-timing]').forEach(function (b) { b.classList.toggle('active', b.dataset.timing === state.timing); });
    document.querySelectorAll('[data-pay]').forEach(function (b) { b.classList.toggle('active', b.dataset.pay === state.pay); });
    checkoutValid();
  }
  function openCheckout() {
    if (!cartCount()) { showToast('Add a product first', 'warning'); return; }
    $('coDType').value = state.dType; $('coDVal').value = state.dVal || 0;
    $('coReceived').value = ''; $('coRef').value = '';
    if (!$('coAddress').value && state.address) $('coAddress').value = state.address;
    refreshCheckout();
    $('spCheckoutModal').classList.add('open');
    setTimeout(function () { (state.pay === 'cash' ? $('coReceived') : $('coRef')).focus(); }, 60);
  }

  // ── Receipt ───────────────────────────────────────────────────
  function receiptHtml(r) {
    var lines = r.lines.map(function (l) {
      return '<div class="rc-line"><span>' + l.qty + ' × ' + esc(l.name) + '</span><span>' + money(l.line) + '</span></div>';
    }).join('');
    var del = r.fulfilment === 'delivery';
    return '<div class="rc-brand">COOPERS FARM</div><div class="rc-sub">Poultry &amp; farm products</div>'
      + '<div class="rc-meta">' + esc(r.number) + '<br>' + esc(r.date) + '<br>Customer: ' + esc(r.customer)
      + (del ? '<br>Delivery to: ' + esc(r.address) : '<br>Pick up') + '</div>'
      + '<div class="rc-rule"></div>' + lines
      + '<div class="rc-rule"></div>'
      + '<div class="rc-line"><span>Subtotal</span><span>' + money(r.subtotal) + '</span></div>'
      + (r.discount > 0 ? '<div class="rc-line"><span>Discount</span><span>−' + money(r.discount) + '</span></div>' : '')
      + (del ? '<div class="rc-line"><span>Delivery fee</span><span>' + (r.fee > 0 ? money(r.fee) : 'Waived') + '</span></div>' : '')
      + '<div class="rc-line rc-total"><span>TOTAL</span><span>' + money(r.total) + '</span></div>'
      + (r.paid_now
          ? '<div class="rc-line"><span>Paid (' + esc(PAY[r.payment] || r.payment) + ')</span><span>' + money(r.received) + '</span></div>'
            + (r.payment === 'cash' ? '<div class="rc-line"><span>Change</span><span>' + money(r.change) + '</span></div>' : '')
          : '<div class="rc-line"><span>Payment</span><span>Due on delivery</span></div>')
      + '<div class="rc-rule"></div><div class="rc-foot">Served by ' + esc(r.cashier) + '<br>Thank you for your business!</div>';
  }
  function showReceipt(r) { $('spReceipt').innerHTML = receiptHtml(r); $('spReceiptModal').classList.add('open'); }

  // ── Completing a sale ─────────────────────────────────────────
  $('coComplete').addEventListener('click', function () {
    if (!checkoutValid()) return;
    var t = total();
    var delivery = state.ful === 'delivery', later = delivery && state.timing === 'later';
    var fd = new FormData();
    fd.append('complete_sale', '1');
    fd.append('items', JSON.stringify(Object.keys(state.cart).map(function (id) { return { id: parseInt(id, 10), qty: state.cart[id].qty }; })));
    fd.append('payment_method', state.pay);
    fd.append('discount_type', state.dType);
    fd.append('discount_value', state.dVal || 0);
    fd.append('customer_id', state.cust && state.cust.id ? state.cust.id : 0);
    if (state.cust && !state.cust.id) {
      fd.append('cust_name', state.cust.name); fd.append('cust_phone', state.cust.phone);
      fd.append('cust_email', state.cust.email || ''); fd.append('cust_notes', state.cust.notes || '');
    }
    fd.append('fulfilment', state.ful);
    fd.append('pay_timing', later ? 'later' : 'now');
    fd.append('delivery_address', delivery ? $('coAddress').value.trim() : '');
    fd.append('received', (!later && state.pay === 'cash') ? ($('coReceived').value || 0) : t);
    fd.append('reference', $('coRef').value || '');
    $('coComplete').disabled = true;
    $('coError').style.display = 'none';
    fetch('walkin_order.php', { method: 'POST', body: fd })
      .then(function (r) { return r.text(); })
      .then(function (text) {
        var d;
        try { d = JSON.parse(text); }
        catch (e) { throw { unreadable: true }; }
        if (!d.ok) {
          $('coError').textContent = d.error || 'The sale was not saved.';
          $('coError').style.display = 'block';
          $('coComplete').disabled = false;
          return;
        }
        Object.keys(d.stock || {}).forEach(function (id) { if (byId[id]) byId[id].stock = d.stock[id]; });
        recent.unshift({ number: d.receipt.number, total: d.receipt.total, payment: d.receipt.payment, received: d.receipt.received,
          customer: d.receipt.customer, date: d.receipt.date,
          lines: d.receipt.lines.map(function (l) { return { name: l.name, qty: l.qty, price: l.price, line: l.line }; }) });
        $('spCheckoutModal').classList.remove('open');
        clearSale();
        showReceipt(d.receipt);
      })
      .catch(function (err) {
        $('coComplete').disabled = false;
        $('coError').textContent = (err && err.unreadable)
          ? 'The server replied, but the reply could not be read. Check Recent sales before retrying — pressing Complete again will not charge twice.'
          : 'Network error — check Recent sales before retrying. Pressing Complete again will not charge twice.';
        $('coError').style.display = 'block';
      });
  });

  // Customer search inside checkout: pick a customer, or add a new one from the form
  function renderCoHits() {
    var q = ($('coCustSearch').value || '').trim().toLowerCase();
    var hits = $('coCustHits');
    if (!q || (state.cust && $('coCustSearch').value === customerName())) { hits.innerHTML = ''; return; }
    var list = customers.filter(function (c) { return (c.name + ' ' + c.phone).toLowerCase().indexOf(q) >= 0; }).slice(0, 5);
    hits.innerHTML = list.map(function (c) {
      return '<button type="button" class="co-hit" data-co-cust="' + c.id + '"><strong>' + esc(c.name) + '</strong><span>' + esc(c.phone || c.email || '') + '</span></button>';
    }).join('') + '<button type="button" class="co-hit co-hit-new" data-co-new="1"><i class="fas fa-user-plus"></i> Add new customer' + (q ? ' “' + esc($('coCustSearch').value.trim()) + '”' : '') + '</button>';
  }
  $('coCustSearch').addEventListener('input', function () {
    if (state.cust) { state.cust = null; }
    renderCoHits(); checkoutValid();
  });
  $('coReceived').addEventListener('input', refreshCheckout);
  $('coRef').addEventListener('input', checkoutValid);
  $('coAddress').addEventListener('input', function () { state.address = this.value.trim(); checkoutValid(); });
  $('coDType').addEventListener('change', function () { state.dType = this.value; if (this.value === 'none') { state.dVal = 0; $('coDVal').value = 0; } refreshCheckout(); renderCart(); });
  $('coDVal').addEventListener('input', function () { state.dVal = parseFloat(this.value || '0'); refreshCheckout(); renderCart(); });

  // ── Hold / resume (kept on this device) ───────────────────────
  function heldList() { try { return JSON.parse(localStorage.getItem(HELD_KEY) || '[]'); } catch (e) { return []; } }
  function saveHeld(list) { localStorage.setItem(HELD_KEY, JSON.stringify(list)); }
  function renderHeldCount() { $('spHeldN').textContent = heldList().length; }
  function renderHeldModal() {
    var list = heldList();
    $('spHeldList').innerHTML = list.length ? list.map(function (h, i) {
      var n = Object.keys(h.cart).reduce(function (s, id) { return s + h.cart[id].qty; }, 0);
      return '<div class="sp-held"><div><strong>Sale ' + (i + 1) + '</strong><span>' + n + ' item(s) · ' + esc(h.custName) + ' · ' + esc(h.when) + '</span></div>'
        + '<div><button class="sp-btn" type="button" data-resume="' + i + '">Resume</button> <button class="sp-btn" type="button" data-discard="' + i + '">Discard</button></div></div>';
    }).join('') : '<div class="sp-empty">No held sales.</div>';
  }
  $('spHold').addEventListener('click', function () {
    if (!cartCount()) { showToast('Nothing to hold', 'warning'); return; }
    var list = heldList();
    if (list.length >= 10) { showToast('Resume a held sale first (10 max)', 'warning'); return; }
    list.push({ cart: state.cart, cust: state.cust, custName: customerName(), dType: state.dType, dVal: state.dVal,
      ful: state.ful, timing: state.timing, address: state.address,
      when: new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) });
    saveHeld(list); clearSale(); showToast('Sale held', 'success');
  });

  // ── One delegated handler for every click on this page ───────
  document.addEventListener('click', function (e) {
    var t = e.target;
    var add = t.closest('[data-add]');   if (add) { addToCart(parseInt(add.dataset.add, 10)); return; }
    var inc = t.closest('[data-inc]');   if (inc) { var i = parseInt(inc.dataset.inc, 10); setQty(i, (state.cart[i] ? state.cart[i].qty : 0) + 1); return; }
    var dec = t.closest('[data-dec]');   if (dec) { var d2 = parseInt(dec.dataset.dec, 10); setQty(d2, (state.cart[d2] ? state.cart[d2].qty : 0) - 1); return; }
    var rm  = t.closest('[data-rm]');    if (rm)  { setQty(parseInt(rm.dataset.rm, 10), 0); return; }
    var cat = t.closest('[data-cat]');   if (cat) { state.cat = cat.dataset.cat; document.querySelectorAll('.sp-cat').forEach(function (b) { b.classList.toggle('active', b === cat); }); renderGrid(); return; }
    var chip = t.closest('[data-filter]'); if (chip) { state.filter = chip.dataset.filter; document.querySelectorAll('.sp-chip').forEach(function (b) { b.classList.toggle('active', b === chip); }); renderGrid(); return; }
    var open = t.closest('[data-open]'); if (open) { openPanel(open.dataset.open); return; }
    var close = t.closest('[data-close]'); if (close) { close.closest('.modal-overlay').classList.remove('open'); return; }
    var cust = t.closest('[data-cust]'); if (cust) {
      var cid = parseInt(cust.dataset.cust, 10);
      state.cust = cid ? customers.filter(function (c) { return c.id === cid; })[0] : null;
      if (state.cust && state.cust.location && !state.address) state.address = state.cust.location;
      fillCustFields(state.cust);
      $('spCustModal').classList.remove('open'); renderAll(); return;
    }
    var ful = t.closest('[data-ful]'); if (ful) { state.ful = ful.dataset.ful; if (state.ful === 'pickup') state.timing = 'now'; refreshCheckout(); renderCart(); return; }
    var tim = t.closest('[data-timing]'); if (tim) { state.timing = tim.dataset.timing; refreshCheckout(); renderCart(); return; }
    var pay = t.closest('[data-pay]'); if (pay) { state.pay = pay.dataset.pay; refreshCheckout(); return; }
    var coc = t.closest('[data-co-cust]'); if (coc) {
      var ccid = parseInt(coc.dataset.coCust, 10);
      state.cust = customers.filter(function (c) { return c.id === ccid; })[0] || null;
      if (state.cust && state.cust.location && !$('coAddress').value) { $('coAddress').value = state.cust.location; state.address = state.cust.location; }
      $('coCustSearch').value = customerName(); $('coCustHits').innerHTML = '';
      fillCustFields(state.cust);
      refreshCheckout(); renderCart(); return;
    }
    var con = t.closest('[data-co-new]'); if (con) {
      var typed = $('coCustSearch').value.trim();
      var digits = typed.replace(/\D/g, '');
      if (digits.length >= 7) $('ncPhone').value = typed; else $('ncName').value = typed;
      $('ncError').style.display = 'none';
      $('spCustModal').classList.add('open');
      return;
    }
    var q = t.closest('[data-quick]'); if (q) {
      var tot = total();
      $('coReceived').value = q.dataset.quick === 'exact' ? tot.toFixed(2) : (Math.ceil(tot) + parseInt(q.dataset.quick, 10)).toFixed(2);
      refreshCheckout(); return;
    }
    var res = t.closest('[data-resume]'); if (res) {
      var list = heldList(), h = list[parseInt(res.dataset.resume, 10)];
      if (!h) return;
      if (cartCount() && !window.confirm('Replace the current sale with the held one? The current sale will be lost.')) return;
      state.cart = h.cart; state.cust = h.cust; state.dType = h.dType; state.dVal = h.dVal;
      state.ful = h.ful || 'pickup'; state.timing = h.timing || 'now'; state.address = h.address || '';
      list.splice(parseInt(res.dataset.resume, 10), 1); saveHeld(list);
      fillCustFields(state.cust);
      $('spHeldModal').classList.remove('open'); renderAll(); return;
    }
    var dis = t.closest('[data-discard]'); if (dis) {
      var l2 = heldList(); l2.splice(parseInt(dis.dataset.discard, 10), 1); saveHeld(l2); renderHeldModal(); renderHeldCount(); return;
    }
  });

  function openPanel(which) {
    if (which === 'customer') {
      $('spCustSearch').value = ''; renderCustList();
      $('ncError').style.display = 'none';
      $('spCustModal').classList.add('open');
    }
    if (which === 'held') { renderHeldModal(); $('spHeldModal').classList.add('open'); }
    if (which === 'recent') { renderRecent(); $('spRecentModal').classList.add('open'); }
  }
  function renderRecent() {
    $('spRecentList').innerHTML = recent.length ? recent.map(function (r, i) {
      return '<button type="button" class="sp-recent" data-recent="' + i + '"><span><strong>' + esc(r.number) + '</strong><small>' + esc(r.date) + ' · ' + esc(PAY[r.payment] || r.payment) + '</small></span><b>' + money(r.total) + '</b></button>';
    }).join('') : '<div class="sp-empty">No sales yet.</div>';
  }
  $('spRecentList').addEventListener('click', function (e) {
    var b = e.target.closest('[data-recent]'); if (!b) return;
    var r = recent[parseInt(b.dataset.recent, 10)];
    var sub = r.lines.reduce(function (s, l) { return s + l.line; }, 0);
    $('spRecentModal').classList.remove('open');
    showReceipt({ number: r.number, date: r.date, customer: r.customer, lines: r.lines, subtotal: sub,
      discount: Math.max(0, sub - r.total), fee: 0, total: r.total, received: r.received, change: Math.max(0, r.received - r.total),
      payment: r.payment, paid_now: true, fulfilment: 'pickup', address: '', cashier: '' });
  });

  $('rcPrint').addEventListener('click', function () { window.print(); });
  $('rcNew').addEventListener('click', function () { $('spReceiptModal').classList.remove('open'); search.focus(); });

  // Keyboard: F2 search, F4 checkout, F8 hold, F9 new sale
  document.addEventListener('keydown', function (e) {
    if (e.key === 'F2') { e.preventDefault(); search.focus(); }
    else if (e.key === 'F4') { e.preventDefault(); if (!document.querySelector('.modal-overlay.open')) openCheckout(); }
    else if (e.key === 'F8') { e.preventDefault(); $('spHold').click(); }
    else if (e.key === 'F9') { e.preventDefault(); if (cartCount() && !window.confirm('Start a new sale? The current sale will be cleared.')) return; clearSale(); }
  });
  $('spCheckout').addEventListener('click', openCheckout);

  renderAll();
  renderCustList();
} catch (e) { console.error('Coopers admin:', e); } })();


/* ════════════════════════════════════════════════════════════
   ORDERS
   ════════════════════════════════════════════════════════════ */
(function () { try {
  const PD = pageData();
// ── Order data for drawer ─────────────────────────────────────
const ordersData = PD.ordersData || {};

const statusColors = PD.statusColors;
const statuses = PD.statuses;

function formatCur(v) { return '₵' + parseFloat(v).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); }
function fmtStatus(s) { return s.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()); }

// ── Inline status update ──────────────────────────────────────
function inlineStatusUpdate(id, sel) {
  const newStatus = sel.value;
  const fd = new FormData();
  fd.append('update_status','1'); fd.append('order_id',id); fd.append('status',newStatus); fd.append('ajax','1');
  fetch('orders.php', { method:'POST', body:fd })
    .then(r=>r.json()).then(data=>{
      if (data.ok) {
        const [bc,tc,bgc] = statusColors[newStatus] || ['#9ca3af','#374151','#f3f4f6'];
        sel.style.backgroundColor = bgc;
        sel.style.color           = tc;
        sel.style.borderColor     = bc + 'aa';
        showToast('Status updated to: ' + fmtStatus(newStatus));
        // Update drawer if open for this order
        if (currentDrawerId == id && ordersData[id]) { ordersData[id].order_status = newStatus; renderDrawer(id); }
      }
    }).catch(()=>{ showToast('Update failed','error'); sel.value = sel.dataset.prev; });
  sel.dataset.prev = newStatus;
}

// ── Drawer ────────────────────────────────────────────────────
let currentDrawerId = null;
function ordersOpenDrawer(id) {
  currentDrawerId = id;
  document.getElementById('drawerOverlay').classList.add('open');
  document.getElementById('orderDrawer').classList.add('open');
  renderDrawer(id);
}
function ordersCloseDrawer() {
  document.getElementById('drawerOverlay').classList.remove('open');
  document.getElementById('orderDrawer').classList.remove('open');
  currentDrawerId = null;
}

function renderDrawer(id) {
  const o = ordersData[id];
  if (!o) return;
  document.getElementById('drawerTitle').textContent = 'Order ' + o.order_number;
  const [bc,tc,bgc] = statusColors[o.order_status] || ['#9ca3af','#374151','#f3f4f6'];

  let itemsHtml = (o._items||[]).map(it => `
    <div class="ditem-row">
      <span>${it.product_name} <span style="color:#9ca3af;">×${it.quantity}</span></span>
      <strong>${formatCur(it.subtotal)}</strong>
    </div>`).join('') || '<div style="color:#9ca3af;font-size:13px;padding:8px 0;">No items found</div>';

  const received  = parseFloat(o.payment_received||0);
  const change    = received > 0 ? Math.max(0, received - parseFloat(o.total_amount)) : 0;

  document.getElementById('drawerBody').innerHTML = `
    <div class="drawer-section">
      <h4>Order Info</h4>
      <div class="drawer-field"><span class="lbl">Order #</span><span class="val" style="font-family:monospace;">${o.order_number}</span></div>
      <div class="drawer-field"><span class="lbl">Date</span><span class="val">${new Date(o.created_at).toLocaleString('en-GB',{dateStyle:'medium',timeStyle:'short'})}</span></div>
      <div class="drawer-field"><span class="lbl">Type</span><span class="val">${o.otype==='walkin'?'🏪 Walk-in':'🌐 Online'}</span></div>
      <div class="drawer-field"><span class="lbl">Status</span>
        <span class="val" style="background:${bgc};color:${tc};padding:3px 10px;border-radius:50px;font-size:12px;">${fmtStatus(o.order_status)}</span>
      </div>
    </div>
    <div class="drawer-section">
      <h4>Customer</h4>
      <div class="drawer-field"><span class="lbl">Name</span><span class="val">${o.customer_name}</span></div>
      <div class="drawer-field"><span class="lbl">Phone</span>
        <span class="val"><a href="tel:${o.customer_phone}" style="color:var(--primary);">${o._phone_fmt}</a></span>
      </div>
      ${o.customer_email?`<div class="drawer-field"><span class="lbl">Email</span><span class="val">${o.customer_email}</span></div>`:''}
      ${o.delivery_address&&o.delivery_address!=='Farm Counter'?`<div class="drawer-field"><span class="lbl">Address</span><span class="val">${o.delivery_address}</span></div>`:''}
    </div>
    <div class="drawer-section">
      <h4>Items</h4>
      ${itemsHtml}
    </div>
    <div class="drawer-section">
      <h4>Payment</h4>
      <div class="drawer-field"><span class="lbl">Method</span><span class="val">${o.payment_method?.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase())||'—'}</span></div>
      <div class="drawer-field"><span class="lbl">Total</span><span class="val" style="font-size:18px;color:var(--primary);">${formatCur(o.total_amount)}</span></div>
      ${received>0?`<div class="drawer-field"><span class="lbl">Received</span><span class="val">${formatCur(received)}</span></div>`:''}
      ${change>0?`<div class="drawer-field"><span class="lbl">Change</span><span class="val" style="color:#16a34a;">${formatCur(change)}</span></div>`:''}
      <div class="drawer-field"><span class="lbl">Payment status</span><span class="val">${o.payment_status||'—'}</span></div>
    </div>
    <div class="drawer-section">
      <h4>Update Status</h4>
      <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px;">
        ${statuses.map(s=>{
          const [bc2,tc2,bgc2] = statusColors[s]||['#9ca3af','#374151','#f3f4f6'];
          const active = o.order_status===s;
          return `<button onclick="inlineStatusUpdate(${id},Object.assign(document.getElementById('stat_${id}')||{},{value:'${s}',dataset:{}}));document.getElementById('stat_${id}')&&(document.getElementById('stat_${id}').value='${s}');ordersData[${id}].order_status='${s}';renderDrawer(${id});"
            style="padding:8px 10px;border-radius:8px;border:2px solid ${active?bc2:'#e5e7eb'};background:${active?bgc2:'white'};color:${active?tc2:'#6b7280'};font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;">
            ${fmtStatus(s)}</button>`;
        }).join('')}
      </div>
    </div>
    ${o.notes?`<div class="drawer-section"><h4>Notes</h4><div style="background:#f9fafb;border-radius:8px;padding:10px 12px;font-size:13px;">${o.notes}</div></div>`:''}
  `;
}

document.addEventListener('keydown', e => { if(e.key==='Escape') ordersCloseDrawer(); });


// Initialise prev values for selects
document.querySelectorAll('.status-select').forEach(s => s.dataset.prev = s.value);

if (PD.openId) {
window.addEventListener('DOMContentLoaded',()=>ordersOpenDrawer(PD.openId));
}
  Object.assign(window, { formatCur, fmtStatus, inlineStatusUpdate, ordersOpenDrawer, ordersCloseDrawer, renderDrawer });
} catch (e) { console.error('Coopers admin:', e); } })();


/* ════════════════════════════════════════════════════════════
   PRODUCTS
   ════════════════════════════════════════════════════════════ */
(function () { try {
  const PD = pageData();
// ── Open modals ───────────────────────────────────────────────
function openAddModal() {
  document.getElementById('addProductModal').classList.add('open');
  const sel = document.getElementById('addCategory');
  if (sel) onAddCategoryChange(sel);
}
function openRestock(id, name) {
  document.getElementById('restockProductId').value = id;
  document.getElementById('restockProductName').textContent = name;
  document.getElementById('restockModal').classList.add('open');
}
function prodOpenEditModal(p) {
  document.getElementById('editId').value       = p.id;
  document.getElementById('editName').value     = p.name;
  document.getElementById('editCategory').value = p.category;
  document.getElementById('editDesc').value     = p.description || '';
  document.getElementById('editPrice').value    = p.price;
  document.getElementById('editUnit').value     = p.unit;
  document.getElementById('editMinOrder').value = p.min_order;
  document.getElementById('editEggUnits').value = p.egg_units || 30;
  document.getElementById('editEggSize').value = p.egg_size || 'large';
  document.getElementById('editBatch').value = p.flock_id || '';
  filterBatches('editBatch', p.category);
  document.getElementById('batchGroupEdit').style.display = (p.category === 'broiler' || p.category === 'layer') ? 'block' : 'none';
  document.getElementById('editEggUnitsGroup').style.display = p.category === 'eggs' ? 'block' : 'none';
  const imgWrap = document.getElementById('editCurrentImg');
  const imgEl   = document.getElementById('editCurrentImgEl');
  if (p.image_url) { imgEl.src = '../public/' + p.image_url; imgWrap.style.display = 'block'; }
  else { imgWrap.style.display = 'none'; }
  document.getElementById('editPreview').style.display = 'none';
  document.getElementById('editImgInput').value = '';
  const eu = document.getElementById('editUnit');
  if (eu) delete eu.dataset.userEdited;
  document.getElementById('editProductModal').classList.add('open');
}

// ── Image zoom ────────────────────────────────────────────────
function zoomImg(src) {
  document.getElementById('imgZoomEl').src = src;
  document.getElementById('imgZoom').classList.add('show');
}

// ── Inline stock edit ─────────────────────────────────────────
function startStockEdit(id) {
  document.getElementById('snum_' + id).style.display  = 'none';
  const inp = document.getElementById('sedit_' + id);
  inp.style.display = 'inline-block';
  inp.focus(); inp.select();
}
function cancelStockEdit(id) {
  document.getElementById('snum_'  + id).style.display = '';
  document.getElementById('sedit_' + id).style.display = 'none';
}
function saveStockEdit(id) {
  const inp  = document.getElementById('sedit_' + id);
  const numEl= document.getElementById('snum_'  + id);
  const qty  = parseInt(inp.value);
  if (isNaN(qty) || qty < 0) { cancelStockEdit(id); return; }
  const fd = new FormData();
  fd.append('inline_stock','1'); fd.append('product_id',id); fd.append('new_stock',qty);
  fetch('products.php', { method:'POST', body:fd })
    .then(r=>r.json()).then(data=>{
      if (!data.ok) { showToast(data.error || 'Not allowed', 'error'); cancelStockEdit(id); return; }
      if (data.ok) {
        numEl.textContent   = qty.toLocaleString();
        numEl.style.display = '';
        inp.style.display   = 'none';
        inp.value           = qty;
        showToast('Stock updated to ' + qty);
      }
    }).catch(()=>showToast('Update failed','error'));
}

// ── Category → Unit ───────────────────────────────────────────
const unitDefaults = {
  eggs:     { unit:'crate', label:'Crates in stock', hint:'1 crate = 30 eggs' },
  broiler:  { unit:'bird',  label:'Birds in stock',  hint:'' },
  layer:    { unit:'bird',  label:'Birds in stock',  hint:'' },
  byproduct:{ unit:'kg',    label:'Quantity in stock',hint:'' },
  other:    { unit:'piece', label:'Units in stock',  hint:'' },
};
// Show only the batches of the matching bird type
  function filterBatches(selectId, category) {
    var sel = document.getElementById(selectId); if (!sel) return;
    Array.prototype.forEach.call(sel.options, function (o) {
      if (!o.dataset.type) return;
      o.hidden = !(category === o.dataset.type);
    });
  }
  function onAddCategoryChange(sel) {
  const d = unitDefaults[sel.value] || unitDefaults.other;
  document.getElementById('addUnitField').value = d.unit;
  document.getElementById('addStockLabel').textContent = d.label;
  document.getElementById('addUnitHint').textContent = d.hint;
  document.getElementById('eggsPerCrateGroup').style.display = sel.value==='eggs'?'block':'none';
  document.getElementById('batchGroupAdd').style.display = (sel.value==='broiler'||sel.value==='layer') ? 'block' : 'none';
  var isEgg = sel.value === 'eggs';
  var sg = document.getElementById('addStockInput'), sh = document.getElementById('addStockHint');
  if (sg) { sg.disabled = isEgg; sg.value = isEgg ? 0 : sg.value; }
  if (sh) sh.style.display = isEgg ? 'block' : 'none';
  filterBatches('addBatch', sel.value);
}
function onEditCategoryChange(sel) {
  document.getElementById('editEggUnitsGroup').style.display = sel.value === 'eggs' ? 'block' : 'none';
  document.getElementById('batchGroupEdit').style.display = (sel.value === 'broiler' || sel.value === 'layer') ? 'block' : 'none';
  filterBatches('editBatch', sel.value);
  const d = unitDefaults[sel.value] || unitDefaults.other;
  const u = document.getElementById('editUnit');
  if (u && !u.dataset.userEdited) u.value = d.unit;
  const h = document.getElementById('editUnitHint');
  if (h) h.textContent = d.hint;
}
const addCat = document.getElementById('addCategory');
if (addCat) { addCat.addEventListener('change', ()=>onAddCategoryChange(addCat)); onAddCategoryChange(addCat); }
const editCat = document.querySelector('#editProductModal select[name="category"]');
if (editCat) editCat.addEventListener('change', ()=>onEditCategoryChange(editCat));
const eu = document.getElementById('editUnit');
if (eu) eu.addEventListener('input', ()=>{ eu.dataset.userEdited='1'; });

// ── Image preview ─────────────────────────────────────────────
// previewImg() and img-drop drag/drop — defined in admin.js

// ── Bulk actions ──────────────────────────────────────────────
function onCbChange() {
  const checked = document.querySelectorAll('.prod-cb:checked');
  const bar     = document.getElementById('bulkBar');
  const countEl = document.getElementById('bulkCount');
  const inputs  = document.getElementById('bulkInputs');
  if (checked.length > 0) {
    bar.classList.add('show');
    countEl.textContent = checked.length;
    inputs.innerHTML = [...checked].map(cb => `<input type="hidden" name="selected_ids[]" value="${cb.value}">`).join('');
  } else {
    bar.classList.remove('show');
    inputs.innerHTML = '';
  }
}
function toggleSelectAll(masterCb) {
  document.querySelectorAll('.prod-cb').forEach(cb => { cb.checked = masterCb.checked; });
  onCbChange();
}
function clearSelection() {
  document.querySelectorAll('.prod-cb, #selectAll').forEach(cb => cb.checked=false);
  onCbChange();
}

// ── Toggle product visibility (avoids nested form issue) ────────
function toggleProduct(id, btn) {
  if (!confirm('Toggle visibility for this product?')) return;
  const fd = new FormData();
  fd.append('toggle_active', '1');
  fd.append('product_id', id);
  fetch('products.php', { method:'POST', body:fd })
    .then(r => r.text()).then(() => {
      // Flip the icon and class without page reload
      const icon = document.getElementById('toggle_icon_' + id);
      const row  = document.getElementById('prod_row_' + id);
      if (icon.classList.contains('fa-eye-slash')) {
        icon.classList.replace('fa-eye-slash', 'fa-eye');
        btn.classList.remove('danger');
        btn.title = 'Hide from customers';
        if (row) row.classList.remove('is-hidden');
        showToast('Product is now visible to customers');
      } else {
        icon.classList.replace('fa-eye', 'fa-eye-slash');
        btn.classList.add('danger');
        btn.title = 'Show to customers';
        if (row) row.classList.add('is-hidden');
        showToast('Product hidden from customers');
      }
    }).catch(() => showToast('Action failed — please refresh', 'error'));
}
  Object.assign(window, { openAddModal, openRestock, prodOpenEditModal, zoomImg, startStockEdit, cancelStockEdit, saveStockEdit, onAddCategoryChange, onEditCategoryChange, onCbChange, toggleSelectAll, clearSelection, toggleProduct });
} catch (e) { console.error('Coopers admin:', e); } })();


/* ════════════════════════════════════════════════════════════
   CUSTOMERS
   ════════════════════════════════════════════════════════════ */
(function () { try {
  const PD = pageData();
// ── Customer data for profile drawer ─────────────────────────
const custData = PD.custData || {};

function openProfile(id) {
  const c = custData[id];
  if (!c) return;
  document.getElementById('drawerTitle').textContent = c.name;
  const tag = behavTag(c);

  const ordersHtml = (c._orders||[]).map(o =>
    `<div class="ord-row">
       <span style="font-family:monospace;font-size:11px;">${o.order_number}</span>
       <span>${o.otype === 'walkin' ? '🏪' : '🌐'} ${new Date(o.created_at).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'})}</span>
       <strong style="color:#4A7C59;">₵${parseFloat(o.total_amount).toFixed(2)}</strong>
     </div>`
  ).join('') || '<div style="color:#9ca3af;font-size:13px;padding:8px 0;">No orders yet</div>';

  const daysSince = c.last_order ? Math.floor((Date.now()-new Date(c.last_order))/86400000) : null;
  const freq = c.order_count > 0 && c.created_at
    ? Math.round((Date.now()-new Date(c.created_at))/86400000 / Math.max(c.order_count,1))
    : null;

  document.getElementById('drawerBody').innerHTML = `
    <div style="text-align:center;padding:16px 0 20px;">
      <div style="width:64px;height:64px;border-radius:50%;background:${randomColor(c.name)};color:white;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;margin:0 auto 10px;">
        ${c.name.charAt(0).toUpperCase()}
      </div>
      <strong style="font-size:18px;">${c.name}</strong><br>
      <span style="font-size:12px;color:#9ca3af;">${c.email||'No email'}</span><br>
      <div style="margin-top:8px;">${tag}</div>
    </div>
    <div class="dsec"><h4>Contact</h4>
      <div class="dfield"><span class="lbl">Phone</span><span class="val"><a href="tel:${c.phone||''}" style="color:var(--primary);">${c.phone||'—'}</a></span></div>
      <div class="dfield"><span class="lbl">Email</span><span class="val">${c.email||'—'}</span></div>
      <div class="dfield"><span class="lbl">Location</span><span class="val">${c.location||'—'}</span></div>
      <div class="dfield"><span class="lbl">Joined</span><span class="val">${new Date(c.created_at).toLocaleDateString('en-GB',{dateStyle:'medium'})}</span></div>
    </div>
    <div class="dsec"><h4>Purchase Summary</h4>
      <div class="dfield"><span class="lbl">Total Orders</span><strong class="val">${c.order_count}</strong></div>
      <div class="dfield"><span class="lbl">Total Spent</span><strong class="val" style="color:var(--primary);">₵${parseFloat(c.total_spent||0).toFixed(2)}</strong></div>
      <div class="dfield"><span class="lbl">Walk-ins</span><span class="val">${c.walkin_count||0}</span></div>
      <div class="dfield"><span class="lbl">Online Orders</span><span class="val">${c.online_count||0}</span></div>
      <div class="dfield"><span class="lbl">Last Visit</span><span class="val">${c.last_order ? new Date(c.last_order).toLocaleDateString('en-GB',{dateStyle:'medium'}) + (daysSince!==null?` (${daysSince}d ago)`:'') : 'Never visited'}</span></div>
      ${freq ? `<div class="dfield"><span class="lbl">Avg. Visit Frequency</span><span class="val">Every ~${freq} days</span></div>` : ''}
    </div>
    <div class="dsec"><h4>Recent Orders</h4>${ordersHtml}</div>
    <div style="display:flex;gap:10px;margin-top:20px;">
      <a href="walkin_order.php" class="btn btn-primary" style="flex:1;justify-content:center;"><i class="fas fa-cart-plus"></i> New Order</a>
      ${c.phone ? `<a href="tel:${c.phone}" class="btn btn-secondary" style="flex:1;justify-content:center;"><i class="fas fa-phone"></i> Call</a>` : ''}
    </div>`;

  document.getElementById('drawerOverlay').classList.add('open');
  document.getElementById('profileDrawer').classList.add('open');
}

function closeProfile() {
  document.getElementById('drawerOverlay').classList.remove('open');
  document.getElementById('profileDrawer').classList.remove('open');
}

function randomColor(name) {
  const c = ['#4A7C59','#3b82f6','#E87722','#a855f7','#06b6d4','#ec4899'];
  return c[name.charCodeAt(0) % c.length];
}

function behavTag(c) {
  const spent  = parseFloat(c.total_spent||0);
  const orders = parseInt(c.order_count||0);
  const days   = c.last_order ? (Date.now()-new Date(c.last_order))/86400000 : 9999;
  if (orders===0)    return '<span style="background:#f1f5f9;color:#64748b;padding:2px 9px;border-radius:50px;font-size:11px;font-weight:700;">No Orders Yet</span>';
  if (spent>=5000)   return '<span style="background:#fef3c7;color:#92400e;padding:2px 9px;border-radius:50px;font-size:11px;font-weight:700;">⭐ High Value</span>';
  if (orders>=3)     return '<span style="background:#dcfce7;color:#15803d;padding:2px 9px;border-radius:50px;font-size:11px;font-weight:700;">🔄 Repeat Buyer</span>';
  if (days>60)       return '<span style="background:#fee2e2;color:#991b1b;padding:2px 9px;border-radius:50px;font-size:11px;font-weight:700;">💤 Inactive</span>';
  return '<span style="background:#dcfce7;color:#15803d;padding:2px 9px;border-radius:50px;font-size:11px;font-weight:700;">✓ Active</span>';
}

function custOpenEditModal(c) {
  document.getElementById('editCustId').value       = c.id;
  document.getElementById('editCustName').value     = c.name;
  document.getElementById('editCustPhone').value    = c.phone||'';
  document.getElementById('editCustEmail').value    = c.email||'';
  document.getElementById('editCustLocation').value = c.location||'';
  document.getElementById('editCustModal').classList.add('open');
}

// ── Live search ───────────────────────────────────────────────
function custLiveFilter(val) {
  const q = val.toLowerCase().trim();
  document.querySelectorAll('.cust-row').forEach(row => {
    const name  = row.dataset.name  || '';
    const phone = row.dataset.phone || '';
    const email = row.dataset.email || '';
    row.style.display = (!q || name.includes(q) || phone.includes(q) || email.includes(q)) ? '' : 'none';
  });
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeProfile(); });
  Object.assign(window, { openProfile, closeProfile, randomColor, behavTag, custOpenEditModal, custLiveFilter });
} catch (e) { console.error('Coopers admin:', e); } })();


/* ════════════════════════════════════════════════════════════
   FLOCKS
   ════════════════════════════════════════════════════════════ */
(function () { try {
  const PD = pageData();
// ── Egg chart with time switcher ──────────────────────────────
if (!('allEggData' in PD)) return;   // not the Flocks page
const allEggData = PD.allEggData || [];
let eggChartInst = null;

function buildEggChart(days) {
  const filtered = allEggData.filter(d => {
    const diff = Math.round((Date.now()-new Date(d.d))/86400000);
    return diff <= days;
  });
  const ctx = document.getElementById('eggChart');
  if (!ctx) return;
  if (eggChartInst) eggChartInst.destroy();
  if (!filtered.length) {
    const sub = ctx.closest('.chart-card') && ctx.closest('.chart-card').querySelector('.chart-sub span');
    if (sub) sub.textContent = 'No egg data for this period.';
    return;
  }
  eggChartInst = new Chart(ctx, {
    type:'bar',
    data:{
      labels: filtered.map(d=>new Date(d.d).toLocaleDateString('en-GB',{month:'short',day:'numeric'})),
      datasets:[{
        data: filtered.map(d=>parseInt(d.qty)),
        backgroundColor: filtered.map((_,i)=>i===filtered.length-1?'#4A7C59':'#7EAD6E'),
        borderRadius:5, borderSkipped:false
      }]
    },
    options:{
      responsive:true, maintainAspectRatio:true,
      plugins:{ legend:{display:false}, tooltip:{ backgroundColor:'#1f2937', callbacks:{ label:c=>' '+parseInt(c.parsed.y).toLocaleString()+' eggs' }} },
      scales:{ y:{beginAtZero:true,grid:{color:'#f3f4f6'},ticks:{callback:v=>v>=1000?v/1000+'k':v}}, x:{grid:{display:false}} }
    }
  });
}

function switchChart(days, btn) {
  document.querySelectorAll('.csw-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  buildEggChart(days);
}

buildEggChart(7);

// ── Drawer data ────────────────────────────────────────────────
const flockData = PD.flockData || {};

function flockOpenDrawer(id) {
  const f = flockData[id]; if(!f) return;
  const age = Math.max(0,Math.round((Date.now()-new Date(f.arrival_date))/86400000));
  function ag(d){return d<7?d+'d':d<30?Math.round(d/7)+'w':d<365?Math.round(d/30)+'mo':(d/365).toFixed(1)+'y';}
  const tm={layer:['🐔','#15803d','#dcfce7'],broiler:['🍗','#9a3412','#ffedd5'],cockerel:['🐓','#6b21a8','#f3e8ff'],turkey:['🦃','#374151','#f1f5f9']}[f.bird_type]||['🐦','#374151','#f1f5f9'];
  const mr=f.quantity>0?((f.total_mortality/f.quantity)*100).toFixed(1):0;
  const layR=f.bird_type==='layer'&&f.quantity>0&&f.month_eggs>0?((f.month_eggs/(f.quantity*new Date().getDate()))*100).toFixed(1):null;

  document.getElementById('dName').textContent=f.flock_name;
  document.getElementById('dSub').innerHTML=`<span>${tm[0]} ${f.bird_type.charAt(0).toUpperCase()+f.bird_type.slice(1)}</span><span>·</span><span>${ag(age)} old</span><span>·</span><span>${f.housing_unit||'No housing'}</span>`;
  document.getElementById('dBadges').innerHTML=`<span style="padding:3px 9px;border-radius:50px;font-size:11px;font-weight:700;background:${tm[2]};color:${tm[1]};">${tm[0]} ${f.bird_type.charAt(0).toUpperCase()+f.bird_type.slice(1)}</span>`;

  const vH=(f._vacc||[]).map(v=>`<div class="ditem"><span>${v.vaccine_name}</span><span style="color:#9ca3af;">${new Date(v.date_given).toLocaleDateString('en-GB',{day:'numeric',month:'short'})} · <strong>${v.status}</strong></span></div>`).join('')||'<p style="color:#9ca3af;font-size:13px;">No records</p>';
  const hH=(f._health||[]).map(h=>`<div class="ditem"><span>${h.disease_name||'Health event'}</span><span style="color:#9ca3af;">${new Date(h.record_date).toLocaleDateString('en-GB',{day:'numeric',month:'short'})} · ${h.outcome||h.status}</span></div>`).join('')||'<p style="color:#9ca3af;font-size:13px;">No records</p>';
  const eH=(f._eggs||[]).map(e=>`<div class="ditem"><span>${new Date(e.production_date).toLocaleDateString('en-GB',{weekday:'short',day:'numeric',month:'short'})}</span><strong style="color:#4A7C59;">${parseInt(e.quantity).toLocaleString()} eggs</strong></div>`).join('')||'<p style="color:#9ca3af;font-size:13px;">No egg data</p>';

  document.getElementById('drawerBody').innerHTML=`
    <div class="dkpi-row">
      <div class="dkpi"><strong>${parseInt(f.quantity).toLocaleString()}</strong><span>Birds</span></div>
      <div class="dkpi"><strong style="color:#f59e0b;">${parseInt(f.today_eggs||0).toLocaleString()}</strong><span>Eggs Today</span></div>
      <div class="dkpi"><strong style="color:${mr>2?'#ef4444':'#22c55e'};">${mr}%</strong><span>Mort. Rate</span></div>
    </div>
    ${layR?`<div style="background:#f0fdf4;border-radius:8px;padding:10px 14px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;font-size:13px;"><span>Monthly Lay Rate</span><strong style="font-size:18px;color:${layR>=70?'#15803d':layR>=45?'#d97706':'#dc2626'};">${layR}%</strong></div>`:''}
    <div class="dsec">
      <h4>Flock Details</h4>
      <div class="drow"><span class="lbl">Arrived</span><span class="val">${new Date(f.arrival_date).toLocaleDateString('en-GB',{dateStyle:'medium'})} (${ag(age)} ago)</span></div>
      <div class="drow"><span class="lbl">Source</span><span class="val">${f.source||'—'}</span></div>
      <div class="drow"><span class="lbl">Housing</span><span class="val">${f.housing_unit||'—'}</span></div>
      <div class="drow"><span class="lbl">All-Time Eggs</span><span class="val">${parseInt(f.total_eggs||0).toLocaleString()}</span></div>
      <div class="drow"><span class="lbl">This Month</span><span class="val" style="color:#4A7C59;font-weight:700;">${parseInt(f.month_eggs||0).toLocaleString()} eggs</span></div>
      <div class="drow"><span class="lbl">This Week</span><span class="val">${parseInt(f.week_eggs||0).toLocaleString()} eggs</span></div>
      <div class="drow"><span class="lbl">Total Deaths</span><span class="val" style="color:${mr>2?'#ef4444':'inherit'}">${parseInt(f.total_mortality||0).toLocaleString()} (${mr}%)</span></div>
      <div class="drow"><span class="lbl">Status</span><span class="val">${f.status.charAt(0).toUpperCase()+f.status.slice(1)}</span></div>
      ${f.notes?`<div class="drow"><span class="lbl">Notes</span><span class="val" style="max-width:260px;">${f.notes}</span></div>`:''}
    </div>
    <div class="dsec"><h4>Recent Egg Production</h4>${eH}</div>
    <div class="dsec"><h4>Vaccination History</h4>${vH}</div>
    <div class="dsec"><h4>Health Records</h4>${hH}</div>
    <div class="dgrid">
      <a href="vaccinations.php?flock_id=${f.id}" class="dfact"><i class="fas fa-syringe"></i> Vaccinations</a>
      <a href="health.php?flock_id=${f.id}" class="dfact"><i class="fas fa-heartbeat"></i> Health</a>
      <a href="egg_production.php?flock_id=${f.id}" class="dfact"><i class="fas fa-egg"></i> Egg Log</a>
      <a href="mortality.php?flock_id=${f.id}" class="dfact"><i class="fas fa-skull"></i> Mortality</a>
    </div>`;

  document.getElementById('drawerOverlay').classList.add('open');
  document.getElementById('flockDrawer').classList.add('open');
}
function flockCloseDrawer(){
  document.getElementById('drawerOverlay').classList.remove('open');
  document.getElementById('flockDrawer').classList.remove('open');
}

// ── Modals ─────────────────────────────────────────────────────
function flockOpenEditModal(f){
  document.getElementById('eFID').value=f.id;
  document.getElementById('eFN').value=f.flock_name;
  document.getElementById('eBT').value=f.bird_type;
  document.getElementById('eQT').value=f.quantity;
  document.getElementById('eAD').value=f.arrival_date;
  document.getElementById('eSR').value=f.source||'';
  document.getElementById('eHU').value=f.housing_unit||'';
  document.getElementById('eNT').value=f.notes||'';
  document.getElementById('editFlockModal').classList.add('open');
}
function openStatusModal(id,status){
  document.getElementById('sSFID').value=id;
  const r=document.getElementById('sr_'+status); if(r) r.checked=true;
  document.getElementById('statusModal').classList.add('open');
}

// ── Quick actions menu ──────────────────────────────────────────
function toggleAddMenu(){
  const m=document.getElementById('addMenu');
  m.style.display=m.style.display==='none'?'block':'none';
}
document.addEventListener('click',e=>{
  const btn=document.getElementById('addMenuBtn'), m=document.getElementById('addMenu');
  if(!btn.contains(e.target)&&!m.contains(e.target)) m.style.display='none';
});

// ── Live search ─────────────────────────────────────────────────
function liveSearch(v){
  const q=v.toLowerCase().trim();
  document.querySelectorAll('.flock-row').forEach(r=>{
    r.style.display=!q||r.dataset.name.includes(q)?'':'none';
  });
}

// ── Health filter ───────────────────────────────────────────────
let healthFilterOn=false;
function toggleHealthFilter(){
  healthFilterOn=!healthFilterOn;
  const btn=document.getElementById('healthFilterBtn');
  btn.classList.toggle('active',healthFilterOn);
  document.querySelectorAll('.flock-row').forEach(r=>{
    r.style.display=!healthFilterOn||r.dataset.health==='healthy'?'':'none';
  });
}

// ── Table sort ──────────────────────────────────────────────────
let sortDir={};
function sortTable(col){
  const table=document.getElementById('flockTable');
  const rows=[...table.querySelectorAll('tbody tr.flock-row')];
  const dir=(sortDir[col]||'asc')==='asc'?'desc':'asc';
  sortDir[col]=dir;
  rows.sort((a,b)=>{
    const av=a.cells[col]?.textContent.trim()||'';
    const bv=b.cells[col]?.textContent.trim()||'';
    const an=parseFloat(av.replace(/[^\d.]/g,'')); const bn=parseFloat(bv.replace(/[^\d.]/g,''));
    if(!isNaN(an)&&!isNaN(bn)) return dir==='asc'?an-bn:bn-an;
    return dir==='asc'?av.localeCompare(bv):bv.localeCompare(av);
  });
  const tbody=table.querySelector('tbody');
  rows.forEach(r=>tbody.appendChild(r));
}

document.addEventListener('keydown',e=>{ if(e.key==='Escape') flockCloseDrawer(); });
  Object.assign(window, { buildEggChart, switchChart, flockOpenDrawer, flockCloseDrawer, flockOpenEditModal, openStatusModal, toggleAddMenu, liveSearch, toggleHealthFilter, sortTable });
} catch (e) { console.error('Coopers admin:', e); } })();


/* ════════════════════════════════════════════════════════════
   VACCINATIONS
   ════════════════════════════════════════════════════════════ */
(function () { try {
  const PD = pageData();
// ── Flock history data for drawer ─────────────────────────────
const flockHistory = PD.flockHistory || {};

function openFlockHistory(flockId, flockName) {
  document.getElementById('drawerTitle').textContent = flockName + ' — Vaccination History';
  document.getElementById('histOverlay').classList.add('open');
  document.getElementById('histDrawer').classList.add('open');

  const records = flockHistory[flockId] || [];
  if (!records.length) {
    document.getElementById('drawerBody').innerHTML =
      '<div style="text-align:center;padding:40px;color:#9ca3af;"><i class="fas fa-syringe" style="font-size:32px;color:#e5e7eb;display:block;margin-bottom:12px;"></i>No vaccination records for this flock.</div>';
    return;
  }

  const statusColors = {
    completed: ['#dcfce7','#15803d','✓'],
    scheduled: ['#dbeafe','#1e40af','📅'],
    overdue:   ['#fee2e2','#991b1b','⚠'],
  };

  const completed = records.filter(r => r.status === 'completed');
  const pending   = records.filter(r => r.status !== 'completed');

  let html = '';
  if (pending.length) {
    html += '<div class="dsec"><h4>Pending / Upcoming</h4>';
    pending.forEach(r => {
      const [bg,color,icon] = statusColors[r.status] || ['#f1f5f9','#475569','?'];
      html += `<div class="hist-item">
        <span><strong>${r.vaccine_name}</strong>
          ${r.next_due_date ? ' — due '+new Date(r.next_due_date).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : ''}
        </span>
        <span style="background:${bg};color:${color};padding:2px 8px;border-radius:50px;font-size:10px;font-weight:700;">${icon} ${r.status.charAt(0).toUpperCase()+r.status.slice(1)}</span>
      </div>`;
    });
    html += '</div>';
  }

  if (completed.length) {
    html += '<div class="dsec"><h4>Completed (' + completed.length + ')</h4>';
    completed.forEach(r => {
      html += `<div class="hist-item">
        <div>
          <strong>${r.vaccine_name}</strong>
          <div style="font-size:11px;color:#9ca3af;margin-top:2px;">
            ${r.date_given && r.date_given!=='0000-00-00'
              ? new Date(r.date_given).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'})
              : '—'}
            ${r.birds_vaccinated ? ' · '+parseInt(r.birds_vaccinated).toLocaleString()+' birds' : ''}
            ${r.administered_by ? ' · '+r.administered_by : ''}
          </div>
        </div>
        <span style="color:#22c55e;font-size:11px;font-weight:700;">✓ Done</span>
      </div>`;
    });
    html += '</div>';
  }

  // Quick action buttons
  html += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:16px;">
    <button onclick="document.getElementById('addVaccModal').classList.add('open');vaccCloseDrawer();"
      class="btn btn-primary btn-sm" style="justify-content:center;">
      <i class="fas fa-plus"></i> Add Vaccination
    </button>
    <a href="flocks.php" class="btn btn-secondary btn-sm" style="justify-content:center;">
      <i class="fas fa-kiwi-bird"></i> View Flock
    </a>
  </div>`;

  document.getElementById('drawerBody').innerHTML = html;
}

function vaccCloseDrawer() {
  document.getElementById('histOverlay').classList.remove('open');
  document.getElementById('histDrawer').classList.remove('open');
}

// ── Modals ─────────────────────────────────────────────────────
function openCompleteModal(id, vaccine, flock) {
  document.getElementById('cVaccId').value       = id;
  document.getElementById('cVaccineName').textContent = vaccine;
  document.getElementById('cFlockName').textContent   = flock;
  document.getElementById('completeModal').classList.add('open');
}

function openRescheduleModal(id, vaccine, flock, currentDate) {
  document.getElementById('rVaccId').value       = id;
  document.getElementById('rVaccineName').textContent = vaccine;
  document.getElementById('rFlockName').textContent   = flock;
  document.getElementById('rCurrentDate').textContent = currentDate
    ? new Date(currentDate).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'})
    : 'Not set';
  const tom = new Date(); tom.setDate(tom.getDate()+1);
  document.getElementById('rNewDate').value = tom.toISOString().split('T')[0];
  document.getElementById('reschedModal').classList.add('open');
}

// ── Live search ─────────────────────────────────────────────────
function vaccLiveFilter(val) {
  const q = val.toLowerCase().trim();
  document.querySelectorAll('.vacc-row').forEach(row => {
    row.style.display = !q || row.dataset.search.includes(q) ? '' : 'none';
  });
}

document.addEventListener('keydown', e => { if(e.key==='Escape') vaccCloseDrawer(); });
  Object.assign(window, { openFlockHistory, vaccCloseDrawer, openCompleteModal, openRescheduleModal, vaccLiveFilter });
} catch (e) { console.error('Coopers admin:', e); } })();


/* ════════════════════════════════════════════════════════════
   HEALTH
   ════════════════════════════════════════════════════════════ */
(function () { try {
  const PD = pageData();
const trendData = PD.trendData || [];
// ── Trend chart ───────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  var ctx = document.getElementById('trendChart');
  if (!ctx || typeof trendData === 'undefined') return;

  new Chart(ctx, {
    type: 'line',
    data: {
      labels: trendData.map(function (d) {
        return new Date(d.d).toLocaleDateString('en-GB', { month: 'short', day: 'numeric' });
      }),
      datasets: [{
        data: trendData.map(function (d) { return parseInt(d.cnt); }),
        borderColor: '#ef4444',
        backgroundColor: 'rgba(239,68,68,.08)',
        borderWidth: 2,
        pointBackgroundColor: '#ef4444',
        pointRadius: 4,
        tension: 0.4,
        fill: true
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#1f2937',
          callbacks: {
            label: function (c) { return ' ' + c.parsed.y + ' case' + (c.parsed.y !== 1 ? 's' : ''); }
          }
        }
      },
      scales: {
        y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { stepSize: 1 } },
        x: { grid: { display: false } }
      }
    }
  });
});

// ── Detail drawer ─────────────────────────────────────────────
function openDetailDrawer(r) {
  document.getElementById('detailOverlay').classList.add('open');
  document.getElementById('detailDrawer').classList.add('open');

  var typeEmoji = { disease: '🦠', injury: '🩹', checkup: '🩺', treatment: '💊' };
  var outcomeColors = {
    recovered: ['#dcfce7', '#15803d'],
    died:      ['#fee2e2', '#991b1b'],
    pending:   ['#fef3c7', '#92400e'],
    ongoing:   ['#dbeafe', '#1e40af'],
    improving: ['#f3e8ff', '#6b21a8']
  };
  var oc = outcomeColors[r.outcome] || ['#f1f5f9', '#475569'];
  var birdEmoji = { layer: '🐔', broiler: '🍗', cockerel: '🐓', turkey: '🦃' };
  var healthRate = r.flock_qty > 0
    ? Math.max(0, Math.round(((r.flock_qty - r.affected_count) / r.flock_qty) * 100))
    : null;

  document.getElementById('drawerTitle').textContent =
    (typeEmoji[r.type] || '📋') + ' ' + (r.disease_name || r.type);

  document.getElementById('drawerBody').innerHTML = `
    <div style="background:#f9fafb;border-radius:10px;padding:14px 16px;margin-bottom:16px;display:grid;grid-template-columns:repeat(3,1fr);gap:10px;text-align:center;">
      <div><strong style="display:block;font-size:20px;font-weight:700;color:#ef4444;">${parseInt(r.affected_count).toLocaleString()}</strong><span style="font-size:10px;color:#9ca3af;text-transform:uppercase;">Affected</span></div>
      <div><strong style="display:block;font-size:20px;font-weight:700;">${healthRate !== null ? healthRate + '%' : '—'}</strong><span style="font-size:10px;color:#9ca3af;text-transform:uppercase;">Health Rate</span></div>
      <div><strong style="display:block;font-size:20px;font-weight:700;background:${oc[0]};color:${oc[1]};padding:2px 8px;border-radius:6px;">${(r.outcome || 'pending').charAt(0).toUpperCase() + (r.outcome || 'pending').slice(1)}</strong><span style="font-size:10px;color:#9ca3af;text-transform:uppercase;">Outcome</span></div>
    </div>

    <div style="margin-bottom:18px;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:8px;padding-bottom:5px;border-bottom:1px solid #f3f4f6;">Record Details</div>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f9fafb;font-size:13px;"><span style="color:#6b7280;">Flock</span><strong>${r.flock_name} (${(birdEmoji[r.bird_type] || '') + ' ' + r.bird_type})</strong></div>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f9fafb;font-size:13px;"><span style="color:#6b7280;">Date</span><span>${new Date(r.record_date).toLocaleDateString('en-GB', { dateStyle: 'medium' })}</span></div>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f9fafb;font-size:13px;"><span style="color:#6b7280;">Type</span><span>${(typeEmoji[r.type] || '') + ' ' + r.type.charAt(0).toUpperCase() + r.type.slice(1)}</span></div>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f9fafb;font-size:13px;"><span style="color:#6b7280;">Status</span><span style="font-weight:700;">${r.status.charAt(0).toUpperCase() + r.status.slice(1)}</span></div>
      <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:13px;"><span style="color:#6b7280;">Treated By</span><span>${r.treated_by || '—'}</span></div>
    </div>

    ${r.symptoms ? `
    <div style="margin-bottom:14px;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:6px;">Symptoms</div>
      <div style="background:#fafafa;border-radius:8px;padding:10px 12px;font-size:13px;color:#374151;">${r.symptoms}</div>
    </div>` : ''}

    ${r.treatment ? `
    <div style="margin-bottom:14px;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:6px;">Treatment Given</div>
      <div style="background:#fafafa;border-radius:8px;padding:10px 12px;font-size:13px;color:#374151;">${r.treatment}</div>
    </div>` : ''}

    ${r.outcome === 'died' ? `
    <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;color:#991b1b;margin-bottom:14px;">
      <i class="fas fa-skull"></i> <strong>${parseInt(r.affected_count).toLocaleString()} deaths auto-logged to Mortality</strong>
      <br><a href="mortality.php" style="color:#991b1b;font-weight:700;font-size:12px;">View mortality log →</a>
    </div>` : ''}

    ${r.notes ? `
    <div style="margin-bottom:14px;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:6px;">Notes</div>
      <div style="background:#fafafa;border-radius:8px;padding:10px 12px;font-size:13px;color:#374151;">${r.notes}</div>
    </div>` : ''}

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:16px;">
      <button onclick="openUpdateModal(${r.id},'${r.outcome}','${r.status}');healthCloseDrawer();"
        class="btn btn-primary btn-sm" style="justify-content:center;">
        <i class="fas fa-edit"></i> Update Record
      </button>
      <a href="flocks.php" class="btn btn-secondary btn-sm" style="justify-content:center;">
        <i class="fas fa-kiwi-bird"></i> View Flock
      </a>
    </div>`;
}

function healthCloseDrawer() {
  document.getElementById('detailOverlay').classList.remove('open');
  document.getElementById('detailDrawer').classList.remove('open');
}

// ── Modals ─────────────────────────────────────────────────────
function openUpdateModal(id, outcome, status) {
  document.getElementById('uRecordId').value = id;
  document.getElementById('uOutcome').value  = outcome;
  document.getElementById('uStatus').value   = status;
  checkDiedOutcome(document.getElementById('uOutcome'));
  document.getElementById('updateModal').classList.add('open');
}

function checkDiedOutcome(sel) {
  var warn = document.getElementById('updateMortWarning');
  if (warn) warn.style.display = sel.value === 'died' ? 'block' : 'none';
  // Auto-set status to closed when recovered
  var statusSel = document.getElementById('uStatus');
  if (statusSel && (sel.value === 'recovered' || sel.value === 'died')) statusSel.value = 'completed';
}

// ── Add form outcome warning ───────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  var addOutcome = document.getElementById('addOutcome');
  var warn       = document.getElementById('addMortalityWarning');
  if (addOutcome && warn) {
    addOutcome.addEventListener('change', function () {
      warn.style.display = this.value === 'died' ? 'block' : 'none';
    });
  }
});

// ── Live filter ────────────────────────────────────────────────
function healthLiveFilter(val) {
  var q = val.toLowerCase().trim();
  document.querySelectorAll('.health-row').forEach(function (row) {
    row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
  });
}
  Object.assign(window, { openDetailDrawer, healthCloseDrawer, openUpdateModal, checkDiedOutcome, healthLiveFilter });
} catch (e) { console.error('Coopers admin:', e); } })();


/* ════════════════════════════════════════════════════════════
   INDEX
   ════════════════════════════════════════════════════════════ */
(function () { try {
  const PD = pageData();
const allSalesData = PD.salesChart || [];
const eggData = PD.eggChart || [];
let salesChartObj  = null;

function formatDate(str, opts) {
  return new Date(str).toLocaleDateString('en-GB', opts || {month:'short',day:'numeric'});
}

function buildSalesChart(days) {
  const cutoff  = new Date();
  cutoff.setDate(cutoff.getDate() - days);
  const filtered = allSalesData.filter(d => new Date(d.date) >= cutoff);

  const labels = filtered.map(d => formatDate(d.date));
  const values = filtered.map(d => parseFloat(d.amount));

  if (salesChartObj) { salesChartObj.destroy(); }

  const ctx = document.getElementById('salesChart');
  if (!ctx) return;
  salesChartObj = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Revenue (₵)',
        data: values,
        borderColor: '#4A7C59',
        backgroundColor: 'rgba(74,124,89,0.08)',
        fill: true,
        tension: 0.4,
        pointRadius: 3,
        pointHoverRadius: 6,
        pointBackgroundColor: '#4A7C59',
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#1f2937',
          padding: 10,
          callbacks: {
            label: ctx => ' ₵' + parseFloat(ctx.parsed.y).toFixed(2),
            title: t  => t[0].label
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: { color: '#f3f4f6' },
          ticks: { callback: v => '₵' + (v >= 1000 ? (v/1000).toFixed(1)+'k' : v) }
        },
        x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } }
      }
    }
  });
}

function switchPeriod(btn, days) {
  document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  buildSalesChart(days);
}

// Build egg chart
const eCtx = document.getElementById('eggChart');
if (eCtx && eggData.length > 0) {
  new Chart(eCtx, {
    type: 'bar',
    data: {
      labels: eggData.map(d => formatDate(d.date, {month:'short',day:'numeric'})),
      datasets: [{
        data: eggData.map(d => parseInt(d.qty)),
        backgroundColor: eggData.map((d,i) => i === eggData.length-1 ? '#4A7C59' : '#7EAD6E'),
        borderRadius: 6,
        borderSkipped: false
      }]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#1f2937',
          padding: 10,
          callbacks: { label: c => ' ' + parseInt(c.parsed.y).toLocaleString() + ' eggs' }
        }
      },
      scales: {
        y: { beginAtZero: true, grid: { color: '#f3f4f6' } },
        x: { grid: { display: false } }
      }
    }
  });
}

// Initialise sales chart on load
buildSalesChart(30);
  Object.assign(window, { formatDate, buildSalesChart, switchPeriod });
} catch (e) { console.error('Coopers admin:', e); } })();


/* ════════════════════════════════════════════════════════════
   EGG_PRODUCTION — crate lines by type and size; trend chart; log and edit
   ════════════════════════════════════════════════════════════ */
(function () { try {
  var PD = pageData();
  var series = PD.series || [], records = PD.records || [], products = PD.products || [];
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var localKey = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
  var fmtDay = function (k) { return new Date(k + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }); };
  var el = function (id) { return document.getElementById(id); };
  var num = function (id) { var v = parseInt(el(id).value, 10); return isNaN(v) ? 0 : v; };
  var SIZE_NAMES = { small: 'Small', medium: 'Medium', large: 'Large' };
  var TYPE_NAMES = { 30: 'Standard crate (30)', 15: 'Half crate (15)', 12: 'Tray (12)' };

  // The egg product for a crate type and size, or null
  function productFor(units, size) {
    for (var i = 0; i < products.length; i++) {
      if (products[i].units === units && products[i].size === size) return products[i];
    }
    return null;
  }

  // ── Trend chart ────────────────────────────────────────────
  var metricSel = el('eggMetric'), flockSel = el('eggFlock'), canvas = el('eggTrend');
  var rangeBtns = document.querySelectorAll('#eggRange .period-btn');
  var chart = null, days = 30;

  function buildChart() {
    if (!canvas || !window.Chart) return;
    var metric = metricSel.value, fid = parseInt(flockSel.value, 10) || 0;
    var byDay = {};
    series.forEach(function (r) {
      if (fid && r.fid !== fid) return;
      var a = byDay[r.d] || (byDay[r.d] = { e: 0, h: 0, b: 0, r: 0 });
      a.e += +r.e; a.h += +r.h; a.b += +r.b; a.r += +r.r;
    });
    var labels = [], vals = [], today = new Date(); today.setHours(0, 0, 0, 0);
    for (var i = days - 1; i >= 0; i--) {
      var d = new Date(today); d.setDate(d.getDate() - i);
      var k = localKey(d), a = byDay[k] || { e: 0, h: 0, b: 0, r: 0 }, v;
      labels.push(fmtDay(k));
      if (metric === 'eggs') v = a.e;
      else if (metric === 'good') v = a.e - a.b - a.r;
      else if (metric === 'hdep') v = a.h > 0 ? +(a.e / a.h * 100).toFixed(1) : null;
      else v = a.e > 0 ? +((a.b + a.r) / a.e * 100).toFixed(2) : null;
      vals.push(v);
    }
    var isBar = metric === 'eggs' || metric === 'good';
    if (chart) chart.destroy();
    chart = new Chart(canvas, {
      type: isBar ? 'bar' : 'line',
      data: { labels: labels, datasets: [{
        label: metricSel.options[metricSel.selectedIndex].text, data: vals,
        backgroundColor: isBar ? '#7EAD6E' : 'rgba(74,124,89,.12)',
        borderColor: metric === 'break' ? '#ef4444' : '#4A7C59', borderWidth: 2,
        fill: !isBar, tension: .35, pointRadius: days > 30 ? 0 : 3, borderRadius: 4, spanGaps: true }] },
      options: { responsive: true, plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, grid: { color: '#f3f4f6' } },
                  x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } } } }
    });
  }
  if (metricSel && flockSel) {
    metricSel.addEventListener('change', buildChart);
    flockSel.addEventListener('change', buildChart);
    rangeBtns.forEach(function (b) {
      b.addEventListener('click', function () {
        rangeBtns.forEach(function (x) { x.classList.remove('active'); });
        b.classList.add('active'); days = parseInt(b.dataset.days, 10); buildChart();
      });
    });
    buildChart();
  }

  // ── Log / edit modal ───────────────────────────────────────
  var modal = el('eggModal'), submit = el('eSubmit'), warn = el('eWarn'), lines = el('crateLines');
  if (!modal) return;

  // One crate line: type, size and count
  function addLine(units, size, count) {
    var row = document.createElement('div');
    row.className = 'crate-line';
    var typeOpts = [30, 15, 12].map(function (u) {
      return '<option value="' + u + '"' + (u === units ? ' selected' : '') + '>' + TYPE_NAMES[u] + '</option>';
    }).join('');
    var sizeOpts = ['small', 'medium', 'large'].map(function (s) {
      return '<option value="' + s + '"' + (s === size ? ' selected' : '') + '>' + SIZE_NAMES[s] + '</option>';
    }).join('');
    row.innerHTML =
      '<select name="crate_units[]" class="form-control cl-type">' + typeOpts + '</select>' +
      '<select name="crate_size[]" class="form-control cl-size">' + sizeOpts + '</select>' +
      '<input type="number" name="crate_count[]" class="form-control cl-count" min="0" value="' + (count || 0) + '">' +
      '<button type="button" class="cl-remove" title="Remove line"><i class="fas fa-times"></i></button>';
    row.querySelectorAll('select, input').forEach(function (f) { f.addEventListener('input', recalc); f.addEventListener('change', recalc); });
    row.querySelector('.cl-remove').addEventListener('click', function () {
      if (lines.querySelectorAll('.crate-line').length > 1) { row.remove(); recalc(); }
    });
    lines.appendChild(row);
  }

  // Each line as {units, size, count}
  function readLines() {
    return Array.prototype.map.call(lines.querySelectorAll('.crate-line'), function (row) {
      return {
        units: parseInt(row.querySelector('.cl-type').value, 10),
        size: row.querySelector('.cl-size').value,
        count: parseInt(row.querySelector('.cl-count').value, 10) || 0
      };
    });
  }

  // Total eggs = crate eggs + broken + rejected
  function totals() {
    var packed = 0, missing = [];
    readLines().forEach(function (ln) {
      if (ln.count <= 0) return;
      packed += ln.units * ln.count;
    });
    var b = num('eBroken'), r = num('eRej');
    return { packed: packed, broken: b, rej: r, eggs: packed + b + r, missing: missing };
  }

  function check() {
    var fsel = el('eFlock'), opt = fsel.options[fsel.selectedIndex];
    var hasFlock = !!(opt && opt.value);
    var qty = hasFlock ? parseInt(opt.dataset.qty, 10) : null;
    var arrival = hasFlock ? opt.dataset.arrival : null;
    var t = totals(), hens = num('eHens');
    var today = localKey(new Date()), date = el('eDate').value, errs = [];

    if (!hasFlock) errs.push('Choose a laying flock.');
    if (date && date > today) errs.push('The date cannot be in the future.');
    if (arrival && date && date < arrival) errs.push('That date is before this flock arrived.');
    if (qty !== null && (hens < 1 || hens > qty)) errs.push('Hens present must be between 1 and ' + qty.toLocaleString() + '.');
    if (t.packed < 1) errs.push('Add at least one crate.');
    if (t.eggs > hens) errs.push('Eggs (' + t.eggs + ') cannot be more than hens present (' + hens + '). A hen lays at most one egg a day.');

    el('lTotal').textContent = t.eggs.toLocaleString();
    el('lHdep').textContent = hens > 0 && t.eggs > 0 ? (t.eggs / hens * 100).toFixed(1) + '%' : '—';
    el('lBreak').textContent = t.eggs > 0 ? ((t.broken + t.rej) / t.eggs * 100).toFixed(2) + '%' : '—';

    var parts = readLines().filter(function (ln) { return ln.count > 0; }).map(function (ln) {
      var p = productFor(ln.units, ln.size);
      return ln.count + ' × ' + (p ? p.name : (SIZE_NAMES[ln.size] + ' eggs, ' + TYPE_NAMES[ln.units] + ' — new product will be created'));
    });
    el('ePack').textContent = parts.length ? 'Adds to stock: ' + parts.join(', ') + '.' : 'Choose the crate type, size and count. Each goes straight into stock.';

    warn.style.display = errs.length ? 'block' : 'none';
    warn.textContent = errs[0] || '';
    submit.disabled = errs.length > 0;
  }

  function recalc() { check(); }

  function onFlockChange() {
    var opt = el('eFlock').options[el('eFlock').selectedIndex];
    if (opt && opt.value) {
      if (el('eRid').value === '0') el('eHens').value = opt.dataset.qty;   // new record: default to flock size
      el('eHens').max = opt.dataset.qty;
      el('eDate').min = opt.dataset.arrival;
    }
    check();
  }

  window.openEggModal = function (id) {
    var r = id ? records.filter(function (x) { return x.id === id; })[0] : null;
    el('eRid').value = r ? r.id : '0';
    el('eFlock').value = r ? String(r.flock_id) : '';
    el('eDate').value = r ? r.date : localKey(new Date());
    el('eHens').value = r ? r.hens : '';
    el('eBroken').value = r ? r.broken : 0;
    el('eRej').value = r ? r.rejected : 0;
    el('eNotes').value = r ? r.notes : '';
    el('eggTitle').innerHTML = '<i class="fas fa-egg" style="color:#f59e0b;margin-right:8px;"></i>' + (r ? 'Edit production' : 'Log egg production');
    lines.innerHTML = '';
    if (r && r.lines && r.lines.length) {
      r.lines.forEach(function (ln) { addLine(ln.units, ln.size, ln.count); });
    } else {
      addLine(30, 'large', 0);
    }
    if (!r) onFlockChange(); else check();
    modal.classList.add('open');
  };
  window.closeEggModal = function () { modal.classList.remove('open'); };

  el('eFlock').addEventListener('change', onFlockChange);
  ['eDate', 'eHens', 'eBroken', 'eRej'].forEach(function (id) { el(id).addEventListener('input', check); });
  el('addLineBtn').addEventListener('click', function () { addLine(30, 'large', 0); recalc(); });

  document.addEventListener('click', function (e) {
    var open = e.target.closest && e.target.closest('[data-open-egg]');
    if (open) { window.openEggModal(parseInt(open.dataset.openEgg, 10) || 0); return; }
    if (e.target.closest && e.target.closest('[data-close-egg]')) { window.closeEggModal(); return; }
    var row = e.target.closest && e.target.closest('tr.rec-row');
    if (row) window.openEggModal(parseInt(row.dataset.edit, 10));
  });
  document.querySelectorAll('.egg-filters select').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });
} catch (e) { console.error('Coopers admin:', e); } })();

/* ════════════════════════════════════════════════════════════
   HEALTH — outcome drives status (died/recovered → completed)
   ════════════════════════════════════════════════════════════ */
(function () { try {
  var statusSel = document.getElementById('uStatus');
  var outcomeSel = document.getElementById('uOutcome');
  if (!outcomeSel) return;
  var prev = outcomeSel.onchange;
  outcomeSel.addEventListener('change', function () {
    if (statusSel && (this.value === 'died' || this.value === 'recovered')) statusSel.value = 'completed';
  });
} catch (e) { console.error('Coopers admin:', e); } })();

/* ════════════════════════════════════════════════════════════
   ORDERS WORKFLOW — accept / reject / process / deliver
   ════════════════════════════════════════════════════════════ */
(function () { try {
  var LABEL = { confirmed:'accepted', processing:'being packed', out_for_delivery:'out for delivery',
                delivered:'delivered', cancelled:'cancelled' };
  function post(id, status, reason) {
    var fd = new FormData();
    fd.append('update_status', '1'); fd.append('order_id', id);
    fd.append('status', status); fd.append('ajax', '1');
    if (reason) fd.append('reason', reason);
    return fetch('orders.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }
  window.orderAction = function (id, status) {
    var reason = '';
    if (status === 'cancelled') {
      reason = window.prompt('Reason for rejecting or cancelling this order (required):', '');
      if (reason === null) return;
      reason = reason.trim();
      if (!reason) { showToast('A reason is required to cancel an order', 'warning'); return; }
    }
    post(id, status, reason).then(function (d) {
      if (!d.ok) { showToast(d.error || 'Could not update the order', 'error'); return; }
      showToast('Order ' + (LABEL[status] || status), 'success');
      setTimeout(function () { location.reload(); }, 600);
    }).catch(function () { showToast('Network error — order not updated', 'error'); });
  };
  // Replaces the dropdown handler: reverts the dropdown if the move is not allowed
  window.inlineStatusUpdate = function (id, sel) {
    var prev = sel.dataset.prev || sel.value;
    var next = sel.value;
    if (next === 'cancelled') return window.orderAction(id, next);
    post(id, next, '').then(function (d) {
      if (!d.ok) { showToast(d.error || 'Not allowed', 'error'); sel.value = prev; return; }
      showToast('Order updated', 'success');
      setTimeout(function () { location.reload(); }, 600);
    }).catch(function () { showToast('Network error', 'error'); sel.value = prev; });
  };
} catch (e) { console.error('Coopers admin:', e); } })();

/* ════════════════════════════════════════════════════════════
   MORTALITY — live checks against birds alive, trend chart
   ════════════════════════════════════════════════════════════ */
(function () { try {
  var sel = document.getElementById('mFlock');
  if (!sel) return;                       // not on the mortality page
  var PD = pageData();
  var flocks = PD.flocks || [];
  var threshold = PD.threshold || 5;
  var cnt = document.getElementById('mCount'), date = document.getElementById('mDate'),
      cause = document.getElementById('mCause'), other = document.getElementById('mCauseOtherWrap'),
      submit = document.getElementById('mSubmit'), alive = document.getElementById('mAliveNum'),
      after = document.getElementById('mAfterNum'), hint = document.getElementById('mCountHint'),
      dhint = document.getElementById('mDateHint'), warn = document.getElementById('mWarn');

  function fmtDate(d) { return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day:'numeric', month:'short', year:'numeric' }); }
  function current() { var id = parseInt(sel.value, 10); return flocks.find(function (f) { return f.id === id; }) || null; }
  function setWarn(msg) { warn.textContent = msg || ''; warn.style.display = msg ? 'block' : 'none'; submit.disabled = !!msg; }

  function validate() {
    var f = current();
    if (!f) {
      alive.textContent = '—'; after.textContent = '—'; hint.textContent = 'Choose a flock first';
      dhint.textContent = ''; setWarn(''); submit.disabled = true; return;
    }
    var n = parseInt(cnt.value, 10);
    cnt.max = f.qty; date.min = f.arrival;
    alive.textContent = f.qty.toLocaleString();
    hint.textContent = 'At most ' + f.qty.toLocaleString() + ' (birds alive)';
    dhint.textContent = 'Between ' + fmtDate(f.arrival) + ' and today';
    var left = isNaN(n) ? f.qty : f.qty - n;
    after.textContent = Math.max(0, left).toLocaleString();
    var d = date.value, today = new Date().toISOString().slice(0, 10);
    if (isNaN(n) || cnt.value === '') return setWarn('');
    if (n < 1)            return setWarn('Enter at least 1 death.');
    if (n > f.qty)        return setWarn('Only ' + f.qty.toLocaleString() + ' birds are alive in ' + f.name + '. You cannot log ' + n.toLocaleString() + ' deaths.');
    if (d && d > today)   return setWarn('The date cannot be in the future.');
    if (d && d < f.arrival) return setWarn(f.name + ' arrived on ' + fmtDate(f.arrival) + '. The date cannot be earlier.');
    setWarn('');
    if (n >= threshold) dhint.textContent = 'Heavy loss (' + n + ' birds) — admins will be emailed.';
  }

  cause.addEventListener('change', function () { other.style.display = cause.value === 'Other' ? 'block' : 'none'; });
  [sel, cnt, date].forEach(function (el) { el.addEventListener('input', validate); el.addEventListener('change', validate); });

  window.openMortModal = function (flockId) {
    if (flockId) sel.value = String(flockId);
    cnt.value = '';
    validate();
    document.getElementById('mortModal').classList.add('open');
  };
  window.closeMortModal = function () { document.getElementById('mortModal').classList.remove('open'); };
  validate();

  var ctx = document.getElementById('mortTrend');
  if (ctx && window.Chart && PD.trend) {
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: PD.trend.map(function (t) { return new Date(t.d + 'T00:00:00').toLocaleDateString('en-GB', { day:'numeric', month:'short' }); }),
        datasets: [{ data: PD.trend.map(function (t) { return t.c; }),
          backgroundColor: PD.trend.map(function (t) { return t.c >= threshold ? '#ef4444' : '#fca5a5'; }),
          borderRadius: 4 }]
      },
      options: {
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + c.parsed.y + ' death(s)'; } } } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f3f4f6' } }, x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } } }
      }
    });
  }
} catch (e) { console.error('Coopers admin:', e); } })();

/* ════════════════════════════════════════════════════════════
   FLOCKS — house insight drawer
   ════════════════════════════════════════════════════════════ */
(function () { try {
  if (!document.getElementById('houseDrawer')) return;
  var houses = (pageData().houses) || [];
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }
  function tile(label, value, sub, clr) {
    return '<div class="hd-tile"><span class="hd-lbl">' + label + '</span><strong style="color:' + (clr || 'inherit') + '">' + value + '</strong><small>' + sub + '</small></div>';
  }
  window.openHouseDrawer = function (idx) {
    var h = houses[idx]; if (!h) return;
    var lay = h.lay === null || h.lay === undefined ? '—' : h.lay + '%';
    var mortClr  = h.mortality > 5 ? '#ef4444' : (h.mortality > 2 ? '#f59e0b' : '#15803d');
    var healthClr = h.health >= 95 ? '#15803d' : (h.health >= 80 ? '#b45309' : '#ef4444');
    var rows = (h.flockRows || []).map(function (f) {
      return '<tr><td><strong>' + esc(f.name) + '</strong><br><small>' + esc(f.type) + '</small></td>' +
             '<td>' + Number(f.qty).toLocaleString() + '</td><td>' + Number(f.vaccinated).toLocaleString() + '</td>' +
             '<td>' + f.deaths30 + '</td><td>' + (f.lay7 === null ? '—' : f.lay7 + '%') + '</td><td>' + f.eggs7 + '</td></tr>';
    }).join('');
    document.getElementById('houseTitle').textContent = 'House ' + h.name;
    document.getElementById('houseBody').innerHTML =
      '<div class="hd-grid">' +
        tile('Birds', Number(h.birds).toLocaleString(), h.flocks + ' flock(s) · ' + h.utilisation + '% of est. capacity') +
        tile('Vaccinated', h.coverage + '%', Number(h.vaccinated).toLocaleString() + ' birds · ' + h.vOverdue + ' overdue · ' + h.vDue7 + ' due in 7 days') +
        tile('Mortality · 30d', h.mortality + '%', h.deaths30 + ' deaths', mortClr) +
        tile('Health', h.health + '%', h.healthCases + ' open case(s) · ' + h.affected + ' birds affected', healthClr) +
        tile('Lay rate · 7d', lay, 'eggs per live hen per logged day') +
        tile('Eggs · 30d', Number(h.eggs30).toLocaleString(), h.eggsPerDay + ' per day on average') +
        tile('Feed · 30d', Number(h.feed30).toLocaleString() + ' kg', h.feedPerBird + ' kg per bird · ₵' + Number(h.feedCost30).toLocaleString()) +
      '</div>' +
      '<div class="hd-sec">Flocks in this house</div>' +
      '<table class="data-table hd-table"><thead><tr><th>Flock</th><th>Birds</th><th>Vaccinated</th><th>Deaths 30d</th><th>Lay 7d</th><th>Eggs 7d</th></tr></thead><tbody>' + rows + '</tbody></table>' +
      '<div class="hd-note">Vaccinated = highest single-round count per flock, capped at flock size. Capacity is an estimate (600) until houses have a capacity field.</div>';
    document.getElementById('houseOverlay').classList.add('open');
    document.getElementById('houseDrawer').classList.add('open');
  };
  window.closeHouseDrawer = function () {
    document.getElementById('houseOverlay').classList.remove('open');
    document.getElementById('houseDrawer').classList.remove('open');
  };
} catch (e) { console.error('Coopers admin:', e); } })();

/* ════════════════════════════════════════════════════════════
   VACCINATIONS — a round is Completed only at 100%; limits use birds left
   ════════════════════════════════════════════════════════════ */
(function () { try {
  var PD = pageData();
  var rows = PD.vaccRow || {}, progress = PD.progress || {};
  function doneFor(fid, vaccine) {
    return progress[fid + '|' + String(vaccine || '').trim().toLowerCase()] || 0;
  }
  function isLotBlocked(btn) {
    var o = btn.dataset.lotOver ? document.getElementById(btn.dataset.lotOver) : null;
    return !!(btn.dataset.lotBlocked && o && !o.checked);
  }
  function setLimit(input, hint, btn, remaining, n, qty, done, labelFull, labelPartial, labelDefault) {
    input.max = remaining;
    if (remaining === 0) {
      hint.textContent = 'Every bird in this batch is already covered for this round.';
      hint.style.color = '#991b1b'; if (btn) btn.disabled = true; return;
    }
    if (!isNaN(n) && n > remaining) {
      hint.textContent = 'Only ' + remaining.toLocaleString() + ' birds are left to vaccinate. You entered ' + n.toLocaleString() + '.';
      hint.style.color = '#991b1b'; if (btn) btn.disabled = true; return;
    }
    hint.textContent = 'Max ' + remaining.toLocaleString() + ' birds left in this round';
    hint.style.color = '';
    if (btn) {
      btn.disabled = isLotBlocked(btn);
      if (!isNaN(n) && n > 0 && n === remaining) btn.innerHTML = labelFull;
      else if (!isNaN(n) && n > 0) btn.innerHTML = labelPartial(Math.round(((done + n) / qty) * 100));
      else btn.innerHTML = labelDefault;
    }
  }

  // ── Record Vaccination
  var sel = document.getElementById('vFlock'), vName = document.getElementById('vVaccine'),
      vBirds = document.getElementById('vBirds'), info = document.getElementById('vBatchInfo'),
      vHint = document.getElementById('vBirdsHint'), vBtn = document.getElementById('vSubmit');
  var recordLabels = {
    full: '<i class="fas fa-check-circle"></i> Save — completes the round (100%)',
    partial: function (p) { return '<i class="fas fa-save"></i> Save partial (' + p + '%)'; },
    def: '<i class="fas fa-save"></i> Save Record'
  };
  function refreshRecord() {
    if (!sel || !vBirds) return;
    var fid = parseInt(sel.value, 10), opt = sel.options[sel.selectedIndex];
    var qty = opt ? parseInt(opt.getAttribute('data-qty'), 10) : NaN;
    var vn = vName ? vName.value.trim() : '';
    if (isNaN(fid) || isNaN(qty)) {
      info.textContent = 'Choose a batch to see its birds'; vBirds.removeAttribute('max');
      vHint.textContent = ''; return;
    }
    var done = vn ? doneFor(fid, vn) : 0;
    var remaining = Math.max(0, qty - done);
    info.innerHTML = 'This batch has <strong>' + qty.toLocaleString() + '</strong> birds'
      + (vn ? ' · <strong>' + done.toLocaleString() + '</strong> already vaccinated this round (' + Math.round(done / qty * 100) + '%) · <strong>' + remaining.toLocaleString() + '</strong> left' : '');
    setLimit(vBirds, vHint, vBtn, remaining, parseInt(vBirds.value, 10), qty, done,
      recordLabels.full, recordLabels.partial, recordLabels.def);
  }
  if (sel && vBirds) {
    sel.addEventListener('change', refreshRecord);
    if (vName) vName.addEventListener('input', refreshRecord);
    vBirds.addEventListener('input', refreshRecord);
    refreshRecord();
  }
  // "Add birds": open the form for this round and fill it from the last log
  var lastLog = PD.lastLog || {};
  function setField(id, value) {
    var el = document.getElementById(id);
    if (el) el.value = (value === null || value === undefined) ? '' : String(value);
  }
  function fmtDate(d) {
    return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day:'numeric', month:'short', year:'numeric' }) : '';
  }
  window.openRecordFor = function (flockId, vaccine) {
    if (!sel) return;
    sel.value = String(flockId);
    if (vName) vName.value = vaccine;
    vBirds.value = '';
    var log = lastLog[flockId + '|' + String(vaccine).trim().toLowerCase()];
    setField('vNext', log ? log.next_due_date : '');
    setField('vLot', log ? log.lot_number : '');
    setField('vExpiry', log ? log.lot_expiry : '');
    setField('vDosage', log ? log.dosage : '');
    setField('vMethod', log ? log.method : '');
    var note = document.getElementById('vPrefillNote');
    if (note) note.textContent = log
      ? 'Filled from the last log (' + fmtDate(log.date_given) + '). Change any field that is different.'
      : 'No previous log for this vaccine — enter the lot and dates.';
    refreshRecord();
    document.getElementById('addVaccModal').classList.add('open');
  };

  // ── Mark Completed: remaining birds for this dose's round
  var cBirds = document.getElementById('cBirds'), cHint = document.getElementById('cBirdsHint'),
      cLabel = document.getElementById('cBatchBirds'), cBtn = document.getElementById('cSubmit');
  var current = null;   // {qty, done, remaining}
  function refreshComplete() {
    if (!cBirds || !current) return;
    cLabel.innerHTML = '<strong>' + current.qty.toLocaleString() + '</strong> birds · <strong>'
      + current.done.toLocaleString() + '</strong> done this round · <strong>' + current.remaining.toLocaleString() + '</strong> left';
    setLimit(cBirds, cHint, cBtn, current.remaining, parseInt(cBirds.value, 10), current.qty, current.done,
      '<i class="fas fa-check"></i> Mark Completed — completes the round (100%)',
      function (p) { return '<i class="fas fa-check"></i> Save partial (' + p + '%)'; },
      '<i class="fas fa-check"></i> Mark Completed');
  }
  if (cBirds) cBirds.addEventListener('input', refreshComplete);

  var openComplete = window.openCompleteModal;
  if (openComplete) {
    window.openCompleteModal = function (id, vaccine, flock) {
      openComplete(id, vaccine, flock);
      var row = rows[id];
      if (row) {
        var done = doneFor(row.f, row.v);
        current = { qty: row.q, done: done, remaining: Math.max(0, row.q - done) };
        cBirds.value = '';
        cBirds.max = current.remaining;
        refreshComplete();
      } else { current = null; if (cLabel) cLabel.textContent = '—'; }
    };
  }
} catch (e) { console.error('Coopers admin:', e); } })();

/* ════════════════════════════════════════════════════════════
   VACCINE LOT EXPIRY — flag within 30 days; expired or due today needs confirmation
   ════════════════════════════════════════════════════════════ */
(function () { try {
  function daysUntil(d) {
    if (!d) return null;
    var t = new Date(); t.setHours(0, 0, 0, 0);
    return Math.round((new Date(d + 'T00:00:00') - t) / 86400000);
  }
  function attach(expId, flagId, overId, submitId) {
    var exp = document.getElementById(expId);
    if (!exp) return;
    var flag = document.getElementById(flagId), over = document.getElementById(overId),
        wrap = over ? over.parentNode : null, btn = document.getElementById(submitId);
    if (btn) btn.dataset.lotOver = overId;
    function refresh() {
      var n = daysUntil(exp.value);
      flag.style.display = 'none'; flag.className = 'lot-flag';
      if (n === null) {
        if (wrap) wrap.style.display = 'none';
        if (btn) { delete btn.dataset.lotBlocked; btn.disabled = false; }
        return;
      }
      if (n <= 0) {
        flag.className = 'lot-flag lot-expired';
        flag.textContent = n === 0
          ? 'This lot expires today. Tick "Use this lot anyway" to record it.'
          : 'This lot expired ' + (-n) + ' day' + (n === -1 ? '' : 's') + ' ago. Tick "Use this lot anyway" to record it.';
        flag.style.display = 'block';
        if (wrap) wrap.style.display = 'flex';
        if (btn) btn.dataset.lotBlocked = '1';
      } else {
        if (wrap) wrap.style.display = 'none';
        if (btn) delete btn.dataset.lotBlocked;
        if (n <= 30) {
          flag.className = 'lot-flag lot-soon';
          flag.textContent = 'This lot expires in ' + n + ' day' + (n === 1 ? '' : 's') + '. Plan to use it soon.';
          flag.style.display = 'block';
        }
      }
      // let the bird-count check re-run so both rules are applied together
      var birds = document.getElementById(expId === 'vExpiry' ? 'vBirds' : 'cBirds');
      if (birds) birds.dispatchEvent(new Event('input'));
      else if (btn) btn.disabled = !!(btn.dataset.lotBlocked && !over.checked);
    }
    exp.addEventListener('input', refresh);
    exp.addEventListener('change', refresh);
    over.addEventListener('change', refresh);
    refresh();
  }
  attach('vExpiry', 'vExpiryFlag', 'vOverride', 'vSubmit');
  attach('cExpiry', 'cExpiryFlag', 'cOverride', 'cSubmit');
} catch (e) { console.error('Coopers admin:', e); } })();

/* ════════════════════════════════════════════════════════════
   DASHBOARD — period switch without inline handlers
   ════════════════════════════════════════════════════════════ */
(function () {
  var buttons = document.querySelectorAll('.period-btn');
  if (!buttons.length || typeof window.switchPeriod !== 'function') return;
  buttons.forEach(function (b) {
    b.addEventListener('click', function () { window.switchPeriod(b, parseInt(b.dataset.days, 10)); });
  });
})();

/* ════════════════════════════════════════════════════════════
   FEED — cost per kg from the quantity bought and the total paid
   ════════════════════════════════════════════════════════════ */
(function () { try {
  var fmt = function (n) { return '₵' + Number(n || 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var fmt4 = function (n) { return '₵' + Number(n || 0).toFixed(4); };
  var el = function (id) { return document.getElementById(id); };

  // Shows the cost per kg as the total and quantity are typed
  function calcFor(form) {
    if (!form) return;
    var kgEl = form.querySelector('[data-cost-qty]'), totEl = form.querySelector('[data-cost-total]'),
        out = form.querySelector('[data-cost-preview]');
    if (!kgEl || !out) return;
    function refresh() {
      var kg = parseFloat(kgEl.value || '0'), tot = parseFloat(totEl && totEl.value || '0');
      var oldKg = parseFloat(out.dataset.oldKg || '0') || 0, oldUnit = parseFloat(out.dataset.oldUnit || '0') || 0;
      if (!(kg > 0)) { out.textContent = out.dataset.oldKg !== undefined ? out.textContent : 'Enter the kilograms and the total paid to see the cost per kg.'; return; }
      var unit = tot / kg;
      if (out.dataset.oldUnit !== undefined) {
        var newKg = oldKg + kg, avg = (oldKg * oldUnit + tot) / newKg;
        out.textContent = fmt(tot) + ' for ' + kg.toLocaleString() + ' kg = ' + fmt4(unit) + '/kg. New average: ' + fmt4(avg) + '/kg on ' + newKg.toLocaleString() + ' kg.';
      } else {
        out.textContent = fmt(tot) + ' for ' + kg.toLocaleString() + ' kg = ' + fmt4(unit) + ' per kg.';
      }
    }
    kgEl.addEventListener('input', refresh);
    if (totEl) totEl.addEventListener('input', refresh);
  }
  document.querySelectorAll('form').forEach(calcFor);

  function openModal(id) { var m = el(id); if (m) m.classList.add('open'); }
  function closeModal(m) { if (m) m.classList.remove('open'); }

  document.addEventListener('click', function (e) {
    var t = e.target;
    var open = t.closest('[data-open-modal]');
    if (open) { openModal(open.dataset.openModal); return; }
    if (t.closest('[data-close-modal]')) { closeModal(t.closest('.modal-overlay')); return; }

    var rs = t.closest('[data-restock]');
    if (rs) {
      el('rsId').value = rs.dataset.id;
      el('rsTitle').textContent = 'Restock ' + rs.dataset.name;
      var old = el('rsOld');
      old.dataset.oldKg = rs.dataset.kg; old.dataset.oldUnit = rs.dataset.unit;
      old.textContent = 'On hand ' + parseFloat(rs.dataset.kg).toLocaleString() + ' kg at ' + fmt4(rs.dataset.unit) + '/kg.';
      var form = old.closest('form'); form.reset(); el('rsId').value = rs.dataset.id;
      old.textContent = 'On hand ' + parseFloat(rs.dataset.kg).toLocaleString() + ' kg at ' + fmt4(rs.dataset.unit) + '/kg. Enter the new purchase.';
      openModal('restockModal'); return;
    }

    var us = t.closest('[data-usage]');
    if (us) {
      el('ulId').value = us.dataset.id;
      el('ulKg').max = us.dataset.kg;
      el('ulInfo').textContent = 'Available: ' + parseFloat(us.dataset.kg).toLocaleString() + ' kg';
      openModal('usageModal'); return;
    }
  });
} catch (e) { console.error('Coopers admin:', e); } })();

/* ════════════════════════════════════════════════════════════
   DAY — while the day is closed, the logging buttons are switched off
   ════════════════════════════════════════════════════════════ */
(function () { try {
  var b = document.getElementById('dayBanner');
  if (b && b.dataset.open === '0') document.body.classList.add('day-closed');
} catch (e) { console.error('Coopers admin:', e); } })();

/* ════════════════════════════════════════════════════════════
   DAY REPORT — print button
   ════════════════════════════════════════════════════════════ */
(function () { try {
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('[data-print]')) window.print();
  });
} catch (e) { console.error('Coopers admin:', e); } })();
