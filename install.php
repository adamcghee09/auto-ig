<?php
declare(strict_types=1); require_once __DIR__.'/_shared.php'; app_start_session();
$installed = is_installed(); $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $username=trim($_POST['username']??''); $password=(string)($_POST['password']??'');
    if ($username==='' || strlen($password)<10) $error='Use a username and a password of at least 10 characters.';
    else {
        $pdo=db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS admins(id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '', encrypted INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS products(id INTEGER PRIMARY KEY, name TEXT NOT NULL, niche TEXT, affiliate_url TEXT NOT NULL, short_description TEXT, benefits TEXT, target_audience TEXT, price_range TEXT, disclaimer_text TEXT, active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS campaigns(id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE, name TEXT NOT NULL, tone TEXT, audience TEXT, content_angle TEXT, cta TEXT, hashtags TEXT, stock_terms TEXT, schedule_frequency TEXT, active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS prompts(id INTEGER PRIMARY KEY, slug TEXT UNIQUE NOT NULL, title TEXT NOT NULL, template TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS drafts(id INTEGER PRIMARY KEY, campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE, product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE, prompt_id INTEGER REFERENCES prompts(id) ON DELETE SET NULL, status TEXT NOT NULL DEFAULT 'generated', hook_text TEXT, secondary_text TEXT, caption TEXT, hashtags TEXT, cta TEXT, video_search_phrase TEXT, alt_text TEXT, affiliate_disclaimer TEXT, source_video_url TEXT, source_attribution TEXT, local_video_path TEXT, rendered_video_path TEXT, publish_id TEXT, publish_error TEXT, scheduled_at TEXT, approved_at TEXT, published_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS logs(id INTEGER PRIMARY KEY, level TEXT NOT NULL, message TEXT NOT NULL, context TEXT, created_at TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS idx_drafts_status ON drafts(status);
CREATE INDEX IF NOT EXISTS idx_campaigns_active ON campaigns(active);");
        $stmt=$pdo->prepare('INSERT OR IGNORE INTO admins(username,password_hash,created_at) VALUES(?,?,?)'); $stmt->execute([$username,password_hash($password,PASSWORD_DEFAULT),now_utc()]);
        foreach (default_prompt_templates() as $slug=>$tpl) {
            $title=ucwords(str_replace('_',' ',$slug));
            $pdo->prepare('INSERT OR IGNORE INTO prompts(slug,title,template,active,updated_at) VALUES(?,?,?,?,?)')->execute([$slug,$title,$tpl,1,now_utc()]);
        }
        $defaults=['openai_model'=>'gpt-4.1-mini','default_hashtags'=>'#affiliate #recommendations #shoppingtips','default_cta'=>'Comment “MORE”','posting_timezone'=>'America/New_York','daily_draft_limit'=>'3','auto_render'=>'0','auto_publish'=>'0'];
        foreach($defaults as $k=>$v) set_setting($k,$v,false);
        flash('Installation complete. Please log in.'); redirect('login.php');
    }
}
layout_header('Install'); if($installed): ?><div class="notice">App is already installed. <a href="login.php">Log in</a>.</div><?php else: ?>
<div class="panel login"><p class="muted">Create the first administrator. SQLite schema, encrypted settings key, and default prompt templates will be created.</p><?php if($error): ?><div class="notice error"><?=h($error)?></div><?php endif; ?><form method="post"><label>Admin username<input name="username" required autocomplete="username"></label><label>Password<input type="password" name="password" required minlength="10" autocomplete="new-password"></label><button>Install</button></form></div>
<?php endif; layout_footer();
