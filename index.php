<?php
session_start();
require_once 'db.php';

// Self-healing: reports table (same shape admin.php creates)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        order_id INT,
        username VARCHAR(100),
        message TEXT,
        status VARCHAR(20) DEFAULT 'new',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) { /* table already exists */ }

// Fallback: rebuild basket items from the order_details text (old orders have no saved JSON)
function parseOrderDetails($details) {
    $items = [];
    foreach (preg_split('/\r\n|\r|\n/', $details) as $line) {
        $line = trim($line);
        if (preg_match('/^(.*) \(Size: (.+)\) x (\d+) - \$(\d+(?:\.\d+)?)$/', $line, $m)) {
            $count = (int)$m[3];
            $subtotal = (float)$m[4];
            $items[] = [
                'key' => $m[1] . ' - ' . $m[2],
                'name' => $m[1],
                'size' => $m[2],
                'count' => $count,
                'price' => $count > 0 ? round($subtotal / $count, 2) : $subtotal
            ];
        }
    }
    return $items;
}

function product_image_src($image) {
    if (empty($image)) {
        return '';
    }

    if (is_string($image) && preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', $image)) {
        return $image;
    }

    if (is_string($image) && filter_var($image, FILTER_VALIDATE_URL)) {
        return $image;
    }

    // Support databases that store a relative filename instead of a BLOB.
    if (is_string($image) && @is_file(__DIR__ . '/' . ltrim($image, '/'))) {
        $file_image = @file_get_contents(__DIR__ . '/' . ltrim($image, '/'));
        if ($file_image !== false) {
            $image = $file_image;
        }
    }

    $image_info = @getimagesizefromstring($image);
    if ($image_info === false && is_string($image)) {
        $decoded_image = base64_decode($image, true);
        if ($decoded_image !== false) {
            $decoded_info = @getimagesizefromstring($decoded_image);
            if ($decoded_info !== false) {
                $image = $decoded_image;
                $image_info = $decoded_info;
            }
        }
    }
    if ($image_info === false) {
        return '';
    }
    $mime = $image_info['mime'] ?? 'image/jpeg';
    if (strpos($mime, 'image/') !== 0) {
        $mime = 'image/jpeg';
    }

    return 'data:' . $mime . ';base64,' . base64_encode($image);
}

function normalized_product_name(string $name): string {
    $name = preg_replace('/\s+/', ' ', trim($name)) ?: '';
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

function valid_image_src($image): bool {
    return is_string($image) && (
        preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', $image) ||
        filter_var($image, FILTER_VALIDATE_URL)
    );
}

// Restore display images for baskets and older orders. Images are kept out of
// the small cart payload when possible, but can always be rebuilt from the
// product record using its id or name.
function attach_product_images(array $items, PDO $pdo): array {
    $by_id = [];
    $by_name = [];
    $image_by_normalized_name = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $product_id = (int)($item['product_id'] ?? 0);
        $name = trim((string)($item['name'] ?? ''));
        $has_image = valid_image_src($item['img'] ?? '');
        if ($has_image || ($product_id < 1 && $name === '')) {
            continue;
        }

        if ($product_id > 0) {
            $by_id[$product_id][] = $index;
        } else {
            $by_name[$name][] = $index;
        }
    }

    if (!empty($by_id)) {
        $stmt = $pdo->prepare("SELECT id, image FROM products WHERE id = ?");
        foreach ($by_id as $product_id => $indexes) {
            $stmt->execute([$product_id]);
            $image = $stmt->fetchColumn();
            $resolved_image = $image ? product_image_src($image) : '';
            foreach ($indexes as $index) {
                if ($resolved_image !== '') {
                    $items[$index]['img'] = $resolved_image;
                } else {
                    $name = trim((string)($items[$index]['name'] ?? ''));
                    if ($name !== '') {
                        $by_name[$name][] = $index;
                    }
                }
            }
        }
    }

    if (!empty($by_name)) {
        $stmt = $pdo->prepare("SELECT image FROM products WHERE name = ? LIMIT 1");
        foreach ($by_name as $name => $indexes) {
            $stmt->execute([$name]);
            $image = $stmt->fetchColumn();
            $resolved_image = $image ? product_image_src($image) : '';
            foreach ($indexes as $index) {
                if ($resolved_image !== '') {
                    $items[$index]['img'] = $resolved_image;
                } else {
                    $normalized_name = normalized_product_name($name);
                    $image_by_normalized_name[$normalized_name] = $image_by_normalized_name[$normalized_name] ?? null;
                }
            }
        }
    }

    // Last fallback for old orders whose saved name differs only by case or
    // extra whitespace from the current catalog name.
    if (!empty($image_by_normalized_name)) {
        $products = $pdo->query("SELECT name, image FROM products")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($products as $product) {
            $normalized_name = normalized_product_name((string)$product['name']);
            if (!isset($image_by_normalized_name[$normalized_name])) {
                $image_by_normalized_name[$normalized_name] = product_image_src($product['image']);
            }
        }
        foreach ($items as $index => $item) {
            if (valid_image_src($item['img'] ?? '')) {
                continue;
            }
            $normalized_name = normalized_product_name((string)($item['name'] ?? ''));
            if (!empty($image_by_normalized_name[$normalized_name])) {
                $items[$index]['img'] = $image_by_normalized_name[$normalized_name];
            }
        }
    }

    return $items;
}

// Add the two optional columns only when they are actually missing. Running
// ALTER TABLE on every page request can lock tables and destabilize XAMPP's
// MySQL server while the checkout request is being processed.
function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $check->execute([$table, $column]);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD `$column` $definition");
    }
}

function hasColumn(PDO $pdo, string $table, string $column): bool {
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $check->execute([$table, $column]);
    return (int)$check->fetchColumn() > 0;
}

try {
    ensureColumn($pdo, 'users', 'cart', 'LONGTEXT');
    ensureColumn($pdo, 'orders', 'order_items', 'LONGTEXT');
} catch (\PDOException $e) {
    error_log('Optional database-column check failed: ' . $e->getMessage());
}

// Older installations may not allow ALTER TABLE for the database user, or
// may use phone_number instead of mobile_number. Orders do not need the
// optional cart/order-items columns to be saved, so detect the columns that
// are actually available before building the INSERT statement.
$users_has_cart = false;
$orders_has_order_items = false;
$orders_phone_column = null;
try {
    $users_has_cart = hasColumn($pdo, 'users', 'cart');
    $orders_has_order_items = hasColumn($pdo, 'orders', 'order_items');
    if (hasColumn($pdo, 'orders', 'mobile_number')) {
        $orders_phone_column = 'mobile_number';
    } elseif (hasColumn($pdo, 'orders', 'phone_number')) {
        $orders_phone_column = 'phone_number';
    }
} catch (\PDOException $e) {
    error_log('Database schema check failed: ' . $e->getMessage());
}

