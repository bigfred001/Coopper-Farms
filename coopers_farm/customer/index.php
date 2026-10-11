<?php
require_once '../config/config.php';

// Fetch products from DB
$productsQuery = $pdo->query("SELECT * FROM products WHERE is_active = 1 ORDER BY category, name LIMIT 6");
$products = $productsQuery->fetchAll();

// Get cart count from session
$cartCount = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cartCount += $item['quantity'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Coopers Farm - Fresh Poultry Products</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../public/css/user.css" />
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar" id="navbar">
  <div class="nav-container">
    <a href="index.php" class="logo">Coopers</a>
    <ul class="nav-menu" id="navMenu">
      <li><a href="#home" class="nav-link active">Home</a></li>
      <li><a href="#about" class="nav-link">About Us</a></li>
      <li><a href="#shop" class="nav-link">Shop</a></li>
      <li><a href="#testimonials" class="nav-link">Reviews</a></li>
      <li><a href="#contact" class="nav-link">Contact</a></li>
    </ul>
    <div class="nav-actions">
      <?php if(isLoggedIn()): ?>
        <a href="pages/profile.php" class="nav-link" style="font-size:13px;">
          <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['name']); ?>
        </a>
        <a href="pages/logout.php" class="nav-link" style="font-size:13px; color:#ef4444;">Logout</a>
      <?php else: ?>
        <a href="pages/login.php" class="nav-link" style="font-size:13px;">Login</a>
        <a href="pages/signup.php" class="nav-cta">Get Started</a>
      <?php endif; ?>
      <a href="#" class="nav-cart" id="cartToggle">
        <i class="fas fa-shopping-basket"></i>
        <span class="cart-badge" id="cartBadge"><?php echo $cartCount; ?></span>
      </a>
    </div>
    <button class="mobile-toggle" id="mobileToggle">
      <span></span><span></span><span></span>
    </button>
  </div>
</nav>

<!-- HERO SECTION -->
<section class="hero-section" id="home">
  <div class="hero-carousel">
    <div class="hero-slide active">
      <div class="hero-bg" style="background: linear-gradient(135deg, #2D5016 0%, #4A7C59 50%, #7EAD6E 100%); width:100%; height:100%;"></div>
      <div class="hero-overlay"></div>
      <div class="hero-content">
        <span class="hero-label">Welcome to Coopers Farm</span>
        <h1 class="hero-title">Raising Healthy Hens,<br>Producing Fresh Eggs</h1>
        <p class="hero-description">Experience the difference of farm-fresh quality. Sustainable practices ensuring every egg meets the highest standards of excellence.</p>
        <a href="#shop" class="hero-cta">Shop Now</a>
      </div>
    </div>
    <div class="hero-slide">
      <div class="hero-bg" style="background: linear-gradient(135deg, #8B5E3C 0%, #C4853C 50%, #E87722 100%); width:100%; height:100%;"></div>
      <div class="hero-overlay"></div>
      <div class="hero-content">
        <span class="hero-label">Quality You Can Trust</span>
        <h1 class="hero-title">Farm Fresh Eggs<br>Delivered Daily</h1>
        <p class="hero-description">From our family farm to your table. The freshest, most nutritious eggs through ethical and sustainable farming practices.</p>
        <a href="#shop" class="hero-cta">Order Now</a>
      </div>
    </div>
    <div class="hero-slide">
      <div class="hero-bg" style="background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 50%, #60a5fa 100%); width:100%; height:100%;"></div>
      <div class="hero-overlay"></div>
      <div class="hero-content">
        <span class="hero-label">Sustainable Farming</span>
        <h1 class="hero-title">Care for Animals,<br>Care for Nature</h1>
        <p class="hero-description">Our birds roam freely in spacious, clean environments. Happy hens produce better eggs, and our sustainable methods prove it.</p>
        <a href="#about" class="hero-cta">Explore Our Farm</a>
      </div>
    </div>
  </div>
  <div class="carousel-indicators">
    <span class="indicator active" data-slide="0"></span>
    <span class="indicator" data-slide="1"></span>
    <span class="indicator" data-slide="2"></span>
  </div>
  <div class="scroll-indicator"><div class="scroll-arrow"></div></div>
</section>

