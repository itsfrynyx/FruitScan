<?php
declare(strict_types=1);
require __DIR__ . '/server/bootstrap.php';
require __DIR__ . '/server/PredictionService.php';
if (is_file(STORAGE.'/database-migration.lock')) fail('Database maintenance is in progress. Please try again shortly.',503);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
session_name('fruitscan_session');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$action = $_GET['action'] ?? 'bootstrap';
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) fail('Method not allowed.', 405);
$readActions = ['bootstrap','history','detail','image','stats','users','classes','settings','feedback-list'];
if ($method === 'GET' && !in_array($action, $readActions, true)) fail('Use POST for this action.', 405);
if ($method === 'POST' && !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail('Your session changed. Refresh the page and try again.', 403);
try {
    switch ($action) {
        case 'bootstrap':
            respond(['success'=>true, 'user'=>current_user(), 'csrf'=>$_SESSION['csrf'], 'demo'=>is_demo(), 'model'=>model_config()['version'] ?? null, 'classes'=>query("SELECT * FROM fruit_classes WHERE status='active' ORDER BY name")->fetchAll(), 'model_classes'=>model_config()['fruit_classes'] ?? [], 'allow_guest'=>setting('allow_guest','1')==='1', 'maintenance'=>setting('maintenance','0')==='1']);
        case 'register':
            rate_limit('register', 10); $d=input(); $name=clean($d['name']??''); $email=strtolower(clean($d['email']??'')); $pass=$d['password']??'';
            if (mb_strlen($name)<2 || !filter_var($email,FILTER_VALIDATE_EMAIL)) fail('Enter your full name and a valid email address.');
            if (!is_string($pass)) fail('Enter a valid password.'); validate_password($pass);
            if ($pass!==($d['confirm']??'')) fail('The passwords do not match.');
            if (query('SELECT id FROM users WHERE email=?',[$email])->fetch()) fail('This email is already registered. Try signing in.');
            query('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)',[$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);
            session_regenerate_id(true); $_SESSION['user_id']=(int)db()->lastInsertId(); $_SESSION['session_version']=1; respond(['success'=>true,'user'=>current_user()]);
        case 'login':
            rate_limit('login',20); $d=input(); $email=strtolower(clean($d['email']??''));
            $u=query('SELECT * FROM users WHERE email=?',[$email])->fetch();
            $hash=$u['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $pass=is_string($d['password']??null)?$d['password']:'';
            if (!password_verify($pass,$hash) || !$u || $u['status']!=='active') fail('Email or password is incorrect, or this account is inactive.',401);
            if (($d['admin']??false) && $u['role']!=='admin') fail('This account does not have administrator access.',403);
            session_regenerate_id(true); $_SESSION['user_id']=$u['id']; $_SESSION['session_version']=(int)$u['session_version']; respond(['success'=>true,'user'=>current_user()]);
        case 'logout':
            $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); respond(['success'=>true,'csrf'=>$_SESSION['csrf']]);
        case 'profile':
            $u=require_user(); $d=input(); $name=clean($d['name']??''); $email=strtolower(clean($d['email']??''));
            if (mb_strlen($name)<2 || !filter_var($email,FILTER_VALIDATE_EMAIL)) fail('Enter a name and valid email.');
            if (query('SELECT id FROM users WHERE email=? AND id<>?',[$email,$u['id']])->fetch()) fail('That email is already in use.');
            query('UPDATE users SET name=?,email=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$name,$email,$u['id']]); respond(['success'=>true,'user'=>current_user()]);
        case 'password':
            $u=require_user(); $d=input(); $old=query('SELECT password_hash FROM users WHERE id=?',[$u['id']])->fetchColumn();
            if (!password_verify((string)($d['current']??''),$old)) fail('Your current password is incorrect.');
            $pass=(string)($d['password']??''); validate_password($pass); if ($pass!==($d['confirm']??'')) fail('The passwords do not match.');
            query('UPDATE users SET password_hash=?,session_version=session_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?',[password_hash($pass,PASSWORD_DEFAULT),$u['id']]);
            query('DELETE FROM reset_tokens WHERE user_id=?',[$u['id']]); $_SESSION['session_version']++; session_regenerate_id(true); respond(['success'=>true]);
        case 'forgot':
            rate_limit('forgot',5); $d=input(); $email=strtolower(clean($d['email']??''));
            if (!filter_var($email,FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address.');
            $u=query("SELECT id FROM users WHERE email=? AND status='active'",[$email])->fetch();
            if ($u) {
                $token=create_reset((int)$u['id']);
                // Local installations use a protected outbox; production can configure mail delivery.
                $base=getenv('FRUITSCAN_PUBLIC_URL')?:'';
                $link=$base.'/#/reset?token='.$token;
                if (getenv('FRUITSCAN_MAIL_FROM') && $base) {
                    if (!mail($email,'Reset your FruitScan password',"Use this link within 30 minutes: $link",'From: '.getenv('FRUITSCAN_MAIL_FROM'))) throw new RuntimeException('Password reset mail failed.');
                } else file_put_contents(STORAGE.'/reset-outbox.log',json_encode(['email'=>$email,'link'=>$link,'created_at'=>gmdate('c')]).PHP_EOL,FILE_APPEND|LOCK_EX);
            }
            respond(['success'=>true,'message'=>getenv('FRUITSCAN_MAIL_FROM')?'If that account exists, a reset link has been sent.':'If that account exists, a reset request is ready. This local installation does not send email; ask the administrator for your reset link.']);
        case 'reset':
            rate_limit('reset',10); $d=input(); $token=clean($d['token']??''); $pass=(string)($d['password']??''); validate_password($pass);
            if ($pass!==($d['confirm']??'')) fail('The passwords do not match.');
            db()->beginTransaction();
            $row=query('SELECT * FROM reset_tokens WHERE token_hash=? AND expires_at>?',[hash('sha256',$token),time()])->fetch();
            if (!$row) { db()->rollBack(); fail('This reset link is invalid or expired. Request another link.'); }
            query('UPDATE users SET password_hash=?,session_version=session_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?',[password_hash($pass,PASSWORD_DEFAULT),$row['user_id']]); query('DELETE FROM reset_tokens WHERE user_id=?',[$row['user_id']]); db()->commit(); unset($_SESSION['user_id']); respond(['success'=>true]);
        case 'predict':
            rate_limit('predict',30,600); $u=current_user();
            if (setting('maintenance','0')==='1') fail('The detector is under maintenance. Please try again later.',503);
            if (!$u && setting('allow_guest','1')!=='1') fail('Please sign in to analyze an image.',401);
            $file=$_FILES['image']??null;
            if (!$file || $file['error']!==UPLOAD_ERR_OK) fail('Upload failed. Choose an image smaller than 10 MB.');
            if ($file['size']>10*1024*1024) fail('Image must be smaller than 10 MB.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']); $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
            if (!$ext) fail('Please upload a JPG, PNG, or WEBP image.');
            $size=@getimagesize($file['tmp_name']);
            if (!$size || $size[0]<64 || $size[1]<64 || $size[0]>12000 || $size[1]>12000 || $size[0]*$size[1]>40000000) fail('Use an image at least 64 × 64 pixels and no larger than 40 megapixels or 12,000 pixels per side.');
            $result=(new PredictionService())->analyzeImage($file['tmp_name'],$mime,clean($_POST['demo_outcome']??'fresh'));
            $result['id']=null; $result['created_at']=gmdate('Y-m-d H:i:s');
            if ($u) {
                $name=bin2hex(random_bytes(20)).'.'.$ext;
                if (!move_uploaded_file($file['tmp_name'],STORAGE.'/'.$name)) throw new RuntimeException('Could not save image.');
                try { query('INSERT INTO detections(user_id,image_path,fruit_class,prediction,confidence,probabilities,model_version,is_demo) VALUES(?,?,?,?,?,?,?,?)',[$u['id'],$name,$result['fruit_class'],$result['prediction'],$result['confidence'],json_encode($result['probabilities']),$result['model_version'],$result['is_demo']]); }
                catch (Throwable $e) { unlink(STORAGE.'/'.$name); throw $e; }
                $result['id']=(int)db()->lastInsertId();
            }
            respond(['success'=>true,'result'=>$result]);
        case 'history':
            $u=require_user(); $admin=$u['role']==='admin' && ($_GET['scope']??'')==='all'; $where=['1=1']; $p=[];
            if (!$admin) { $where[]='d.user_id=?'; $p[]=$u['id']; }
            if (!empty($_GET['search'])) { $where[]='(d.fruit_class LIKE ? OR u.name LIKE ? OR u.email LIKE ?)'; $q='%'.clean($_GET['search']).'%'; array_push($p,$q,$q,$q); }
            foreach (['prediction'=>'d.prediction','fruit'=>'d.fruit_class','date'=>'date(d.created_at)'] as $key=>$column) if (!empty($_GET[$key])) { $where[]="$column=?"; $p[]=clean($_GET[$key]); }
            if ($admin && !empty($_GET['user'])) { $where[]='d.user_id=?'; $p[]=(int)$_GET['user']; }
            $where=implode(' AND ',$where); $page=max(1,(int)($_GET['page']??1)); $order=($_GET['sort']??'newest')==='oldest'?'ASC':'DESC';
            $total=(int)query("SELECT COUNT(*) FROM detections d JOIN users u ON u.id=d.user_id WHERE $where",$p)->fetchColumn(); $page=min($page,max(1,(int)ceil($total/8))); $offset=($page-1)*8;
            $rows=query("SELECT d.*,u.name AS user_name FROM detections d JOIN users u ON u.id=d.user_id WHERE $where ORDER BY d.created_at $order,d.id $order LIMIT 8 OFFSET $offset",$p)->fetchAll();
            foreach ($rows as &$r) { unset($r['image_path']); }
            $fruits=query('SELECT DISTINCT fruit_class FROM detections'.($admin?'':' WHERE user_id=?'),$admin?[]:[$u['id']])->fetchAll(PDO::FETCH_COLUMN);
            respond(['success'=>true,'records'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/8)),'fruits'=>$fruits]);
        case 'detail': case 'image': case 'delete-detection':
            $u=require_user(); $d=$method==='POST'?input():$_GET; $row=query('SELECT * FROM detections WHERE id=?',[(int)($d['id']??0)])->fetch();
            if (!$row || ($row['user_id']!==$u['id'] && $u['role']!=='admin')) fail('Detection not found.',404);
            if ($action==='delete-detection') { delete_detection($row); respond(['success'=>true]); }
            if ($action==='image') { $path=STORAGE.'/'.basename($row['image_path']); if (!is_file($path)) fail('Image not found.',404); header('Content-Type: '.(new finfo(FILEINFO_MIME_TYPE))->file($path)); header('Content-Disposition: inline'); readfile($path); exit; }
            unset($row['image_path']); $row['probabilities']=json_decode($row['probabilities']??'null',true); respond(['success'=>true,'record'=>$row]);
        case 'stats':
            $u=require_user(); $admin=$u['role']==='admin'&&($_GET['scope']??'')==='all'; $w=['1=1']; $p=[];
            if (!$admin) { $w[]='user_id=?'; $p[]=$u['id']; }
            foreach (['from'=>'>=','to'=>'<='] as $k=>$op) if (!empty($_GET[$k])) { $w[]="date(created_at) $op ?"; $p[]=clean($_GET[$k]); }
            $where=implode(' AND ',$w);
            $stats=query("SELECT COUNT(*) AS total,COALESCE(SUM(prediction='fresh'),0) AS fresh,COALESCE(SUM(prediction='rotten'),0) AS rotten,COALESCE(SUM(is_demo),0) AS demo FROM detections WHERE $where",$p)->fetch();
            $stats = array_map('intval', $stats);
            $stats['users']=$admin?(int)query('SELECT COUNT(*) FROM users')->fetchColumn():null;
            $daily=query("SELECT date(created_at) AS label,COUNT(*) AS value FROM detections WHERE $where GROUP BY date(created_at) ORDER BY label",$p)->fetchAll();
            $fruits=query("SELECT fruit_class AS label,COUNT(*) AS value FROM detections WHERE $where GROUP BY fruit_class ORDER BY value DESC LIMIT 10",$p)->fetchAll();
            $activity=$admin?query("SELECT u.name AS label,COUNT(*) AS value FROM detections d JOIN users u ON u.id=d.user_id WHERE ".str_replace(['user_id','created_at'],['d.user_id','d.created_at'],$where).' GROUP BY u.id ORDER BY value DESC LIMIT 10',$p)->fetchAll():[];
            respond(['success'=>true,'stats'=>$stats,'daily'=>$daily,'fruits'=>$fruits,'activity'=>$activity]);
        case 'users':
            require_user(true); $q='%'.clean($_GET['search']??'').'%';
            respond(['success'=>true,'users'=>query('SELECT u.id,u.name,u.email,u.role,u.status,u.created_at,(SELECT COUNT(*) FROM detections WHERE user_id=u.id) AS scans FROM users u WHERE name LIKE ? OR email LIKE ? ORDER BY u.id DESC',[$q,$q])->fetchAll()]);
        case 'user-update':
            $u=require_user(true); $d=input(); $id=(int)($d['id']??0); if ($id===$u['id']) fail('You cannot change your own administrator account here.');
            $target=query('SELECT * FROM users WHERE id=?',[$id])->fetch(); if (!$target || $target['role']==='admin') fail('This account cannot be modified here.');
            if (($d['operation']??'')==='delete') { foreach(query('SELECT * FROM detections WHERE user_id=?',[$id])->fetchAll() as $r) delete_detection($r); query('DELETE FROM users WHERE id=?',[$id]); }
            elseif (($d['operation']??'')==='reset') { respond(['success'=>true,'token'=>create_reset($id)]); }
            else { $status=($d['status']??'')==='active'?'active':'inactive'; query('UPDATE users SET status=?,session_version=session_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$status,$id]); }
            respond(['success'=>true]);
        case 'classes':
            require_user(true); respond(['success'=>true,'classes'=>query('SELECT * FROM fruit_classes ORDER BY name')->fetchAll(),'model_classes'=>model_config()['fruit_classes']??[]]);
        case 'class-save':
            require_user(true); $d=input(); $id=(int)($d['id']??0); $name=clean($d['name']??'',80); $desc=clean($d['description']??'',500); $img=clean($d['image_path']??'',1000);
            if (!$name || !$desc) fail('Enter a fruit name and description.');
            if (!is_demo() && !in_array($name,model_config()['fruit_classes']??[],true)) fail('This fruit is not in the connected model manifest. Update the model manifest before adding its display details.');
            if ($img && (!filter_var($img,FILTER_VALIDATE_URL) || parse_url($img,PHP_URL_SCHEME)!=='https')) fail('Use an HTTPS image URL or leave it empty.');
            $status=($d['status']??'active')==='active'?'active':'inactive';
            if (query('SELECT id FROM fruit_classes WHERE name=? AND id<>?',[$name,$id])->fetch()) fail('A class with this name already exists.');
            if ($id) query('UPDATE fruit_classes SET name=?,description=?,image_path=?,status=? WHERE id=?',[$name,$desc,$img,$status,$id]); else query('INSERT INTO fruit_classes(name,description,image_path,status) VALUES(?,?,?,?)',[$name,$desc,$img,$status]); respond(['success'=>true]);
        case 'class-delete':
            require_user(true); $d=input(); query('DELETE FROM fruit_classes WHERE id=?',[(int)($d['id']??0)]); respond(['success'=>true]);
        case 'settings':
            require_user(true); respond(['success'=>true,'settings'=>['allow_guest'=>setting('allow_guest','1'),'maintenance'=>setting('maintenance','0')],'demo'=>is_demo(),'model'=>model_config()]);
        case 'settings-save':
            require_user(true); $d=input();
            $upsert=database_is_mysql()?'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)':'ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value';
            foreach(['allow_guest','maintenance'] as $k) query('INSERT INTO system_settings(setting_key,setting_value) VALUES(?,?) '.$upsert,[$k,($d[$k]??'0')==='1'?'1':'0']); respond(['success'=>true]);
        case 'feedback':
            rate_limit('feedback',5,3600); $d=input(); $name=clean($d['name']??''); $email=clean($d['email']??''); $message=clean($d['message']??'',3000);
            if (!$name || !filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($message)<10) fail('Enter your name, a valid email, and a message of at least 10 characters.');
            query('INSERT INTO feedback(name,email,message) VALUES(?,?,?)',[$name,$email,$message]); respond(['success'=>true]);
        case 'feedback-list':
            require_user(true); respond(['success'=>true,'feedback'=>query('SELECT * FROM feedback ORDER BY id DESC LIMIT 100')->fetchAll()]);
        default: fail('Endpoint not found.',404);
    }
} catch (Throwable $e) {
    try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable $ignored) { }
    error_log('FruitScan: '.$e->getMessage());
    fail($action==='predict'?'FruitScan is temporarily unable to analyze this image. Please try again.':'Something went wrong. Please try again.',503);
}
