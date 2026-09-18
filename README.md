# Clothes Store

A PHP and MySQL e-commerce site for browsing and ordering clothes, with a customer-facing storefront and an admin panel for managing products and orders.

## Features

- Product catalog with category themes (t-shirts, shoes, summer, winter, pajama sets, etc.)
- Shopping cart and checkout flow
- User accounts — sign up, log in, log out
- Order history and a customer report/support system
- Admin panel (`admin.php`) for managing products, orders, and reports

## Screenshots

![Clothes Store screenshot](screenshot.png)

## Tech Stack

- PHP (PDO + MySQL)
- MySQL / MariaDB
- HTML, CSS, JavaScript (`index.css`, `index.js`)

## Running It Locally

This project needs a local PHP + MySQL server, such as **XAMPP**, **WAMP**, or **MAMP**.

1. **Install a local server stack** if you don't have one — [XAMPP](https://www.apachefriends.org/) is a simple option.
2. **Copy the project folder** into your server's web root:
   - XAMPP: `htdocs/clothes-store`
3. **Create the database:**
   - Open phpMyAdmin (or the MySQL CLI) and create a database named `clothes-store1`.
   - Import `database/clothes-store1.sql` into it.
4. **Check the database credentials** in `db.php` — by default it expects:
   - Host: `127.0.0.1`, Port: `3306`, User: `root`, Password: *(empty)*
   - Update these if your local MySQL setup differs.
5. **Start Apache and MySQL** from your server stack's control panel.
6. **Open the site** in your browser:
   - `http://localhost/clothes-store/index.php`

## Notes

- The `images/` folder holds product photos used across the storefront.
- The site auto-creates a `reports` table on first load if it doesn't already exist.
