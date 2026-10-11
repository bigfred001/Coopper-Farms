// ============================================================
// COOPERS FARM - Customer UI JavaScript
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

  // ---- NAVBAR SCROLL ----
  const navbar = document.getElementById('navbar');
  if (navbar) {
    window.addEventListener('scroll', () => {
      navbar.classList.toggle('scrolled', window.scrollY > 50);
    });
  }

  // ---- MOBILE MENU ----
  const mobileToggle = document.getElementById('mobileToggle');
  const navMenu = document.getElementById('navMenu');
  if (mobileToggle && navMenu) {
    mobileToggle.addEventListener('click', () => {
      navMenu.classList.toggle('open');
    });
    document.addEventListener('click', (e) => {
      if (!mobileToggle.contains(e.target) && !navMenu.contains(e.target)) {
        navMenu.classList.remove('open');
      }
    });
  }

  // ---- HERO CAROUSEL ----
  const slides = document.querySelectorAll('.hero-slide');
  const indicators = document.querySelectorAll('.indicator');
  let currentSlide = 0;

  function goToSlide(index) {
    slides[currentSlide].classList.remove('active');
    if (indicators[currentSlide]) indicators[currentSlide].classList.remove('active');
    currentSlide = index;
    slides[currentSlide].classList.add('active');
    if (indicators[currentSlide]) indicators[currentSlide].classList.add('active');
  }

  if (slides.length > 1) {
    const autoSlide = setInterval(() => {
      goToSlide((currentSlide + 1) % slides.length);
    }, 5000);

    indicators.forEach((ind, i) => {
      ind.addEventListener('click', () => {
        clearInterval(autoSlide);
        goToSlide(i);
      });
    });
  }

  // ---- SMOOTH SCROLL ----
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', (e) => {
      const target = document.querySelector(a.getAttribute('href'));
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        navMenu && navMenu.classList.remove('open');
      }
    });
  });

  // ---- CART SYSTEM ----
  let cart = JSON.parse(localStorage.getItem('coopersCart') || '{}');

  const cartSidebar = document.getElementById('cartSidebar');
  const cartOverlay = document.getElementById('cartOverlay');
  const cartToggle = document.getElementById('cartToggle');
  const cartClose = document.getElementById('cartClose');
  const cartItemsEl = document.getElementById('cartItems');
  const cartTotalEl = document.getElementById('cartTotal');
  const cartBadge = document.getElementById('cartBadge');

  function updateCartDisplay() {
    if (!cartItemsEl) return;
    const items = Object.values(cart);
    if (items.length === 0) {
      cartItemsEl.innerHTML = `
        <div class="cart-empty">
          <i class="fas fa-shopping-basket"></i>
          <p>Your cart is empty</p>
          <small>Add some fresh products!</small>
        </div>`;
      if (cartTotalEl) cartTotalEl.textContent = '₵0.00';
      if (cartBadge) cartBadge.textContent = '0';
      return;
    }

    let totalQty = 0;
    let totalAmount = 0;
    let html = '';

    items.forEach(item => {
      const sub = item.price * item.qty;
      totalAmount += sub;
      totalQty += item.qty;
      html += `
        <div class="cart-item" data-id="${item.id}">
          <div class="cart-item-img">${item.emoji}</div>
          <div class="cart-item-info">
            <div class="cart-item-name">${item.name}</div>
            <div class="cart-item-price">₵${sub.toFixed(2)}</div>
            <div class="cart-item-qty">
              <button class="qty-btn" onclick="changeQty('${item.id}', -1)">−</button>
              <span class="qty-num">${item.qty}</span>
              <button class="qty-btn" onclick="changeQty('${item.id}', 1)">+</button>
            </div>
          </div>
          <button class="cart-remove" onclick="removeFromCart('${item.id}')">
            <i class="fas fa-trash"></i>
          </button>
        </div>`;
    });

    cartItemsEl.innerHTML = html;
    if (cartTotalEl) cartTotalEl.textContent = '₵' + totalAmount.toFixed(2);
    if (cartBadge) cartBadge.textContent = totalQty;

    // Sync to session via AJAX
    syncCartToSession();
  }

  function syncCartToSession() {
    fetch('ajax/cart.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'sync', cart })
    }).catch(() => {});
  }

  window.changeQty = function (id, delta) {
    if (cart[id]) {
      cart[id].qty = Math.max(1, cart[id].qty + delta);
      localStorage.setItem('coopersCart', JSON.stringify(cart));
      updateCartDisplay();
    }
  };

  window.removeFromCart = function (id) {
    delete cart[id];
    localStorage.setItem('coopersCart', JSON.stringify(cart));
    updateCartDisplay();
  };

  // Add to cart buttons
  document.querySelectorAll('.add-to-cart').forEach(btn => {
    btn.addEventListener('click', function () {
      const id = this.dataset.id;
      const name = this.dataset.name;
      const price = parseFloat(this.dataset.price);
      const emoji = this.dataset.emoji || '🛒';

      if (cart[id]) {
        cart[id].qty++;
      } else {
        cart[id] = { id, name, price, qty: 1, emoji };
      }

      localStorage.setItem('coopersCart', JSON.stringify(cart));
      updateCartDisplay();
      showToast(`${emoji} ${name} added to cart!`);

      // Pulse animation
      this.style.transform = 'scale(1.3)';
      setTimeout(() => { this.style.transform = ''; }, 200);
    });
  });

  if (cartToggle) {
    cartToggle.addEventListener('click', (e) => {
      e.preventDefault();
      cartSidebar && cartSidebar.classList.add('open');
      cartOverlay && cartOverlay.classList.add('open');
    });
  }

  if (cartClose) cartClose.addEventListener('click', closeCart);
  if (cartOverlay) cartOverlay.addEventListener('click', closeCart);

  function closeCart() {
    cartSidebar && cartSidebar.classList.remove('open');
    cartOverlay && cartOverlay.classList.remove('open');
  }

  // Init cart from localStorage
  updateCartDisplay();

  // ---- CONTACT FORM ----
  const contactForm = document.getElementById('contactForm');
  if (contactForm) {
    contactForm.addEventListener('submit', function (e) {
      e.preventDefault();
      showToast('Message sent! We\'ll get back to you soon. 🌿', 'success');
      contactForm.reset();
    });
  }

  // ---- INTERSECTION OBSERVER (entrance animations) ----
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(el => {
      if (el.isIntersecting) {
        el.target.style.opacity = '1';
        el.target.style.transform = 'translateY(0)';
      }
    });
  }, { threshold: 0.1 });

  document.querySelectorAll('.shop-card, .testimonial-card, .stat-card, .product-card').forEach(el => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(24px)';
    el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
    observer.observe(el);
  });
});

// ---- TOAST ----
function showToast(message, type = 'success') {
  const container = document.getElementById('toastContainer');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast ${type === 'error' ? 'error' : ''}`;
  toast.innerHTML = `
    <i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}" 
       style="color:${type === 'error' ? '#ef4444' : '#4ade80'};font-size:18px;"></i>
    <span>${message}</span>`;
  container.appendChild(toast);

  setTimeout(() => {
    toast.style.animation = 'toastIn 0.3s ease reverse';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}
