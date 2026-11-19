import { Page, expect } from '@playwright/test';

export async function loginAs(page: Page, role: 'administrator' | 'compliance_staff' | 'general_employee') {
  const email = `demo.${role}@compliance-platform.test`;
  const password = process.env.DEMO_USER_PASSWORD || 'password';

  await page.goto('/login');
  await page.waitForLoadState('networkidle');

  await page.fill('input[name="email"], input[type="email"]', email);
  await page.fill('input[name="password"], input[type="password"]', password);
  await page.click('button[type="submit"]');

  if (role === 'general_employee') {
    await expect(page).toHaveURL(/assistant/);
  } else {
    await expect(page).toHaveURL(/dashboard/);
  }
}
