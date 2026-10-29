<?php
require __DIR__ . '/db.php';

$labels = ['Oils' => 'oils', 'Brake Pads' => 'brakes', 'Tires' => 'tires', 'Gears' => 'gears'];
$parts = [];
try {
  $parts = db()->query("SELECT * FROM parts ORDER BY FIELD(category,'Oils','Brake Pads','Tires','Gears'), sort_order, id")->fetchAll();
} catch (Exception $e) {}

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function peso($n) { return '&#8369;' . number_format((float)$n); }
// Finds the real photo even if it is .png, .jpeg or .webp instead of the .jpg saved in the database.
function img_path($path) {
  $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $path);
  foreach (['jpg', 'jpeg', 'png', 'webp', 'JPG', 'JPEG', 'PNG'] as $x)
    if (is_file(__DIR__ . '/' . $base . '.' . $x)) return $base . '.' . $x;
  return $path;
}
function price($p) { return peso($p['price_min']) . ' &ndash; ' . peso($p['price_max']); }

$byCat = [];
foreach ($parts as $p) $byCat[$p['category']][] = $p;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="description" content="Motorcycle parts in Bangkal, Makati: oils, brake pads, tires and gears. Message The Feng's Garage to ask for price and stock." />
  <title>Parts | The Feng's Garage in Bangkal, Makati</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@0,600;1,600;1,800&family=Titillium+Web:wght@400;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="style.css" />
</head>
<body>

  <header>
    <input type="checkbox" id="nav-toggle" />
    <div class="wrap nav">
      <a class="brand" href="index.html">
        <span class="brand-mark">
          <svg class="brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="5.5" cy="17" r="3.5"/><circle cx="18.5" cy="17" r="3.5"/><path d="M5.5 17h5l3-8h3"/><path d="M14 9l2.5 8"/><path d="M13 6h3"/></svg>
          <img class="brand-logo" src="assets/logo.png" alt="The Feng's Garage logo" onerror="this.remove()" />
        </span>
        <span>The Feng's Garage</span>
      </a>
      <nav>
        <a href="index.html">Home</a>
        <a href="services.html">Services</a>
        <a class="active" href="parts.php">Parts</a>
        <a href="about.html">About</a>
        <a href="gallery.html">Gallery</a>
      </nav>
      <a class="btn" href="booking.html">Book a Service</a>
      <label class="menu-btn" for="nav-toggle" aria-label="Open menu"><span></span><span></span><span></span></label>
    </div>
  </header>

  <main id="top">

    <section class="g-hero">
      <p class="small-text">Bangkal, Makati &middot; by appointment</p>
      <h1>The <em>Parts</em></h1>
      <p class="lead">Oils, brake pads, tires and gears for your scooter. Tap any photo to see it bigger, then message us for price and stock.</p>
    </section>

    <div class="checkers"></div>

    <section class="block" id="photos">
      <div class="wrap">

        <input class="g-radio" type="radio" name="g-filter" id="f-all" checked />
        <input class="g-radio" type="radio" name="g-filter" id="f-oils" />
        <input class="g-radio" type="radio" name="g-filter" id="f-brakes" />
        <input class="g-radio" type="radio" name="g-filter" id="f-tires" />
        <input class="g-radio" type="radio" name="g-filter" id="f-gears" />

        <div class="g-filters">
          <label for="f-all">All parts</label>
          <label for="f-oils">Oils</label>
          <label for="f-brakes">Brake Pads</label>
          <label for="f-tires">Tires</label>
          <label for="f-gears">Gears</label>
        </div>

        <div class="g-grid">
<?php foreach ($parts as $p): $c = $labels[$p['category']]; ?>

          <a class="g-item <?= $c ?><?= $p['is_featured'] ? ' feature' : '' ?>" href="#<?= e($p['slug']) ?>">
            <img src="<?= e(img_path($p['image'])) ?>" alt="<?= e($p['name']) ?>" loading="lazy" onerror="this.remove()" />
            <span class="g-tag"><?= e($p['category']) ?></span>
            <div class="g-cap"><div class="g-info"><span><?= e($p['name']) ?></span><small class="g-price"><?= $p['in_stock'] ? price($p) : 'Out of stock' ?></small></div></div>
          </a>
