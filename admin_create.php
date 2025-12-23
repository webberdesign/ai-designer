<?php
// Admin Create page – provides quick access to various creative tools such as
// logo makers, T‑shirt designers, posters, flyers, social posts and product
// photos. Each card links to an appropriate page inside the admin interface.
// The design uses colorful gradient cards with Font Awesome icons and a
// modern Google font (Poppins). On small screens the text is hidden and
// icons grow to provide a clean mobile experience.

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Create Tools</title>
    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome Icons (no integrity) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
	
	<link rel="stylesheet" href="admin-styles.css">
	
    <style>
     
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
        <?php
            // Determine active page for nav highlighting
            $currentScript = basename($_SERVER['SCRIPT_NAME']);
        ?>
        <aside class="admin-sidebar">
            <!-- Reordered navigation: Create, Idea Generator, Library, Designs, Products, Orders, Config -->
            <a href="admin_create.php" class="<?php echo $currentScript === 'admin_create.php' ? 'active' : ''; ?>">Create</a>
            <a href="admin_idea_generator.php" class="<?php echo $currentScript === 'admin_idea_generator.php' ? 'active' : ''; ?>">Idea Generator</a>
            <a href="admin_media_library.php" class="<?php echo $currentScript === 'admin_media_library.php' ? 'active' : ''; ?>">Library</a>
            <a href="admin.php?section=designs" class="<?php echo ($currentScript === 'admin.php' && (!isset($_GET['section']) || $_GET['section'] === 'designs')) ? 'active' : ''; ?>">Designs</a>
            <a href="admin.php?section=products" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'products') ? 'active' : ''; ?>">Products</a>
            <a href="admin.php?section=orders" class="<?php echo ($currentScript === 'admin.php' && isset($_GET['section']) && $_GET['section'] === 'orders') ? 'active' : ''; ?>">Orders</a>
            <a href="admin_config.php" class="<?php echo $currentScript === 'admin_config.php' ? 'active' : ''; ?>">Config</a>
        </aside>
        <section class="admin-content">
            <h1>Create Tools</h1>
            <div class="tool-grid">
                <a href="admin_logo_creator.php" class="tool-card tool-logo">
                    <i class="fa-solid fa-pen-nib"></i>
                    <span>Logo</span>
                </a>
                <a href="admin_design_lab.php" class="tool-card tool-tshirt">
                    <i class="fa-solid fa-shirt"></i>
                    <span>T‑Shirt</span>
                </a>
                <!-- Cover Art Creator -->
                <a href="admin_cover_creator.php" class="tool-card tool-poster">
                    <i class="fa-solid fa-book-open"></i>
                    <span>Cover Art</span>
                </a>
                <!-- Business Card Maker -->
                <a href="admin_business_card_creator.php" class="tool-card tool-business">
                    <i class="fa-solid fa-id-card"></i>
                    <span>Business Card</span>
                </a>
                <!-- Certificates & Awards -->
                <a href="admin_certificate_creator.php" class="tool-card tool-certificate">
                    <i class="fa-solid fa-award"></i>
                    <span>Certificate</span>
                </a>
                <!-- Packaging & Label Designer -->
                <a href="admin_packaging_creator.php" class="tool-card tool-packaging">
                    <i class="fa-solid fa-box-open"></i>
                    <span>Packaging</span>
                </a>
                <!-- Illustrations & Characters -->
                <a href="admin_illustration_creator.php" class="tool-card tool-illustration">
                    <i class="fa-solid fa-feather-pointed"></i>
                    <span>Illustration</span>
                </a>
                <!-- Product Mockups -->
                <a href="admin_mockup_creator.php" class="tool-card tool-mockup">
                    <i class="fa-solid fa-cube"></i>
                    <span>Mockup</span>
                </a>
                <a href="admin_poster_creator.php" class="tool-card tool-poster">
                    <i class="fa-solid fa-scroll"></i>
                    <span>Poster</span>
                </a>
                <a href="admin_flyer_creator.php" class="tool-card tool-flyer">
                    <i class="fa-solid fa-file-lines"></i>
                    <span>Flyer</span>
                </a>
                <a href="admin_social_creator.php" class="tool-card tool-social">
                    <i class="fa-solid fa-share-nodes"></i>
                    <span>Social Post</span>
                </a>
                <a href="admin_product_photo_creator.php" class="tool-card tool-photo">
                    <i class="fa-solid fa-camera-retro"></i>
                    <span>Product Photo</span>
                </a>
                <!-- Brochure Creator -->
                <a href="admin_brochure_creator.php" class="tool-card tool-brochure">
                    <i class="fa-solid fa-file-contract"></i>
                    <span>Brochure</span>
                </a>
                <!-- Video Creator -->
                <a href="admin_video_creator.php" class="tool-card tool-video">
                    <i class="fa-solid fa-film"></i>
                    <span>Video</span>
                </a>
            </div>
        </section>
    </div>
</div>
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