<?php
/* ─────────────────────────────────────────────
   Central company branding + app credit.
   Included from auth.php, so it's available on every page.

   The logo is loaded from the assets/ folder. The active company logo is:
       assets/EURO_LOGO_clean.svg
   (a different file named assets/logo.png|jpg|webp|svg also works as an override).
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
