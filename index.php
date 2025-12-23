<?php
// Basic store front listing published designs and available merch items.
// This file replicates the functionality of store.php but is used as the new
// landing page for the storefront. It lists all published designs and
// supports adding merchandise to a cart.

session_start();

// Load designs and products
$designs = json_decode(@file_get_contents(__DIR__ . '/tshirt_designs.json'), true) ?: [];
$products = json_decode(@file_get_contents(__DIR__ . '/merch_products.json'), true) ?: [];

// Ensure cart session exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Handle add to cart action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_to_cart') {
    $design_id   = trim($_POST['design_id'] ?? '');
    $product_type= trim($_POST['product_type'] ?? '');
    $quantity    = (int)($_POST['quantity'] ?? 1);
    // Validate inputs
    $design = null;
    foreach ($designs as $d) {
        if ($d['id'] === $design_id) { $design = $d; break; }
    }
    $product = null;
    foreach ($products as $p) {
        if ($p['id'] === $product_type) { $product = $p; break; }
    }
    if ($design && $product && $quantity > 0 && ($design['published'] ?? false)) {
        // Check if same item already in cart; if so, increment quantity
        $found = false;
        foreach ($_SESSION['cart'] as &$item) {
            if ($item['design_id'] === $design_id && $item['product_type'] === $product_type) {
                $item['quantity'] += $quantity;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $_SESSION['cart'][] = [
                'design_id'    => $design_id,
                'product_type' => $product_type,
                'quantity'     => $quantity,
            ];
        }
        // Redirect to avoid form resubmission
        header('Location: index.php');
        exit;
    } else {
        $error_msg = 'Invalid item or quantity.';
    }
}

// Helper: compute cart summary
function cart_summary(array $cart, array $products) {
    $total_items = 0;
    $total_price = 0;
    foreach ($cart as $item) {
        $total_items += $item['quantity'];
        // Find product price
        foreach ($products as $p) {
            if ($p['id'] === $item['product_type']) {
                $total_price += $p['price'] * $item['quantity'];
                break;
            }
        }
    }
    return [$total_items, $total_price];
}
list($cart_count, $cart_total) = cart_summary($_SESSION['cart'], $products);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop – Custom Merch</title>
    <style>
        :root{
            --bg:#f6f7fb;
            --primary:#111827;
            --accent:#2563eb;
            --accent-light:#3b82f6;
            --card-bg:#ffffff;
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
            color: #ffffff;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        header .brand {
            font-size: 1.5rem;
            font-weight: 700;
        }
        header .cart-summary {
            font-size: 0.9rem;
        }
        header .cart-summary a {
            color: #ffffff;
            text-decoration: underline;
            margin-left: 8px;
        }
        main {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }
        .error {
            color: #c0392b;
            margin-bottom: 12px;
        }
        .design-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 24px;
        }
        .design-card {
            background: var(--card-bg);
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: transform .15s ease;
        }
        .design-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }
        .design-card img {
            width: 100%;
            /* Maintain a 2:3 width to height ratio for product images */
            aspect-ratio: 2 / 3;
            height: auto;
            object-fit: cover;
            display: block;
        }
        .design-info {
            padding: 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .design-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 8px;
        }
        .design-graphic {
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 12px;
        }
        .design-form {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .design-form select,
        .design-form input[type=number] {
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .design-form button {
            background: var(--accent);
            color: #fff;
            border: none;
            padding: 8px 14px;
            border-radius: 4px;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .design-form button:hover {
            background: var(--accent-light);
        }
        .no-designs {
            font-size: 1rem;
            color: var(--muted);
            text-align: center;
            margin-top: 40px;
        }
    </style>
</head>
<body>
<header>
    <div class="brand">Custom Merch Shop</div>
    <div class="cart-summary">
        Cart: <?php echo $cart_count; ?> item<?php echo $cart_count === 1 ? '' : 's'; ?> (<?php echo number_format($cart_total, 2); ?>)
        <a href="cart.php">View Cart</a>
    </div>
</header>
<main>
    <?php if (isset($error_msg)): ?>
        <div class="error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>
    <?php $hasDesign = false; ?>
    <div class="design-grid">
        <?php foreach ($designs as $design): ?>
            <?php if (($design['published'] ?? false) && is_file(__DIR__ . '/generated_tshirts/' . $design['file'])): ?>
                <?php $hasDesign = true; ?>
                <div class="design-card">
                    <img src="<?php echo 'generated_tshirts/' . rawurlencode($design['file']); ?>" alt="<?php echo htmlspecialchars($design['display_text']); ?>">
                    <div class="design-info">
                        <div>
                            <div class="design-title"><?php echo htmlspecialchars($design['display_text']); ?></div>
                            <div class="design-graphic">Graphic: <?php echo htmlspecialchars($design['graphic']); ?></div>
                        </div>
                        <form class="design-form" method="post" action="index.php">
                            <input type="hidden" name="action" value="add_to_cart">
                            <input type="hidden" name="design_id" value="<?php echo htmlspecialchars($design['id']); ?>">
                            <select name="product_type" required>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?php echo htmlspecialchars($p['id']); ?>"><?php echo htmlspecialchars($p['name']); ?> (<?php echo number_format($p['price'], 2); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="quantity" value="1" min="1">
                            <button type="submit">Add to Cart</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php if (!$hasDesign): ?>
        <div class="no-designs">No products available yet. Come back soon!</div>
    <?php endif; ?>
</main>
</body>
</html>