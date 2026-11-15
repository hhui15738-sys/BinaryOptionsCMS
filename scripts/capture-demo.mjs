import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';

const output = 'demo-screenshots';
await mkdir(output, { recursive: true });

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });

await page.goto('http://127.0.0.1:8080/', { waitUntil: 'networkidle' });
await page.screenshot({ path: `${output}/01-home.png`, fullPage: true });

await page.goto('http://127.0.0.1:8080/login', { waitUntil: 'networkidle' });
await page.screenshot({ path: `${output}/02-login.png`, fullPage: true });
await page.locator('#email').fill('admin@example.test');
await page.locator('#password').fill('DemoPassword123!');
await page.locator('#checkCaptcha').evaluate((form) => form.submit());
await page.waitForLoadState('networkidle');
await page.screenshot({ path: `${output}/03-admin.png`, fullPage: true });

await browser.close();
