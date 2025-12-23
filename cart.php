<?php
// Cart page shows items added by user with ability to modify quantities or remove.
session_start();

$designs = json_decode(@file_get_contents(__DIR__ . '/tshirt_designs.json'), true) ?: [];
$products = json_decode(@file_get_contents(__DIR__ . '/merch_products.json'), true) ?: [];

// Ensure cart exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Helper: find design by id
function get_design($designs, $id) {
    foreach ($designs as $d) {
        if ($d['id'] === $id) return $d;
    }
    return null;
}
// Helper: find product by id
function get_product($products, $id) {
    foreach ($products as $p) {
        if ($p['id'] === $id) return $p;
    }
    return null;
}

// Handle cart updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update') {
        // Iterate through posted quantities
        foreach ($_SESSION['cart'] as $idx => &$item) {
            $key = 'qty_' . $idx;
            if (isset($_POST[$key])) {
                $qty = (int)$_POST[$key];
                if ($qty > 0) {
                    $item['quantity'] = $qty;
                } else {
                    unset($_SESSION['cart'][$idx]);
                }
            }
        }
        // Re-index array to avoid gaps
        $_SESSION['cart'] = array_values($_SESSION['cart']);
        header('Location: cart.php');
        exit;
    } elseif ($action === 'remove') {
        $index = (int)($_POST['index'] ?? -1);
        if (isset($_SESSION['cart'][$index])) {
            unset($_SESSION['cart'][$index]);
            $_SESSION['cart'] = array_values($_SESSION['cart']);
        }
        header('Location: cart.php');
        exit;
    }
}

// Compute totals
$total = 0;
foreach ($_SESSION['cart'] as $item) {
    $product = get_product($products, $item['product_type']);
    if ($product) {
        $total += $product['price'] * $item['quantity'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Cart – Custom Merch</title>
    <style>
        :root{
            --bg:#f6f7fb;
            --card-bg:#ffffff;
            --primary:#111827;
            --accent:#2563eb;
            --accent-light:#3b82f6;
            --success:#10b981;
            --warning:#ef4444;
            --text:#1f2937;
            --muted:#6b7280;
        }
        body {
            font-family: system-ui, sans-serif;
            margin: 0;
            background: var(--bg);
            color: var(--text);
        }
        header {
            background: var(--primary);
            color: #fff;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        header .brand {
            font-size: 1.4rem;
            font-weight: 700;
        }
        header a { color: #fff; text-decoration: underline; margin-left: 8px; font-size: 0.9rem; }
        main { max-width: 1000px; margin: 0 auto; padding: 24px; }
        h1 { margin-top: 0; margin-bottom: 20px; font-size: 1.6rem; }
        table { width: 100%; border-collapse: collapse; background: var(--card-bg); border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow:hidden; margin-bottom: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e5e7eb; font-size: 0.9rem; }
        th { background: #f9fafb; font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        input[type=number] { width: 70px; padding: 6px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.9rem; }
        button { background: var(--accent); color: #fff; border: none; padding: 8px 12px; border-radius: 4px; font-size: 0.9rem; cursor: pointer; }
        button:hover { background: var(--accent-light); }
        .remove-btn { background: var(--warning); }
        .remove-btn:hover { background: #b91c1c; }
        .actions { display: flex; gap: 12px; margin-top: 12px; }
        .empty { margin-top: 40px; font-size: 1rem; color: var(--muted); text-align: center; }
        .checkout-link { display: inline-block; padding: 10px 18px; background: var(--success); color: #fff; text-decoration: none; border-radius: 4px; }
        .checkout-link:hover { background: #059669; }
        .continue-link { margin-top: 20px; display:block; text-align:center; color: var(--accent); text-decoration: underline; }
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
    <h1>Your Cart</h1>
    <?php if (!empty($_SESSION['cart'])): ?>
        <form method="post" action="cart.php">
            <input type="hidden" name="action" value="update">
            <table>
                <thead>
                    <tr>
                        <th>Design</th>
                        <th>Product</th>
                        <th>Unit price</th>
                        <th>Quantity</th>
                        <th>Subtotal</th>
                        <th>Remove</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($_SESSION['cart'] as $index => $item): ?>
                    <?php $design = get_design($designs, $item['design_id']); ?>
                    <?php $product = get_product($products, $item['product_type']); ?>
                    <?php if ($design && $product): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($design['display_text']); ?><br>
                                <small style="color: var(--muted);">Graphic: <?php echo htmlspecialchars($design['graphic']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                            <td><?php echo number_format($product['price'], 2); ?></td>
                            <td>
                                <input type="number" name="qty_<?php echo $index; ?>" value="<?php echo $item['quantity']; ?>" min="1">
                            </td>
                            <td><?php echo number_format($product['price'] * $item['quantity'], 2); ?></td>
                            <td>
                                <form method="post" action="cart.php" style="display:inline;">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="index" value="<?php echo $index; ?>">
                                    <button type="submit" class="remove-btn">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <tr>
                    <td colspan="4" style="text-align:right;font-weight:bold;">Total:</td>
                    <td colspan="2"><strong><?php echo number_format($total, 2); ?></strong></td>
                </tr>
                </tbody>
            </table>
            <div class="actions">
                <button type="submit">Update Cart</button>
                <a href="checkout.php" class="checkout-link">Proceed to Checkout</a>
            </div>
        </form>
    <?php else: ?>
        <div class="empty">Your cart is empty.</div>
        <a href="index.php" class="continue-link">Continue shopping</a>
    <?php endif; ?>
</main>
</body>
</html>