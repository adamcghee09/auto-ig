<?php
declare(strict_types=1); require_once __DIR__.'/_automation.php';
if(PHP_SAPI!=='cli') require_login();
$id=generate_one_draft();
if(get_setting('auto_render','0')==='1') render_approved_drafts();
echo $id ? "Generated draft #$id\n" : "No draft generated\n";
