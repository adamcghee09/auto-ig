<?php
declare(strict_types=1); require_once __DIR__.'/_shared.php'; require_install(); app_start_session();
if (current_admin()) redirect('dashboard.php'); $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') { $stmt=db()->prepare('SELECT * FROM admins WHERE username=?'); $stmt->execute([trim($_POST['username']??'')]); $admin=$stmt->fetch(); if($admin && password_verify((string)($_POST['password']??''),$admin['password_hash'])) { $_SESSION['admin_id']=$admin['id']; redirect('dashboard.php'); } $error='Invalid username or password.'; }
layout_header('Admin Login'); show_flash(); if($error) echo '<div class="notice error">'.h($error).'</div>'; ?><div class="panel login"><form method="post"><label>Username<input name="username" autocomplete="username" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button>Log in</button></form></div><?php layout_footer();
