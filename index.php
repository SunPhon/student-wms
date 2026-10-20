<?php
declare(strict_types=1);
session_start();
require_once __DIR__.'/config.php';
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url='index.php'): never { header('Location: '.$url); exit; }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function require_login(): void { if (empty($_SESSION['user'])) redirect('index.php?login=1'); }
function flash(string $msg, string $kind='ok'): void { $_SESSION['flash']=['msg'=>$msg,'kind'=>$kind]; }
function refno(string $prefix): string { return $prefix.date('ymdHis').random_int(10,99); }

try { $pdo=db(); } catch(Throwable $e) {
  http_response_code(500);
  echo '<meta charset="utf-8"><h2>เชื่อมต่อฐานข้อมูลไม่ได้</h2><p>ตรวจสอบค่าใน config.php และติดตั้งฐานข้อมูลผ่าน setup.php</p><pre>'.h($e->getMessage()).'</pre>'; exit;
}

if (isset($_GET['logout'])) { session_destroy(); redirect(); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { flash('คำขอไม่ถูกต้อง กรุณาลองใหม่','err'); redirect(); }
  $action=(string)($_POST['action'] ?? '');
  if ($action==='login') {
    $stmt=$pdo->prepare('SELECT * FROM users WHERE username=?'); $stmt->execute([trim((string)$_POST['username'])]); $u=$stmt->fetch();
    if ($u && password_verify((string)$_POST['password'],$u['password_hash'])) {
      session_regenerate_id(true); $_SESSION['user']=['id'=>$u['id'],'name'=>$u['name'],'role'=>$u['role']]; redirect();
    }
    flash('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง','err'); redirect('index.php?login=1');
  }
  require_login();
  try {
    if ($action==='product_add') {
      $stmt=$pdo->prepare('INSERT INTO products(sku,name,category,unit,min_stock) VALUES(?,?,?,?,?)');
      $stmt->execute([trim($_POST['sku']),trim($_POST['name']),trim($_POST['category']),trim($_POST['unit']),max(0,(int)$_POST['min_stock'])]);
      flash('เพิ่มสินค้าแล้ว');
    } elseif ($action==='location_add') {
      $stmt=$pdo->prepare('INSERT INTO locations(code,zone,max_capacity) VALUES(?,?,?)');
      $stmt->execute([trim($_POST['code']),trim($_POST['zone']),max(1,(int)$_POST['max_capacity'])]); flash('เพิ่มตำแหน่งจัดเก็บแล้ว');
    } elseif ($action==='receive') {
      $productId=(int)$_POST['product_id']; $qty=(int)$_POST['qty']; $lotNo=trim($_POST['lot_no']);
      if ($qty<=0 || $lotNo==='') throw new RuntimeException('จำนวนต้องมากกว่า 0 และต้องระบุ Lot');
      $pdo->beginTransaction();
      $locId=(int)($_POST['location_id'] ?? 0);
      if ($locId===0) {
        // Suggest active location with the most remaining capacity.
        $q=$pdo->prepare("SELECT l.id, l.max_capacity-COALESCE(SUM(t.qty),0) AS free_qty FROM locations l LEFT JOIN lots t ON t.location_id=l.id WHERE l.active=1 GROUP BY l.id,l.max_capacity HAVING free_qty>=? ORDER BY free_qty DESC,l.code LIMIT 1");
        $q->execute([$qty]); $best=$q->fetch();
        if (!$best) throw new RuntimeException('ไม่มีตำแหน่งจัดเก็บที่มีพื้นที่เพียงพอ กรุณาเพิ่ม Location หรือเลือกตำแหน่งเอง');
        $locId=(int)$best['id'];
      }
      $check=$pdo->prepare('SELECT id FROM locations WHERE id=? AND active=1'); $check->execute([$locId]); if (!$check->fetch()) throw new RuntimeException('ตำแหน่งจัดเก็บไม่ถูกต้อง');
      $stmt=$pdo->prepare('SELECT id FROM lots WHERE product_id=? AND lot_no=? AND location_id=?'); $stmt->execute([$productId,$lotNo,$locId]); $existing=$stmt->fetch();
      if ($existing) {
        $up=$pdo->prepare('UPDATE lots SET qty=qty+?, received_at=LEAST(received_at,?), expiry_date=COALESCE(?,expiry_date) WHERE id=?');
        $up->execute([$qty,$_POST['received_at'] ?: date('Y-m-d'),$_POST['expiry_date'] ?: null,$existing['id']]); $lotId=(int)$existing['id'];
      } else {
        $ins=$pdo->prepare('INSERT INTO lots(product_id,lot_no,location_id,qty,received_at,expiry_date) VALUES(?,?,?,?,?,?)');
        $ins->execute([$productId,$lotNo,$locId,$qty,$_POST['received_at'] ?: date('Y-m-d'),$_POST['expiry_date'] ?: null]); $lotId=(int)$pdo->lastInsertId();
      }
      $m=$pdo->prepare("INSERT INTO movements(ref_no,movement_type,product_id,lot_id,to_location_id,qty,note,user_id) VALUES(?,?,?,?,?,?,?,?)");
      $m->execute([refno('RCV'), 'RECEIVE',$productId,$lotId,$locId,$qty,trim($_POST['note'] ?? ''),$_SESSION['user']['id']]);
      $pdo->commit(); flash('รับสินค้าเข้าคลังสำเร็จ');
    } elseif ($action==='issue') {
      $productId=(int)$_POST['product_id']; $qty=(int)$_POST['qty']; $strategy=$_POST['strategy']==='FEFO'?'FEFO':'FIFO';
      if ($qty<=0) throw new RuntimeException('จำนวนเบิกต้องมากกว่า 0');
      $pdo->beginTransaction();
      $order=$strategy==='FEFO' ? 'CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END, expiry_date ASC, received_at ASC, id ASC' : 'received_at ASC, id ASC';
      $q=$pdo->prepare("SELECT id,location_id,qty,lot_no FROM lots WHERE product_id=? AND qty>0 ORDER BY $order FOR UPDATE"); $q->execute([$productId]); $lots=$q->fetchAll();
      $available=array_sum(array_map(fn($x)=>(int)$x['qty'],$lots));
      if ($available<$qty) throw new RuntimeException("สต็อกไม่พอ คงเหลือ $available หน่วย");
      $remaining=$qty; $ref=refno('ISS');
      foreach ($lots as $lot) {
        if ($remaining<=0) break;
        $take=min($remaining,(int)$lot['qty']);
        $up=$pdo->prepare('UPDATE lots SET qty=qty-? WHERE id=?'); $up->execute([$take,$lot['id']]);
        $m=$pdo->prepare("INSERT INTO movements(ref_no,movement_type,product_id,lot_id,from_location_id,qty,note,user_id) VALUES(?,'ISSUE',?,?,?,?,?,?)");
        $m->execute([$ref,$productId,$lot['id'],$lot['location_id'],$take,'จ่ายตาม '.$strategy.' / Lot '.$lot['lot_no'],$_SESSION['user']['id']]);
        $remaining-=$take;
      }
      $pdo->commit(); flash("เบิกสินค้า $qty หน่วยสำเร็จด้วยหลัก $strategy (เลขที่ $ref)");
    } elseif ($action==='adjust') {
      $lotId=(int)$_POST['lot_id']; $newQty=(int)$_POST['new_qty']; if ($newQty<0) throw new RuntimeException('จำนวนคงเหลือต้องไม่ติดลบ');
      $pdo->beginTransaction(); $q=$pdo->prepare('SELECT * FROM lots WHERE id=? FOR UPDATE'); $q->execute([$lotId]); $lot=$q->fetch(); if (!$lot) throw new RuntimeException('ไม่พบ Lot');
      $diff=$newQty-(int)$lot['qty']; $up=$pdo->prepare('UPDATE lots SET qty=? WHERE id=?'); $up->execute([$newQty,$lotId]);
      if ($diff!==0) { $m=$pdo->prepare("INSERT INTO movements(ref_no,movement_type,product_id,lot_id,from_location_id,to_location_id,qty,note,user_id) VALUES(?,'ADJUST',?,?,?,?,?,?,?)"); $m->execute([refno('ADJ'),$lot['product_id'],$lotId,$lot['location_id'],$lot['location_id'],abs($diff),'ปรับยอดตรวจนับจาก '.(int)$lot['qty'].' เป็น '.$newQty,$_SESSION['user']['id']]); }
      $pdo->commit(); flash('ปรับยอดสต็อกแล้ว');
    }
  } catch(Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash($e instanceof PDOException && str_contains($e->getMessage(),'Duplicate') ? 'รหัสสินค้าหรือตำแหน่งซ้ำ กรุณาตรวจสอบ' : $e->getMessage(),'err');
  }
  redirect();
}

