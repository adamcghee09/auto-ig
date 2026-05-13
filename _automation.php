<?php
declare(strict_types=1); require_once __DIR__.'/_shared.php';

function choose_prompt(): array { $rows=db()->query('SELECT * FROM prompts WHERE active=1 ORDER BY RANDOM() LIMIT 1')->fetchAll(); return $rows[0] ?? ['id'=>null,'template'=>'Create a safe affiliate Instagram Reel for {{product_name}}.']; }
function fill_template(string $tpl, array $p, array $c): string { $vars=['{{product_name}}'=>$p['name']??'','{{affiliate_url}}'=>$p['affiliate_url']??'','{{audience}}'=>$c['audience'] ?: ($p['target_audience']??''),'{{benefits}}'=>$p['benefits']??'','{{cta}}'=>$c['cta'] ?: get_setting('default_cta','Comment “MORE”'),'{{tone}}'=>$c['tone']??'friendly','{{hashtags}}'=>$c['hashtags'] ?: get_setting('default_hashtags','')]; return strtr($tpl,$vars); }
function safe_json_from_text(string $text): array { $text=trim($text); if (preg_match('/\{.*\}/s',$text,$m)) $text=$m[0]; $j=json_decode($text,true); return is_array($j)?$j:[]; }

function generate_one_draft(): ?int {
    $limit=(int)get_setting('daily_draft_limit','3');
    $today=db()->prepare("SELECT COUNT(*) FROM drafts WHERE created_at >= ?"); $today->execute([gmdate('Y-m-d 00:00:00')]); if((int)$today->fetchColumn() >= $limit) { app_log('info','Daily draft limit reached'); return null; }
    $campaign=db()->query('SELECT c.*, p.name product_name FROM campaigns c JOIN products p ON p.id=c.product_id WHERE c.active=1 AND p.active=1 ORDER BY RANDOM() LIMIT 1')->fetch(); if(!$campaign) { app_log('warning','No active campaign/product available'); return null; }
    $productStmt=db()->prepare('SELECT * FROM products WHERE id=?'); $productStmt->execute([$campaign['product_id']]); $product=$productStmt->fetch(); $prompt=choose_prompt();
    $base=fill_template($prompt['template'],$product,$campaign);
    $system='You create compliant affiliate Instagram content. Never invent guarantees, income claims, medical/legal/financial outcomes, scarcity, or product facts not provided. Always include a clear affiliate disclosure. Return only valid JSON.';
    $user=$base."\nReturn JSON with keys: hook_text, secondary_text, caption, hashtags, cta, suggested_video_search_phrase, alt_text, affiliate_disclaimer. Product disclaimer: ".($product['disclaimer_text'] ?: 'May contain affiliate links.')." Content angle: ".($campaign['content_angle'] ?? '');
    $api=get_setting('openai_api_key'); $model=get_setting('openai_model','gpt-4.1-mini');
    if($api) {
        try { $res=http_json('https://api.openai.com/v1/chat/completions',['Content-Type: application/json','Authorization: Bearer '.$api],['model'=>$model,'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],'response_format'=>['type'=>'json_object'],'temperature'=>0.8]); $data=safe_json_from_text($res['choices'][0]['message']['content'] ?? ''); }
        catch(Throwable $e){ app_log('error','OpenAI generation failed',['error'=>$e->getMessage()]); $data=[]; }
    } else $data=[];
    if(!$data) $data=['hook_text'=>'A smarter way to shop for '.$product['name'],'secondary_text'=>substr((string)$product['short_description'],0,120),'caption'=>'Affiliate disclosure: I may earn a commission if you buy through my link. '.($product['short_description'] ?: 'Here is a practical recommendation worth reviewing.').' '.$product['affiliate_url'],'hashtags'=>$campaign['hashtags'] ?: get_setting('default_hashtags'),'cta'=>$campaign['cta'] ?: get_setting('default_cta'),'suggested_video_search_phrase'=>$campaign['stock_terms'] ?: $product['niche'],'alt_text'=>'Short vertical affiliate marketing video for '.$product['name'],'affiliate_disclaimer'=>$product['disclaimer_text'] ?: 'Affiliate disclosure: I may earn a commission from qualifying purchases.'];
    $stmt=db()->prepare("INSERT INTO drafts(campaign_id,product_id,prompt_id,status,hook_text,secondary_text,caption,hashtags,cta,video_search_phrase,alt_text,affiliate_disclaimer,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$campaign['id'],$product['id'],$prompt['id']??null,'needs review',$data['hook_text']??'',$data['secondary_text']??'',$data['caption']??'',$data['hashtags']??'',$data['cta']??'',$data['suggested_video_search_phrase']??'',$data['alt_text']??'',$data['affiliate_disclaimer']??'',now_utc(),now_utc()]);
    $id=(int)db()->lastInsertId(); app_log('info','Generated draft',['draft_id'=>$id]); return $id;
}

function source_video_for_draft(array $draft): ?array {
    $q = urlencode(trim(($draft['video_search_phrase'] ?? '') ?: 'lifestyle product'));
    $pexels = get_setting('pexels_api_key');
    if ($pexels) {
        try {
            $r = http_json("https://api.pexels.com/videos/search?query=$q&orientation=portrait&per_page=5", ['Authorization: '.$pexels], null, 30);
            foreach (($r['videos'] ?? []) as $v) {
                $files = $v['video_files'] ?? [];
                usort($files, fn($a, $b) => ($b['height'] ?? 0) <=> ($a['height'] ?? 0));
                foreach ($files as $f) {
                    if (($f['height'] ?? 0) >= ($f['width'] ?? 999) && !empty($f['link'])) {
                        return ['url'=>$f['link'], 'source_url'=>$v['url'] ?? $f['link'], 'attribution'=>'Pexels video by '.($v['user']['name'] ?? 'unknown')];
                    }
                }
            }
        } catch (Throwable $e) {
            app_log('error', 'Pexels lookup failed', ['error'=>$e->getMessage()]);
        }
    }
    $pix = get_setting('pixabay_api_key');
    if ($pix) {
        try {
            $r = http_json("https://pixabay.com/api/videos/?key=".urlencode($pix)."&q=$q&per_page=5&safesearch=true", [], null, 30);
            foreach (($r['hits'] ?? []) as $hit) {
                $v = $hit['videos']['large'] ?? $hit['videos']['medium'] ?? null;
                if ($v && !empty($v['url'])) {
                    return ['url'=>$v['url'], 'source_url'=>$hit['pageURL'] ?? $v['url'], 'attribution'=>'Pixabay video by '.($hit['user'] ?? 'unknown')];
                }
            }
        } catch (Throwable $e) {
            app_log('error', 'Pixabay lookup failed', ['error'=>$e->getMessage()]);
        }
    }
    return null;
}

function render_approved_drafts(int $max=5): int {
    $rows=db()->query("SELECT * FROM drafts WHERE status='approved' ORDER BY approved_at ASC, id ASC LIMIT ".(int)$max)->fetchAll(); $count=0;
    foreach($rows as $d){ try{ if(empty($d['local_video_path'])){ $src=source_video_for_draft($d); if(!$src) throw new RuntimeException('No stock video source available. Add Pexels/Pixabay credentials or try different search terms.'); $local='storage/videos/draft-'.$d['id'].'-'.time().'.mp4'; download_file($src['url'],APP_BASE_PATH.'/'.$local); db()->prepare('UPDATE drafts SET source_video_url=?,source_attribution=?,local_video_path=?,updated_at=? WHERE id=?')->execute([$src['source_url'],$src['attribution'],$local,now_utc(),$d['id']]); $d['local_video_path']=$local; }
        $out='storage/renders/draft-'.$d['id'].'-'.time().'.mp4'; $hook=str_replace(["'",":","\\"],['’','\\:',''],mb_substr($d['hook_text']??'',0,80)); $cta=str_replace(["'",":","\\"],['’','\\:',''],mb_substr($d['cta']??get_setting('default_cta'),0,50));
        $vf="scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,drawbox=y=0:color=black@0.28:width=iw:height=ih:t=fill,drawtext=text='$hook':fontcolor=white:fontsize=72:fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf:x=(w-text_w)/2:y=260:box=1:boxcolor=black@0.45:boxborderw=24,drawtext=text='$cta':fontcolor=white:fontsize=48:fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf:x=(w-text_w)/2:y=h-300:box=1:boxcolor=black@0.55:boxborderw=18";
        $cmd=escapeshellcmd(FFMPEG_BIN).' -y -i '.escapeshellarg(APP_BASE_PATH.'/'.$d['local_video_path']).' -t 20 -vf '.escapeshellarg($vf).' -an -c:v libx264 -preset veryfast -pix_fmt yuv420p '.escapeshellarg(APP_BASE_PATH.'/'.$out).' 2>&1'; exec($cmd,$output,$code); if($code!==0) throw new RuntimeException('FFmpeg failed: '.implode("\n",array_slice($output,-8)));
        db()->prepare("UPDATE drafts SET rendered_video_path=?,status='rendered',updated_at=? WHERE id=?")->execute([$out,now_utc(),$d['id']]); app_log('info','Rendered draft',['draft_id'=>$d['id']]); $count++;
    } catch(Throwable $e){ db()->prepare("UPDATE drafts SET status='publish failed',publish_error=?,updated_at=? WHERE id=?")->execute([$e->getMessage(),now_utc(),$d['id']]); app_log('error','Render failed',['draft_id'=>$d['id'],'error'=>$e->getMessage()]); } }
    return $count;
}

function public_media_url(string $path): string { $base=rtrim(PUBLIC_STORAGE_URL,'/'); if($base) return $base.'/'.ltrim($path,'/'); $scheme=(!empty($_SERVER['HTTPS'])?'https':'http'); $host=$_SERVER['HTTP_HOST']??''; return $host ? $scheme.'://'.$host.'/'.ltrim($path,'/') : ''; }
function publish_ready_drafts(int $max=3): int {
    if(get_setting('auto_publish','0')!=='1' && PHP_SAPI==='cli') { app_log('info','Auto-publish disabled'); return 0; }
    $token=get_setting('instagram_access_token'); $ig=get_setting('instagram_business_account_id'); if(!$token||!$ig){ app_log('warning','Instagram setup needed'); return 0; }
    $rows=db()->query("SELECT * FROM drafts WHERE status='rendered' AND rendered_video_path IS NOT NULL ORDER BY updated_at ASC LIMIT ".(int)$max)->fetchAll(); $count=0;
    foreach($rows as $d){ try{ $url=public_media_url($d['rendered_video_path']); if(!$url) throw new RuntimeException('Set PUBLIC_STORAGE_URL to a public HTTPS URL for Instagram publishing.'); $caption=trim(($d['affiliate_disclaimer']? $d['affiliate_disclaimer']."\n\n":'').$d['caption']."\n\n".$d['hashtags']);
        $create=http_json("https://graph.facebook.com/v20.0/".urlencode($ig)."/media",[],['media_type'=>'REELS','video_url'=>$url,'caption'=>$caption,'access_token'=>$token],60); $creation=$create['id']??''; if(!$creation) throw new RuntimeException('No creation ID returned.'); sleep(5); $pub=http_json("https://graph.facebook.com/v20.0/".urlencode($ig)."/media_publish",[],['creation_id'=>$creation,'access_token'=>$token],60); $pid=$pub['id']??$creation;
        db()->prepare("UPDATE drafts SET status='published',publish_id=?,published_at=?,publish_error=NULL,updated_at=? WHERE id=?")->execute([$pid,now_utc(),now_utc(),$d['id']]); app_log('info','Published draft',['draft_id'=>$d['id'],'publish_id'=>$pid]); $count++;
    }catch(Throwable $e){ db()->prepare("UPDATE drafts SET status='publish failed',publish_error=?,updated_at=? WHERE id=?")->execute([$e->getMessage(),now_utc(),$d['id']]); app_log('error','Publish failed',['draft_id'=>$d['id'],'error'=>$e->getMessage()]); } }
    return $count;
}
