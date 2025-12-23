<?php
// Simple admin dashboard for managing designs, products, and viewing orders.
session_start();

// Path constants
$design_file   = __DIR__ . '/tshirt_designs.json';
$product_file  = __DIR__ . '/merch_products.json';
$orders_file   = __DIR__ . '/orders.json';

// Load data
$designs  = json_decode(@file_get_contents($design_file), true) ?: [];
$products = json_decode(@file_get_contents($product_file), true) ?: [];
$orders   = json_decode(@file_get_contents($orders_file), true) ?: [];

// Handle design upload from admin page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_design') {
    // Process the uploaded file if present
    if (isset($_FILES['design_upload_admin']) && $_FILES['design_upload_admin']['error'] === UPLOAD_ERR_OK && $_FILES['design_upload_admin']['size'] > 0) {
        $tmpPath  = $_FILES['design_upload_admin']['tmp_name'];
        $origName = $_FILES['design_upload_admin']['name'];
        $mime     = mime_content_type($tmpPath) ?: 'application/octet-stream';
        $allowed  = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/webp' => 'webp'];
        $ext      = isset($allowed[$mime]) ? $allowed[$mime] : 'png';
        try {
            $filename = 'ts_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        } catch (Exception $e) {
            $filename = 'ts_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
        }
        $destPath = __DIR__ . '/generated_tshirts/' . $filename;
        // Move uploaded file or copy if moving fails
        if (!move_uploaded_file($tmpPath, $destPath)) {
            @copy($tmpPath, $destPath);
        }
        // Convert to PNG when possible for consistency
        if ($ext !== 'png' && class_exists('Imagick') && is_file($destPath)) {
            try {
                $im = new Imagick($destPath);
                $im->setImageFormat('png');
                $pngPath = preg_replace('~\.[A-Za-z0-9]+$~', '.png', $destPath);
                $im->writeImage($pngPath);
                $im->clear();
                $im->destroy();
                @unlink($destPath);
                $destPath = $pngPath;
                $filename = basename($destPath);
            } catch (Throwable $ex) {
                // Conversion failed; proceed with original file
            }
        }
        // Determine display text
        $displayText = trim($_POST['upload_display_text'] ?? '');
        if ($displayText === '') {
            $displayText = pathinfo($origName, PATHINFO_FILENAME);
        }
        // Append record to designs
        $designsFromFile = json_decode(@file_get_contents($design_file), true) ?: [];
        $record = [
            'id'           => uniqid('ts_', true),
            'display_text' => $displayText,
            'graphic'      => pathinfo($origName, PATHINFO_FILENAME),
            'bg_color'     => '',
            'file'         => $filename,
            'created_at'   => date('c'),
            'published'    => false,
        ];
        $designsFromFile[] = $record;
        file_put_contents($design_file, json_encode($designsFromFile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        // Reload designs for view
        $designs = $designsFromFile;
        $upload_msg = 'Design uploaded successfully.';
    } else {
        $upload_msg = 'Please choose an image to upload.';
    }
}

// Handle design publish update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_designs') {
    // Loop through designs; update published flag based on POST checkboxes
    // Note: PHP normalizes dots in input names to underscores, so we must
    // sanitize the design ID when constructing the checkbox name. Without
    // this, checkboxes for designs whose IDs contain periods will never
    // register as checked on submission.
    foreach ($designs as &$d) {
        // Replace dots with underscores to match PHP's $_POST key normalization
        $safeId  = str_replace('.', '_', $d['id']);
        $cb_name = 'publish_' . $safeId;
        // If checkbox is present, mark as true, otherwise false
        $d['published'] = isset($_POST[$cb_name]);
    }
    file_put_contents($design_file, json_encode($designs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    // Reload data to reflect changes
    $designs  = json_decode(@file_get_contents($design_file), true) ?: [];
    $update_msg = 'Design publish status updated.';
}

// Handle product updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_products') {
    // Update existing product prices
    foreach ($products as &$p) {
        $key = 'price_' . $p['id'];
        if (isset($_POST[$key])) {
            $price = floatval($_POST[$key]);
            if ($price > 0) {
                $p['price'] = $price;
            }
        }
    }
    // Add new product if provided
    $new_id   = trim($_POST['new_product_id'] ?? '');
    $new_name = trim($_POST['new_product_name'] ?? '');
    $new_price= trim($_POST['new_product_price'] ?? '');
    if ($new_id !== '' && $new_name !== '' && is_numeric($new_price) && floatval($new_price) > 0) {
        // Check for duplicate id
        $exists = false;
        foreach ($products as $p) {
            if ($p['id'] === $new_id) { $exists = true; break; }
        }
        if (!$exists) {
            $products[] = [
                'id'    => $new_id,
                'name'  => $new_name,
                'price' => floatval($new_price),
            ];
        }
    }
    // Save products
    file_put_contents($product_file, json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    // Reload
    $products = json_decode(@file_get_contents($product_file), true) ?: [];
    $prod_msg = 'Products updated.';
}

?>
<?php
// Determine the active section from the query string. Default to 'designs'.
$section = isset($_GET['section']) ? $_GET['section'] : 'designs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebberSites AI Studio Dashboard</title>
    <style>
        :root {
            --primary: #111827;
            --secondary: #1f2937;
            --accent: #2563eb;
            --accent-light: #3b82f6;
            --text-light: #f3f4f6;
            --text-muted: #9ca3af;
            --bg: #f6f7fb;
            --bg-dark: #0f172a;
            --warning: #ef4444;
            --success: #10b981;
        }
        body {
            margin: 0;
            font-family: system-ui, sans-serif;
            background: var(--bg);
            color: #333;
        }
        .admin-container {
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }
        header.admin-header {
            background: var(--primary);
            color: var(--text-light);
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        header .brand {
            font-size: 1.4rem;
            font-weight: 700;
            letter-spacing: 0.03em;
        }
        header .top-nav a {
            color: var(--text-light);
            text-decoration: none;
            margin-left: 20px;
            font-size: 0.9rem;
        }
        .admin-main {
            flex: 1;
            display: flex;
            min-height: 0;
        }
        .admin-sidebar {
            width: 220px;
            background: var(--secondary);
            color: var(--text-light);
            padding-top: 24px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }
        .admin-sidebar a {
            display: block;
            padding: 12px 24px;
            color: var(--text-light);
            text-decoration: none;
            font-size: 0.95rem;
        }
        .admin-sidebar a.active,
        .admin-sidebar a:hover {
            background: var(--accent);
        }
        .admin-content {
            flex: 1;
            padding: 24px;
            overflow-y: auto;
        }
        h1 {
            font-size: 1.6rem;
            margin-top: 0;
            margin-bottom: 20px;
        }
        .msg {
            padding: 8px 12px;
            background: #e0f8e0;
            color: #2d643d;
            border: 1px solid #b6e2b6;
            border-radius: 4px;
            margin-bottom: 16px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border-radius: 8px;
            overflow: hidden;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.9rem;
        }
        th {
            background: #f9fafb;
            font-weight: 600;
        }
        tr:last-child td {
            border-bottom: none;
        }
        input[type=text], input[type=number] {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        input[type=checkbox] {
            width: 18px;
            height: 18px;
        }
        .btn {
            background: var(--accent);
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .btn:hover {
            background: var(--accent-light);
        }
        .pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .pill.published {
            background: var(--success);
            color: #fff;
        }
        .pill.unpublished {
            background: var(--warning);
            color: #fff;
        }
        .actions a {
            color: var(--accent);
            text-decoration: none;
            margin-right: 8px;
            font-size: 0.85rem;
        }
        .actions a:hover {
            text-decoration: underline;
        }

        /* Upload Design card */
        .upload-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .upload-card h2 {
            margin-top: 0;
            margin-bottom: 12px;
            font-size: 1.1rem;
            color: #111827;
        }
        .upload-area-admin {
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            color: var(--text-muted);
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s;
        }
        .upload-area-admin.dragover {
            border-color: var(--accent);
            background: #f3f4f6;
        }
        .upload-area-admin span {
            pointer-events: none;
        }
        .upload-field {
            margin-top: 12px;
        }
        .upload-field .field-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 4px;
            display: block;
        }
    </style>
</head>
<body>
<div class="admin-container">
    <header class="admin-header">
        <div class="brand">WebberSites AI Studio</div>
        <nav class="top-nav">
            <!-- Link to the public storefront -->
            <a href="index.php" target="_blank">View Store</a>
        </nav>
    </header>
    <div class="admin-main">
        <aside class="admin-sidebar">
            <!-- Main navigation links. We include links to the new design lab and idea generator pages. -->
            <a href="admin.php?section=designs" class="<?php echo $section === 'designs' ? 'active' : ''; ?>">Designs</a>
            <!-- Unified Design Lab navigation: highlight for both GPT and Gemini pages -->
            <a href="admin_design_lab.php" class="<?php echo (basename($_SERVER['SCRIPT_NAME']) === 'admin_design_lab.php' || basename($_SERVER['SCRIPT_NAME']) === 'admin_design_lab_gemini.php') ? 'active' : ''; ?>">Design Lab</a>
            <a href="admin_idea_generator.php" class="<?php echo basename($_SERVER['SCRIPT_NAME']) === 'admin_idea_generator.php' ? 'active' : ''; ?>">Idea Generator</a>
            <a href="admin_create.php" class="<?php echo basename($_SERVER['SCRIPT_NAME']) === 'admin_create.php' ? 'active' : ''; ?>">Create</a>
            <a href="admin_media_library.php" class="<?php echo basename($_SERVER['SCRIPT_NAME']) === 'admin_media_library.php' ? 'active' : ''; ?>">Library</a>
            <a href="admin.php?section=products" class="<?php echo $section === 'products' ? 'active' : ''; ?>">Products</a>
            <a href="admin.php?section=orders" class="<?php echo $section === 'orders' ? 'active' : ''; ?>">Orders</a>
            <a href="admin_config.php" class="<?php echo basename($_SERVER['SCRIPT_NAME']) === 'admin_config.php' ? 'active' : ''; ?>">Config</a>
        </aside>
        <section class="admin-content">
            <?php if (isset($update_msg)): ?><div class="msg"><?php echo htmlspecialchars($update_msg); ?></div><?php endif; ?>
            <?php if (isset($prod_msg)): ?><div class="msg"><?php echo htmlspecialchars($prod_msg); ?></div><?php endif; ?>
            <?php if (isset($upload_msg)): ?><div class="msg"><?php echo htmlspecialchars($upload_msg); ?></div><?php endif; ?>
            <?php if ($section === 'designs'): ?>
                <h1>Designs</h1>

                <!-- Upload New Design Form -->
                <div class="upload-card">
                    <h2>Upload New Design</h2>
                    <form method="post" action="admin.php?section=designs" enctype="multipart/form-data" class="upload-form">
                        <input type="hidden" name="action" value="upload_design">
                        <div class="upload-area-admin" id="upload-area-admin">
                            <span id="upload-placeholder-admin">Drag &amp; drop image here or click to select</span>
                            <input type="file" name="design_upload_admin" accept="image/*" id="design-upload-admin" hidden>
                        </div>
                        <div class="upload-field">
                            <label class="field-label" for="upload-display-text">Design Text (optional)</label>
                            <input type="text" id="upload-display-text" name="upload_display_text" placeholder="Text on the shirt (optional)">
                        </div>
                        <button type="submit" class="btn" style="margin-top: 12px;">Upload Design</button>
                    </form>
                </div>
                <form method="post" action="admin.php?section=designs">
                    <input type="hidden" name="action" value="update_designs">
                    <table>
                        <thead>
                            <tr>
                                <th>Preview</th>
                                <th>Text</th>
                                <th>Graphic</th>
                                <th>Created</th>
                                <th>Status</th>
                                <th>Toggle</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($designs as $d): ?>
                            <tr>
                                <td>
                                    <?php if (is_file(__DIR__ . '/generated_tshirts/' . $d['file'])): ?>
                                        <img src="<?php echo 'generated_tshirts/' . rawurlencode($d['file']); ?>" alt="" style="width:60px;height:auto;">
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($d['display_text']); ?></td>
                                <td><?php echo htmlspecialchars($d['graphic']); ?></td>
                                <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($d['created_at']))); ?></td>
                                <td>
                                    <?php if ($d['published'] ?? false): ?>
                                        <span class="pill published">Published</span>
                                    <?php else: ?>
                                        <span class="pill unpublished">Hidden</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php
                                    // Sanitize ID for checkbox name to avoid dot-to-underscore conversion issues
                                    $safeId = str_replace('.', '_', $d['id']);
                                    ?>
                                    <input type="checkbox" name="publish_<?php echo htmlspecialchars($safeId); ?>" <?php echo ($d['published'] ?? false) ? 'checked' : ''; ?>>
                                </td>
                                <td class="actions">
                                    <!-- Link to the admin version of the design editor. Opens in a new tab to allow editing without leaving the dashboard. -->
                                    <a href="admin_edit_design.php?id=<?php echo urlencode($d['id']); ?>" target="_blank">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="submit" class="btn">Save Changes</button>
                </form>
            <?php elseif ($section === 'products'): ?>
                <h1>Products</h1>
                <form method="post" action="admin.php?section=products">
                    <input type="hidden" name="action" value="update_products">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Price</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['id']); ?></td>
                                <td><?php echo htmlspecialchars($p['name']); ?></td>
                                <td>
                                    <input type="number" step="0.01" name="price_<?php echo htmlspecialchars($p['id']); ?>" value="<?php echo htmlspecialchars(number_format($p['price'], 2, '.', '')); ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><input type="text" name="new_product_id" placeholder="new id"></td>
                            <td><input type="text" name="new_product_name" placeholder="new name"></td>
                            <td><input type="number" step="0.01" name="new_product_price" placeholder="price"></td>
                        </tr>
                        </tbody>
                    </table>
                    <button type="submit" class="btn">Save Products</button>
                </form>
            <?php elseif ($section === 'orders'): ?>
                <h1>Orders</h1>
                <?php if (!empty($orders)): ?>
                    <table>
                        <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Items</th>
                    </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($o['order_id']); ?></td>
                                <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($o['date']))); ?></td>
                                <td>
                                    <?php
                                    $cname  = $o['customer_name'] ?? '';
                                    $cemail = $o['customer_email'] ?? '';
                                    if ($cname || $cemail) {
                                        echo htmlspecialchars($cname);
                                        if ($cemail) {
                                            echo '<br><small style="color: var(--text-muted);">' . htmlspecialchars($cemail) . '</small>';
                                        }
                                    } else {
                                        echo '<em>—</em>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo number_format($o['total'], 2); ?></td>
                                <td>
                                    <ul style="margin:0;padding-left:16px;">
                                        <?php foreach ($o['items'] as $itm): ?>
                                            <li><?php echo htmlspecialchars($itm['quantity'] . ' × ' . $itm['design_text'] . ' (' . $itm['product_name'] . ')'); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No orders yet.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</div>
</body>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var dropArea = document.getElementById('upload-area-admin');
    var fileInput = document.getElementById('design-upload-admin');
    var placeholder = document.getElementById('upload-placeholder-admin');
    if (dropArea && fileInput) {
        dropArea.addEventListener('click', function() {
            fileInput.click();
        });
        dropArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            dropArea.classList.add('dragover');
        });
        dropArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            dropArea.classList.remove('dragover');
        });
        dropArea.addEventListener('drop', function(e) {
            e.preventDefault();
            dropArea.classList.remove('dragover');
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                fileInput.files = e.dataTransfer.files;
                placeholder.textContent = e.dataTransfer.files[0].name;
            }
        });
        fileInput.addEventListener('change', function() {
            if (fileInput.files && fileInput.files.length > 0) {
                placeholder.textContent = fileInput.files[0].name;
            } else {
                placeholder.textContent = 'Drag & drop image here or click to select';
            }
        });
    }
});
</script>
</html>