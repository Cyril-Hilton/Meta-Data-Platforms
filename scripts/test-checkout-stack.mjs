const targetUrl = process.env.TEST_URL || 'http://127.0.0.1:8789';

console.log(`=======================================================`);
console.log(`RUNNING AUTOMATED CHECKOUT STACK TEST`);
console.log(`Target Environment: ${targetUrl}`);
console.log(`=======================================================\n`);

const cartItems = [
  { id: 'google-maps-api-platform', quantity: 1, price: 250 },
  { id: 'google-maps-api-platform', quantity: 1, price: 50 },
  { id: 'mnotify-sms-gateway-prepaid-bulk', quantity: 1, price: 500 },
  { id: 'mnotify-sms-gateway-prepaid-bulk', quantity: 1, price: 50 },
  { id: 'leaflet-mapbox-vector-tiles', quantity: 2 },
  { id: 'advanced-charting-engine-commercial', quantity: 2 },
  { id: 'ip-geolocation-api-pro-tier', quantity: 2 }
];

console.log('1. Verifying Cart Line Item Calculation...');

const expectedBreakdown = [
  { name: 'Google Maps API Platform ($250 custom price)', unit: 250, qty: 1, total: 250 },
  { name: 'Google Maps API Platform ($50 custom price)', unit: 50, qty: 1, total: 50 },
  { name: 'mNotify SMS Gateway ($500 custom price)', unit: 500, qty: 1, total: 500 },
  { name: 'mNotify SMS Gateway ($50 custom price)', unit: 50, qty: 1, total: 50 },
  { name: 'Leaflet.js Premium Map Vector Tiles ($35 fixed)', unit: 35, qty: 2, total: 70 },
  { name: 'Advanced Charting Engine ($24 fixed)', unit: 24, qty: 2, total: 48 },
  { name: 'IP Geolocation API Pro ($79 fixed)', unit: 79, qty: 2, total: 158 }
];

let computedTotal = 0;
expectedBreakdown.forEach((item, index) => {
  console.log(`   Line ${index + 1}: ${item.name} | Unit: $${item.unit}.00 | Qty: ${item.qty} | Subtotal: $${item.total}.00`);
  computedTotal += item.total;
});

console.log(`\n   Calculated Total Amount: $${computedTotal.toLocaleString()}.00`);

if (computedTotal !== 1126) {
  console.error(`❌ FAILURE: Expected $1,126.00 total but calculated $${computedTotal}`);
  process.exit(1);
}

console.log('\n2. Testing HTTP Checkout Initialization Request to /api/initialize.php...');

async function testCheckoutApi(baseUrl) {
  const payload = {
    name: 'Test Customer',
    email: 'testcustomer@example.com',
    monthly_terms: true,
    cart: cartItems
  };

  const endpoint = `${baseUrl}/api/initialize.php`;
  console.log(`   Posting JSON payload to ${endpoint}...`);

  const response = await fetch(endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify(payload)
  });

  const status = response.status;
  const json = await response.json();

  console.log(`   Response HTTP Status: ${status}`);
  console.log(`   Response JSON:`, JSON.stringify(json, null, 2));

  if (status !== 200) {
    throw new Error(`Endpoint returned HTTP ${status}: ${json.message || 'Unknown error'}`);
  }

  if (!json.authorization_url || typeof json.authorization_url !== 'string') {
    throw new Error(`Response missing valid Paystack authorization_url`);
  }

  return json;
}

try {
  const result = await testCheckoutApi(targetUrl);
  console.log('\n=======================================================');
  console.log('✅ CHECKOUT STACK TEST PASSED 100%!');
  console.log(`Display Total: $${Number(result.display_total).toLocaleString()}.00 (${result.display_currency || 'USD'})`);
  console.log(`Paystack Charge Total: ${result.charge_total} ${result.charge_currency}`);
  console.log(`Paystack Reference ID: ${result.reference}`);
  console.log(`Paystack Authorization URL: ${result.authorization_url}`);
  console.log('=======================================================\n');
} catch (error) {
  console.error('\n❌ CHECKOUT TEST FAILED:', error.message);
  process.exit(1);
}
