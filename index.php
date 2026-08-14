<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

/*
 * Purchase Management Dashboard
 * Localhost: JSON files under /data
 * Vercel: Vercel Blob object storage.
 *
 * Vercel Blob now supports OIDC. When the project is connected to a Blob
 * store, Vercel provides VERCEL_OIDC_TOKEN + BLOB_STORE_ID automatically.
 * The legacy BLOB_READ_WRITE_TOKEN remains supported as a fallback.
 */
$store = __DIR__ . '/data';
$blobToken = trim((string)(getenv('BLOB_READ_WRITE_TOKEN') ?: ''));
$oidcToken = trim((string)(getenv('VERCEL_OIDC_TOKEN') ?: ''));
$configuredStoreId = trim((string)(getenv('BLOB_STORE_ID') ?: ''));
$isVercel = (string)(getenv('VERCEL') ?: '') === '1';

if (!$isVercel && !is_dir($store)) {
    mkdir($store, 0775, true);
}

function respond(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function readBody(): array {
    if ($_POST) return $_POST;
    $raw = trim((string)file_get_contents('php://input'));
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        respond(['ok'=>false,'message'=>'Request body must contain valid JSON.'], 400);
    }
    return $data;
}

function fileFor(string $entity): string {
    global $store;
    return "$store/$entity.json";
}

function blobStoreId(): string {
    global $configuredStoreId, $blobToken;
    if ($configuredStoreId !== '') {
        return str_starts_with($configuredStoreId, 'store_')
            ? substr($configuredStoreId, 6)
            : $configuredStoreId;
    }
    if ($blobToken !== '') {
        $parts = explode('_', $blobToken);
        return $parts[3] ?? '';
    }
    return '';
}

function blobAuthHeaders(): array {
    global $oidcToken, $blobToken;
    $token = $oidcToken !== '' ? $oidcToken : $blobToken;
    return $token !== '' ? ['Authorization: Bearer '.$token] : [];
}

function blobRequest(string $url, string $method, ?string $body = null, array $headers = []): array {
    $allHeaders = array_merge(blobAuthHeaders(), $headers);
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $allHeaders)."\r\n",
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 15,
        ]
    ]);
    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $match);
    return [
        'status' => (int)($match[1] ?? 0),
        'body' => $response === false ? '' : $response,
    ];
}

function blobObjectUrl(string $entity): string {
    $storeId = blobStoreId();
    if ($storeId === '') return '';
    /*
     * The current Blob store shown in the Vercel dashboard is Public.
     * Public blobs use the .public.blob.vercel-storage.com host.
     */
    return 'https://'.$storeId.'.public.blob.vercel-storage.com/purchase-dashboard/'.$entity.'.json';
}

function blobApiUrl(string $entity): string {
    return 'https://vercel.com/api/blob/?pathname='.rawurlencode('purchase-dashboard/'.$entity.'.json');
}

function readLocalSeed(string $entity): array {
    $file = fileFor($entity);
    if (!is_file($file)) return [];
    $raw = (string)@file_get_contents($file);
    if (trim($raw) === '') return [];
    $value = json_decode($raw, true);
    return is_array($value) ? $value : [];
}

function all(string $entity): array {
    global $blobToken, $oidcToken, $isVercel;

    if ($isVercel) {
        if ($blobStoreId() === '' || ($oidcToken === '' && $blobToken === '')) {
            respond([
                'ok'=>false,
                'message'=>'Vercel Blob is connected, but its store credentials are not available to this deployment.'
            ], 500);
        }

        $url = blobObjectUrl($entity);
        $response = blobRequest($url, 'GET');

        /*
         * First deployment convenience: if the Blob object does not exist yet,
         * use the JSON shipped with the project as seed data. Once a save occurs,
         * saveAll() creates the Blob object and all future reads come from Blob.
         */
        if ($response['status'] === 404) {
            return readLocalSeed($entity);
        }

        if ($response['status'] !== 200) {
            respond([
                'ok'=>false,
                'message'=>'Unable to read data from Vercel Blob (HTTP '.$response['status'].').'
            ], 500);
        }

        $raw = trim((string)$response['body']);
        if ($raw === '') return [];

        $value = json_decode($raw, true);
        if (!is_array($value)) {
            respond(['ok'=>false,'message'=>'Stored data is invalid.'], 500);
        }
        return $value;
    }

    return readLocalSeed($entity);
}

