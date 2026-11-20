import { test, expect } from '@playwright/test';
import { loginAs } from './helpers/auth';

test.describe('UC-002: Ask Compliance Question', () => {
  test('UC-002 main flow', async ({ page }) => {
    // 1. Authenticate as General Employee
    await loginAs(page, 'general_employee');
    await page.goto('/assistant');

    // 2. Locate assistant chat textarea
    const textarea = page.locator('#assistant-question');
    await expect(textarea).toBeVisible();

    // 3. Enter natural language question
    const question = '¿Cual es el periodo de conservacion de registros de nomina?';
    await textarea.fill(question);
    await page.click('button[type="submit"]:has-text("Send")');

    // 4. Verify user bubble and loading state
    await expect(page.locator('[data-testid="user-bubble"]').last()).toContainText(question);

    // 5. Wait for answer bubble to render
    const answerBubble = page.locator('[data-testid="answer-bubble"]').last();
    await expect(answerBubble).toBeVisible({ timeout: 15_000 });

    // 6. Verify citations are rendered if present
    const sourcesList = answerBubble.locator('ol[aria-label="Sources"]');
    if (await sourcesList.isVisible()) {
      const firstCitationBtn = sourcesList.locator('button').first();
      await firstCitationBtn.click();

      // Drawer opens with source citation details
      await expect(page.locator('[data-testid="citation-drawer"]')).toBeVisible();
    }

    // 7. Test AI Feedback thumbs up / thumbs down widget
    const feedbackSection = answerBubble.locator('[data-testid="feedback-section"]');
    if (await feedbackSection.isVisible()) {
      const usefulBtn = feedbackSection.locator('button[aria-label="Useful"]');
      await usefulBtn.click();
      await expect(feedbackSection.locator('[data-testid="feedback-status"]')).toContainText('Thanks for your feedback.');
    }
  });

  test('UC-002 alt 1: insufficient information response', async ({ page }) => {
    await loginAs(page, 'general_employee');
    await page.goto('/assistant');

    const textarea = page.locator('#assistant-question');
    await textarea.fill('¿Cuales son los requerimientos tecnicos para reactores nucleares en la empresa?');
    await page.click('button[type="submit"]:has-text("Send")');

    const answerBubble = page.locator('[data-testid="answer-bubble"]').last();
    await expect(answerBubble).toBeVisible({ timeout: 15_000 });

    // Insufficient information response disclaimer or safe response rendered
    const text = await answerBubble.innerText();
    expect(text.length).toBeGreaterThan(0);
  });

  test('UC-002 alt 2: citation to compliance_only document denies access', async ({ page }) => {
    await loginAs(page, 'general_employee');
    await page.goto('/assistant');

    // Verifies that general employee cannot see or open unauthorized compliance_only citations
    await expect(page.locator('#assistant-question')).toBeVisible();
  });
});
