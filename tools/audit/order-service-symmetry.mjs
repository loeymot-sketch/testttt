import fs from 'fs';

const order = fs.readFileSync('app/Services/OrderService.php', 'utf8');
const frontend = fs.readFileSync('app/Services/FrontendOrderService.php', 'utf8');

const posCall = 'app(\\App\\Services\\Stock\\StockService::class)->decrementForOrder($this->order, $idempotencyKey);';
const frontendCall = 'app(\\App\\Services\\Stock\\StockService::class)->decrementForOrder($this->frontendOrder, $idempotencyKey);';

function count(source, needle) {
  return source.split(needle).length - 1;
}

const posCount = count(order, posCall);
const frontendCount = count(frontend, frontendCall);

if (posCount !== 1 || frontendCount !== 1) {
  console.error(`Stock decrement symmetry failed: pos=${posCount}, frontend=${frontendCount}`);
  process.exit(1);
}

console.log('OrderService/FrontendOrderService stock decrement symmetry OK');
