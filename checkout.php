<?php
// Checkout page: review cart and submit order
session_start();

$designs = json_decode(@file_get_contents(__DIR__ . '/tshirt_designs.json'), true) ?: [];
$products = json_decode(@file_get_contents(__DIR__ . '/merch_products.json'), true) ?: [];

// Ensure cart exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Helpers to find design and product
function find_design($designs, $id) {
    foreach ($designs as $d) {
        if ($d['id'] === $id) return $d;
    }
    return null;
}
function find_product($products, $id) {
    foreach ($products as $p) {
        if ($p['id'] === $id) return $p;
    }
    return null;
}

// Compute cart items with price and subtotal
$items = [];
$total = 0;
foreach ($_SESSION['cart'] as $item) {
    $design = find_design($designs, $item['design_id']);
    $product = find_product($products, $item['product_type']);
    if ($design && $product) {
        $subtotal = $product['price'] * $item['quantity'];
        $total += $subtotal;
        $items[] = [
            'design'      => $design,
            'product'     => $product,
            'quantity'    => $item['quantity'],
            'unit_price'  => $product['price'],
            'subtotal'    => $subtotal,
        ];
    }
}

// Handle placing order with customer details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'place_order' && !empty($items)) {
    // Retrieve and validate customer info
    $customer_name  = trim($_POST['customer_name'] ?? '');
    $customer_email = trim($_POST['customer_email'] ?? '');
    // Simple validation: require non-empty name and valid email format
    if ($customer_name === '' || $customer_email === '' || !filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
        $order_error = 'Please enter a valid name and email address.';
    } else {
        // Save order
        $orders = json_decode(@file_get_contents(__DIR__ . '/orders.json'), true) ?: [];
        $order_id = uniqid('order_', true);
        $order = [
            'order_id'      => $order_id,
            'date'          => date('c'),
            'items'         => [],
            'total'         => $total,
            'customer_name' => $customer_name,
            'customer_email'=> $customer_email,
        ];
        foreach ($items as $it) {
            $order['items'][] = [
                'design_id'    => $it['design']['id'],
                'design_text'  => $it['design']['display_text'],
                'product_type' => $it['product']['id'],
                'product_name' => $it['product']['name'],
                'quantity'     => $it['quantity'],
                'unit_price'   => $it['unit_price'],
                'subtotal'     => $it['subtotal'],
            ];
        }
        $orders[] = $order;
        file_put_contents(__DIR__ . '/orders.json', json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        // Clear cart
        $_SESSION['cart'] = [];
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout – Custom Merch</title>
    <style>
        :root{
            --bg:#f6f7fb;
            --card-bg:#ffffff;
            --primary:#111827;
            --success:#10b981;
            --accent:#2563eb;
            --accent-light:#3b82f6;
            --text:#1f2937;
            --muted:#6b7280;
        }
        body {
            font-family: system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
        }
        header {
            background: var(--primary);
            color: #fff;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        header .brand { font-size: 1.4rem; font-weight: 700; }
        header a { color: #fff; text-decoration: underline; font-size: 0.9rem; margin-left: 8px; }
        main { max-width: 1000px; margin: 0 auto; padding: 24px; }
        h1 { margin-top: 0; margin-bottom: 20px; font-size: 1.6rem; }
        table { width: 100%; border-collapse: collapse; background: var(--card-bg); border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow:hidden; margin-bottom: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e5e7eb; font-size: 0.9rem; }
        th { background: #f9fafb; font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        .btn { background: var(--success); color: #fff; border: none; padding: 10px 18px; border-radius: 4px; font-size: 0.9rem; cursor: pointer; }
        .btn:hover { background: #059669; }
        .message {
            padding: 20px; background: #e0f8e0; color: #2d643d;
            border: 1px solid #b6e2b6; border-radius: 4px; margin-bottom: 20px;
        }
        .continue-link { display: inline-block; margin-top: 20px; color: var(--accent); text-decoration: underline; }
    </style>
</head>
<body>
<header>
    <div class="brand">Custom Merch Shop</div>
    <div>
        <a href="index.php">Back to Shop</a>
    </div>
</header>
<main>
    <h1>Checkout</h1>
    <?php if (isset($success) && $success): ?>
        <div class="message">Thank you for your order! Your order has been placed.</div>
        <a href="index.php" class="continue-link">Back to Store</a>
    <?php else: ?>
        <?php if (empty($items)): ?>
            <p>Your cart is empty. <a href="index.php">Continue shopping</a></p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Design</th>
                        <th>Product</th>
                        <th>Unit price</th>
                        <th>Quantity</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($it['design']['display_text']); ?></td>
                        <td><?php echo htmlspecialchars($it['product']['name']); ?></td>
                        <td><?php echo number_format($it['unit_price'], 2); ?></td>
                        <td><?php echo $it['quantity']; ?></td>
                        <td><?php echo number_format($it['subtotal'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td colspan="4" style="text-align:right;font-weight:bold;">Total:</td>
                        <td><strong><?php echo number_format($total, 2); ?></strong></td>
                    </tr>
                </tbody>
            </table>
            <?php if (isset($order_error)): ?>
                <div class="message" style="background:#fde2e2;color:#b91c1c;border-color:#f5c6cb;">
                    <?php echo htmlspecialchars($order_error); ?>
                </div>
            <?php endif; ?>
            <form method="post" action="checkout.php">
                <input type="hidden" name="action" value="place_order">
                <div style="margin-bottom:12px;">
                    <label for="customer_name" style="display:block;margin-bottom:4px;font-size:0.9rem;">Your Name</label>
                    <input type="text" id="customer_name" name="customer_name" value="<?php echo isset($_POST['customer_name']) ? htmlspecialchars($_POST['customer_name']) : ''; ?>" required style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.9rem;">
                </div>
                <div style="margin-bottom:16px;">
                    <label for="customer_email" style="display:block;margin-bottom:4px;font-size:0.9rem;">Email Address</label>
                    <input type="email" id="customer_email" name="customer_email" value="<?php echo isset($_POST['customer_email']) ? htmlspecialchars($_POST['customer_email']) : ''; ?>" required style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.9rem;">
                </div>
                <button type="submit" class="btn">Place Order</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>