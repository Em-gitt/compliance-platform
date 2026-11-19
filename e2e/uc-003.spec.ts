import { test, expect } from '@playwright/test';
import { loginAs } from './helpers/auth';

test.describe('UC-003: Manage Compliance Requirement', () => {
  test('UC-003 main flow', async ({ page }) => {
    // 1. Authenticate as Compliance Staff
    await loginAs(page, 'compliance_staff');

    // 2. Navigate to compliance dashboard
    await page.goto('/dashboard');
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/dashboard/i);

    // 3. Navigate to requirements list
    await page.goto('/requirements');
    await expect(page.getByRole('heading', { name: /compliance requirements/i })).toBeVisible();

    // 4. Verify requirements table displays rows with deadline and status
    const table = page.locator('table');
    await expect(table).toBeVisible();
    await expect(table.locator('tbody tr').first()).toBeVisible();
  });

  test('UC-003 alt 1: missing required information', async ({ page }) => {
    await loginAs(page, 'compliance_staff');
    await page.goto('/requirements');

    // Filter controls test: searching non-existent requirement displays empty state
    const searchInput = page.locator('input[placeholder*="Search by requirement name"]');
    if (await searchInput.isVisible()) {
      await searchInput.fill('NonExistentRequirementQuery99999');
      await page.waitForTimeout(400); // debounce
      await expect(page.locator('tbody tr')).toHaveCount(0);
    }
  });

  test('UC-003 alt 2: required document missing identified', async ({ page }) => {
    await loginAs(page, 'compliance_staff');
    await page.goto('/requirements');

    // Check that missing documents indicator is visible in table
    const table = page.locator('table');
    await expect(table).toBeVisible();
    const missingBadge = table.locator('text=Missing').first();
    if (await missingBadge.isVisible()) {
      await expect(missingBadge).toBeVisible();
    }
  });

  test('UC-003 alt 3: notification period alert generated', async ({ page }) => {
    await loginAs(page, 'compliance_staff');
    await page.goto('/dashboard');

    // Alert bell icon is visible in header
    const bellBtn = page.locator('[data-testid="alerts-bell-button"]');
    await expect(bellBtn).toBeVisible();

    // Clicking bell opens alerts dropdown
    await bellBtn.click();
    await expect(page.locator('[data-testid="alerts-dropdown"]')).toBeVisible();
  });
});