// Save the unsent basket (called from JS on every change)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_cart'])) {
    if (isset($_SESSION['user_id']) && $users_has_cart) {
        $stmt = $pdo->prepare("UPDATE users SET cart = ? WHERE id = ?");
        $stmt->execute([$_POST['save_cart'], $_SESSION['user_id']]);
    }
    exit;
}

// Restore a saved order into the current basket from the order-history page.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reorder_items'])) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    $decoded_items = json_decode($_POST['reorder_items'], true);
    $basket_items = [];
    if (is_array($decoded_items)) {
        foreach ($decoded_items as $item) {
            if (!is_array($item) || !isset($item['name'], $item['size'], $item['price'], $item['count'])) {
                continue;
            }
            $name = trim((string)$item['name']);
            $size = trim((string)$item['size']);
            $price = round((float)$item['price'], 2);
            $count = max(1, min(99, (int)$item['count']));
            if ($name === '' || $size === '' || $price < 0) {
                continue;
            }
            $basket_items[] = [
                'key' => $name . ' - ' . $size,
                'name' => substr($name, 0, 255),
                'size' => substr($size, 0, 50),
                'price' => $price,
                'count' => $count,
                'product_id' => max(0, (int)($item['product_id'] ?? 0)),
            ];
        }
    }

    $basket_items = attach_product_images($basket_items, $pdo);
    if ($users_has_cart) {
        $stmt = $pdo->prepare("UPDATE users SET cart = ? WHERE id = ?");
        $stmt->execute([json_encode($basket_items, JSON_UNESCAPED_UNICODE), $_SESSION['user_id']]);
    }
    header('Location: index.php?basket_restored=1');
    exit;
}

// Load the user's saved basket
$saved_cart_json = 'null';
if (isset($_SESSION['user_id']) && $users_has_cart) {
    $stmt = $pdo->prepare("SELECT cart FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $saved = $stmt->fetchColumn();
    if ($saved !== false && $saved !== null && $saved !== '' && $saved !== '[]') {
        $decoded = json_decode($saved, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $decoded = attach_product_images($decoded, $pdo);
            $saved_cart_json = json_encode($decoded);
        }
    }
}

// Handle order submission
$order_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['place_order'])) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
    
    $mobile = trim($_POST['phone_number']);
    $location = trim($_POST['location']);
    // The JS basket is submitted with the form; fall back to session.
    // Keep the product id with the saved order. Product images are rebuilt
    // from the catalog when order history is displayed.
    $decoded_cart = json_decode($_POST['basket_data'] ?? '[]', true);
    $decoded_cart = is_array($decoded_cart) ? $decoded_cart : ($_SESSION['cart'] ?? []);
    $cart_data = [];
    foreach ($decoded_cart as $item) {
        if (!is_array($item) || !isset($item['name'], $item['size'], $item['price'], $item['count'])) {
            continue;
        }
        $count = max(1, min(99, (int)$item['count']));
        $price = round((float)$item['price'], 2);
        $name = trim((string)$item['name']);
        $size = trim((string)$item['size']);
        $image = trim((string)($item['img'] ?? ''));
        if (!preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', $image) || strlen($image) > 2000000) {
            $image = '';
        }
        if ($name === '' || $size === '' || $price < 0) {
            continue;
        }
        $cart_data[] = [
            'key' => $name . ' - ' . $size,
            'name' => substr($name, 0, 255),
            'size' => substr($size, 0, 50),
            'price' => $price,
            'count' => $count,
            'product_id' => max(0, (int)($item['product_id'] ?? 0)),
            'img' => $image,
        ];
    }

    if (!empty($cart_data) && !empty($mobile) && !empty($location)) {
        $total = 0;
        $details = "";
        foreach ($cart_data as $item) {
            $subtotal = $item['price'] * $item['count'];
            $total += $subtotal;
            $details .= "{$item['name']} (Size: {$item['size']}) x {$item['count']} - \${$subtotal}\n";
        }
        
        // Images are display data, not order data. Storing their base64
        // contents in the order can exceed MySQL's packet limit and cause
        // the server to drop the connection. Order history rebuilds images
        // from product_id instead.
        $order_items = array_map(static function (array $item): array {
            unset($item['img']);
            return $item;
        }, $cart_data);
        $order_items_json = json_encode($order_items, JSON_UNESCAPED_UNICODE);
        if ($order_items_json === false) {
            $order_message = "We couldn't read the basket. Please refresh and try again.";
        } else {
            try {
                // Build the insert from the current schema. This keeps
                // checkout working with databases created before the saved
                // order-items column was added.
                $order_columns = ['user_id', 'order_details', 'total_price', 'status'];
                $order_values = [$_SESSION['user_id'], $details, $total, 'sent'];
                if ($orders_phone_column !== null) {
                    $order_columns[] = $orders_phone_column;
                    $order_values[] = $mobile;
                }
                if (hasColumn($pdo, 'orders', 'location')) {
                    $order_columns[] = 'location';
                    $order_values[] = $location;
                }
                if ($orders_has_order_items) {
                    $order_columns[] = 'order_items';
                    $order_values[] = $order_items_json;
                }

                $quoted_columns = array_map(
                    static fn(string $column): string => "`$column`",
                    $order_columns
                );
                $placeholders = implode(', ', array_fill(0, count($order_values), '?'));
                $stmt = $pdo->prepare(
                    'INSERT INTO `orders` (' . implode(', ', $quoted_columns) . ') VALUES (' . $placeholders . ')'
                );
                $stmt->execute($order_values);

                // The order is already saved. Clearing the optional saved
                // basket must not make a successful order look like a failed
                // checkout if an older users table has no cart column.
                unset($_SESSION['cart']);
                if ($users_has_cart) {
                    try {
                        $stmt = $pdo->prepare("UPDATE users SET cart = NULL WHERE id = ?");
                        $stmt->execute([$_SESSION['user_id']]);
                    } catch (\PDOException $e) {
                        error_log('Saved basket cleanup failed after order #' . $pdo->lastInsertId() . ': ' . $e->getMessage());
                    }
                }

                // Always return to a clean storefront URL. This prevents a
                // previous-orders overlay query string from reopening after
                // checkout.
                header('Location: index.php?order_placed=1');
                exit;
            } catch (\PDOException $e) {
                // Do not retry an INSERT automatically: after a dropped
                // connection MySQL may have committed it already, and a retry
                // could create a duplicate order.
                error_log('Order insert failed: ' . $e->getMessage());
                $order_message = "We couldn't save your order. Please check that the store database is running and try again.";
            }
        }
    }
}

