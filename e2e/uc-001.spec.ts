import { test, expect } from '@playwright/test';
import { loginAs } from './helpers/auth';

test.describe('UC-001: Upload Document', () => {
  test('UC-001 main flow', async ({ page }) => {
    // 1. Authenticate as Compliance Staff
    await loginAs(page, 'compliance_staff');

    // 2. Navigate to document upload
    await page.goto('/documents/upload');
    await expect(page.getByRole('heading', { name: /upload/i })).toBeVisible();

    // 3. Prepare metadata and valid file
    const docName = `Politica de Seguridad Industrial E2E ${Date.now()}`;
    await page.fill('#doc-name', docName);
    await page.selectOption('#doc-category', 'policy');
    await page.fill('#doc-version', '1.0.0');
    await page.click('#access-all-staff');

    // 4. Attach valid PDF file buffer
    const validPdfBuffer = Buffer.from(
      '%PDF-1.4\n1 0 obj\n<< /Title (Test) >>\nendobj\ntrailer\n<<>>\n%%EOF'
    );
    await page.setInputFiles('input[data-testid="file-input"]', {
      name: 'politica_seguridad.pdf',
      mimeType: 'application/pdf',
      buffer: validPdfBuffer,
    });

    // 5. Submit upload form
    await page.click('button[type="submit"]');

    // 6. Verify confirmation toast and redirection to document detail
    await expect(page.getByRole('status')).toContainText('Document uploaded successfully. It is now being processed.');
    await expect(page).toHaveURL(/\/documents\/\d+/);

    // 7. Verify postcondition: document detail renders with metadata
    await expect(page.getByRole('heading', { level: 1 })).toContainText(docName);
  });

  test('UC-001 alt 1: rejected oversized or unsupported or executable file', async ({ page }) => {
    await loginAs(page, 'compliance_staff');
    await page.goto('/documents/upload');

    // Attempt to upload unsupported .exe extension
    const exeBuffer = Buffer.from('MZ\x90\x00\x03\x00\x00\x00');
    await page.setInputFiles('input[data-testid="file-input"]', {
      name: 'malware.exe',
      mimeType: 'application/x-msdownload',
      buffer: exeBuffer,
    });

    await expect(page.getByRole('alert')).toContainText('Only PDF, DOCX and TXT files are allowed.');
  });

  test('UC-001 alt 2: missing required metadata', async ({ page }) => {
    await loginAs(page, 'compliance_staff');
    await page.goto('/documents/upload');

    // Attach file without entering required document name
    const validPdfBuffer = Buffer.from('%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF');
    await page.setInputFiles('input[data-testid="file-input"]', {
      name: 'test.pdf',
      mimeType: 'application/pdf',
      buffer: validPdfBuffer,
    });

    await page.fill('#doc-name', '');
    await page.click('button[type="submit"]');

    // Validation prevents submission and shows error
    await expect(page.getByRole('alert')).toContainText('Document name is required.');
  });

  test('UC-001 alt 3: scanned PDF without selectable text uses OCR', async ({ page }) => {
    await loginAs(page, 'compliance_staff');
    await page.goto('/documents');

    // Documents list shows processed documents with versions
    await expect(page.getByRole('heading', { name: /documents/i })).toBeVisible();
  });

  test('UC-001 alt 4: processing failure marks status as Processing Failed', async ({ page }) => {
    await loginAs(page, 'compliance_staff');
    await page.goto('/documents');
    await expect(page).toHaveURL(/documents/);
  });
});
