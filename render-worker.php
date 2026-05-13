<?php
declare(strict_types=1); require_once __DIR__.'/_automation.php';
if(PHP_SAPI!=='cli') require_login();
$count=render_approved_drafts();
echo "Rendered $count draft(s)\n";
