# 🐔 Coopers Farm — Poultry Management System

A complete poultry farm management system with customer e-commerce frontend and admin dashboard. Built with PHP, MySQL, Chart.js, and FontAwesome.

---

## 📁 Project Structure

```
coopers_farm/
├── config/
│   └── config.php              ← Database connection + helpers
├── database/
│   └── schema.sql              ← Full DB schema + seed data
├── public/
│   ├── css/
│   │   ├── user.css            ← Customer UI styles
│   │   └── admin.css           ← Admin dashboard styles
│   └── js/
│       ├── user.js             ← Customer UI logic + cart
│       └── admin.js            ← Admin dashboard logic
├── customer/
│   ├── index.php               ← Customer homepage
│   ├── ajax/
│   │   └── cart.php            ← Cart sync AJAX handler
│   └── pages/
│       ├── login.php           ← Login page
│       ├── signup.php          ← Registration page
│       ├── checkout.php        ← Checkout + order placement
│       ├── profile.php         ← Customer order history
│       └── logout.php          ← Session destroyer
└── admin/
    ├── index.php               ← Admin dashboard overview
    ├── orders.php              ← Order management
    ├── products.php            ← Product & inventory management
    ├── customers.php           ← Customer list
    ├── flocks.php              ← Flock management
    ├── vaccinations.php        ← Vaccination tracker
    ├── health.php              ← Health records
    ├── mortality.php           ← Mortality logging
    ├── egg_production.php      ← Egg production tracking
    ├── feed.php                ← Feed stock & usage
    ├── reports.php             ← Analytics & charts
    └── includes/
        ├── sidebar.php         ← Admin sidebar
        └── topbar.php          ← Admin topbar
```

---

## 🚀 Setup Instructions

### 1. Requirements
- PHP 7.4+ or 8.x
- MySQL 5.7+ or MariaDB
- Web server: Apache (XAMPP/WAMP) or Nginx
- Composer (optional, not required)

### 2. Install

1. **Copy files** to your web server root:
   ```
   /xampp/htdocs/coopers_farm/    (XAMPP on Windows)
   /var/www/html/coopers_farm/    (Linux / Ubuntu)
   /Applications/XAMPP/htdocs/   (Mac XAMPP)
   ```

2. **Create the database:**
   Open phpMyAdmin or MySQL CLI and run:
   ```sql
   SOURCE /path/to/coopers_farm/database/schema.sql;
   ```
   Or import the `schema.sql` file via phpMyAdmin → Import.

3. **Configure database connection:**
   Edit `config/config.php`:
   ```php
   $host     = 'localhost';
   $dbname   = 'coopers_farm';
   $username = 'root';   // your MySQL username
   $password = '';       // your MySQL password
   ```

4. **Set site URL:**
   In `config/config.php`:
   ```php
   define('SITE_URL', 'http://localhost/coopers_farm');
   ```

5. **Visit the site:**
   - Customer frontend: `http://localhost/coopers_farm/customer/index.php`
   - Admin dashboard:   `http://localhost/coopers_farm/admin/index.php`

---

## 🔐 Default Login Credentials

| Role  | Email                     | Password   |
|-------|---------------------------|------------|
| Admin | admin@coopersfarm.com     | password   |
| Customer | customer@example.com   | password   |

> ⚠️ **Change these passwords immediately in production!**

---

## 📦 Features

### Customer Side
- ✅ Homepage with hero carousel, about section, products, testimonials, contact
- ✅ Product shop with add-to-cart (localStorage + session sync)
- ✅ Cart sidebar with quantity controls
- ✅ Checkout with Mobile Money payment options (MTN, Vodafone, AirtelTigo, Cash)
- ✅ User registration & login
- ✅ Order history / profile page

### Admin Dashboard
- ✅ Dashboard overview with live stats
- ✅ Order management (status updates, search, filter)
- ✅ Product & inventory management
- ✅ Customer management

### Farm Management
- ✅ Flock management (add, track, update status)
- ✅ Vaccination tracker (schedule, record, overdue alerts)
- ✅ Health records (disease, treatment, outcome tracking)
- ✅ Mortality logging with cause analysis
- ✅ Egg production tracking with productivity metrics
- ✅ Feed stock management with reorder alerts
- ✅ Reports & analytics with Chart.js charts

---

## 🛠️ Tech Stack

- **Backend:** PHP 8 (PDO, sessions)
- **Database:** MySQL
- **Frontend:** HTML5, CSS3, Vanilla JavaScript
- **Charts:** Chart.js 4.x
- **Icons:** Font Awesome 6
- **Fonts:** Playfair Display + DM Sans (Google Fonts)

---

## 🌍 Ghana-Specific Features

- Currency: GHS (₵)
- Mobile Money: MTN MoMo, Vodafone Cash, AirtelTigo Money
- Timezone: Africa/Accra
- Contact location: Accra, Ghana

---

## 🔄 Extending the System

### Add SMS Notifications
Integrate with Arkesel or Hubtel SMS API for order and vaccination alerts.

### Add More Payment Methods
Update the checkout form and orders table to include additional payment processors.

### Add Image Uploads
Add a product image upload field and update the `products` table with an image path.

### WhatsApp Integration
Use Twilio or the WhatsApp Business API to send order confirmations.

---

## 📝 Notes

- Run `UPDATE vaccinations SET status='overdue' WHERE status='scheduled' AND next_due_date < CURDATE();` 
  periodically (cron job) to auto-update overdue vaccinations.
- The cart uses `localStorage` on the client and syncs to PHP sessions on checkout.
- All form inputs are sanitized via `htmlspecialchars` + `strip_tags`.
