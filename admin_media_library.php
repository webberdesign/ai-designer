<?php
/*
 * Admin Media Library
 *
 * This page aggregates all generated designs across the various creator tools and
 * displays them in a unified, filterable gallery. Administrators can view
 * thumbnails, filter by design type or source model, inspect detailed
 * information about each design, and download the original file. The layout
 * follows the existing admin dashboard aesthetic and includes a sidebar
 * navigation link for quick access.
 */

session_start();

// Load configuration and helper functions
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Define a mapping of tool keys to their JSON database files. Update this
// mapping whenever a new design type is introduced so it appears in the library.
$toolFiles = [
    'tshirt'        => __DIR__ . '/tshirt_designs.json',
    'logo'          => __DIR__ . '/logo_designs.json',
    'poster'        => __DIR__ . '/poster_designs.json',
    'flyer'         => __DIR__ . '/flyer_designs.json',
    'social'        => __DIR__ . '/social_designs.json',
    'photo'         => __DIR__ . '/photo_designs.json',
    'business_card' => __DIR__ . '/business_card_designs.json',
    'certificate'   => __DIR__ . '/certificate_designs.json',
    'packaging'     => __DIR__ . '/packaging_designs.json',
    'illustration'  => __DIR__ . '/illustration_designs.json',
    'mockup'        => __DIR__ . '/mockup_designs.json',
    // Vectorized designs (produced via the vectorize tool)
    'vector'        => __DIR__ . '/vector_designs.json',
    // Uploaded designs (user uploads saved via front‑end or admin upload). These are hidden by default unless filtered.
    'upload'        => __DIR__ . '/upload_designs.json',
];

// Populate a flat list of all designs, tagging each with its tool key
$allDesigns = [];
foreach ($toolFiles as $key => $file) {
    if (!file_exists($file)) {
        continue;
    }
    $records = json_decode(@file_get_contents($file), true);
    if (!is_array($records)) {
        continue;
    }
    foreach ($records as $rec) {
        // Ensure we have at least a file reference; skip incomplete records
        if (!isset($rec['file'])) {
            continue;
        }
        $rec['tool'] = $rec['tool'] ?? $key;
        $rec['source'] = $rec['source'] ?? ($rec['tool'] === 'tshirt' ? 'openai' : 'unknown');
        $allDesigns[] = $rec;
    }
}

// Sort designs by creation date descending
usort($allDesigns, function ($a, $b) {
    return strtotime($b['created_at'] ?? '0') <=> strtotime($a['created_at'] ?? '0');
});

// Handle filtering based on GET parameters
$selectedTool   = $_GET['tool']   ?? 'all';
$selectedSource = $_GET['source'] ?? 'all';

$filteredDesigns = array_filter($allDesigns, function ($d) use ($selectedTool, $selectedSource) {
    // Hide upload items when the default "all" filter is active. They can still be viewed by selecting the Upload filter explicitly.
    if ($selectedTool === 'all' && ($d['tool'] ?? '') === 'upload') {
        return false;
    }
    if ($selectedTool !== 'all' && $d['tool'] !== $selectedTool) {
        return false;
    }
    if ($selectedSource !== 'all' && $d['source'] !== $selectedSource) {
        return false;
    }
    return true;
});