function saveAll(string $entity, array $records): void {
    global $blobToken, $oidcToken, $isVercel;

    $json = json_encode(
        array_values($records),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
    if ($json === false) {
        respond(['ok'=>false,'message'=>'Unable to encode data. Please try again.'], 500);
    }

    if ($isVercel) {
        if (blobStoreId() === '' || ($oidcToken === '' && $blobToken === '')) {
            respond([
                'ok'=>false,
                'message'=>'Vercel Blob is connected, but its store credentials are not available to this deployment.'
            ], 500);
        }

        /*
         * Use the current Blob API shape:
         * - OIDC token (or legacy RW token) in Authorization
         * - store ID in x-vercel-blob-store-id
         * - access mode matching the Public Blob store
         * - overwrite enabled because these JSON objects are updated in place
         */
        $headers = [
            'Content-Type: application/json',
            'x-vercel-blob-store-id: '.blobStoreId(),
            'x-api-version: 12',
            'x-vercel-blob-access: public',
            'x-add-random-suffix: 0',
            'x-allow-overwrite: 1',
            'x-content-type: application/json',
            'Content-Length: '.strlen($json),
        ];

        $response = blobRequest(blobApiUrl($entity), 'PUT', $json, $headers);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            respond([
                'ok'=>false,
                'message'=>'Unable to save data to Vercel Blob (HTTP '.$response['status'].').'
            ], 500);
        }
        return;
    }

    if (@file_put_contents(fileFor($entity), $json, LOCK_EX) === false) {
        respond(['ok'=>false,'message'=>'Unable to save data. Please try again.'], 500);
    }
}