<?php endforeach; ?>
<?php if (!$parts): ?>
          <p class="note">Parts list is unavailable right now. Please message us on Facebook.</p>
<?php endif; ?>

        </div>

        <p class="note">Photos are being added. Don't see your part? Message us on Facebook and we'll check.</p>
      </div>
    </section>

<?php foreach ($byCat as $list): $n = count($list); foreach ($list as $i => $p):
  $prev = $list[($i + $n - 1) % $n]['slug']; $next = $list[($i + 1) % $n]['slug']; ?>

    <div class="lightbox" id="<?= e($p['slug']) ?>" role="dialog" aria-label="<?= e($p['name']) ?>">
      <a class="lb-btn lb-close" href="#photos" aria-label="Close">&times;</a>
      <a class="lb-btn lb-prev" href="#<?= e($prev) ?>" aria-label="Previous photo">&#8249;</a>
      <a class="lb-btn lb-next" href="#<?= e($next) ?>" aria-label="Next photo">&#8250;</a>
      <div class="lb-stage"><img src="<?= e(img_path($p['image'])) ?>" alt="" onerror="this.remove()" /></div>
      <div class="lb-title"><?= e($p['name']) ?><small><?= e($p['description']) ?> &middot; <?= $p['in_stock'] ? price($p) : 'Out of stock' ?> &middot; Message us for exact price</small></div>
      <a class="btn" href="https://m.me/thefengsgarage" target="_blank" rel="noopener noreferrer">Ask about this part</a>
    </div>
<?php endforeach; endforeach; ?>

    <div class="checkers"></div>

    <section class="contact" id="contact">
      <div class="wrap">
        <h2>Looking for a part?</h2>
        <p>Ask about parts, pricing, and availability on Facebook, or call the shop.</p>
        <div class="buttons">
          <a class="btn" href="https://m.me/thefengsgarage" target="_blank" rel="noopener noreferrer">Message TFG on Facebook</a>
          <a class="btn" href="tel:+639177043243">Call the shop</a>
        </div>
      </div>
    </section>

  </main>

  <footer>
    <div class="wrap">
      <div class="footer-grid">
        <div>
          <a class="brand" href="index.html">
            <span class="brand-mark">
              <svg class="brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="5.5" cy="17" r="3.5"/><circle cx="18.5" cy="17" r="3.5"/><path d="M5.5 17h5l3-8h3"/><path d="M14 9l2.5 8"/><path d="M13 6h3"/></svg>
              <img class="brand-logo" src="assets/logo.png" alt="The Feng's Garage logo" onerror="this.remove()" />
            </span>
            <span>The Feng's Garage</span>
          </a>
          <p>TFG in Bangkal, Makati. Service by appointment.</p>
        </div>
        <div>
          <h4>Pages</h4>
          <ul>
            <li><a href="services.html">Services</a></li>
            <li><a href="parts.php">Parts</a></li>
            <li><a href="about.html">About</a></li>
            <li><a href="gallery.html">Gallery</a></li>
          </ul>
        </div>
        <div>
          <h4>Visit and contact</h4>
          <ul>
            <li>3759 Estrella St., Bangkal, Makati City</li>
            <li>Near the Phoenix gasoline station</li>
            <li>Schedule: By appointment</li>
            <li>Payment: GCash or Cash</li>
            <li><a href="tel:+639177043243">+63 917 704 3243</a></li>
            <li><a href="https://www.facebook.com/thefengsgarage/" target="_blank" rel="noopener noreferrer">Facebook: The Feng's Garage</a></li>
          </ul>
        </div>
      </div>
      <div class="copyright">&copy; 2026 The Feng's Garage. All rights reserved.</div>
    </div>
  </footer>

</body>
</html>