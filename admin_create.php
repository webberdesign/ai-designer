<?php
// Admin Create page – provides quick access to various creative tools.
$pageTitle = 'Admin — Create Tools';
$activeSection = 'create';
require_once __DIR__ . '/admin_header.php';
?>
<h1>Create Tools</h1>
<p class="lead">Jump into any generator or designer with the refreshed admin toolkit.</p>
<div class="tool-grid">
    <a href="admin_logo_creator.php" class="tool-card gradient-a">
        <span class="badge">Branding</span>
        <i class="fa-solid fa-pen-nib"></i>
        <span>Logo</span>
    </a>
    <a href="admin_design_lab.php" class="tool-card gradient-b">
        <span class="badge">Apparel</span>
        <i class="fa-solid fa-shirt"></i>
        <span>T‑Shirt</span>
    </a>
    <a href="admin_cover_creator.php" class="tool-card gradient-c">
        <span class="badge">Music</span>
        <i class="fa-solid fa-book-open"></i>
        <span>Cover Art</span>
    </a>
    <a href="admin_business_card_creator.php" class="tool-card gradient-d">
        <span class="badge">Print</span>
        <i class="fa-solid fa-id-card"></i>
        <span>Business Card</span>
    </a>
    <a href="admin_certificate_creator.php" class="tool-card gradient-e">
        <span class="badge">Awards</span>
        <i class="fa-solid fa-award"></i>
        <span>Certificate</span>
    </a>
    <a href="admin_packaging_creator.php" class="tool-card gradient-f">
        <span class="badge">Retail</span>
        <i class="fa-solid fa-box-open"></i>
        <span>Packaging</span>
    </a>
    <a href="admin_illustration_creator.php" class="tool-card gradient-b">
        <span class="badge">Art</span>
        <i class="fa-solid fa-feather-pointed"></i>
        <span>Illustration</span>
    </a>
    <a href="admin_mockup_creator.php" class="tool-card gradient-a">
        <span class="badge">Showcase</span>
        <i class="fa-solid fa-cube"></i>
        <span>Mockup</span>
    </a>
    <a href="admin_poster_creator.php" class="tool-card gradient-c">
        <span class="badge">Large</span>
        <i class="fa-solid fa-scroll"></i>
        <span>Poster</span>
    </a>
    <a href="admin_flyer_creator.php" class="tool-card gradient-d">
        <span class="badge">Events</span>
        <i class="fa-solid fa-file-lines"></i>
        <span>Flyer</span>
    </a>
    <a href="admin_social_creator.php" class="tool-card gradient-b">
        <span class="badge">Social</span>
        <i class="fa-solid fa-share-nodes"></i>
        <span>Social Post</span>
    </a>
    <a href="admin_product_photo_creator.php" class="tool-card gradient-e">
        <span class="badge">Photos</span>
        <i class="fa-solid fa-camera-retro"></i>
        <span>Product Photo</span>
    </a>
    <a href="admin_brochure_creator.php" class="tool-card gradient-f">
        <span class="badge">Print</span>
        <i class="fa-solid fa-file-contract"></i>
        <span>Brochure</span>
    </a>
    <a href="admin_video_creator.php" class="tool-card gradient-a">
        <span class="badge">Media</span>
        <i class="fa-solid fa-film"></i>
        <span>Video</span>
    </a>
    <a href="admin_invitation_creator.php" class="tool-card gradient-c">
        <span class="badge">Events</span>
        <i class="fa-solid fa-envelope-open-text"></i>
        <span>Invitation</span>
    </a>
</div>
<?php require_once __DIR__ . '/admin_footer.php'; ?>
