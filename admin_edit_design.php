<?php
// Admin Edit Design Page
// This page embeds the existing edit_design.php interface within the admin dashboard.
// It provides the same editing functionality for a specific design while maintaining the admin header and sidebar.

// Ensure a design ID is provided
$designId = $_GET['id'] ?? '';
if ($designId === '') {
    die('Missing design ID.');
}

// Load design record to display its name in the title bar and validate existence
$designFiles = [
    'tshirt_designs.json',
    'logo_designs.json',
    'poster_designs.json',
    'flyer_designs.json',
    'social_designs.json',
    'photo_designs.json',
    'business_card_designs.json',
    'certificate_designs.json',
    'packaging_designs.json',
    'illustration_designs.json',
    'mockup_designs.json',
    'vector_designs.json',
    'upload_designs.json',
    'cover_designs.json',
    'brochure_designs.json',
    'video_designs.json',
    'invitation_designs.json',
];
$designRecord = null;
foreach ($designFiles as $file) {
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        continue;
    }
    $records = json_decode(@file_get_contents($path), true);
    if (!is_array($records)) continue;
    foreach ($records as $d) {
        if (($d['id'] ?? '') === $designId) {
            $designRecord = $d;
            break 2;
        }
    }
}
if (!$designRecord) {
    die('Design not found.');
}
$designName = $designRecord['display_text'] ?? $designRecord['title'] ?? 'Design';

// Determine active page for sidebar highlighting. We treat this page as part of the Designs section.
$activePage = 'edit';
$pageTitle     = 'Admin — Edit Design';
$activeSection = 'designs';
$extraCss      = ['admin-creators.css'];
require_once __DIR__ . '/admin_header.php';
?>
<h1>Edit Design: <?php echo htmlspecialchars($designName); ?></h1>
            <!-- Embed the existing editing interface in an iframe -->
            <iframe class="editor-iframe" src="edit_design.php?id=<?php echo urlencode($designId); ?>"></iframe>
<?php require_once __DIR__ . '/admin_footer.php'; ?>
