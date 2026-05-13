<?php
declare(strict_types=1); require_once __DIR__.'/_shared.php'; require_login();
$rows=db()->query('SELECT * FROM logs ORDER BY id DESC LIMIT 200')->fetchAll();
layout_header('Logs'); ?><div class="panel"><p class="muted">Recent application events. File log is stored in <code>storage/logs/app.log</code>.</p><div class="table-wrap"><table><tr><th>Time</th><th>Level</th><th>Message</th><th>Context</th></tr><?php foreach($rows as $r): ?><tr><td><?=h($r['created_at'])?></td><td><?=h($r['level'])?></td><td><?=h($r['message'])?></td><td><code><?=h($r['context'])?></code></td></tr><?php endforeach; ?></table></div></div><?php layout_footer();