function nextCode(string $entity, string $prefix): string {
    $max = 0;
    foreach (all($entity) as $r) {
        $max = max(
            $max,
            (int)preg_replace('/\D/', '', (string)($r['code'] ?? $r['number'] ?? ''))
        );
    }
    return $prefix.str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

function required(array $d, array $keys): array {
    foreach ($keys as $k) {
        if (!isset($d[$k]) || trim((string)$d[$k]) === '') {
            return [false, "$k is required."];
        }
    }
    return [true, ''];
}

function findById(string $entity, string $id): ?array {
    foreach (all($entity) as $row) {
        if (($row['id'] ?? '') === $id) return $row;
    }
    return null;
}

function validDate(string $date): bool {
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

function numberInRange(mixed $value, float $min, float $max): bool {
    return is_numeric($value) && (float)$value >= $min && (float)$value <= $max;
}

if (isset($_GET['api'])) {
  $entity=$_GET['api']; $method=$_SERVER['REQUEST_METHOD']; $id=$_GET['id']??''; $body=readBody();
  if(!is_string($entity) || !is_string($id)) respond(['ok'=>false,'message'=>'Invalid request identifier.'],400);
  if(!in_array($entity,['suppliers','items','orders'],true)) respond(['ok'=>false,'message'=>'Unknown resource'],404);
  if(!in_array($method,['GET','POST','DELETE'],true)) respond(['ok'=>false,'message'=>'Method not allowed.'],405);
  foreach(['name','phone','email','status','category','unit','price','tax','date','supplierId','deliveryDate','reference','paymentTerms','location','notes','charges'] as $key) if(isset($body[$key]) && !is_scalar($body[$key])) respond(['ok'=>false,'message'=>"$key must be a valid value."],422);
  if($method==='GET') { $rows=all($entity); if($id) foreach($rows as $r) if(($r['id']??'')===$id) respond(['ok'=>true,'data'=>$r]); respond(['ok'=>true,'data'=>$rows]); }
  if($method==='DELETE') { $rows=all($entity); $found=false; $rows=array_values(array_filter($rows,function($r)use($id,&$found){if(($r['id']??'')===$id){$found=true;return false;}return true;})); if(!$found) respond(['ok'=>false,'message'=>'Record not found'],404); saveAll($entity,$rows); respond(['ok'=>true,'message'=>'Deleted successfully']); }
  $current=null; if($id) foreach(all($entity) as $row) if(($row['id']??'')===$id){$current=$row;break;}
  if($id && !$current) respond(['ok'=>false,'message'=>'Record not found'],404);
  if($entity==='orders' && ($_GET['action']??'')==='complete') {
    if($method!=='POST') respond(['ok'=>false,'message'=>'Method not allowed.'],405);
    if(!$current || ($current['status']??'')!=='Pending') respond(['ok'=>false,'message'=>'Only Pending purchase orders can be marked as Completed.'],422);
    $rows=all('orders'); foreach($rows as $i=>$row) if(($row['id']??'')===$id) {$rows[$i]['status']='Completed'; $current=$rows[$i]; break;}
    saveAll('orders',$rows); respond(['ok'=>true,'message'=>'Purchase order marked as Completed.','data'=>$current]);
  }
  if($entity==='suppliers') {
    [$ok,$msg]=required($body,['name','phone','email']); if(!$ok) respond(['ok'=>false,'message'=>$msg],422); if(!filter_var($body['email'],FILTER_VALIDATE_EMAIL)) respond(['ok'=>false,'message'=>'Enter a valid email address.'],422); if(!preg_match('/^[0-9+()\- ]{7,20}$/',(string)$body['phone'])) respond(['ok'=>false,'message'=>'Enter a valid phone number.'],422); if(!in_array($body['status']??'Active',['Active','Inactive'],true)) respond(['ok'=>false,'message'=>'Supplier status is invalid.'],422);
    $record=['id'=>$id?:uniqid('sup_',true),'code'=>$current['code']??nextCode('suppliers','SUP-'),'name'=>trim($body['name']),'contact'=>trim($body['contact']??''),'phone'=>trim($body['phone']),'email'=>trim($body['email']),'address'=>trim($body['address']??''),'taxNo'=>trim($body['taxNo']??''),'paymentTerms'=>trim($body['paymentTerms']??'30 Days'),'status'=>$body['status']??'Active'];
  } elseif($entity==='items') {
    [$ok,$msg]=required($body,['name','category','unit','price']); if(!$ok) respond(['ok'=>false,'message'=>$msg],422); if(!numberInRange($body['price'],0,PHP_FLOAT_MAX)) respond(['ok'=>false,'message'=>'Purchase price must be valid.'],422); if(!numberInRange($body['tax']??0,0,100)) respond(['ok'=>false,'message'=>'Item tax must be between 0 and 100.'],422); if(!in_array($body['status']??'Active',['Active','Inactive'],true)) respond(['ok'=>false,'message'=>'Item status is invalid.'],422);
    $record=['id'=>$id?:uniqid('itm_',true),'code'=>$current['code']??nextCode('items','ITM-'),'name'=>trim($body['name']),'description'=>trim($body['description']??''),'category'=>trim($body['category']),'unit'=>trim($body['unit']),'price'=>(float)$body['price'],'tax'=>(float)($body['tax']??0),'status'=>$body['status']??'Active'];
  } else {
    [$ok,$msg]=required($body,['date','supplierId','deliveryDate','items']); if(!$ok) respond(['ok'=>false,'message'=>$msg],422);
    if(!validDate((string)$body['date']) || !validDate((string)$body['deliveryDate'])) respond(['ok'=>false,'message'=>'PO date and expected delivery date must be valid dates.'],422);
    if($body['deliveryDate'] < $body['date']) respond(['ok'=>false,'message'=>'Expected delivery date cannot be earlier than PO date.'],422);
    $supplier=findById('suppliers',(string)$body['supplierId']); if(!$supplier) respond(['ok'=>false,'message'=>'Selected supplier does not exist.'],422); if(($supplier['status']??'')!=='Active') respond(['ok'=>false,'message'=>'Selected supplier is inactive.'],422);
    if(!is_array($body['items'])||count($body['items'])===0) respond(['ok'=>false,'message'=>'Add at least one item.'],422); if(!numberInRange($body['charges']??0,0,PHP_FLOAT_MAX)) respond(['ok'=>false,'message'=>'Additional charges must be a number greater than or equal to zero.'],422);
    $lines=[]; $subtotal=0; $totalDiscount=0; $totalTax=0;
    foreach($body['items'] as $line) {
      if(!is_array($line) || empty($line['itemId'])) respond(['ok'=>false,'message'=>'Every line requires a selected item.'],422);
      foreach(['itemId','description','qty','price','discount','tax'] as $key) if(isset($line[$key]) && !is_scalar($line[$key])) respond(['ok'=>false,'message'=>"Item $key must be a valid value."],422);
      $item=findById('items',(string)$line['itemId']); if(!$item) respond(['ok'=>false,'message'=>'A selected item does not exist.'],422); if(($item['status']??'')!=='Active') respond(['ok'=>false,'message'=>'A selected item is inactive.'],422);
      if(!numberInRange($line['qty']??null,0.000001,PHP_FLOAT_MAX)) respond(['ok'=>false,'message'=>'Quantity must be greater than zero.'],422);
      if(!numberInRange($line['price']??null,0,PHP_FLOAT_MAX)) respond(['ok'=>false,'message'=>'Unit price must be greater than or equal to zero.'],422);
      if(!numberInRange($line['discount']??0,0,100)) respond(['ok'=>false,'message'=>'Discount must be between 0 and 100.'],422);
      if(!numberInRange($line['tax']??0,0,100)) respond(['ok'=>false,'message'=>'Tax must be between 0 and 100.'],422);
      $qty=(float)$line['qty']; $price=(float)$line['price']; $discountRate=(float)($line['discount']??0); $taxRate=(float)($line['tax']??0); $base=$qty*$price; $discount=$base*$discountRate/100; $taxable=$base-$discount; $tax=$taxable*$taxRate/100; $lineTotal=$taxable+$tax;
      $subtotal+=$base; $totalDiscount+=$discount; $totalTax+=$tax;
      $lines[]=['itemId'=>$item['id'],'code'=>$item['code'],'description'=>trim((string)($line['description']??$item['description']??'')),'qty'=>$qty,'unit'=>$item['unit'],'price'=>$price,'discount'=>$discountRate,'tax'=>$taxRate,'lineTotal'=>$lineTotal];
    }
    $charges=(float)($body['charges']??0); $grandTotal=$subtotal-$totalDiscount+$totalTax+$charges; $requestedStatus=$body['status']??'Draft';
    if(!in_array($requestedStatus,['Draft','Pending'],true)) respond(['ok'=>false,'message'=>'Invalid purchase order status.'],422);
    if($current) { $oldStatus=$current['status']??'Draft'; if($oldStatus==='Completed' || ($oldStatus==='Pending' && $requestedStatus==='Draft')) respond(['ok'=>false,'message'=>'This purchase order status transition is not allowed.'],422); }
    $record=['id'=>$id?:uniqid('po_',true),'number'=>$current['number']??nextCode('orders','PO-'),'date'=>$body['date'],'supplierId'=>$supplier['id'],'supplier'=>$supplier['name'],'deliveryDate'=>$body['deliveryDate'],'reference'=>trim($body['reference']??''),'paymentTerms'=>trim($body['paymentTerms']??''),'location'=>trim($body['location']??''),'notes'=>trim($body['notes']??''),'items'=>$lines,'subtotal'=>$subtotal,'discount'=>$totalDiscount,'tax'=>$totalTax,'charges'=>$charges,'total'=>$grandTotal,'status'=>$requestedStatus,'createdBy'=>$current['createdBy']??'Administrator'];
  }
  $rows=all($entity); $updated=false; foreach($rows as $i=>$r) if(($r['id']??'')===$id){$rows[$i]=$record;$updated=true;} if(!$updated)$rows[]=$record; saveAll($entity,$rows); respond(['ok'=>true,'message'=>$updated?'Updated successfully':'Saved successfully','data'=>$record]);
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bluefinch Management | Purchase Dashboard</title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/modal.css"><link rel="stylesheet" href="assets/dashboard.css"><link rel="stylesheet" href="assets/brand.css"></head><body>
<aside><div class="brand"><span class="brand-mark" aria-hidden="true"><i></i></span><span class="brand-copy"><b>Bluefinch</b><small>Management</small></span></div><nav><button class="nav active" data-view="dashboard">▦ Dashboard</button><button class="nav" data-view="orders">▤ Purchase Orders</button><p>MASTERS</p><button class="nav" data-view="suppliers">♙ Suppliers</button><button class="nav" data-view="items">▣ Items</button></nav><div class="profile">● <span>Administrator<br><small>Procurement Team</small></span></div></aside>
<main><header><div><h1 id="title">Dashboard</h1><span id="subtitle">Welcome — here’s your purchasing overview.</span></div><button class="primary" id="newBtn">＋ New Purchase Order</button></header><section id="app"></section></main><div id="toast"></div><script src="assets/app.js"></script></body></html>
