<?php
session_start();
require_once 'db.php';

// items for the Details column: saved JSON if present, else parse the details text
function admin_order_items($o) {
    if (!empty($o['order_items'])) {
        $decoded = json_decode($o['order_items'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && count($decoded) > 0) {
            return $decoded;
        }
    }
    $items = [];
    foreach (explode("\n", $o['order_details']) as $line) {
        $line = trim($line);
        if (preg_match('/^(.*) \(Size: (.+)\) x (\d+) - \$(\d+(?:\.\d+)?)$/', $line, $m)) {
            $count = (int)$m[3];
            $items[] = [
                'key'   => $m[1] . ' - ' . $m[2],
                'name'  => $m[1],
                'size'  => $m[2],
                'count' => $count,
                'price' => $count > 0 ? round((float)$m[4] / $count, 2) : (float)$m[4],
            ];
        }
    }
    return $items;
}

function attach_order_item_images(array $items, PDO $pdo): array {
    $by_id = [];
    $by_name = [];
    foreach ($items as $index => $item) {
        if (!is_array($item) || !empty($item['img'])) {
            continue;
        }

        $product_id = (int)($item['product_id'] ?? 0);
        $name = trim((string)($item['name'] ?? ''));
        if ($product_id > 0) {
            $by_id[$product_id][] = $index;
        } elseif ($name !== '') {
            $by_name[$name][] = $index;
        }
    }

    if (!empty($by_id)) {
        $stmt = $pdo->prepare("SELECT image FROM products WHERE id = ? LIMIT 1");
        foreach ($by_id as $product_id => $indexes) {
            $stmt->execute([$product_id]);
            $image = $stmt->fetchColumn();
            $image_src = $image ? product_image_src($image) : '';
            foreach ($indexes as $index) {
                if ($image_src !== '') {
                    $items[$index]['img'] = $image_src;
                }
            }
        }
    }

    if (!empty($by_name)) {
        $stmt = $pdo->prepare("SELECT image FROM products WHERE name = ? LIMIT 1");
        foreach ($by_name as $name => $indexes) {
            $stmt->execute([$name]);
            $image = $stmt->fetchColumn();
            $image_src = $image ? product_image_src($image) : '';
            foreach ($indexes as $index) {
                if ($image_src !== '') {
                    $items[$index]['img'] = $image_src;
                }
            }
        }
    }

    return $items;
}

function product_image_src($image) {
    if (empty($image)) {
        return '';
    }

    $image_info = @getimagesizefromstring($image);
    $mime = $image_info['mime'] ?? 'image/jpeg';
    if (strpos($mime, 'image/') !== 0) {
        $mime = 'image/jpeg';
    }

    return 'data:' . $mime . ';base64,' . base64_encode($image);
}

// Self-healing: reports table (same shape index.php creates)
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
} catch (\PDOException $e) { /* ignore */ }

// Keep the catalog compatible with older databases that predate percentage sales.
try {
    $pdo->exec("ALTER TABLE products ADD sale_percent INT DEFAULT 0");
} catch (\PDOException $e) { /* column already exists */ }