<!-- ABOUT SECTION -->
<section class="about-section" id="about">
  <div class="about-container">
    <div class="about-content">
      <span class="about-label">About Our Farm</span>
      <h2 class="about-title">Sustainable Poultry Farming for a Healthier Tomorrow</h2>
      <p class="about-description">We're committed to raising healthy, happy birds in clean, spacious environments. Our farm practices prioritize animal welfare, environmental sustainability, and producing the highest quality poultry products for your family.</p>
      <div class="stats-grid">
        <div class="stat-card"><div class="stat-number">18+</div><div class="stat-label">Years Experience</div></div>
        <div class="stat-card"><div class="stat-number">5000+</div><div class="stat-label">Happy Customers</div></div>
        <div class="stat-card"><div class="stat-number">100%</div><div class="stat-label">Organic Feed</div></div>
      </div>
      <div class="features-list">
        <div class="feature-item"><span class="feature-icon">✓</span><span>Certified organic farming practices</span></div>
        <div class="feature-item"><span class="feature-icon">✓</span><span>Humane treatment of all birds</span></div>
        <div class="feature-item"><span class="feature-icon">✓</span><span>Regular health monitoring & veterinary care</span></div>
        <div class="feature-item"><span class="feature-icon">✓</span><span>Farm-fresh products delivered daily</span></div>
      </div>
      <a href="#shop" class="about-cta">Shop Our Products</a>
    </div>
    <div class="about-image">
      <div style="width:100%;height:500px;border-radius:20px;background:linear-gradient(135deg,#4A7C59,#7EAD6E);display:flex;align-items:center;justify-content:center;font-size:120px;">🐔</div>
      <div class="image-badge">
        <div class="badge-text">Trusted Since</div>
        <div class="badge-year">2006</div>
      </div>
    </div>
  </div>
</section>

<!-- SHOP SECTION -->
<section class="shop-section" id="shop">
  <div class="container">
    <div class="section-header">
      <span class="section-label">Our Products</span>
      <h2>Fresh From The Farm</h2>
      <p>Quality poultry products raised with care and delivered fresh to your door</p>
    </div>
    <div class="shop-grid">
      <?php
      $emojis = ['eggs'=>'🥚','broiler'=>'🍗','layer'=>'🐔','other'=>'🌾'];
      foreach($products as $p):
        $emoji = $emojis[$p['category']] ?? '🌾';
      ?>
      <div class="shop-card">
        <div class="shop-card-img"><?php echo $emoji; ?></div>
        <div class="shop-card-body">
          <div class="shop-card-cat"><?php echo ucfirst($p['category']); ?></div>
          <div class="shop-card-name"><?php echo htmlspecialchars($p['name']); ?></div>
          <div class="shop-card-desc"><?php echo htmlspecialchars(substr($p['description'], 0, 80)); ?>...</div>
          <div class="shop-card-footer">
            <div class="shop-card-price">
              <?php echo formatCurrency($p['price']); ?>
              <small>/<?php echo $p['unit']; ?></small>
            </div>
            <button class="btn-cart add-to-cart"
              data-id="<?php echo $p['id']; ?>"
              data-name="<?php echo htmlspecialchars($p['name']); ?>"
              data-price="<?php echo $p['price']; ?>"
              data-emoji="<?php echo $emoji; ?>">
              <i class="fas fa-plus"></i>
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- TESTIMONIALS -->
<section class="testimonials-section" id="testimonials">
  <div class="container">
    <div class="section-header">
      <span class="section-label">Customer Reviews</span>
      <h2>What Our Customers Say</h2>
    </div>
    <div class="testimonials-grid">
      <div class="testimonial-card">
        <div class="rating">★★★★★</div>
        <p class="testimonial-text">"The freshest eggs I've ever tasted! You can genuinely taste the difference. My family refuses to buy from anywhere else now."</p>
        <div class="client-info">
          <div class="client-avatar">AK</div>
          <div><h4 class="client-name">Ama Kofi</h4><p class="client-role">Accra, Ghana</p></div>
        </div>
      </div>
      <div class="testimonial-card">
        <div class="rating">★★★★★</div>
        <p class="testimonial-text">"Excellent service and quality birds! I've been buying from Coopers for my restaurant for 2 years now. Consistent quality every time."</p>
        <div class="client-info">
          <div class="client-avatar">KA</div>
          <div><h4 class="client-name">Kwame Asante</h4><p class="client-role">Restaurant Owner, Kumasi</p></div>
        </div>
      </div>
      <div class="testimonial-card">
        <div class="rating">★★★★★</div>
        <p class="testimonial-text">"Fast delivery, great prices and the quality is unmatched. The Mobile Money payment makes ordering so convenient!"</p>
        <div class="client-info">
          <div class="client-avatar">AB</div>
          <div><h4 class="client-name">Abena Boateng</h4><p class="client-role">Homemaker, Tema</p></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- CONTACT SECTION -->