// Send a report (complaint / problem) to the admin
$report_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_report'])) {
    if (!isset($_SESSION['user_id'])) {
        $report_message = "Please sign in to send a report 🌸";
    } else {
        $msg = trim($_POST['report_text'] ?? '');
        if ($msg !== '') {
            $stmt = $pdo->prepare("INSERT INTO reports (user_id, order_id, username, message, status) VALUES (?, ?, ?, ?, 'new')");
            $stmt->execute([$_SESSION['user_id'], !empty($_POST['report_order_id']) ? (int)$_POST['report_order_id'] : null, $_SESSION['username'] ?? '', $msg]);
            $report_message = "Your report has been sent to the admin ✓";
        }
    }
}

// Fetch products from database grouped by section
$stmt = $pdo->query("SELECT * FROM products");
$all_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sections = ['shirts' => [], 'shoes' => [], 'winter' => [], 'summer' => [], 'pajamas' => []];
foreach ($all_products as $prod) {
    if (array_key_exists($prod['section'], $sections)) {
        $sections[$prod['section']][] = $prod;
    }
}

// Load a small page of saved orders for the overlay. The catalog stays light
// even when an account has a long order history.
$orders_per_page = 8;
$orders_page = filter_input(INPUT_GET, 'orders_page', FILTER_VALIDATE_INT);
$orders_page = $orders_page && $orders_page > 0 ? $orders_page : 1;
$previous_orders = [];
$previous_orders_total = 0;
$previous_orders_pages = 1;
$user_reports = [];
if (isset($_SESSION['user_id'])) {
    $orders_count_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM orders WHERE user_id = ? AND status IN ('sent', 'delivered')"
    );
    $orders_count_stmt->execute([$_SESSION['user_id']]);
    $previous_orders_total = (int)$orders_count_stmt->fetchColumn();
    $previous_orders_pages = max(1, (int)ceil($previous_orders_total / $orders_per_page));
    $orders_page = min($orders_page, $previous_orders_pages);
    $orders_offset = ($orders_page - 1) * $orders_per_page;

    $order_items_select = $orders_has_order_items ? ', order_items' : '';
    $orders_stmt = $pdo->prepare(
        "SELECT id, order_details, total_price{$order_items_select}, status, created_at
         FROM orders
         WHERE user_id = ? AND status IN ('sent', 'delivered')
         ORDER BY created_at DESC, id DESC
         LIMIT {$orders_per_page} OFFSET {$orders_offset}"
    );
    $orders_stmt->execute([$_SESSION['user_id']]);
    $previous_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

    $reports_stmt = $pdo->prepare(
        "SELECT id, order_id, message, status, created_at
         FROM reports
         WHERE user_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT 20"
    );
    $reports_stmt->execute([$_SESSION['user_id']]);
    $user_reports = $reports_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aurielle Shop</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.0.0/dist/css/bootstrap.min.css" integrity="sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm" crossorigin="anonymous">
    <script src="https://code.jquery.com/jquery-3.2.1.slim.min.js" integrity="sha384-KJ3o2DKtIkvYIK3UENzmM7KCkRr/rE9/Qpg6aAZGJwFDMVNA/GpGFF93hXpG5KkN" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.12.9/dist/umd/popper.min.js" integrity="sha384-ApNbgh9B+Y1QKtv3Rn7W3mgPxhU9K/ScQsAP7hUibX39j7fakFPskvXusvfa0b4Q" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.0.0/dist/js/bootstrap.min.js" integrity="sha384-JZR6Spejh4U02d8jOt6vLEHfe/JQGiRRSQQxSfFWpi1MquVdAyjUar5+76PVCmYl" crossorigin="anonymous"></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="index.css">

    <style>
        .element { position: relative; }
        .sale-badge {
            position: absolute;
            top: 18px;
            left: 16px;
            background: linear-gradient(135deg, #ff5f6d, #c31432);
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            padding: 5px 14px;
            border-radius: 20px;
            box-shadow: 0 4px 12px rgba(195, 20, 50, 0.4);
            z-index: 2;
        }
        .old-price {
            text-decoration: line-through;
            color: #9aa0a6;
            font-size: 15px;
            margin-right: 8px;
        }
        .new-price { color: #c31432; }
        .alert-success {
            background: #e6f4f1; color: #2a7988; border: 1px solid #bcdfe8;
            border-radius: 12px; font-family: 'Poppins', sans-serif;
        }
        #basket-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(42,121,136,0.35); backdrop-filter: blur(3px); z-index: 1000; justify-content: center; align-items: center; }
        #basket-content {
            background: #fff; border-radius: 20px; padding: 28px; width: 440px; max-width: 92vw;
            max-height: 85vh; overflow-y: auto; position: relative;
            box-shadow: 0 20px 50px rgba(0,0,0,0.15); font-family: 'Poppins', sans-serif;
            scrollbar-width: thin;
            scrollbar-color: #2a7988 transparent;
        }
        #basket-content::-webkit-scrollbar,
        #orders-content::-webkit-scrollbar,
        #reports-content::-webkit-scrollbar { width: 8px; }
        #basket-content::-webkit-scrollbar-button,
        #orders-content::-webkit-scrollbar-button,
        #reports-content::-webkit-scrollbar-button { display: none; }
        #basket-content::-webkit-scrollbar-track,
        #orders-content::-webkit-scrollbar-track,
        #reports-content::-webkit-scrollbar-track {
            background: transparent; border-radius: 999px; margin: 12px 2px;
        }
        #basket-content::-webkit-scrollbar-thumb,
        #orders-content::-webkit-scrollbar-thumb,
        #reports-content::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #63b8c4, #2a7988);
            border: 2px solid transparent; background-clip: padding-box; border-radius: 999px;
        }
        #basket-content::-webkit-scrollbar-thumb:hover,
        #orders-content::-webkit-scrollbar-thumb:hover,
        #reports-content::-webkit-scrollbar-thumb:hover { background: #205f6b; }
        #basket-close { position: absolute; top: 12px; right: 18px; background: none; border: none; font-size: 30px; color: #9aa0a6; cursor: pointer; line-height: 1; }
        #basket-close:hover { color: #c31432; }
        #basket-title { color: #2a7988; margin: 0 0 16px; font-size: 24px; font-weight: 700; }
        .basket-item {
            display: grid; grid-template-columns: 56px minmax(0, 1fr) auto;
            grid-template-areas: "image name price" "image controls price";
            align-items: center; column-gap: 12px; row-gap: 5px;
            background: #f4f8f9; border: 1px solid #e7f0f2; border-radius: 14px;
            padding: 12px 14px; margin-bottom: 10px;
        }
        .basket-item-name { grid-area: name; min-width: 0; font-size: 14px; font-weight: 600; color: #333; }
        .basket-item-name small { display: block; color: #888; font-weight: 400; }
        .qty-controls {
            grid-area: controls; justify-self: start; display: inline-flex; align-items: center;
            gap: 8px; padding: 3px; border: 1px solid #d7e7ea; border-radius: 999px;
            background: #fff;
        }
        .qty-btn {
            width: 28px; height: 28px; border: 1px solid #cfe2e6; border-radius: 50%;
            background: #fff; color: #2a7988; font-weight: 700; cursor: pointer;
            font-size: 15px; line-height: 1; transition: all .2s ease;
        }
        .qty-btn:hover { background: #2a7988; border-color: #2a7988; color: #fff; }
        .qty-count { min-width: 22px; text-align: center; color: #26383d; font-weight: 700; }
        .basket-item-price { grid-area: price; font-weight: 700; color: #2a7988; min-width: 68px; text-align: right; }
        .basket-item-img {
            grid-area: image; width: 56px; height: 56px; object-fit: cover; border-radius: 12px;
            border: 1px solid #e4edf0; flex-shrink: 0;
        }
        .basket-item-noimg {
            display: flex; align-items: center; justify-content: center;
            background: #f7f9fa; font-size: 20px;
        }
        #basket-empty { text-align: center; color: #888; padding: 24px 0; font-size: 15px; }
        #basket-total-row {
            display: flex; justify-content: space-between; font-size: 18px; font-weight: 700;
            color: #333; padding: 12px 4px; border-top: 2px dashed #e0e4ed; margin-top: 6px;
        }
        #basket-total-row .total-value { color: #2a7988; }
        #checkout-section h3 { font-size: 15px; color: #2a7988; margin: 16px 0 10px; }
        #checkout-section input, #checkout-section select {
            width: 100%; padding: 10px 14px; border-radius: 12px; border: 1px solid #e0e4ed;
            margin-bottom: 10px; font-size: 14px; font-family: 'Poppins', sans-serif; background: #fafbfc;
        }
        #order-btn {
            width: 100%; background: #2a7988; color: #fff; border: none; border-radius: 25px;
            padding: 12px; font-weight: 600; font-size: 15px; cursor: pointer; transition: all .2s ease;
        }
        #order-btn:hover { background: #39a9c0; }
        .toast-container { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 2000; display: flex; flex-direction: column; gap: 8px; align-items: center; }
        .toast {
            background: #2a7988; color: #fff; padding: 12px 24px; border-radius: 25px;
            font-family: 'Poppins', sans-serif; font-size: 14px; font-weight: 500;
            box-shadow: 0 8px 20px rgba(0,0,0,0.2); animation: toastIn .3s ease; white-space: nowrap;
        }
        .toast.toast-error { background: #c31432; }
        @keyframes toastIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .po-main-title {
            text-align: center !important; color: #2a7988; font-size: 30px;
            font-weight: 700; margin: 0 0 24px !important;
        }
        /* Reports section (under Previous Orders) */
        #reports-section { margin-top: 40px; }
        .report-card {
            background: #fff; border-radius: 14px; padding: 20px 22px;
            max-width: 640px; margin: 0 auto; box-shadow: 0 4px 14px rgba(0,0,0,0.06);
        }
        .report-hint { color: #7d939c; font-size: 13px; margin: 0 0 12px; font-family: 'Poppins', sans-serif; }
        .report-textarea {
            width: 100%; min-height: 90px; resize: vertical; border: 1px solid #d8e6ea;
            border-radius: 10px; padding: 10px 14px; font-size: 14px; outline: none;
            font-family: 'Poppins', sans-serif; box-sizing: border-box;
        }
        .report-textarea:focus { border-color: #2a7988; }
        .report-select {
            width: 100%; padding: 10px 14px; margin-bottom: 10px; border: 1px solid #d8e6ea;
            border-radius: 10px; background: #fafcfc; color: #405157; font: 13px 'Poppins', sans-serif;
            outline: none;
        }
        .report-select:focus { border-color: #2a7988; }
        .report-send-btn {
            margin-top: 10px; background: #2a7988; color: #fff; border: none;
            border-radius: 25px; padding: 10px 26px; font-weight: 600; font-size: 14px;
            cursor: pointer; font-family: 'Poppins', sans-serif; transition: all .2s ease;
        }
        .report-send-btn:hover { background: #39a9c0; }
        .report-card { margin-bottom: 30px; }
        .after-products-actions {
            text-align:center; justify-content: center; flex-wrap: wrap; gap: 14px;
            padding: 34px 20px 58px; background: #f4f6fc;}
        .after-products-btn {
            border: 0; border-radius: 999px; background: #2a7988; color: #fff;
            padding: 12px 22px; font: 600 14px 'Poppins', sans-serif; cursor: pointer;
            box-shadow: 0 7px 18px rgba(42,121,136,.16); transition: all .2s ease;
        }
        .after-products-btn:hover { background: #39a9c0; transform: translateY(-1px); }
        #orders-modal {
            display: none; position: fixed; inset: 0; z-index: 1001;
            background: rgba(42,121,136,0.35); backdrop-filter: blur(4px);
            justify-content: center; align-items: center; padding: 20px;
        }
        #orders-content {
            width: min(760px, 94vw); max-height: 88vh; overflow-y: auto; position: relative;
            padding: 30px; background: #fff; border-radius: 22px;
            box-shadow: 0 20px 60px rgba(0,0,0,.18); font-family: 'Poppins', sans-serif;
            scrollbar-width: thin; scrollbar-color: #2a7988 transparent;
        }
        #reports-modal {
            display: none; position: fixed; inset: 0; z-index: 1002;
            background: rgba(42,121,136,0.35); backdrop-filter: blur(4px);
            justify-content: center; align-items: center; padding: 20px;
        }
        #reports-content {
            width: min(650px, 94vw); max-height: 88vh; overflow-y: auto; position: relative;
            padding: 30px; background: #fff; border-radius: 22px;
            box-shadow: 0 20px 60px rgba(0,0,0,.18); font-family: 'Poppins', sans-serif;
            scrollbar-width: thin; scrollbar-color: #2a7988 transparent;
        }
        #reports-close {
            position: absolute; top: 14px; right: 20px; border: 0; background: transparent;
            color: #9aa0a6; font-size: 30px; line-height: 1; cursor: pointer;
        }
        #reports-close:hover { color: #c31432; }
        #reports-title {
            color: #2a7988; text-align: center; margin: 0 36px 6px;
            font-size: 26px; font-weight: 700;
        }
        .reports-subtitle {
            color: #8a9ba0; text-align: center; font-size: 13px; margin: 0 0 20px;
        }
        .report-history-title {
            color: #2a7988; font-size: 16px; font-weight: 700; margin: 26px 0 12px;
        }
        .report-history-card {
            background: #f4f8f9; border: 1px solid #e3eef0; border-radius: 14px;
            padding: 14px 16px; margin-bottom: 10px;
        }
        .report-history-head {
            display: flex; justify-content: space-between; align-items: center; gap: 10px;
            margin-bottom: 8px;
        }
        .report-history-date { color: #8a9ba0; font-size: 11px; }
        .report-history-status {
            border-radius: 999px; padding: 4px 10px; background: #eee6d4; color: #7a6a45;
            font-size: 11px; font-weight: 600; text-transform: capitalize;
        }
        .report-history-status.handled { background: #e6f4f1; color: #2a7988; }
        .report-history-message {
            color: #405157; font-size: 13px; line-height: 1.55; margin: 0;
            white-space: pre-wrap; overflow-wrap: anywhere;
        }
        #orders-close {
            position: absolute; top: 14px; right: 20px; border: 0; background: transparent;
            color: #9aa0a6; font-size: 30px; line-height: 1; cursor: pointer;
        }
        #orders-close:hover { color: #c31432; }
        #orders-title {
            color: #2a7988; margin: 0 36px 6px 0; font-size: 26px; font-weight: 700;
        }
        .orders-subtitle { color: #8a9ba0; font-size: 13px; margin: 0 0 20px; }
        .overlay-order-card {
            background: #f4f8f9; border: 1px solid #e3eef0; border-radius: 15px;
            padding: 16px 18px; margin-bottom: 12px;
        }
        .overlay-order-head, .overlay-order-foot {
            display: flex; justify-content: space-between; align-items: center; gap: 12px;
        }
        .overlay-order-head { margin-bottom: 10px; }
        .overlay-order-id { color: #26383d; font-weight: 700; font-size: 15px; }
        .overlay-order-date { display: block; color: #8a9ba0; font-size: 11px; font-weight: 400; margin-top: 2px; }
        .overlay-order-status {
            border-radius: 999px; padding: 4px 11px; background: #eee6d4; color: #7a6a45;
            font-size: 11px; font-weight: 600; text-transform: capitalize;
        }
        .overlay-order-status.delivered { background: #e6f4f1; color: #2a7988; }
        .overlay-order-items {
            border-top: 1px solid #e0ebed; border-bottom: 1px solid #e0ebed; padding: 4px 0;
        }
        .overlay-order-item {
            display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 8px 0;
            color: #405157; font-size: 12px;
        }
        .overlay-order-item + .overlay-order-item { border-top: 1px solid #e7eff0; }
        .overlay-order-item-main { display: flex; align-items: center; min-width: 0; gap: 9px; }
        .overlay-order-item-img {
            width: 42px; height: 48px; flex: 0 0 42px; object-fit: cover;
            border-radius: 9px; border: 1px solid #dcebed; background: #fff;
        }
        .overlay-order-item small { color: #8a9ba0; margin-left: 7px; }
        .overlay-order-item strong { color: #2a7988; white-space: nowrap; }
        .overlay-order-total { color: #2a7988; font-weight: 700; font-size: 14px; }
        .overlay-reorder {
            border: 0; border-radius: 999px; background: #2a7988; color: #fff; padding: 7px 13px;
            font: 600 11px 'Poppins', sans-serif; cursor: pointer;
        }
        .overlay-reorder:hover { background: #39a9c0; }
        .orders-empty { color: #8a9ba0; text-align: center; padding: 28px 0; font-size: 14px; }
        .orders-pagination { display: flex; justify-content: center; gap: 6px; margin-top: 18px; }
        .orders-page-link {
            display: inline-flex; align-items: center; justify-content: center; min-width: 32px; height: 32px;
            padding: 0 9px; border-radius: 9px; color: #2a7988; background: #edf4f5;
            font-size: 12px; font-weight: 600; text-decoration: none;
        }
        .orders-page-link:hover, .orders-page-link.active { background: #2a7988; color: #fff; text-decoration: none; }
        @media (max-width: 575.98px) {
            #basket-content {
                width: calc(100vw - 24px);
                max-width: none;
                max-height: 90vh;
                padding: 22px 14px 16px;
                border-radius: 16px;
            }
            #basket-title {
                padding-right: 24px;
                font-size: 20px;
            }
            .basket-item {
                grid-template-columns: 48px minmax(0, 1fr) auto;
                grid-template-areas: "image name price" "image controls price";
                column-gap: 8px;
                padding: 10px;
            }
            .basket-item-img { width: 48px; height: 48px; }
            .basket-item-name { overflow-wrap: anywhere; }
            .basket-item-price { min-width: 55px; font-size: 13px; }
            #orders-modal { padding: 12px; }
            #orders-content { width: 100%; max-height: 92vh; padding: 24px 15px; border-radius: 17px; }
            #orders-title { font-size: 21px; }
            #reports-modal { padding: 12px; }
            #reports-content { width: 100%; max-height: 92vh; padding: 24px 15px; border-radius: 17px; }
            #reports-title { font-size: 21px; }
            .overlay-order-card { padding: 13px; }
            .overlay-order-item { align-items: flex-start; }
            .overlay-order-item small { display: block; margin: 3px 0 0; }
            .overlay-order-foot { align-items: stretch; flex-direction: column; }
            .overlay-reorder { width: 100%; }
            .toast-container {
                width: calc(100vw - 24px);
            }
            .toast {
                width: 100%;
                white-space: normal;
                text-align: center;
            }
        }
        @media (max-width: 799.98px) {
            .old-price {
                font-size: clamp(10px, 2.2vw, 14px) !important;
                margin-right: 3px !important;
            }
            .new-price {
                font-size: clamp(11px, 2.8vw, 16px) !important;
            }
            .sale-badge {
                top: 8px !important;
                left: 8px !important;
                padding: 3px 8px !important;
                font-size: clamp(9px, 2.2vw, 12px) !important;
                box-shadow: 0 3px 8px rgba(195, 20, 50, 0.3) !important;
            }
        }
        @media (max-width: 399.98px) {
            .old-price {
                font-size: 9px !important;
                margin-right: 2px !important;
            }
            .new-price {
                font-size: 11px !important;
            }
            .sale-badge {
                top: 6px !important;
                left: 6px !important;
                padding: 2px 6px !important;
                font-size: 9px !important;
            }
        }
        
    </style>
</head>
<body>
    
    <div class="one">
        <nav class="navbar navbar-expand-lg navbar-light">
            <a class="navbar-brand" href="#two">Home</a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarSupportedContent">
                <ul class="navbar-nav mr-auto">
                <li class="nav-item">
                    <a class="nav-link" href="#five">t-shirts</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#six">shoes</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#seven">winter</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#eight">summer</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#nine">pajamas</a>
                </li>

                <?php if (isset($_SESSION['user_id'])): ?>
                <li class="nav-item">
                    <a class="nav-link" href="?orders=1" onclick="openOrders(); return false;">Previous Orders</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="?reports=1" onclick="openReports(); return false;">Reports</a>
                </li>
                <?php endif; ?>
                </ul>

                <div class="form-inline mr-3 user-nav-section">
                    <?php if (isset($_SESSION['username'])): ?>
                        <span class="navbar-text mr-3">Hello, <?= htmlspecialchars($_SESSION['username']) ?></span>
                        <a href="logout.php" class="btn-logout-custom">Logout</a>
                    <?php else: ?>
                        <a href="login.php" class="btn-login-custom">Login</a>
                    <?php endif; ?>
                </div>

            </div>
            <h3 class="cart" id="cart" style="cursor:pointer;" onclick="toggleBasket()">
                <span id="cart-count">0</span>
                <img src="images/cart.png" width="45px" id="cart-img">
            </h3>
        </nav>
    </div>

    <?php if($order_message): ?>
        <script>
            // Order placed — clear the basket so it starts fresh + show a toast
            localStorage.removeItem('cart');
            document.addEventListener('DOMContentLoaded', function () {
                showToast(<?= json_encode($order_message) ?>);
            });
        </script>
    <?php endif; ?>

    <?php if(isset($_GET['order_placed'])): ?>
        <script>
            localStorage.removeItem('cart');
            document.addEventListener('DOMContentLoaded', function () {
                showToast('Your order has been placed successfully ✓');
                window.history.replaceState({}, document.title, 'index.php');
            });
        </script>
    <?php endif; ?>

    <?php if($report_message): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                showToast(<?= json_encode($report_message) ?>);
            });
        </script>
    <?php endif; ?>

    <?php if(isset($_GET['basket_restored'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                showToast('Saved order added to your basket ✓');
            });
        </script>
    <?php endif; ?>

    <div class="two" id="two">
        <img class="first-img" src="images/main page img.png" >
        <h1 id="shop-name">Aurielle</h1>
        <h2 id="title2">Where Elegance <br>Begins</h2>
        <h5 id="title3">Discover timeless pieces designed for the<br>modern woman</h5>
        <button id="main-button">
            <a href="#three" id="a-button">View Collection</a>
        </button>
    </div>

    <div class="three" id="three">
        <div class="collection">
            <h2>
                <a class="collection-link" href="#five">
                    <img class="collection-img" src="images/t-shirts theme.jfif">
                    t-shirt
                </a>
            </h2>
        </div>
        <div class="collection">
            <h2>
                <a class="collection-link" href="#six">
                    <img class="collection-img" src="images/shoes theme.jfif">
                    shoes
                </a>
            </h2>
        </div>
        <div class="collection">
            <h2>
                <a class="collection-link" href="#seven">
                    <img class="collection-img" src="images/winter theme.jfif">
                    winter
                </a>
            </h2>
        </div>
        <div class="collection">
            <h2>
                <a class="collection-link" href="#eight">
                    <img class="collection-img" src="images/summer theme.jfif">
                    summer
                </a>
            </h2>
        </div>
        <div class="collection">
            <h2>
                <a class="collection-link" href="#nine">
                    <img class="collection-img" src="images/Sweet Dream Pajama Set.jfif">
                    pajamas
                </a>
            </h2>
        </div>
    </div>

    <?php 
    $sections_config = [
        'shirts' => ['title' => 'T-Shirts', 'id' => 'five'],
        'shoes' => ['title' => 'Shoes', 'id' => 'six'],
        'winter' => ['title' => 'Winter', 'id' => 'seven'],
        'summer' => ['title' => 'Summer', 'id' => 'eight'],
        'pajamas' => ['title' => 'Pajamas', 'id' => 'nine']
    ];

    foreach ($sections_config as $key => $sec): 
    ?>
        <div><h1 class="title"><?= $sec['title'] ?></h1></div>
        <div class="<?= $sec['id'] ?>" id="<?= $sec['id'] ?>">
            <?php if(empty($sections[$key])): ?>
                <div class="empty-section-banner">
                    <h3>Nothing here now</h3>
                    <p>Check back soon for new arrivals!</p>
                </div>
            <?php else: ?>
                <?php foreach($sections[$key] as $item):
                    $sale = (int)($item['sale_percent'] ?? 0);
                    $final_price = round($item['price'] * (100 - $sale) / 100, 2);
                ?>
                    <div class="element">
                        <?php if($sale > 0): ?><span class="sale-badge">-<?= $sale ?>%</span><?php endif; ?>
                        <div class="product-image-frame">
                            <img class="element-img" id="img_<?= $item['id'] ?>" src="<?= htmlspecialchars(product_image_src($item['image']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                        </div>
                        <h5 class="product-name"><?= htmlspecialchars($item['name']) ?></h5>
                        <h4 class="product-price">
                            <?php if($sale > 0): ?>
                                <del class="old-price"><?= $item['price'] ?>$</del><span class="new-price"><?= $final_price ?>$</span>
                            <?php else: ?>
                                <?= $item['price'] ?>$
                            <?php endif; ?>
                        </h4>
                        <h5 class="product-size">size : 
                        <select id="sizeID_<?= $item['id'] ?>">
                            <option value="">none</option>
                            <?php if($key === 'shoes'): ?>
                                <option value="36">36</option><option value="37">37</option><option value="38">38</option><option value="39">39</option><option value="40">40</option><option value="41">41</option>
                            <?php else: ?>
                                <option value="small">small</option><option value="medium">medium</option><option value="large">large</option>
                            <?php endif; ?>
                        </select>
                        <br>
                        <button class="add-button" onclick="add_to_basket(<?= $item['id'] ?>, '<?= htmlspecialchars($item['name']) ?>', 'sizeID_<?= $item['id'] ?>', <?= $final_price ?>)">add to basket</button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <!-- Previous Orders Overlay -->
    <div id="orders-modal" aria-hidden="true">
        <div id="orders-content" role="dialog" aria-modal="true" aria-labelledby="orders-title">
            <button type="button" id="orders-close" onclick="closeOrders()" aria-label="Close previous orders">&times;</button>
            <h2 id="orders-title">Previous Orders</h2>
            <p class="orders-subtitle">
                <?= $previous_orders_total ?> <?= $previous_orders_total === 1 ? 'order' : 'orders' ?> saved to your account
            </p>

            <?php if (empty($previous_orders)): ?>
                <div class="orders-empty">Your completed orders will appear here after checkout.</div>
            <?php else: ?>
                <?php foreach ($previous_orders as $po): ?>
                    <?php
                    if (!empty($po['order_items'])) {
                        $po_items = json_decode($po['order_items'], true) ?: [];
                    } else {
                        $po_items = parseOrderDetails($po['order_details']);
                    }
                    $po_items = attach_product_images($po_items, $pdo);
                    $reorder_items = array_map(function ($item) {
                        unset($item['img']);
                        return $item;
                    }, $po_items);
                    ?>
                    <article class="overlay-order-card">
                        <div class="overlay-order-head">
                            <div class="overlay-order-id">
                                Order #<?= (int)$po['id'] ?>
                                <span class="overlay-order-date"><?= htmlspecialchars(date('M j, Y · g:i A', strtotime($po['created_at']))) ?></span>
                            </div>
                            <span class="overlay-order-status <?= $po['status'] === 'delivered' ? 'delivered' : '' ?>">
                                <?= htmlspecialchars($po['status']) ?>
                            </span>
                        </div>
                        <?php if (!empty($po_items)): ?>
                            <div class="overlay-order-items">
                                <?php foreach ($po_items as $po_item): ?>
                                    <?php
                                    $item_count = max(1, (int)($po_item['count'] ?? 1));
                                    $item_price = (float)($po_item['price'] ?? 0);
                                    $item_image = $po_item['img'] ?? '';
                                    ?>
                                    <div class="overlay-order-item">
                                        <span class="overlay-order-item-main">
                                            <?php if ($item_image): ?>
                                                <img class="overlay-order-item-img" src="<?= htmlspecialchars($item_image, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                            <?php else: ?>
                                                <span class="basket-item-img basket-item-noimg overlay-order-item-img">🧺</span>
                                            <?php endif; ?>
                                            <span>
                                                <?= htmlspecialchars($po_item['name'] ?? 'Item') ?>
                                                <small>Size: <?= htmlspecialchars($po_item['size'] ?? '—') ?> · Qty: <?= $item_count ?></small>
                                            </span>
                                        </span>
                                        <strong>$<?= number_format($item_price * $item_count, 2) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="overlay-order-foot" style="margin-top: 12px;">
                            <span class="overlay-order-total">Total: $<?= number_format((float)$po['total_price'], 2) ?></span>
                            <?php if (!empty($po_items)): ?>
                                <form method="POST">
                                    <input type="hidden" name="reorder_items" value="<?= htmlspecialchars(json_encode($reorder_items, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="overlay-reorder">Add to basket</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>

                <?php if ($previous_orders_pages > 1): ?>
                    <div class="orders-pagination" aria-label="Previous order pages">
                        <?php if ($orders_page > 1): ?>
                            <a class="orders-page-link" href="?orders=1&amp;orders_page=<?= $orders_page - 1 ?>">‹</a>
                        <?php endif; ?>
                        <?php for ($page_number = 1; $page_number <= $previous_orders_pages; $page_number++): ?>
                            <a class="orders-page-link <?= $page_number === $orders_page ? 'active' : '' ?>"
                               href="?orders=1&amp;orders_page=<?= $page_number ?>"><?= $page_number ?></a>
                        <?php endfor; ?>
                        <?php if ($orders_page < $previous_orders_pages): ?>
                            <a class="orders-page-link" href="?orders=1&amp;orders_page=<?= $orders_page + 1 ?>">›</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Reports Overlay -->
    <div id="reports-modal" aria-hidden="true">
        <div id="reports-content" role="dialog" aria-modal="true" aria-labelledby="reports-title">
            <button type="button" id="reports-close" onclick="closeReports()" aria-label="Close reports">&times;</button>
            <h2 id="reports-title">Reports</h2>
            <p class="reports-subtitle">Tell us about a problem with an order or the store.</p>

            <div class="report-card">
                <form method="POST" action="index.php?reports=1">
                    <input type="hidden" name="send_report" value="1">
                    <select name="report_order_id" id="report_order_id" class="report-select">
                        <option value="">This is not about a specific order</option>
                        <?php foreach ($previous_orders as $report_order): ?>
                            <option value="<?= (int)$report_order['id'] ?>">Order #<?= (int)$report_order['id'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <textarea name="report_text" class="report-textarea" placeholder="Write your report here..." required></textarea>
                    <button type="submit" class="report-send-btn">Send Report ✓</button>
                </form>
            </div>

            <?php if (!empty($user_reports)): ?>
                <h3 class="report-history-title">Your submitted reports</h3>
                <?php foreach ($user_reports as $user_report): ?>
                    <div class="report-history-card">
                        <div class="report-history-head">
                            <span class="report-history-date">
                                <?= htmlspecialchars(date('M j, Y · g:i A', strtotime($user_report['created_at']))) ?>
                                <?php if (!empty($user_report['order_id'])): ?>
                                    · Order #<?= (int)$user_report['order_id'] ?>
                                <?php endif; ?>
                            </span>
                            <span class="report-history-status <?= $user_report['status'] === 'handled' ? 'handled' : '' ?>">
                                <?= htmlspecialchars($user_report['status']) ?>
                            </span>
                        </div>
                        <p class="report-history-message"><?= htmlspecialchars($user_report['message']) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Basket Modal Overlay -->
    <div id="basket-modal">
        <div id="basket-content">
            <button onclick="toggleBasket()" id="basket-close">&times;</button>
            <h2 id="basket-title">Your Basket 🧺</h2>
            <div id="basket-items-list"></div>
            <div id="basket-total-row">
                <span>Total</span>
                <span class="total-value"><span id="basket-total">0.00</span>$</span>
            </div>

            <div id="checkout-section">
                <h3>Confirm your order</h3>
                <form method="POST" id="checkout-form">
                    <input type="hidden" name="basket_data" id="basket_data">
                    <input type="text" name="phone_number" id="phone_number" placeholder="Your phone number" required>
                    <select name="location" id="location" required>
                        <option value="">Select your location</option>
                        <option value="Palestine-Ramallah">Palestine - Ramallah</option>
                        <option value="Palestine-Jerusalem">Palestine - Jerusalem</option>
                        <option value="Egypt-Cairo">Egypt - Cairo</option>
                        <option value="Saudi Arabia-Riyadh">Saudi Arabia - Riyadh</option>
                        <option value="Jordan-Amman">Jordan - Amman</option>
                        <option value="Iraq-Baghdad">Iraq - Baghdad</option>
                        <option value="United Arab Emirates-Abu Dhabi">United Arab Emirates - Abu Dhabi</option>
                        <option value="Kuwait-Kuwait City">Kuwait - Kuwait City</option>
                        <option value="Morocco-Rabat">Morocco - Rabat</option>
                        <option value="Lebanon-Beirut">Lebanon - Beirut</option>
                        <option value="Syria-Damascus">Syria - Damascus</option>
                        <option value="Yemen-Sana'a">Yemen - Sana'a</option>
                        <option value="Qatar-Doha">Qatar - Doha</option>
                        <option value="Oman-Muscat">Oman - Muscat</option>
                        <option value="Bahrain-Manama">Bahrain - Manama</option>
                        <option value="Sudan-Khartoum">Sudan - Khartoum</option>
                        <option value="Algeria-Algiers">Algeria - Algiers</option>
                        <option value="Tunisia-Tunis">Tunisia - Tunis</option>
                        <option value="Turkey-Ankara">Turkey - Ankara</option>
                        <option value="Pakistan-Islamabad">Pakistan - Islamabad</option>
                        <option value="Iran-Tehran">Iran - Tehran</option>
                    </select>
                    <button type="submit" name="place_order" id="order-btn">Place Order</button>
                </form>
            </div>
        </div>
    </div>

    <div class="final-page">
        <img class="final-img" src="images/Uni_Qlo-removebg-preview.png" alt="">
        <h3>contact us</h3>
        <h6><span style="font-weight: bold;">Facebook : </span>Aurielle_shop_jo</h6>
        <h6><span style="font-weight: bold;">Instagram : </span>Aurielle27shop</h6>
        <h6><span style="font-weight: bold;">phone number : </span>962-78-693-3907</h6>
        <h6>Feedback is always welcome! We love hearing from you.</h6>
        <h6>We start packaging in 2 hours. Delivery time depends on the shipping method.</h6>
        
        <div class="one-line">
            <h1 class="shop-name">Aurielle</h1>
            <h3>Glow in Your Own Way</h3>
        </div>
        <h6>© All rights reserved.</h6>
    </div>

    <script>
        let isLoggedIn = <?= isset($_SESSION['user_id']) ? 'true' : 'false' ?>;
        let savedCart = <?= $saved_cart_json ?>;
        let basket = [];
        if (isLoggedIn) {
            // logged in: the server-saved basket wins, else the local one
            basket = savedCart ? savedCart : (JSON.parse(localStorage.getItem('cart')) || []);
            localStorage.setItem('cart', JSON.stringify(basket));
        } else {
            // logged out = basket is 0 (but it stays saved on the account)
            localStorage.removeItem('cart');
        }
        let cart_count = basket.reduce((sum, item) => sum + item.count, 0);
        document.getElementById("cart-count").innerHTML = cart_count;

        function saveBasket() {
            localStorage.setItem('cart', JSON.stringify(basket));
            if (isLoggedIn) {
                // Keep display-only image URLs out of the database payload.
                const basketForServer = basket.map(({ img, ...item }) => item);
                fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'save_cart=' + encodeURIComponent(JSON.stringify(basketForServer))
                });
            }
        }

        function showToast(msg, isError) {
            let container = document.querySelector('.toast-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'toast-container';
                document.body.appendChild(container);
            }
            let toast = document.createElement('div');
            toast.className = 'toast' + (isError ? ' toast-error' : '');
            toast.textContent = msg;
            container.appendChild(toast);
            setTimeout(() => {
                toast.style.transition = 'opacity .3s';
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }, 2500);
        }

        function toggleBasket() {
            let modal = document.getElementById("basket-modal");
            modal.style.display = modal.style.display === "flex" ? "none" : "flex";
            renderBasketModal();
        }

        function openOrders() {
            const modal = document.getElementById("orders-modal");
            if (!modal) return;
            modal.style.display = "flex";
            modal.setAttribute("aria-hidden", "false");
            document.body.style.overflow = "hidden";
        }

        function closeOrders() {
            const modal = document.getElementById("orders-modal");
            if (!modal) return;
            modal.style.display = "none";
            modal.setAttribute("aria-hidden", "true");
            document.body.style.overflow = "";
        }

        function openReports() {
            const modal = document.getElementById("reports-modal");
            if (!modal) return;
            modal.style.display = "flex";
            modal.setAttribute("aria-hidden", "false");
            document.body.style.overflow = "hidden";
        }

        function closeReports() {
            const modal = document.getElementById("reports-modal");
            if (!modal) return;
            modal.style.display = "none";
            modal.setAttribute("aria-hidden", "true");
            document.body.style.overflow = "";
        }

        document.getElementById("orders-modal").addEventListener("click", function (event) {
            if (event.target === this) closeOrders();
        });

        document.getElementById("reports-modal").addEventListener("click", function (event) {
            if (event.target === this) closeReports();
        });

        <?php if (isset($_GET['orders'])): ?>
            openOrders();
        <?php endif; ?>

        <?php if (isset($_GET['reports'])): ?>
            openReports();
        <?php endif; ?>

        // attach the basket contents to the checkout form before it submits
        document.getElementById("checkout-form").addEventListener("submit", function () {
            // Send the product id, not the large base64 image. PHP rebuilds
            // the image from the products table before saving the order.
            const basketForServer = basket.map(({ img, ...item }) => item);
            document.getElementById("basket_data").value = JSON.stringify(basketForServer);
        });

        function add_to_basket(id, name, sizeID, price) {
            if (!isLoggedIn) {
                showToast("Please sign in to add items 🌸", true);
                setTimeout(() => { window.location.href = "login.php"; }, 1200);
                return;
            }

            let size = document.getElementById(sizeID).value;
            if(size == ""){
                showToast("Please choose a size first!", true);
                return;
            }

            let product_key = name + " - " + size;
            let existing = basket.find(item => item.key === product_key);

            // grab the product photo so the basket rows show what was ordered
            let imgEl = document.getElementById("img_" + id);
            let img = imgEl ? imgEl.src : "";

            if(existing) {
                existing.count += 1;
                if (img) existing.img = img;
                existing.product_id = id;
            } else {
                basket.push({
                    key: product_key,
                    name: name,
                    size: size,
                    price: price,
                    count: 1,
                    product_id: id,
                    img: img
                });
            }

            cart_count += 1;
            document.getElementById("cart-count").innerHTML = cart_count;
            saveBasket();
            showToast("Added to basket ✓");
        }

        function renderBasketModal() {
            let listContainer = document.getElementById("basket-items-list");
            let totalElement = document.getElementById("basket-total");
            listContainer.innerHTML = "";
            let total_price = 0;

            if(basket.length === 0) {
                listContainer.innerHTML = '<div id="basket-empty">Your basket is empty 🧺</div>';
                totalElement.innerHTML = "0.00";
                return;
            }

            basket.forEach((item, index) => {
                let subtotal = (item.price * item.count).toFixed(2);
                total_price += item.price * item.count;
                let imgHtml = item.img
                    ? `<img class="basket-item-img" src="${item.img}" alt="">`
                    : `<div class="basket-item-img basket-item-noimg">🧺</div>`;
                listContainer.innerHTML += `
                    <div class="basket-item">
                        ${imgHtml}
                        <div class="basket-item-name">${item.name}<small>size: ${item.size}</small></div>
                        <div class="qty-controls">
                            <button class="qty-btn" onclick="update_qty(${index}, -1)">&minus;</button>
                            <span class="qty-count">${item.count}</span>
                            <button class="qty-btn" onclick="update_qty(${index}, 1)">+</button>
                        </div>
                        <div class="basket-item-price">${subtotal}$</div>
                    </div>
                `;
            });
            totalElement.innerHTML = total_price.toFixed(2);
        }

        function update_qty(index, change) {
            basket[index].count += change;
            cart_count += change;
            document.getElementById("cart-count").innerHTML = cart_count;

            if(basket[index].count <= 0) {
                basket.splice(index, 1);
            }

            localStorage.setItem('cart', JSON.stringify(basket));
            saveBasket();
            renderBasketModal();
        }

    </script>
</body>
</html>