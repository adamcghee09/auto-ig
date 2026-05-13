<?php
declare(strict_types=1); require_once __DIR__.'/_automation.php';
if(PHP_SAPI!=='cli') require_login();
if(PHP_SAPI!=='cli' || get_setting('auto_publish','0')==='1') $count=publish_ready_drafts(); else $count=0;
echo "Published $count draft(s)\n";
