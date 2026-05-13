<?php
declare(strict_types=1); require_once __DIR__.'/_shared.php'; app_start_session(); session_destroy(); redirect('login.php');