<section class="contact-section" id="contact">
  <div class="contact-header">
    <p class="contact-label">CONTACT</p>
    <h2 class="contact-title">Get in Touch With Us</h2>
    <p class="contact-description">Have questions about our products? We'd love to hear from you. Send us a message and we'll respond as soon as possible.</p>
  </div>
  <div class="contact-container">
    <div class="contact-info">
      <div class="info-item">
        <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
        <div class="info-content"><h3>Address</h3><p>123 Farm Road, Accra, Ghana</p></div>
      </div>
      <div class="info-item">
        <div class="info-icon"><i class="fas fa-envelope"></i></div>
        <div class="info-content"><h3>Email</h3><p>info@coopersfarm.com</p></div>
      </div>
      <div class="info-item">
        <div class="info-icon"><i class="fas fa-phone-alt"></i></div>
        <div class="info-content"><h3>Phone</h3><p>+233 24 123 4567</p><p>+233 20 987 6543</p></div>
      </div>
      <div class="info-item">
        <div class="info-icon"><i class="fas fa-clock"></i></div>
        <div class="info-content"><h3>Hours</h3><p>Mon – Sat: 7AM – 6PM</p></div>
      </div>
    </div>
    <form class="contact-form" id="contactForm">
      <div class="form-row">
        <input type="text" name="name" placeholder="Your Name" required>
        <input type="email" name="email" placeholder="Email Address" required>
      </div>
      <div class="form-row">
        <input type="tel" name="phone" placeholder="Phone Number">
        <input type="text" name="subject" placeholder="Subject">
      </div>
      <textarea name="message" placeholder="Your message..." rows="5" required></textarea>
      <button type="submit" class="submit-btn">Send Message <i class="fas fa-arrow-right"></i></button>
    </form>
  </div>
</section>

<!-- FOOTER -->
<footer class="footer">
  <div class="footer-container">
    <div class="footer-top">
      <div class="footer-column">
        <h3 class="footer-logo">Coopers</h3>
        <p class="footer-about">Dedicated to sustainable poultry farming and producing the freshest, highest quality eggs and birds for your family. Farm fresh, naturally raised.</p>
        <div class="footer-social">
          <a href="#" class="social-link"><i class="fab fa-facebook-f"></i></a>
          <a href="#" class="social-link"><i class="fab fa-instagram"></i></a>
          <a href="#" class="social-link"><i class="fab fa-whatsapp"></i></a>
          <a href="#" class="social-link"><i class="fab fa-twitter"></i></a>
        </div>
      </div>
      <div class="footer-column">
        <h4 class="footer-title">Quick Links</h4>
        <ul class="footer-links">
          <li><a href="#home">Home</a></li>
          <li><a href="#about">About Us</a></li>
          <li><a href="#shop">Shop</a></li>
          <li><a href="#contact">Contact</a></li>
        </ul>
      </div>
      <div class="footer-column">
        <h4 class="footer-title">Our Products</h4>
        <ul class="footer-links">
          <li><a href="#shop">Fresh Eggs</a></li>
          <li><a href="#shop">Live Birds</a></li>
          <li><a href="#shop">Day-Old Chicks</a></li>
          <li><a href="#shop">Wholesale</a></li>
        </ul>
      </div>
      <div class="footer-column">
        <h4 class="footer-title">Contact Us</h4>
        <ul class="footer-contact">
          <li><i class="fas fa-map-marker-alt contact-icon"></i><span>123 Farm Road, Accra, Ghana</span></li>
          <li><i class="fas fa-phone-alt contact-icon"></i><span>+233 24 123 4567</span></li>
          <li><i class="fas fa-envelope contact-icon"></i><span>info@coopersfarm.com</span></li>
          <li><i class="fas fa-clock contact-icon"></i><span>Mon - Sat: 7AM - 6PM</span></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <div class="footer-bottom-content">
        <p class="copyright">© 2025 Coopers Farm. All rights reserved.</p>
        <div class="footer-legal">
          <a href="#">Privacy Policy</a>
          <span class="separator">•</span>
          <a href="#">Terms of Service</a>
        </div>
      </div>
    </div>
  </div>
</footer>

<!-- CART SIDEBAR -->
<div class="cart-overlay" id="cartOverlay"></div>
<div class="cart-sidebar" id="cartSidebar">
  <div class="cart-header">
    <h3>🛒 Your Cart</h3>
    <button class="cart-close" id="cartClose"><i class="fas fa-times"></i></button>
  </div>
  <div class="cart-items" id="cartItems">
    <div class="cart-empty">
      <i class="fas fa-shopping-basket"></i>
      <p>Your cart is empty</p>
      <small>Add some fresh products!</small>
    </div>
  </div>
  <div class="cart-footer">
    <div class="cart-total">
      <span>Total</span>
      <span id="cartTotal">₵0.00</span>
    </div>
    <a href="pages/checkout.php" class="btn-checkout">Proceed to Checkout</a>
  </div>
</div>

<!-- TOAST -->
<div class="toast-container" id="toastContainer"></div>

<script src="../public/js/user.js"></script>
</body>
</html>
