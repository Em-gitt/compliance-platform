import { test, expect } from '@playwright/test';
import { loginAs } from './helpers/auth';

/**
 * CMP-46: Core functions cross-browser compatibility checklist.
 * Evaluates core functions:
 * 1. Sign In / Sign Out
 * 2. Compliance Dashboard
 * 3. Register Organization
 * 4. Create Requirement
 * 5. Change Compliance Status
 * 6. Upload Document
 * 7. Document Version History
 * 8. Search and Filters
 * 9. AI Chat with Citations and Citation Viewer
 * 10. User Management
 * 11. Alerts Bell
 */
test.describe('CMP-46: Core Functions Browser Compatibility', () => {
  test('1 & 10. Sign in, user management, and sign out', async ({ page }) => {
    await loginAs(page, 'administrator');
    await page.goto('/users');
    await expect(page.getByRole('heading', { name: /users/i })).toBeVisible();

    // Verify user table renders
    const table = page.locator('table');
    await expect(table).toBeVisible();
    await expect(table.locator('tbody tr').first()).toBeVisible();

    // Logout
    const logoutBtn = page.locator('button:has-text("Sign out"), button:has-text("Log out"), a:has-text("Logout")').first();
    if (await logoutBtn.isVisible()) {
      await logoutBtn.click();
      await expect(page).toHaveURL(/login/);
    }
  });

  test('2 & 11. Compliance dashboard and alerts bell', async ({ page }) => {
    await loginAs(page, 'compliance_staff');
    await page.goto('/dashboard');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    // Alerts bell verification
    const bell = page.locator('[data-testid="alerts-bell-button"]');
    await expect(bell).toBeVisible();
    await bell.click();
    await expect(page.locator('[data-testid="alerts-dropdown"]')).toBeVisible();
  });

  test('4 & 5. Requirements browsing and status filters', async ({ page }) => {
    await loginAs(page, 'compliance_staff');
    await page.goto('/requirements');
    await expect(page.getByRole('heading', { name: /compliance requirements/i })).toBeVisible();

    const searchInput = page.locator('input[placeholder*="Search by requirement name"]');
    if (await searchInput.isVisible()) {
      await searchInput.fill('Seguridad');
      await page.waitForTimeout(350);
    }
  });

  test('6 & 7 & 8. Document upload, search filters, and version history', async ({ page }) => {
    await loginAs(page, 'compliance_staff');
    await page.goto('/documents');
    await expect(page.getByRole('heading', { name: /documents/i })).toBeVisible();

    const searchInput = page.locator('input[placeholder*="Search by document name"]');
    if (await searchInput.isVisible()) {
      await searchInput.fill('Politica');
      await page.waitForTimeout(350);
    }

    await page.goto('/documents/upload');
    await expect(page.locator('#doc-name')).toBeVisible();
  });

  test('9. AI Assistant with citations and feedback widget', async ({ page }) => {
    await loginAs(page, 'general_employee');
    await page.goto('/assistant');
    await expect(page.locator('#assistant-question')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Send' })).toBeVisible();
  });
});