$logged=!empty($_SESSION['user']);
if (!$logged || isset($_GET['login'])) {
  $f=$_SESSION['flash']??null; unset($_SESSION['flash']);
  ?><!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Student WMS · Login</title><link rel="stylesheet" href="style.css"></head><body class="login-bg"><main class="login-card"><div class="brand-mark">W</div><p class="eyebrow">STUDENT PROJECT</p><h1>Warehouse Management</h1><p class="muted">ระบบจัดการคลังสินค้า · Web + Mobile</p><?php if($f): ?><div class="alert <?=h($f['kind'])?>"><?=h($f['msg'])?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="login"><label>ชื่อผู้ใช้</label><input name="username" required autocomplete="username" placeholder="admin"><label>รหัสผ่าน</label><input type="password" name="password" required autocomplete="current-password" placeholder="รหัสผ่าน"><button class="primary wide">เข้าสู่ระบบ</button></form><p class="hint">บัญชีทดลองหลังติดตั้ง: admin / WmsDemo123!</p></main></body></html><?php exit;
}

$page=(string)($_GET['page']??'dashboard');
$allowed=['dashboard','products','locations','receive','issue','lots','movements']; if(!in_array($page,$allowed,true)) $page='dashboard';
$products=$pdo->query('SELECT * FROM products ORDER BY sku')->fetchAll();
$locations=$pdo->query('SELECT * FROM locations WHERE active=1 ORDER BY code')->fetchAll();
$lots=$pdo->query('SELECT t.*,p.sku,p.name AS product_name,p.unit,l.code AS location_code FROM lots t JOIN products p ON p.id=t.product_id JOIN locations l ON l.id=t.location_id ORDER BY t.received_at,t.id')->fetchAll();
$totalQty=(int)$pdo->query('SELECT COALESCE(SUM(qty),0) FROM lots')->fetchColumn();
$skuCount=count($products); $lowCount=(int)$pdo->query('SELECT COUNT(*) FROM (SELECT p.id,p.min_stock,COALESCE(SUM(t.qty),0) q FROM products p LEFT JOIN lots t ON t.product_id=p.id GROUP BY p.id,p.min_stock HAVING q<=p.min_stock) x')->fetchColumn();
$moveCount=(int)$pdo->query('SELECT COUNT(*) FROM movements')->fetchColumn();
$movements=$pdo->query('SELECT m.*,p.sku,p.name AS product_name,u.name AS user_name,fl.code AS from_code,tl.code AS to_code FROM movements m JOIN products p ON p.id=m.product_id LEFT JOIN users u ON u.id=m.user_id LEFT JOIN locations fl ON fl.id=m.from_location_id LEFT JOIN locations tl ON tl.id=m.to_location_id ORDER BY m.id DESC LIMIT 100')->fetchAll();
$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
?><!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Student WMS</title><link rel="stylesheet" href="style.css"></head><body><div class="app"><aside class="sidebar"><a class="brand" href="index.php"><span class="brand-mark small">W</span><span><b>WMS</b><small>STUDENT PROJECT</small></span></a><div class="nav-label">WORKSPACE</div><a class="nav <?= $page==='dashboard'?'active':''?>" href="?page=dashboard">▦ <span>ภาพรวมคลัง</span></a><a class="nav <?= $page==='products'?'active':''?>" href="?page=products">▤ <span>สินค้า</span></a><a class="nav <?= $page==='locations'?'active':''?>" href="?page=locations">⌗ <span>ตำแหน่งจัดเก็บ</span></a><div class="nav-label">OPERATIONS</div><a class="nav <?= $page==='receive'?'active':''?>" href="?page=receive">↓ <span>รับสินค้าเข้า</span></a><a class="nav <?= $page==='issue'?'active':''?>" href="?page=issue">↑ <span>เบิกสินค้า</span></a><a class="nav <?= $page==='lots'?'active':''?>" href="?page=lots">▦ <span>Lot & สต็อก</span></a><a class="nav <?= $page==='movements'?'active':''?>" href="?page=movements">↔ <span>ประวัติการเคลื่อนไหว</span></a><div class="sidebar-bottom"><div class="avatar"><?=h(mb_substr($_SESSION['user']['name'],0,1))?></div><div class="user-info"><b><?=h($_SESSION['user']['name'])?></b><small><?=h($_SESSION['user']['role'])?></small></div><a class="logout" href="?logout=1" title="ออกจากระบบ">↗</a></div></aside><main class="main"><header class="topbar"><div><div class="eyebrow">WAREHOUSE OPERATIONS</div><h1><?=['dashboard'=>'ภาพรวมคลังสินค้า','products'=>'จัดการสินค้า','locations'=>'ตำแหน่งจัดเก็บ','receive'=>'รับสินค้าเข้าคลัง','issue'=>'เบิกสินค้าออก','lots'=>'Lot และยอดคงเหลือ','movements'=>'ประวัติการเคลื่อนไหว'][$page]?></h1></div><div class="top-right"><span class="live-dot"></span><span>ระบบพร้อมใช้งาน</span><span class="date"><?=date('d/m/Y')?></span></div></header>
<?php if($flash): ?><div class="alert <?=h($flash['kind'])?>"><?=h($flash['msg'])?></div><?php endif; ?>
<?php if($page==='dashboard'): ?>
<section class="welcome"><div><p class="eyebrow light">WAREHOUSE CONTROL CENTER</p><h2>เห็นสต็อกชัด<br>จัดการได้เป็นระบบ</h2><p>ติดตามสินค้า Lot และการเคลื่อนไหวจากจุดเดียว</p></div><div class="warehouse-art"><div class="rack"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div></div></section>
<section class="stats"><article class="stat"><span class="stat-icon blue">▤</span><div><span>รายการสินค้า</span><strong><?=$skuCount?></strong><small>SKU ในระบบ</small></div></article><article class="stat"><span class="stat-icon green">▦</span><div><span>จำนวนคงเหลือรวม</span><strong><?=number_format($totalQty)?></strong><small>หน่วยสินค้า</small></div></article><article class="stat"><span class="stat-icon orange">!</span><div><span>สินค้าใกล้หมด/ถึงขั้นต่ำ</span><strong><?=$lowCount?></strong><small>รายการที่ควรตรวจสอบ</small></div></article><article class="stat"><span class="stat-icon purple">↔</span><div><span>ธุรกรรมทั้งหมด</span><strong><?=number_format($moveCount)?></strong><small>ประวัติที่บันทึกไว้</small></div></article></section>
<section class="quick-grid"><a class="quick" href="?page=receive"><span class="quick-icon green">↓</span><div><b>รับสินค้าเข้า</b><small>บันทึก Lot และวันหมดอายุ</small></div><span class="arrow">↗</span></a><a class="quick" href="?page=issue"><span class="quick-icon blue">↑</span><div><b>เบิกสินค้าออก</b><small>เลือก FIFO หรือ FEFO</small></div><span class="arrow">↗</span></a><a class="quick" href="?page=lots"><span class="quick-icon purple">▦</span><div><b>ตรวจสอบ Lot</b><small>ดูตำแหน่งและยอดคงเหลือ</small></div><span class="arrow">↗</span></a></section>
<section class="panel"><div class="panel-head"><div><h3>การเคลื่อนไหวล่าสุด</h3><p>รายการล่าสุดที่เกิดขึ้นในคลัง</p></div><a class="text-link" href="?page=movements">ดูทั้งหมด ↗</a></div><?php render_movements($movements,10); ?></section>
<?php elseif($page==='products'): ?>
<section class="panel"><div class="panel-head"><div><h3>เพิ่มสินค้าใหม่</h3><p>กำหนดรหัสสินค้า หน่วยนับ และระดับสต็อกขั้นต่ำ</p></div></div><form class="form-grid" method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="product_add"><div><label>รหัสสินค้า (SKU)</label><input name="sku" required placeholder="เช่น SKU-1004"></div><div><label>ชื่อสินค้า</label><input name="name" required placeholder="ชื่อสินค้า"></div><div><label>หมวดหมู่</label><input name="category" placeholder="เช่น อุปกรณ์คลัง" value="ทั่วไป"></div><div><label>หน่วยนับ</label><input name="unit" required value="ชิ้น"></div><div><label>สต็อกขั้นต่ำ</label><input name="min_stock" type="number" min="0" value="0" required></div><div class="form-action"><button class="primary">+ บันทึกสินค้า</button></div></form></section>
<section class="panel"><div class="panel-head"><div><h3>รายการสินค้า</h3><p>ทะเบียนสินค้าทั้งหมด</p></div><input class="search" placeholder="ค้นหา SKU / ชื่อสินค้า..." data-filter="products-table"></div><div class="table-wrap"><table id="products-table"><thead><tr><th>รหัสสินค้า</th><th>ชื่อสินค้า</th><th>หมวดหมู่</th><th>หน่วย</th><th>ขั้นต่ำ</th><th>คงเหลือ</th></tr></thead><tbody><?php foreach($products as $p): $s=$pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM lots WHERE product_id=?');$s->execute([$p['id']]);$q=(int)$s->fetchColumn(); ?><tr><td><b><?=h($p['sku'])?></b></td><td><?=h($p['name'])?></td><td><?=h($p['category'])?></td><td><?=h($p['unit'])?></td><td><?=$p['min_stock']?></td><td><span class="pill <?=$q<=$p['min_stock']?'warning':'success'?>"><?=number_format($q)?></span></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php elseif($page==='locations'): ?>
<section class="panel"><div class="panel-head"><div><h3>เพิ่มตำแหน่งจัดเก็บ</h3><p>ระบบใช้ตำแหน่งที่มีพื้นที่ว่างมากพอในการแนะนำอัตโนมัติ</p></div></div><form class="form-grid" method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="location_add"><div><label>รหัส Location</label><input name="code" required placeholder="เช่น A-02-01"></div><div><label>โซน</label><input name="zone" required value="A"></div><div><label>ความจุ (หน่วย)</label><input name="max_capacity" type="number" min="1" value="500" required></div><div class="form-action"><button class="primary">+ เพิ่มตำแหน่ง</button></div></form></section>
<section class="panel"><div class="panel-head"><div><h3>ผังตำแหน่งจัดเก็บ</h3><p>ความจุคำนวณจากยอดรวมทุก Lot ใน Location</p></div></div><div class="location-grid"><?php foreach($locations as $l): $q=$pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM lots WHERE location_id=?');$q->execute([$l['id']]);$used=(int)$q->fetchColumn();$pct=min(100,round($used/max(1,(int)$l['max_capacity'])*100)); ?><article class="location-card"><div class="loc-top"><span class="loc-symbol">⌗</span><span class="pill <?=$pct>85?'warning':'success'?>"><?=$pct?>% ใช้พื้นที่</span></div><h3><?=h($l['code'])?></h3><p>Zone <?=h($l['zone'])?></p><div class="progress"><i style="width:<?=$pct?>%"></i></div><div class="loc-bottom"><span><?=number_format($used)?> ใช้แล้ว</span><b><?=number_format($l['max_capacity'])?> หน่วย</b></div></article><?php endforeach; ?></div></section>
<?php elseif($page==='receive'): ?>
<section class="operation-layout"><div class="panel"><div class="panel-head"><div><h3>แบบฟอร์มรับสินค้า</h3><p>สร้างหรือเพิ่มจำนวนให้ Lot ที่รับเข้า</p></div><span class="pill success">INBOUND</span></div><form method="post" class="stack-form"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="receive"><label>สินค้า</label><select name="product_id" required><?php foreach($products as $p): ?><option value="<?=$p['id']?>"><?=h($p['sku'].' — '.$p['name'])?></option><?php endforeach; ?></select><label>เลขที่ Lot / Batch</label><input name="lot_no" required placeholder="เช่น LOT-2026-001"><div class="two"><div><label>จำนวนรับเข้า</label><input name="qty" type="number" min="1" required placeholder="0"></div><div><label>วันที่รับเข้า</label><input name="received_at" type="date" value="<?=date('Y-m-d')?>" required></div></div><label>วันหมดอายุ (ถ้ามี)</label><input name="expiry_date" type="date"><label>ตำแหน่งจัดเก็บ</label><select name="location_id"><option value="0">ให้ระบบแนะนำอัตโนมัติ</option><?php foreach($locations as $l): ?><option value="<?=$l['id']?>"><?=h($l['code'].' · Zone '.$l['zone'])?></option><?php endforeach; ?></select><label>หมายเหตุ</label><input name="note" placeholder="เช่น รับจากผู้ขาย / เลขที่เอกสาร"><button class="primary wide">บันทึกรับสินค้า</button></form></div><aside class="info-panel"><span class="info-icon">⌗</span><h3>Smart Put-away</h3><p>หากเลือกให้ระบบแนะนำ ระบบจะเลือก Location ที่เปิดใช้งานและมีพื้นที่ว่างเพียงพอ โดยจัดอันดับจากพื้นที่ว่างมากไปน้อย</p><div class="info-rule"><b>ก่อนบันทึก</b><span>ตรวจสอบ SKU, Lot, จำนวน และวันหมดอายุให้ถูกต้อง</span></div><div class="info-rule"><b>ผลลัพธ์</b><span>อัปเดตยอด Lot และบันทึก Movement อัตโนมัติ</span></div></aside></section>
<?php elseif($page==='issue'): ?>
<section class="operation-layout"><div class="panel"><div class="panel-head"><div><h3>แบบฟอร์มเบิกสินค้า</h3><p>ระบบตัดสต็อกจาก Lot ที่เหมาะสมอัตโนมัติ</p></div><span class="pill warning">OUTBOUND</span></div><form method="post" class="stack-form"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="issue"><label>สินค้า</label><select name="product_id" required><?php foreach($products as $p): ?><option value="<?=$p['id']?>"><?=h($p['sku'].' — '.$p['name'])?></option><?php endforeach; ?></select><label>จำนวนที่ต้องการเบิก</label><input name="qty" type="number" min="1" required placeholder="จำนวน"><label>หลักการตัดสต็อก</label><select name="strategy"><option value="FIFO">FIFO — รับเข้าก่อน จ่ายออกก่อน</option><option value="FEFO">FEFO — หมดอายุก่อน จ่ายออกก่อน</option></select><div class="info-rule"><b>FIFO</b><span>เรียงตามวันที่รับเข้าเก่าสุดก่อน</span></div><div class="info-rule"><b>FEFO</b><span>เรียงตามวันหมดอายุใกล้สุดก่อน โดย Lot ที่ไม่มีวันหมดอายุอยู่ท้ายรายการ</span></div><button class="primary wide">ตรวจสอบและเบิกสินค้า</button></form></div><aside class="info-panel"><span class="info-icon">↗</span><h3>Stock allocation</h3><p>การเบิกหนึ่งครั้งสามารถตัดหลาย Lot ต่อเนื่องกันได้ ระบบจะตรวจยอดคงเหลือก่อนเริ่มทำรายการ</p><div class="info-rule"><b>ป้องกันสต็อกติดลบ</b><span>หากจำนวนคงเหลือไม่เพียงพอ ระบบยกเลิกรายการทั้งหมด</span></div><div class="info-rule"><b>Audit trail</b><span>บันทึกเลขอ้างอิง Lot ตำแหน่ง จำนวน และผู้ทำรายการ</span></div></aside></section>
<?php elseif($page==='lots'): ?>
<section class="panel"><div class="panel-head"><div><h3>ยอดคงเหลือแยกตาม Lot</h3><p>ค้นหา Lot วันหมดอายุ และตำแหน่งจัดเก็บ</p></div><input class="search" placeholder="ค้นหาสินค้า / Lot / Location..." data-filter="lots-table"></div><div class="table-wrap"><table id="lots-table"><thead><tr><th>สินค้า</th><th>Lot</th><th>Location</th><th>รับเข้า</th><th>หมดอายุ</th><th>คงเหลือ</th><th>ปรับยอด</th></tr></thead><tbody><?php foreach($lots as $l): ?><tr><td><b><?=h($l['sku'])?></b><small class="cell-sub"><?=h($l['product_name'])?></small></td><td><?=h($l['lot_no'])?></td><td><span class="pill"><?=h($l['location_code'])?></span></td><td><?=h($l['received_at'])?></td><td><?=h($l['expiry_date']?:'—')?></td><td><b><?=number_format($l['qty'])?></b> <?=h($l['unit'])?></td><td><form class="inline-form" method="post" onsubmit="return confirm('ยืนยันปรับยอด Lot นี้?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="adjust"><input type="hidden" name="lot_id" value="<?=$l['id']?>"><input type="number" min="0" name="new_qty" value="<?=$l['qty']?>" aria-label="ยอดใหม่"><button class="small-btn">บันทึก</button></form></td></tr><?php endforeach; ?></tbody></table></div><p class="hint">การปรับยอดควรใช้หลังตรวจนับจริงเท่านั้น ระบบจะบันทึกผลต่างไว้ในประวัติ</p></section>
<?php elseif($page==='movements'): ?>
<section class="panel"><div class="panel-head"><div><h3>Movement history</h3><p>ประวัติรับเข้า เบิกออก และปรับยอด พร้อมเลขอ้างอิง</p></div><input class="search" placeholder="ค้นหาเลขอ้างอิง / SKU / ประเภท..." data-filter="movement-table"></div><?php render_movements($movements,100,'movement-table'); ?></section>
<?php endif; ?>
<footer>Student WMS · โครงการระบบบริหารคลังสินค้าเพื่อการศึกษา <span>PHP · MySQL · Responsive UI</span></footer></main></div><script src="app.js"></script></body></html>
<?php
function render_movements(array $rows,int $limit=10,string $tableId=''): void {
  $subset=array_slice($rows,0,$limit);
  echo '<div class="table-wrap"><table'.($tableId?' id="'.h($tableId).'"':'').'><thead><tr><th>เลขอ้างอิง</th><th>ประเภท</th><th>สินค้า</th><th>Lot</th><th>จาก → ไป</th><th>จำนวน</th><th>ผู้ทำรายการ</th><th>เวลา</th></tr></thead><tbody>';
  if (!$subset) echo '<tr><td colspan="8" class="empty">ยังไม่มีประวัติการเคลื่อนไหว</td></tr>';
  foreach($subset as $m) {
    $type=$m['movement_type']; $cls=$type==='RECEIVE'?'success':($type==='ISSUE'?'warning':'neutral'); $label=$type==='RECEIVE'?'รับเข้า':($type==='ISSUE'?'เบิกออก':'ปรับยอด');
    echo '<tr><td><b>'.h($m['ref_no']).'</b></td><td><span class="pill '.$cls.'">'.$label.'</span></td><td>'.h($m['sku']).'<small class="cell-sub">'.h($m['product_name']).'</small></td><td>'.h($m['lot_id']??'—').'</td><td>'.h($m['from_code']??'—').' → '.h($m['to_code']??'—').'</td><td><b>'.number_format((int)$m['qty']).'</b></td><td>'.h($m['user_name']??'ระบบ').'</td><td>'.h($m['created_at']).'</td></tr>';
  }
  echo '</tbody></table></div>';
}
