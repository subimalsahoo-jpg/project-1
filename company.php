<?php
/* ─────────────────────────────────────────────
   Central company branding + app credit.
   Included from auth.php, so it's available on every page.

   To use YOUR real logo everywhere: drop the image file at
       assets/logo.png   (png / jpg / webp / svg all supported)
   It will automatically replace the bundled fallback mark.
───────────────────────────────────────────── */
if (!defined('COMPANY_NAME'))  define('COMPANY_NAME',  'EURO TROUSERS MFG CO (FZC)');
if (!defined('COMPANY_SHORT')) define('COMPANY_SHORT', 'Euro Trousers');
if (!defined('APP_CREDIT'))    define('APP_CREDIT',    'Payroll Developed by Euro Trousers');

/* Resolve a usable logo URL (prefers a real uploaded logo, falls back to SVG). */
if (!function_exists('company_logo_url')) {
    function company_logo_url() {
        $dir = __DIR__;
        foreach (['assets/EURO_LOGO_clean.svg', 'assets/logo.png', 'assets/logo.jpg', 'assets/logo.jpeg', 'assets/logo.webp', 'assets/logo.svg'] as $rel) {
            if (is_file($dir . '/' . $rel)) {
                return $rel;
            }
        }
        return 'assets/EURO_LOGO_clean.svg';
    }
}

/* Ready-to-print <img> tag for the logo. $h = height in px. */
if (!function_exists('company_logo_img')) {
    function company_logo_img($h = 40, $extra_style = '') {
        $src = htmlspecialchars(company_logo_url());
        $h   = (int)$h;
        return '<img src="' . $src . '" alt="' . htmlspecialchars(COMPANY_NAME) . '" '
             . 'style="height:' . $h . 'px;width:auto;object-fit:contain;vertical-align:middle;' . $extra_style . '">';
    }
}


/* Resolve a printable letterhead image URL, if the user has dropped one in.
   Drop the scanned/exported letterhead header at one of these paths to make
   printed documents (e.g. the Gate Pass) use the EXACT company letterhead:
       assets/letterhead.png   (png / jpg / jpeg / webp / svg all supported)
   Returns '' when none is present so callers can fall back to the HTML
   recreation below. */
if (!function_exists('company_letterhead_url')) {
    function company_letterhead_url() {
        $dir = __DIR__;
        foreach ([
            'assets/letterhead.png', 'assets/letterhead.jpg', 'assets/letterhead.jpeg',
            'assets/letterhead.webp', 'assets/letterhead.svg', 'assets/letterhead_header.png',
        ] as $rel) {
            if (is_file($dir . '/' . $rel)) {
                return $rel;
            }
        }
        return '';
    }
}

/* Printable company letterhead block.
   - If a real letterhead image exists (see company_letterhead_url), it is
     rendered full-width so printed pages match the official stationery.
   - Otherwise an HTML recreation of the Euro Trousers letterhead is produced
     (Arabic name, company name, P.O. Box / Saif Zone address, tel/fax, email)
     so the document still prints with a proper header on plain white paper. */
if (!function_exists('company_letterhead_html')) {
    function company_letterhead_html() {
        $img = company_letterhead_url();
        if ($img !== '') {
            return '<div class="lh-img-wrap" style="text-align:center;">'
                 . '<img src="' . htmlspecialchars($img) . '" alt="' . htmlspecialchars(COMPANY_NAME) . '" '
                 . 'style="max-width:100%;width:100%;height:auto;display:block;margin:0 auto;">'
                 . '</div>';
        }

        $blue = '#1a4f9c';
        ob_start();
        ?>
        <div class="lh-recreated" style="text-align:center;color:<?php echo $blue; ?>;font-family:'Times New Roman',Georgia,serif;line-height:1.25;">
            <div dir="rtl" style="font-size:26px;font-weight:700;letter-spacing:1px;">يـروتـراوزرس ام اف جي كمبـني (ش.م.ح.)</div>
            <div style="font-size:34px;font-weight:800;letter-spacing:1.5px;margin:2px 0 4px;">EURO TROUSERS MFG. CO. (FZC)</div>
            <div style="font-size:12.5px;font-weight:700;font-family:Arial,Helvetica,sans-serif;letter-spacing:.2px;">
                P.O. Box : 8565, Saif Zone, Sharjah - U.A.E., Tel. : 00971-6-5571819 / 5571579, Fax : 00971-6-5571817
            </div>
            <div style="font-size:12px;font-weight:600;font-family:Arial,Helvetica,sans-serif;margin-top:1px;">
                E-mail : Enquiry@eurotrs.ae
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
