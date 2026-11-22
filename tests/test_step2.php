<?php
declare(strict_types=1);
define('ABSPATH', __DIR__);
function __(string $text, string $domain = ''): string { return $text; }
function sanitize_title(string $text): string { return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-'); }
function sanitize_key(string $text): string { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $text)); }
require __DIR__ . '/../src/Analysis/SitePartDetector.php';
require __DIR__ . '/../src/Analysis/TemplateTypeDetector.php';
require __DIR__ . '/../src/Analysis/PageDetector.php';
use Condixia\AISiteToWordPress\Analysis\SitePartDetector;
use Condixia\AISiteToWordPress\Analysis\TemplateTypeDetector;
use Condixia\AISiteToWordPress\Analysis\PageDetector;
function check(bool $ok, string $name): void { if (!$ok) { fwrite(STDERR, "FAIL $name\n"); exit(1); } echo "PASS $name\n"; }
function el(string $path, string $cls, string $fingerprint): array {
    return [
      'tag'=>'div','path'=>$path,'depth'=>2,
      'attributes'=>['class'=>explode(' ', $cls)],
      'subtree_fingerprint'=>$fingerprint,'ref'=>'ref'.md5($path.$fingerprint)
    ];
}
$header = el('/html[1]/body[1]/div[3]','container-fluid nav-bar p-0','nav-shared');
$footer = el('/html[1]/body[1]/div[12]','container-fluid footer py-5','footer-shared');
$pageHeader = el('/html[1]/body[1]/div[5]','container-fluid page-header py-5','page-header');
$hero = el('/html[1]/body[1]/div[6]','container-fluid header-carousel','hero-carousel');
$widget = el('/html[1]/body[1]/div[12]/div[1]','footer-item','widget');
$nestedFooter = el('/html[1]/body[1]/div[12]/div[2]','footer-section','nested');
$d = ['index.html'=>['elements'=>[$header,$footer,$pageHeader,$hero,$widget,$nestedFooter]],'shop.html'=>['elements'=>[$header,$footer,$pageHeader,$hero,$widget,$nestedFooter]]];
$found = (new SitePartDetector())->detect($d);
$roles = array_column($found['candidates'],'role');
check($found['header_variants'] === 1, 'Header class wrapper detected once');
check($found['footer_variants'] === 1, 'Footer class wrapper detected once');
check(count($found['candidates']) === 2, 'Nested footer, page-header, header-carousel and footer-item are excluded');
check($found['ambiguous'] === 0, 'Repeated shared parts are high confidence');
$pageClass = new ReflectionClass(PageDetector::class);
$page = $pageClass->newInstanceWithoutConstructor();
$explicitTemplate = $pageClass->getMethod('isTemplateOnlyFile');
foreach (['index.html','shop.html','single.html','404.html','bestseller.html','cart.html','cheackout.html','contact.html'] as $file) {
    check($explicitTemplate->invoke($page, $file) === false, $file . ' kept as a static page');
}
check($explicitTemplate->invoke($page, 'single-product.html') === true, 'Explicit single-product template remains deferred');
$detector = new TemplateTypeDetector();
function detected(string $file, string $html, bool $home=false): ?array {
    return (new TemplateTypeDetector())->detect($file,['classification'=>'page','candidate'=>['home_candidate'=>$home]],$html);
}
check(detected('index.html','<html></html>', true)['role'] === 'front_page','Root index suggested as front-page');
check(detected('404.html','<h1>Page Not Found</h1>')['role'] === '404','404 role suggested');
$shop=detected('shop.html','<div class="shop product-item"></div>');
check($shop['role'] === 'product_archive' && $shop['ambiguous'] === true, 'Shop archive suggestion remains ambiguous');
$single=detected('single.html','<div class="container-fluid related-product"></div>');
check($single['role'] === 'single_product' && $single['ambiguous'] === true, 'Electro product detail suggested without auto-assignment');
check(detected('single.html','<div class="article"></div>') === null,'No single-product assumption without supporting evidence');
check(detected('contact.html','<div class="contact"></div>') === null,'Ordinary page does not become a dynamic template');
if ($argc >= 2) {
  $electro=file_get_contents($argv[1]);
  check(is_string($electro) && detected('single.html',$electro)['role']==='single_product', 'Original Electro single.html classified as product suggestion');
}
echo "All detector tests passed.\n";
