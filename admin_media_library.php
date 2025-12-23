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
    'invitation'    => __DIR__ . '/invitation_designs.json',
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

$pageTitle = 'Admin — Media Library';
$activeSection = 'library';
$extraCss = ['admin-creators.css'];
require_once __DIR__ . '/admin_header.php';
?>
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
<?php require_once __DIR__ . '/admin_footer.php'; ?>