// Hardcoded admin login logic or session check
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_POST['username'] === 'admin' && $_POST['password'] === '200510') {
        $_SESSION['is_admin'] = 1;
        $_SESSION['username'] = 'admin';
    } else {
        if (isset($_POST['username'])) {
            $error = "Invalid admin credentials.";
        }
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Admin Login - Aurielle Shop</title>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.0.0/dist/css/bootstrap.min.css">
            <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
            <style>
                body {
                    font-family: 'Poppins', sans-serif;
                    background: #f4f6fc;
                    height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .login-card {
                    background: #ffffff;
                    padding: 40px;
                    border-radius: 16px;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.05);
                    width: 100%;
                    max-width: 420px;
                }
                .login-card h2 {
                    color: #2a7988;
                    font-weight: 600;
                    margin-bottom: 25px;
                    text-align: center;
                }
                .btn-custom {
                    background-color: #2a7988;
                    color: white;
                    border-radius: 25px;
                    border: none;
                    font-weight: 600;
                    padding: 10px;
                    transition: all 0.2s ease;
                }
                .btn-custom:hover {
                    background-color: #39a9c0;
                    color: white;
                }
                @media (max-width: 575.98px) {
                    body {
                        padding: 12px;
                    }
                    .login-card {
                        padding: 24px 16px;
                        border-radius: 14px;
                    }
                    .login-card h2 {
                        font-size: 1.4rem;
                        margin-bottom: 20px;
                    }
                }
            </style>
        </head>
        <body>
            <div class="login-card">
                <h2>Admin Login</h2>
                <?php if(isset($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-custom btn-block mt-4">Login</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Handle Product Add / Update / Delete
if (isset($_POST['action'])) {
    if ($_POST['action'] == 'add') {
        $name = $_POST['name'];
        $section = $_POST['section'];
        $price = $_POST['price'];
        $image_data = null;

        // Process uploaded file into binary data for database storage
        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $image_data = file_get_contents($_FILES['image_file']['tmp_name']);
        }

        $stmt = $pdo->prepare("INSERT INTO products (name, section, price, image) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $section, $price, $image_data]);
    } elseif ($_POST['action'] == 'update') {
        $sale_percent = max(0, min(90, (int)$_POST['sale_percent']));
        $new_image = null;
        $replace_image = false;

        if (isset($_FILES['edit_image_file']) && $_FILES['edit_image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['edit_image_file']['error'] === UPLOAD_ERR_OK
                && $_FILES['edit_image_file']['size'] <= 5 * 1024 * 1024) {
                $candidate_image = file_get_contents($_FILES['edit_image_file']['tmp_name']);
                if ($candidate_image !== false && @getimagesizefromstring($candidate_image) !== false) {
                    $new_image = $candidate_image;
                    $replace_image = true;
                }
            }
        }

        if ($replace_image) {
            $stmt = $pdo->prepare("UPDATE products SET name = ?, price = ?, sale_percent = ?, image = ? WHERE id = ?");
            $stmt->execute([$_POST['name'], $_POST['price'], $sale_percent, $new_image, $_POST['id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE products SET name = ?, price = ?, sale_percent = ? WHERE id = ?");
            $stmt->execute([$_POST['name'], $_POST['price'], $sale_percent, $_POST['id']]);
        }
    } elseif ($_POST['action'] == 'bulk_sale') {
        $sale_percent = max(0, min(90, (int)$_POST['bulk_sale_percent']));
        $bulk_section = $_POST['bulk_sale_section'] ?? 'all';
        $allowed_sections = ['shirts', 'shoes', 'winter', 'summer', 'pajamas'];
        if ($bulk_section === 'all') {
            $stmt = $pdo->prepare("UPDATE products SET sale_percent = ?");
            $stmt->execute([$sale_percent]);
        } elseif (in_array($bulk_section, $allowed_sections, true)) {
            $stmt = $pdo->prepare("UPDATE products SET sale_percent = ? WHERE section = ?");
            $stmt->execute([$sale_percent, $bulk_section]);
        }
    } elseif ($_POST['action'] == 'handle_report') {
        $stmt = $pdo->prepare("UPDATE reports SET status = 'handled' WHERE id = ?");
        $stmt->execute([$_POST['report_id']]);
        $redirect_tab = 'reports-section';
    } elseif ($_POST['action'] == 'delete') {
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$_POST['id']]);
    } elseif ($_POST['action'] == 'mark_delivered') {
        $stmt = $pdo->prepare("UPDATE orders SET status = 'delivered' WHERE id = ?");
        $stmt->execute([$_POST['order_id']]);
    } elseif ($_POST['action'] == 'update_order_status') {
        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$_POST['status'], $_POST['order_id']]);
    }
    
    $redirect_tab = $_POST['active_tab'] ?? 'add-item-section';
    header("Location: admin.php?tab=" . $redirect_tab);
    exit;
}

$products = $pdo->query("SELECT * FROM products ORDER BY section")->fetchAll();
$orders = $pdo->query("SELECT o.*, u.username FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.status = 'sent' ORDER BY o.created_at DESC")->fetchAll();
$reports = $pdo->query("SELECT * FROM reports ORDER BY created_at DESC")->fetchAll();

$stmt_new_reports = $pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'new'");
$new_reports_count = $stmt_new_reports->fetchColumn();

$stmt_undelivered = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'sent'");
$undelivered_count = $stmt_undelivered->fetchColumn();

$active_tab = $_GET['tab'] ?? 'add-item-section';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - Aurielle</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.0.0/dist/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f4f6fc;
            color: #333;
        }
        .admin-navbar {
            background: #ffffff;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .admin-brand {
            font-weight: 700;
            font-size: 22px;
            color: #2a7988;
            text-decoration: none !important;
        }
        .nav-tabs-custom {
            display: flex;
            gap: 10px;
            list-style: none;
            margin: 0;
            padding: 0;
            align-items: center;
        }
        .nav-tabs-custom a {
            text-decoration: none;
            color: #6c757d;
            font-weight: 500;
            font-size: clamp(12px, calc(10px + 0.38vw), 16px);
            line-height: 1.2;
            padding: 8px clamp(7px, 1.25vw, 18px);
            border-radius: 20px;
            transition: all 0.2s ease;
            position: relative;
            white-space: nowrap;
        }
        .nav-tabs-custom li { flex: 0 0 auto; }
        .nav-tabs-custom a:hover, .nav-tabs-custom a.active-tab {
            color: #2a7988;
            background: rgba(42, 121, 136, 0.08);
        }
        .nav-tabs-custom a.active-tab {
            font-weight: 600;
            background: rgba(42, 121, 136, 0.12);
        }
        .badge-pending {
            background-color: #af2421;
            color: white;
            border-radius: 50%;
            width: clamp(21px, calc(18px + 0.35vw), 27px);
            height: clamp(21px, calc(18px + 0.35vw), 27px);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(10px, calc(8px + 0.25vw), 13px);
            position: relative;
            top: -2px;
            font-weight: 600;
        }
        .admin-actions {
            display: flex;
            gap: 10px;
        }
        .main-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .tab-pane {
            display: none;
        }
        .tab-pane.active-pane {
            display: block;
            animation: fadeIn 0.3s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .admin-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            margin-bottom: 35px;
            border: none;
        }
        .admin-card h3 {
            color: #2a7988;
            font-weight: 600;
            font-size: 20px;
            margin-bottom: 20px;
        }
        .inventory-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
            padding: 16px;
            background: #f8fbfc;
            border: 1px solid #e4edf0;
            border-radius: 12px;
        }
        .inventory-search {
            flex: 1 1 260px;
            min-width: 220px;
        }
        .section-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .section-filter {
            border: 1px solid #cfe1e5;
            background: #fff;
            color: #2a7988;
            border-radius: 18px;
            padding: 7px 13px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s ease;
        }
        .section-filter:hover,
        .section-filter.active {
            background: #2a7988;
            color: #fff;
            border-color: #2a7988;
        }
        .section-filter[data-filter="shirts"].active {
            background: #4b9b63;
            border-color: #4b9b63;
        }
        .section-filter[data-filter="shoes"].active {
            background: #6f5aa7;
            border-color: #6f5aa7;
        }
        .section-filter[data-filter="winter"].active {
            background: #3e78a8;
            border-color: #3e78a8;
        }
        .section-filter[data-filter="summer"].active {
            background: #d48736;
            border-color: #d48736;
        }
        .section-filter[data-filter="pajamas"].active {
            background: #b66b8a;
            border-color: #b66b8a;
        }
        .bulk-sale-box {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
            margin-bottom: 22px;
            padding: 18px;
            border: 1px solid #f1d3d2;
            background: #fff8f7;
            border-radius: 12px;
        }
        .bulk-sale-copy h4 {
            color: #af2421;
            font-size: 16px;
            font-weight: 700;
            margin: 0 0 4px;
        }
        .bulk-sale-copy p {
            color: #7d6b6a;
            font-size: 12px;
            margin: 0;
        }
        .bulk-sale-form {
            display: flex;
            align-items: end;
            gap: 8px;
            flex-wrap: wrap;
        }
        .bulk-sale-form label {
            color: #6c757d;
            font-size: 12px;
            font-weight: 600;
            margin: 0;
        }
        .bulk-sale-form .form-control {
            width: 110px;
            margin-top: 4px;
        }
        .inventory-count {
            color: #7d939c;
            font-size: 12px;
            margin: 0;
        }
        .inventory-table-wrap {
            max-height: 66vh;
            overflow: auto;
            border: 1px solid #eef1f3;
            border-radius: 12px;
        }
        .inventory-table-wrap thead th {
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .inventory-table {
            width: 100%;
            min-width: 900px;
            table-layout: fixed;
            margin: 0;
        }
        .inventory-table .inventory-col-image { width: 82px; }
        .inventory-table .inventory-col-id { width: 70px; }
        .inventory-table .inventory-col-name { width: auto; }
        .inventory-table .inventory-col-section { width: 118px; }
        .inventory-table .inventory-col-price { width: 98px; }
        .inventory-table .inventory-col-actions { width: 190px; }
        .inventory-table th,
        .inventory-table td {
            overflow: hidden;
            vertical-align: middle !important;
        }
        .inventory-table th {
            white-space: nowrap;
        }
        .inventory-table .inventory-image-cell,
        .inventory-table .inventory-id-cell,
        .inventory-table .inventory-section-cell,
        .inventory-table .inventory-price-cell {
            white-space: nowrap;
        }
        .inventory-product-name {
            display: inline-block;
            max-width: calc(100% - 58px);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: middle;
        }
        .inventory-actions {
            white-space: nowrap;
        }
        .inventory-actions form {
            display: inline;
        }
        .inventory-actions .btn {
            margin-right: 4px;
        }
        .product-row.is-hidden,
        .edit-row.is-hidden {
            display: none !important;
        }
        .product-row td:first-child {
            border-left: 5px solid transparent;
        }
        .product-row[data-section="shirts"] {
            background: #f1faf3;
        }
        .product-row[data-section="shirts"] td:first-child {
            border-left-color: #4b9b63;
        }
        .product-row[data-section="shoes"] {
            background: #f7f4fc;
        }
        .product-row[data-section="shoes"] td:first-child {
            border-left-color: #6f5aa7;
        }
        .product-row[data-section="winter"] {
            background: #f2f7fc;
        }
        .product-row[data-section="winter"] td:first-child {
            border-left-color: #3e78a8;
        }
        .product-row[data-section="summer"] {
            background: #fff8ef;
        }
        .product-row[data-section="summer"] td:first-child {
            border-left-color: #d48736;
        }
        .product-row[data-section="pajamas"] {
            background: #fff5f9;
        }
        .product-row[data-section="pajamas"] td:first-child {
            border-left-color: #b66b8a;
        }
        .edit-row[data-edit-section="shirts"] > td {
            background: #e7f5ea;
            border-left: 5px solid #4b9b63;
        }
        .edit-row[data-edit-section="shoes"] > td {
            background: #f0ebfa;
            border-left: 5px solid #6f5aa7;
        }
        .edit-row[data-edit-section="winter"] > td {
            background: #eaf3fb;
            border-left: 5px solid #3e78a8;
        }
        .edit-row[data-edit-section="summer"] > td {
            background: #fff2df;
            border-left: 5px solid #d48736;
        }
        .edit-row[data-edit-section="pajamas"] > td {
            background: #ffedf5;
            border-left: 5px solid #b66b8a;
        }
        .inventory-section-badge {
            min-width: 72px;
            display: inline-block;
            text-align: center;
            color: #fff !important;
            border: none !important;
        }
        .inventory-section-badge.section-shirts { background: #4b9b63; }
        .inventory-section-badge.section-shoes { background: #6f5aa7; }
        .inventory-section-badge.section-winter { background: #3e78a8; }
        .inventory-section-badge.section-summer { background: #d48736; }
        .inventory-section-badge.section-pajamas { background: #b66b8a; }
        .table-custom {
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            border: none;
        }
        .table-custom th {
            background-color: #fafbfc;
            border-top: none;
            color: #2a7988;
            font-weight: 600;
            font-size: 14px;
        }
        .table-custom td {
            vertical-align: middle !important;
            font-size: 14px;
            color: #495057;
        }
        .form-control {
            border-radius: 10px;
            border: 1px solid #e0e4ed;
            font-size: 14px;
            padding: 10px 15px;
        }
        .form-control:focus {
            box-shadow: none;
            border-color: #2a7988;
        }
        .btn-primary-custom {
            background-color: #2a7988;
            color: white;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            padding: 10px 20px;
            transition: all 0.2s ease;
        }
        .btn-primary-custom:hover {
            background-color: #39a9c0;
            color: white;
        }
        .btn-danger-custom {
            background-color: #af2421;
            color: white;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            padding: 6px 14px;
            font-size: 13px;
        }
        .btn-danger-custom:hover {
            background-color: #c93b38;
            color: white;
        }
        pre {
            background: #f8f9fa;
            padding: 8px;
            border-radius: 6px;
            font-size: 12px;
            margin: 0;
            max-width: 250px;
            white-space: pre-wrap;
        }
        .product-thumb {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #eee;
        }
        .sale-badge {
            background-color: #af2421;
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            vertical-align: middle;
            margin-left: 6px;
            letter-spacing: 0.5px;
        }
        .edit-row form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            background: #fafbfc;
            padding: 12px;
            border-radius: 10px;
        }
        .edit-row label {
            font-size: 13px;
            color: #2a7988;
            font-weight: 600;
            margin: 0;
        }
        /* basket-style order items list (Details column) */
        .order-items-list {
            background: #f7f9fa; border: 1px solid #e4edf0; border-radius: 10px;
            padding: 8px 12px; min-width: 280px; max-width: 360px;
            font-family: 'Poppins', sans-serif;
        }
        .oi-row {
            display: flex; justify-content: space-between; align-items: center;
            gap: 12px; padding: 6px 0; border-bottom: 1px dashed #d8e6ea;
        }
        .oi-row:last-child { border-bottom: none; }
        .oi-main { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .oi-image {
            width: 46px; height: 46px; object-fit: cover; border-radius: 8px;
            border: 1px solid #e4edf0; flex: 0 0 auto; background: #f7f9fa;
        }
        .oi-no-image {
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
        }
        .oi-name { font-size: 13px; font-weight: 600; color: #2b3a42; line-height: 1.35; }
        .oi-name small { display: block; font-size: 11px; font-weight: 400; color: #7d939c; }
        .oi-price { font-size: 13px; font-weight: 700; color: #2a7988; white-space: nowrap; }
        /* order items modal */
        #admin-items-modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(42,121,136,0.35); backdrop-filter: blur(3px); z-index: 2000;
            justify-content: center; align-items: center; padding: 16px;
            box-sizing: border-box; overflow-y: auto;
        }
        #admin-items-modal .admin-items-card {
            background: #fff; border-radius: 20px; width: 420px; max-width: 92vw;
            max-height: calc(100vh - 32px); padding: 24px; box-shadow: 0 20px 50px rgba(0,0,0,0.18);
            font-family: 'Poppins', sans-serif; position: relative; display: flex;
            flex-direction: column; box-sizing: border-box; min-height: 0;
        }
        #admin-items-modal h2 { color: #2a7988; font-size: 20px; font-weight: 700; margin: 0 0 14px; }
        #admin-items-modal #admin-items-list {
            max-height: calc(100vh - 170px); min-height: 0; overflow-y: auto;
            overscroll-behavior: contain; -webkit-overflow-scrolling: touch;
            scrollbar-width: thin; padding-right: 8px;
        }
        #admin-items-close {
            position: absolute; top: 10px; right: 16px; background: none; border: none;
            font-size: 26px; color: #7d939c; cursor: pointer; line-height: 1;
        }
        #admin-items-close:hover { color: #2a7988; }
        #admin-items-total-row {
            display: flex; justify-content: space-between; align-items: center;
            border-top: 2px dashed #d8e6ea; margin-top: 10px; padding-top: 12px;
            font-weight: 700; color: #2a7988; flex: 0 0 auto;
        }
        @media (max-width: 991.98px) {
            body {
                overflow-x: hidden;
            }
            .admin-navbar {
                padding: 12px 16px;
                gap: 10px;
                flex-wrap: wrap;
            }
            .admin-brand {
                font-size: 19px;
            }
            .nav-tabs-custom {
                order: 3;
                flex: 1 0 100%;
                min-width: 0;
                gap: 4px;
                overflow-x: auto;
                padding-bottom: 2px;
                justify-content: flex-start;
                -webkit-overflow-scrolling: touch;
            }
            .nav-tabs-custom li {
                flex: 0 0 auto;
            }
            .nav-tabs-custom a {
                display: block;
                padding: 7px 11px;
                font-size: clamp(11px, calc(9px + 0.5vw), 15px);
                white-space: nowrap;
            }
            .admin-actions {
                margin-left: auto;
                gap: 6px;
            }
            .main-container {
                max-width: 100%;
                margin: 24px auto;
                padding: 0 12px;
            }
            .admin-card {
                padding: 20px 16px;
                margin-bottom: 22px;
                border-radius: 13px;
            }
            .admin-card h3 {
                font-size: 18px;
                line-height: 1.25;
            }
            .inventory-toolbar,
            .bulk-sale-box {
                padding: 12px;
                gap: 10px;
            }
            .inventory-search {
                flex-basis: 100%;
                min-width: 0;
            }
            .section-filters {
                width: 100%;
            }
            .bulk-sale-copy,
            .bulk-sale-form {
                width: 100%;
            }
            .bulk-sale-form {
                align-items: stretch;
            }
            .bulk-sale-form label {
                flex: 1 1 140px;
            }
            .bulk-sale-form .form-control {
                width: 100%;
            }
            .bulk-sale-form .btn {
                flex: 1 1 100%;
            }
            .inventory-table-wrap {
                max-height: none;
                overflow-x: auto;
            }
            .inventory-table {
                min-width: 760px;
            }
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .table-custom {
                min-width: 680px;
            }
            #admin-items-modal {
                padding: 10px;
            }
            #admin-items-modal .admin-items-card {
                width: 100%;
                max-width: 100%;
                padding: 20px 14px;
                border-radius: 15px;
            }
            #admin-items-modal .order-items-list {
                min-width: 0;
            }
        }
        @media (max-width: 575.98px) {
            .admin-navbar {
                padding: 10px 12px;
            }
            .admin-brand {
                font-size: 17px;
            }
            .admin-actions .btn {
                padding: 5px 9px !important;
                font-size: 11px;
            }
            .main-container {
                margin-top: 18px;
                padding: 0 8px;
            }
            .admin-card {
                padding: 16px 11px;
            }
            .section-filter {
                flex: 1 1 calc(50% - 8px);
                padding: 7px 5px;
            }
            .inventory-table {
                min-width: 720px;
            }
            .table-custom {
                min-width: 620px;
            }
            .edit-row form {
                align-items: stretch;
            }
            .edit-row form .form-control,
            .edit-row form button,
            .edit-row form small {
                width: 100% !important;
                max-width: none !important;
            }
        }
        @media (max-width: 991.98px) {
            .nav-tabs-custom {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                width: 100%;
                overflow: visible;
                gap: 6px;
            }
            .nav-tabs-custom li,
            .nav-tabs-custom a {
                width: 100%;
            }
            .nav-tabs-custom a {
                text-align: center;
                padding: 8px 6px;
                font-size: clamp(11px, calc(9px + 0.5vw), 15px);
            }
        }
        @media (max-width: 767.98px) {
            .inventory-table-wrap,
            .table-responsive {
                overflow-x: visible;
            }
            .inventory-table,
            #orders-section .table-custom,
            #reports-section .table-custom {
                min-width: 0;
                width: 100%;
                display: block;
                table-layout: auto;
            }
            .inventory-table thead,
            #orders-section .table-custom thead,
            #reports-section .table-custom thead {
                display: none;
            }
            .inventory-table tbody,
            .inventory-table .product-row,
            .inventory-table .edit-row,
            #orders-section .table-custom tbody,
            #reports-section .table-custom tbody {
                display: block;
                width: 100%;
            }
            .inventory-table .product-row,
            #orders-section .table-custom tbody tr,
            #reports-section .table-custom tbody tr {
                display: block;
                margin-bottom: 10px;
                padding: 10px;
                border: 1px solid #e9eef0;
                border-radius: 10px;
                background: #fff;
            }
            .inventory-table .product-row td,
            #orders-section .table-custom tbody td,
            #reports-section .table-custom tbody td {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                width: 100%;
                min-height: 34px;
                padding: 6px 0;
                border: 0;
                text-align: right;
                white-space: normal;
                overflow: visible;
            }
            .inventory-table .product-row td::before,
            #orders-section .table-custom tbody td::before,
            #reports-section .table-custom tbody td::before {
                flex: 0 0 auto;
                color: #7d939c;
                font-size: 11px;
                font-weight: 600;
                text-align: left;
            }
            .inventory-table .product-row td:nth-child(1)::before { content: "Image"; }
            .inventory-table .product-row td:nth-child(2)::before { content: "ID"; }
            .inventory-table .product-row td:nth-child(3)::before { content: "Name"; }
            .inventory-table .product-row td:nth-child(4)::before { content: "Section"; }
            .inventory-table .product-row td:nth-child(5)::before { content: "Price"; }
            .inventory-table .product-row td:nth-child(6)::before { content: "Action"; }
            #orders-section .table-custom tbody td:nth-child(1)::before { content: "Order ID"; }
            #orders-section .table-custom tbody td:nth-child(2)::before { content: "User"; }
            #orders-section .table-custom tbody td:nth-child(3)::before { content: "Mobile"; }
            #orders-section .table-custom tbody td:nth-child(4)::before { content: "Location"; }
            #orders-section .table-custom tbody td:nth-child(5)::before { content: "Details"; }
            #orders-section .table-custom tbody td:nth-child(6)::before { content: "Total"; }
            #orders-section .table-custom tbody td:nth-child(7)::before { content: "Status"; }
            #orders-section .table-custom tbody td:nth-child(8)::before { content: "Action"; }
            #reports-section .table-custom tbody td:nth-child(1)::before { content: "Report ID"; }
            #reports-section .table-custom tbody td:nth-child(2)::before { content: "User"; }
            #reports-section .table-custom tbody td:nth-child(3)::before { content: "Order"; }
            #reports-section .table-custom tbody td:nth-child(4)::before { content: "Message"; }
            #reports-section .table-custom tbody td:nth-child(5)::before { content: "Date"; }
            #reports-section .table-custom tbody td:nth-child(6)::before { content: "Status"; }
            #reports-section .table-custom tbody td:nth-child(7)::before { content: "Action"; }
            .inventory-table .inventory-product-name {
                max-width: 58%;
                white-space: normal;
                overflow-wrap: anywhere;
                text-align: right;
            }
            .inventory-table .inventory-actions {
                white-space: normal;
                text-align: right;
            }
            .inventory-table .edit-row > td {
                display: block;
                padding: 0;
            }
            .inventory-table .edit-row form {
                width: 100%;
            }
            .order-items-list {
                min-width: 0;
                max-width: 100%;
            }
            .oi-row {
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <nav class="admin-navbar">
        <a href="admin.php?tab=add-item-section" class="admin-brand">Aurielle Admin</a>
        <ul class="nav-tabs-custom">
            <li>
                <a href="admin.php?tab=add-item-section" class="<?= $active_tab == 'add-item-section' ? 'active-tab' : '' ?>">Add New Item</a>
            </li>
            <li>
                <a href="admin.php?tab=products-section" class="<?= $active_tab == 'products-section' ? 'active-tab' : '' ?>">Products Inventory</a>
            </li>
            <li>
                <a href="admin.php?tab=orders-section" class="<?= $active_tab == 'orders-section' ? 'active-tab' : '' ?>">
                    Customer Orders
                    <?php if ($undelivered_count > 0): ?>
                        <span class="badge-pending"><?= $undelivered_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="admin.php?tab=reports-section" class="<?= $active_tab == 'reports-section' ? 'active-tab' : '' ?>">
                    Reports
                    <?php if ($new_reports_count > 0): ?>
                        <span class="badge-pending"><?= $new_reports_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
        <div class="admin-actions">
            <a href="index.php" class="btn btn-outline-secondary btn-sm" style="border-radius: 20px; padding: 6px 15px;">View Store</a>
            <a href="logout.php?admin=1" class="btn btn-outline-danger btn-sm" style="border-radius: 20px; padding: 6px 15px;">Logout</a>
        </div>
    </nav>

    <div class="main-container">

        <!-- Add New Item Tab Pane -->
        <div class="tab-pane <?= $active_tab == 'add-item-section' ? 'active-pane' : '' ?>" id="add-item-section">
            <div class="admin-card">
                <h3>Add New Item</h3>
                <!-- enctype="multipart/form-data" is required for file uploading -->
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="active_tab" value="add-item-section">
                    <div class="form-row">
                        <div class="col-md-3 mb-3">
                            <label class="small text-muted font-weight-bold">Product Name</label>
                            <input type="text" name="name" class="form-control" placeholder="Enter product name" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="small text-muted font-weight-bold">Section</label>
                            <select name="section" class="form-control">
                                <option value="shirts">Shirts</option>
                                <option value="shoes">Shoes</option>
                                <option value="winter">Winter</option>
                                <option value="summer">Summer</option>
                                <option value="pajamas">Pajamas</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="small text-muted font-weight-bold">Price ($)</label>
                            <input type="number" step="0.01" name="price" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="small text-muted font-weight-bold">Product Image File</label>
                            <input type="file" name="image_file" class="form-control-file mt-1" accept="image/*" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary-custom mt-2">Add Item to Catalog</button>
                </form>
            </div>
        </div>

        <!-- Existing Products Tab Pane -->
        <div class="tab-pane <?= $active_tab == 'products-section' ? 'active-pane' : '' ?>" id="products-section">
            <div class="admin-card">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <h3 class="mb-2">Existing Products</h3>
                    <p class="inventory-count mb-2"><?= count($products) ?> product<?= count($products) == 1 ? '' : 's' ?> in catalog</p>
                </div>

                <div class="bulk-sale-box">
                    <div class="bulk-sale-copy">
                        <h4>Bulk sale</h4>
                        <p>Apply a discount to the whole catalog or to one section. Use 0% to remove sales from the selected scope.</p>
                    </div>
                    <form method="POST" class="bulk-sale-form" onsubmit="return confirmBulkSale(this);">
                        <input type="hidden" name="action" value="bulk_sale">
                        <input type="hidden" name="active_tab" value="products-section">
                        <label>Apply to
                            <select name="bulk_sale_section" class="form-control">
                                <option value="all">All products</option>
                                <option value="shirts">Shirts</option>
                                <option value="shoes">Shoes</option>
                                <option value="winter">Winter</option>
                                <option value="summer">Summer</option>
                                <option value="pajamas">Pajamas</option>
                            </select>
                        </label>
                        <label>Discount %
                            <input type="number" name="bulk_sale_percent" class="form-control" min="0" max="90" step="5" value="0" required>
                        </label>
                        <button type="submit" class="btn btn-danger-custom">Apply sale</button>
                    </form>
                </div>

                <div class="inventory-toolbar">
                    <input type="search" id="inventory-search" class="form-control inventory-search" placeholder="Search by product name or ID..." oninput="filterInventory()">
                    <div class="section-filters" role="group" aria-label="Filter products by section">
                        <button type="button" class="section-filter active" data-filter="all" onclick="setInventoryFilter('all', this)">All</button>
                        <button type="button" class="section-filter" data-filter="shirts" onclick="setInventoryFilter('shirts', this)">Shirts</button>
                        <button type="button" class="section-filter" data-filter="shoes" onclick="setInventoryFilter('shoes', this)">Shoes</button>
                        <button type="button" class="section-filter" data-filter="winter" onclick="setInventoryFilter('winter', this)">Winter</button>
                        <button type="button" class="section-filter" data-filter="summer" onclick="setInventoryFilter('summer', this)">Summer</button>
                        <button type="button" class="section-filter" data-filter="pajamas" onclick="setInventoryFilter('pajamas', this)">Pajamas</button>
                    </div>
                </div>

                <div class="inventory-table-wrap">
                    <table class="table table-custom table-hover inventory-table">
                        <colgroup>
                            <col class="inventory-col-image">
                            <col class="inventory-col-id">
                            <col class="inventory-col-name">
                            <col class="inventory-col-section">
                            <col class="inventory-col-price">
                            <col class="inventory-col-actions">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Section</th>
                                <th>Price</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($products as $p): ?>
                            <tr class="product-row" data-product-name="<?= htmlspecialchars(strtolower($p['name']), ENT_QUOTES) ?>" data-product-id="<?= $p['id'] ?>" data-section="<?= htmlspecialchars(strtolower($p['section']), ENT_QUOTES) ?>">
                                <td class="inventory-image-cell">
                                    <?php if(!empty($p['image'])): ?>
                                        <!-- Render binary image data from database using base64 data URI -->
                                        <img src="<?= htmlspecialchars(product_image_src($p['image']), ENT_QUOTES, 'UTF-8') ?>" class="product-thumb" alt="Product Image">
                                    <?php else: ?>
                                        <span class="text-muted small">No Image</span>
                                    <?php endif; ?>
                                </td>
                                <td class="inventory-id-cell">#<?= $p['id'] ?></td>
                                <td class="font-weight-bold inventory-name-cell">
                                    <span class="inventory-product-name"><?= htmlspecialchars($p['name']) ?></span>
                                    <?php if ((int)($p['sale_percent'] ?? 0) > 0): ?><span class="sale-badge">-<?= (int)$p['sale_percent'] ?>%</span><?php endif; ?>
                                </td>
                                <td class="inventory-section-cell"><span class="badge text-uppercase px-2 py-1 inventory-section-badge section-<?= htmlspecialchars(strtolower($p['section']), ENT_QUOTES) ?>"><?= htmlspecialchars($p['section']) ?></span></td>
                                <td class="inventory-price-cell">$<?= number_format($p['price'], 2) ?></td>
                                <td class="inventory-actions">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="active_tab" value="products-section">
                                        <button type="submit" class="btn btn-danger-custom">Delete</button>
                                    </form>
                                    <button type="button" class="btn btn-primary-custom" style="padding: 6px 14px; font-size: 13px;" onclick="toggleEditRow(<?= $p['id'] ?>)">Edit</button>
                                </td>
                            </tr>
                            <tr class="edit-row" id="edit-row-<?= $p['id'] ?>" data-edit-section="<?= htmlspecialchars(strtolower($p['section']), ENT_QUOTES) ?>" style="display:none;">
                                <td colspan="6">
                                    <form method="POST" enctype="multipart/form-data">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="active_tab" value="products-section">
                                        <label>Name
                                            <input type="text" name="name" class="form-control" style="width: 180px;" value="<?= htmlspecialchars($p['name']) ?>" required>
                                        </label>
                                        <label>Price ($)
                                            <input type="number" step="0.01" name="price" class="form-control" style="width: 110px;" value="<?= $p['price'] ?>" required>
                                        </label>
                                        <label>Sale %
                                            <input type="number" min="0" max="90" step="5" name="sale_percent" class="form-control" style="width: 90px;" value="<?= (int)($p['sale_percent'] ?? 0) ?>">
                                        </label>
                                        <label>Change Image
                                            <input type="file" name="edit_image_file" class="form-control-file mt-1" accept="image/*">
                                        </label>
                                        <small class="text-muted" style="width: 130px;">0 = no sale</small>
                                        <button type="submit" class="btn btn-primary-custom btn-sm">Save</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleEditRow(<?= $p['id'] ?>)">Cancel</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr id="no-inventory-results" style="display:none;">
                                <td colspan="6" class="text-center text-muted py-4">No products match this search or section.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Customer Orders Tab Pane -->
        <div class="tab-pane <?= $active_tab == 'orders-section' ? 'active-pane' : '' ?>" id="orders-section">
            <div class="admin-card">
                <h3>Customer Orders</h3>
                <div class="table-responsive">
                    <table class="table table-custom table-striped">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>User</th>
                                <th>Mobile</th>
                                <th>Location</th>
                                <th>Details</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($orders as $o): ?>
                            <tr>
                                <td class="font-weight-bold">#<?= $o['id'] ?></td>
                                <td><?= htmlspecialchars($o['username'] ?? ('user #' . $o['user_id'])) ?></td>
                                <td><?= htmlspecialchars($o['mobile_number']) ?></td>
                                <td><?= htmlspecialchars($o['location']) ?></td>
                                <td>
                                    <?php $order_items_for_admin = attach_order_item_images(admin_order_items($o), $pdo); ?>
                                    <button type="button" class="btn btn-primary-custom btn-sm" style="padding: 6px 16px; font-size: 12px;" onclick='showAdminItems(<?= htmlspecialchars(json_encode($order_items_for_admin), ENT_QUOTES) ?>, <?= $o['id'] ?>)'>View Items</button>
                                </td>
                                <td class="font-weight-bold text-success">$<?= number_format($o['total_price'], 2) ?></td>
                                <td>
                                    <?php if($o['status'] == 'delivered'): ?>
                                        <span class="badge badge-success px-2 py-1">Delivered</span>
                                    <?php elseif($o['status'] == 'sent'): ?>
                                        <span class="badge badge-warning px-2 py-1">Sent</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary px-2 py-1">Under Editing</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="mark_delivered">
                                        <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                        <input type="hidden" name="active_tab" value="orders-section">
                                        <button type="submit" class="btn btn-primary-custom btn-sm" style="padding: 6px 16px; font-size: 12px;">Mark Delivered ✓</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- Customer Reports Tab Pane -->
    <div class="tab-pane <?= $active_tab == 'reports-section' ? 'active-pane' : '' ?>" id="reports-section">
        <div class="admin-card">
            <h3>Customer Reports</h3>
            <div class="table-responsive">
                <table class="table table-custom table-striped">
                    <thead>
                        <tr>
                            <th>Report ID</th>
                            <th>User</th>
                            <th>Order</th>
                            <th>Message</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($reports as $r): ?>
                        <tr>
                            <td class="font-weight-bold">#<?= $r['id'] ?></td>
                            <td><?= htmlspecialchars($r['username']) ?></td>
                            <td>#<?= $r['order_id'] ?></td>
                            <td><pre><?= htmlspecialchars($r['message']) ?></pre></td>
                            <td><?= $r['created_at'] ?></td>
                            <td>
                                <?php if($r['status'] == 'handled'): ?>
                                    <span class="badge badge-success px-2 py-1">Handled</span>
                                <?php else: ?>
                                    <span class="badge badge-warning px-2 py-1">New</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($r['status'] != 'handled'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="handle_report">
                                    <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn btn-primary-custom btn-sm" style="padding: 4px 12px; font-size: 12px;">Mark Handled</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($reports)): ?>
                        <tr><td colspan="7" class="text-center text-muted">No reports yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
<script>
    let activeInventoryFilter = "all";

    function toggleEditRow(id) {
        let row = document.getElementById("edit-row-" + id);
        if (!row) return;
        const isOpen = row.style.display !== "none" && row.style.display !== "";
        document.querySelectorAll(".edit-row").forEach(editRow => {
            editRow.style.display = "none";
        });
        row.style.display = isOpen ? "none" : "table-row";
        if (!isOpen) {
            row.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
    }

    function setInventoryFilter(filter, button) {
        activeInventoryFilter = filter;
        document.querySelectorAll(".section-filter").forEach(item => item.classList.remove("active"));
        if (button) button.classList.add("active");
        filterInventory();
    }

    function filterInventory() {
        const query = (document.getElementById("inventory-search")?.value || "").trim().toLowerCase();
        let visibleCount = 0;
        document.querySelectorAll(".product-row").forEach(row => {
            const matchesSection = activeInventoryFilter === "all" || row.dataset.section === activeInventoryFilter;
            const matchesSearch = !query ||
                row.dataset.productName.includes(query) ||
                row.dataset.productId.includes(query);
            const visible = matchesSection && matchesSearch;
            if (visible) visibleCount++;
            row.classList.toggle("is-hidden", !visible);
            const editRow = document.getElementById("edit-row-" + row.dataset.productId);
            if (editRow) {
                editRow.classList.toggle("is-hidden", !visible);
                if (!visible) editRow.style.display = "none";
            }
        });
        const emptyState = document.getElementById("no-inventory-results");
        if (emptyState) emptyState.style.display = visibleCount === 0 ? "table-row" : "none";
    }

    function confirmBulkSale(form) {
        const percent = parseInt(form.elements.bulk_sale_percent.value, 10) || 0;
        const scope = form.elements.bulk_sale_section;
        const scopeLabel = scope.options[scope.selectedIndex].text;
        const message = percent === 0
            ? "Remove the sale from " + scopeLabel.toLowerCase() + "?"
            : "Apply a " + percent + "% sale to " + scopeLabel.toLowerCase() + "?";
        return window.confirm(message);
    }
</script>

    <!-- Order Items Modal -->
    <div id="admin-items-modal">
        <div class="admin-items-card">
            <button onclick="closeAdminItems()" id="admin-items-close">&times;</button>
            <h2 id="admin-modal-title">Order Items</h2>
            <div class="order-items-list" id="admin-items-list" style="max-width:none;"></div>
            <div id="admin-items-total-row">
                <span>Total</span>
                <span><span id="admin-items-total">0.00</span>$</span>
            </div>
        </div>
    </div>

    <script>
        function escapeAdminHtml(value) {
            return String(value ?? "").replace(/[&<>"']/g, function (character) {
                return {
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    '"': "&quot;",
                    "'": "&#039;"
                }[character];
            });
        }

        function showAdminItems(items, orderId) {
            let list = document.getElementById("admin-items-list");
            list.innerHTML = "";
            let total = 0;
            document.getElementById("admin-modal-title").textContent = "Order #" + orderId + " Items";
            if (!items || items.length === 0) {
                list.innerHTML = '<div class="oi-row"><span class="oi-name">No item details</span></div>';
            } else {
                items.forEach(it => {
                    const itemPrice = Number(it.price) || 0;
                    const itemCount = Number(it.count) || 0;
                    total += itemPrice * itemCount;
                    const image = it.img
                        ? `<img class="oi-image" src="${escapeAdminHtml(it.img)}" alt="">`
                        : '<span class="oi-image oi-no-image">🧺</span>';
                    list.innerHTML += `
                        <div class="oi-row">
                            <span class="oi-main">
                                ${image}
                                <span class="oi-name">${escapeAdminHtml(it.name)}<small>size: ${escapeAdminHtml(it.size)} × ${itemCount}</small></span>
                            </span>
                            <span class="oi-price">${(itemPrice * itemCount).toFixed(2)}$</span>
                        </div>
                    `;
                });
            }
            document.getElementById("admin-items-total").textContent = total.toFixed(2);
            document.getElementById("admin-items-modal").style.display = "flex";
        }
        function closeAdminItems() {
            document.getElementById("admin-items-modal").style.display = "none";
        }
    </script>
</body>
</html>