<p>Real-time scan of file integrity and unknown files in your WordPress directory.</p>
<p>Does not require external verification, and uses a recursive directory iterator to cross-reference against checksums from WordPress.org.</p>
<p>Currently has a short whitelist for unknown files:</p>
<ul>
  <li>'.htaccess',           // Apache configuration</li>
  <li>'php.ini',            // PHP configuration</li>
  <li>'wp-config.php',      // WordPress configuration</li>
  <li>'robots.txt',         // Search engine directives</li>
  <li>'favicon.ico',        // Site favicon</li>
  <li>'.user.ini',         // User-specific PHP configuration</li>
  <li>'web.config',        // IIS configuration</li>
  <li>'.well-known',       // SSL and other verification files directory</li>
  <li>'sitemap.xml',       // XML sitemap</li>
  <li>'humans.txt',        // Team credits file</li>
  <li>'error_log',         // Error logging</li>
  <li>'php_errorlog'       // PHP error logging</li>
</ul>
