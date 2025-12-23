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

// Determine active section
$section = $_GET['section'] ?? 'designs';
if (!in_array($section, ['designs', 'products', 'orders'], true)) {
    $section = 'designs';
}

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
$pageTitle     = 'WebberSites AI Studio Dashboard';
$activeSection = $section;
$extraCss      = ['admin-creators.css'];
require_once __DIR__ . '/admin_header.php';
?>
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
            <?php endif; ?><script>
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
<?php require_once __DIR__ . '/admin_footer.php'; ?>