// Build lists for filter dropdowns
$toolOptions = array_keys($toolFiles);
$sourceOptions = [];
foreach ($allDesigns as $d) {
    $sourceOptions[$d['source']] = true;
}
$sourceOptions = array_keys($sourceOptions);
sort($sourceOptions);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Media Library</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            margin: 0;
            font-family: Poppins, system-ui, sans-serif;
            background: var(--bg);
            color: #333;
        }
        .admin-container { display: flex; min-height: 100vh; flex-direction: column; }
        header.admin-header {
            background: var(--primary);
            color: var(--text-light);
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        header .brand { font-size: 1.4rem; font-weight: 700; letter-spacing: 0.03em; }
        header .top-nav a { color: var(--text-light); text-decoration: none; margin-left: 20px; font-size: 0.9rem; }
        .admin-main { flex: 1; display: flex; min-height: 0; }
        .admin-sidebar {
            width: 220px;
            background: var(--secondary);
            color: var(--text-light);
            padding-top: 24px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            transition: left 0.3s ease;
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

        /* Mobile menu styles */
        .menu-toggle {
            display: none;
            font-size: 1.5rem;
            color: var(--text-light);
            cursor: pointer;
            margin-right: 10px;
        }
        @media (max-width: 768px) {
            .admin-sidebar {
                position: fixed;
                top: 0;
                left: -240px;
                height: 100vh;
                z-index: 1000;
                width: 200px;
            }
            body.mobile-open .admin-sidebar {
                left: 0;
            }
            .admin-main {
                flex-direction: column;
            }
            .menu-toggle {
                display: inline-block;
            }
        }
        .admin-content {
            flex: 1;
            padding: 24px;
            overflow-y: auto;
        }
        h1 { font-size: 1.6rem; margin-top: 0; margin-bottom: 20px; }
        .filters {
            margin-bottom: 20px;
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        .filters select {
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.9rem;
            background: #fff;
        }
        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 16px;
        }
        .gallery-item {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            cursor: pointer;
            transition: transform 0.15s ease;
        }
        .gallery-item:hover { transform: translateY(-4px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }

        /* Filter pill buttons */
        .filter-pills {
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .filter-pill {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            color: #374151;
            background: #e5e7eb;
            border: 1px solid #d1d5db;
            transition: background 0.2s, color 0.2s;
        }
        .filter-pill:hover { background: #d1d5db; }
        .filter-pill.active {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }
        .gallery-item img { width: 100%; height: 140px; object-fit: cover; background: #f0f0f0; }
        .gallery-info { padding: 10px; font-size: 0.8rem; color: #4b5563; }
        .gallery-info .title { font-weight: 600; margin-bottom: 4px; color: #1f2937; }
        .gallery-info .meta { font-size: 0.7rem; color: #6b7280; }
        /* Modal */
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.75);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 100;
        }
        .modal.open { display: flex; }
        .modal-content {
            background: #fff;
            border-radius: 8px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .modal-header { padding: 16px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 1.1rem; font-weight: 600; }
        .modal-body { padding: 16px; }
        .modal-body img { width: 100%; height: auto; border-radius: 4px; margin-bottom: 12px; }
        .modal-body table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .modal-body th, .modal-body td { text-align: left; padding: 4px 6px; font-size: 0.85rem; }
        .modal-body th { width: 30%; color: #4b5563; }
        .modal-body td { color: #1f2937; }
        .modal-actions { padding: 16px; border-top: 1px solid #e5e7eb; text-align: right; }
        .modal-actions a { background: var(--accent); color: #fff; padding: 8px 14px; border-radius: 6px; text-decoration: none; font-size: 0.85rem; }
        .modal-actions a:hover { background: var(--accent-light); }
        .close-btn { font-size: 1rem; cursor: pointer; color: #6b7280; }
        .close-btn:hover { color: #1f2937; }
    </style>
</head>
<body>
<div class="admin-container">
    <header class="admin-header">
        <span class="menu-toggle" id="mobileMenuToggle">&#9776;</span>
        <div class="brand">WebberSites AI Studio</div>
        <nav class="top-nav">
            <a href="index.php" target="_blank">View Store</a>
        </nav>
    </header>
    <div class="admin-main">
        <?php $currentScript = basename($_SERVER['SCRIPT_NAME']); ?>
        <aside class="admin-sidebar">
            <a href="admin.php?section=designs" class="<?php echo ($currentScript === 'admin.php' && (!isset($_GET['section']) || $_GET['section'] === 'designs')) ? 'active' : ''; ?>">Designs</a>
            <a href="admin_design_lab.php" class="<?php echo ($currentScript === 'admin_design_lab.php' || $currentScript === 'admin_design_lab_gemini.php') ? 'active' : ''; ?>">Design Lab</a>
            <a href="admin_idea_generator.php" class="<?php echo $currentScript === 'admin_idea_generator.php' ? 'active' : ''; ?>">Idea Generator</a>
            <a href="admin_create.php" class="<?php echo $currentScript === 'admin_create.php' ? 'active' : ''; ?>">Create</a>
            <a href="admin_media_library.php" class="<?php echo $currentScript === 'admin_media_library.php' ? 'active' : ''; ?>">Library</a>
            <a href="admin.php?section=products" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'products') ? 'active' : ''; ?>">Products</a>
            <a href="admin.php?section=orders" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'orders') ? 'active' : ''; ?>">Orders</a>
            <a href="admin_config.php" class="<?php echo $currentScript === 'admin_config.php' ? 'active' : ''; ?>">Config</a>
        </aside>
        <section class="admin-content">
            <h1>Media Library</h1>
            <!-- Filters using pill buttons -->
            <div class="filter-pills">
                <span style="margin-right:8px;font-weight:600;color:#374151;font-size:0.85rem;">Type:</span>
                <a href="admin_media_library.php?tool=all&source=<?php echo urlencode($selectedSource); ?>" class="filter-pill <?php echo ($selectedTool === 'all') ? 'active' : ''; ?>">All</a>
                <?php foreach ($toolOptions as $opt): ?>
                <a href="admin_media_library.php?tool=<?php echo urlencode($opt); ?>&source=<?php echo urlencode($selectedSource); ?>" class="filter-pill <?php echo ($selectedTool === $opt) ? 'active' : ''; ?>"><?php echo ucwords(str_replace('_', ' ', $opt)); ?></a>
                <?php endforeach; ?>
            </div>
            <div class="filter-pills">
                <span style="margin-right:8px;font-weight:600;color:#374151;font-size:0.85rem;">Source:</span>
                <a href="admin_media_library.php?tool=<?php echo urlencode($selectedTool); ?>&source=all" class="filter-pill <?php echo ($selectedSource === 'all') ? 'active' : ''; ?>">All</a>
                <?php foreach ($sourceOptions as $opt): ?>
                <a href="admin_media_library.php?tool=<?php echo urlencode($selectedTool); ?>&source=<?php echo urlencode($opt); ?>" class="filter-pill <?php echo ($selectedSource === $opt) ? 'active' : ''; ?>"><?php echo ucwords($opt); ?></a>
                <?php endforeach; ?>
            </div>
            <!-- Gallery -->
            <div class="gallery" id="gallery">
                <?php foreach ($filteredDesigns as $d): ?>
                <div class="gallery-item" data-id="<?php echo htmlspecialchars($d['id']); ?>">
                    <?php
                        $imgSrc = is_file(__DIR__ . '/generated_tshirts/' . $d['file']) ? 'generated_tshirts/' . rawurlencode($d['file']) : '';
                    ?>
                    <img src="<?php echo $imgSrc; ?>" alt="<?php echo htmlspecialchars($d['tool']); ?> design">
                    <div class="gallery-info">
                        <div class="title">
                            <?php
                                // Determine a display name: use specific fields or fall back to ID
                                if (isset($d['display_text']) && $d['display_text'] !== '') {
                                    echo htmlspecialchars($d['display_text']);
                                } elseif (isset($d['name'])) {
                                    echo htmlspecialchars($d['name']);
                                } elseif (isset($d['title'])) {
                                    echo htmlspecialchars($d['title']);
                                } elseif (isset($d['product_name'])) {
                                    echo htmlspecialchars($d['product_name']);
                                } elseif (isset($d['subject'])) {
                                    echo htmlspecialchars($d['subject']);
                                } else {
                                    echo htmlspecialchars($d['id']);
                                }
                            ?>
                        </div>
                        <div class="meta">
                            <?php echo ucwords(str_replace('_', ' ', $d['tool'])); ?> • <?php echo date('Y-m-d', strtotime($d['created_at'] ?? '')); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>

<!-- Modal overlay for details -->
<div class="modal" id="detailModal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle">Design Details</div>
            <span class="close-btn" id="closeModal">&times;</span>
        </div>
        <div class="modal-body" id="modalBody">
            <!-- Populated via JS -->
        </div>
        <div class="modal-actions" id="modalActions">
            <!-- Download button inserted via JS -->
        </div>
    </div>
</div>

<script>
// JavaScript to handle modal display and details
document.addEventListener('DOMContentLoaded', function () {
    const gallery = document.getElementById('gallery');
    const modal   = document.getElementById('detailModal');
    const closeBtn= document.getElementById('closeModal');
    const modalTitle  = document.getElementById('modalTitle');
    const modalBody   = document.getElementById('modalBody');
    const modalActions= document.getElementById('modalActions');
    if (gallery) {
        gallery.addEventListener('click', function (e) {
            const item = e.target.closest('.gallery-item');
            if (!item) return;
            const id = item.getAttribute('data-id');
            // Fetch details from a generated JS object embedded in page
            const details = window.designDetails[id];
            if (!details) return;
            // Populate modal
            modalTitle.textContent = details.display_title;
            let html = '';
            if (details.image_url) {
                html += '<img src="' + details.image_url + '" alt="Preview">';
            }
            html += '<table>';
            for (const key of Object.keys(details.info)) {
                html += '<tr><th>' + key + '</th><td>' + details.info[key] + '</td></tr>';
            }
            html += '</table>';
            modalBody.innerHTML = html;
            modalActions.innerHTML = '';
            // Add download button
            if (details.download_url) {
                const dl = document.createElement('a');
                dl.href = details.download_url;
                dl.textContent = 'Download';
                dl.download = '';
                modalActions.appendChild(dl);
            }
            // Add vectorize button
            if (details.vectorize_url) {
                const vec = document.createElement('a');
                vec.href = details.vectorize_url;
                vec.textContent = 'Vectorize';
                modalActions.appendChild(vec);
            }
            // Add edit button
            if (details.edit_url) {
                const editBtn = document.createElement('a');
                editBtn.href = details.edit_url;
                editBtn.textContent = 'Edit';
                modalActions.appendChild(editBtn);
            }
            modal.classList.add('open');
        });
    }
    if (closeBtn && modal) {
        closeBtn.addEventListener('click', function () {
            modal.classList.remove('open');
        });
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                modal.classList.remove('open');
            }
        });
    }
    // Prepare a dictionary of design details for quick lookup
    window.designDetails = {};
    <?php
    foreach ($filteredDesigns as $d):
        // Build display title
        $displayTitle = '';
        if (isset($d['display_text']) && $d['display_text'] !== '') {
            $displayTitle = $d['display_text'];
        } elseif (isset($d['name']) && $d['name'] !== '') {
            $displayTitle = $d['name'];
        } elseif (isset($d['title']) && $d['title'] !== '') {
            $displayTitle = $d['title'];
        } elseif (isset($d['product_name']) && $d['product_name'] !== '') {
            $displayTitle = $d['product_name'];
        } elseif (isset($d['subject']) && $d['subject'] !== '') {
            $displayTitle = $d['subject'];
        } else {
            $displayTitle = $d['id'];
        }
        // Build info fields: include all key-value pairs except file and id
        $info = [];
        foreach ($d as $k => $v) {
            if (in_array($k, ['id','file'])) continue;
            // Convert arrays to JSON strings for display
            if (is_array($v)) {
                $v = json_encode($v);
            }
            $info[ucwords(str_replace('_',' ', $k))] = htmlspecialchars((string)$v);
        }
        // Determine image URL
        $imageUrl = '';
        if (isset($d['file']) && is_file(__DIR__ . '/generated_tshirts/' . $d['file'])) {
            $imageUrl = 'generated_tshirts/' . rawurlencode($d['file']);
        }
        // Determine download URL (same as image URL)
        $downloadUrl = $imageUrl;
        $jsId = json_encode($d['id']);
        $jsTitle = json_encode($displayTitle);
        $jsInfo = json_encode($info);
        $jsImage = json_encode($imageUrl);
        $jsDownload = json_encode($downloadUrl);
        $vectorUrl = 'admin_vectorize.php?id=' . urlencode($d['id']);
        $jsVector = json_encode($vectorUrl);
        $editUrl  = 'admin_edit_design.php?id=' . urlencode($d['id']);
        $jsEdit = json_encode($editUrl);
        echo "window.designDetails[$jsId] = {display_title: $jsTitle, info: $jsInfo, image_url: $jsImage, download_url: $jsDownload, vectorize_url: $jsVector, edit_url: $jsEdit};\n";
    endforeach;
    ?>
});
</script>
<!-- Mobile menu toggle script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.getElementById('mobileMenuToggle');
    if (toggle) {
        toggle.addEventListener('click', function() {
            document.body.classList.toggle('mobile-open');
        });
    }
});
</script>
</body>
</html>