/**
 * FX-5: the log-sheet import as a user actually meets it.
 *
 * Drives a real headless browser against `php artisan serve` and a throwaway
 * database: log in, upload a minimal and a full workbook, read the success
 * message, open the import and its detail pages, export, and clear a log sheet.
 *
 * The run fails if anything logged to the console, threw in the page, or
 * answered with an HTTP status of 400 or worse — a pretty screenshot is not
 * worth a broken asset.
 *
 * Driven by environment:
 *   BASE_URL    (default http://127.0.0.1:8123)
 *   EMAIL/PASSWORD
 *   FIXTURE_DIR (default tests/fixtures/logsheets)
 *   DOWNLOAD_DIR
 *   HEADLESS=0  to watch it work
 */

import { mkdirSync, readdirSync, existsSync } from 'node:fs';
import { join, resolve } from 'node:path';
import puppeteer from 'puppeteer';

const BASE_URL = (process.env.BASE_URL || 'http://127.0.0.1:8123').replace(/\/$/, '');
const EMAIL = process.env.EMAIL || 'puppeteer@transport.test';
const PASSWORD = process.env.PASSWORD || 'password123';
const ROOT = resolve(process.env.FIXTURE_DIR || 'tests/fixtures/logsheets');
const DOWNLOAD_DIR = resolve(process.env.DOWNLOAD_DIR || 'storage/app/puppeteer-downloads');
const HEADLESS = process.env.HEADLESS !== '0';

/**
 * The clear panel's root, not its form: the confirmation modal is a sibling of
 * `#clear-form`, so a query scoped to the form cannot see the Confirm button.
 *
 * Only usable from Node — code passed to page.evaluate() runs in the browser
 * and cannot see this constant, so those queries spell the selector out.
 */
const CLEAR_PANEL = 'div[data-preview-url]';

const problems = [];
const notes = [];

function fail(message) {
    problems.push(message);
}

function note(message) {
    notes.push(message);
    console.log(`  ok  ${message}`);
}

function check(condition, message) {
    if (condition) {
        note(message);
    } else {
        fail(message);
    }

    return !!condition;
}

function fixture(name) {
    const path = join(ROOT, name);

    if (!existsSync(path)) {
        throw new Error(`fixture not found: ${path}`);
    }

    return path;
}

/**
 * Widen the upload form's own date range to cover every fixture.
 *
 * The form defaults to today–today, which is a real filter: a workbook from
 * another month would import nothing. The clear panel has date fields with the
 * same ids, so this is deliberately scoped to the upload form.
 */
async function setFullRange(page) {
    for (const id of ['#date_from', '#date_to']) {
        const el = await page.$(`form[action$="/logsheets"] ${id}`);

        if (!el) {
            continue;
        }

        await el.click({ clickCount: 3 });
        await el.press('Backspace');
        await el.type(id.endsWith('from') ? '2020-01-01' : '2030-12-31');
    }
}

async function bodyText(page) {
    return page.evaluate(() => document.body.innerText);
}

async function upload(page, file, label) {
    const input = await page.$('form[action$="/logsheets"] input[type=file]');

    if (!input) {
        fail(`${label}: the upload form has no file input`);

        return false;
    }

    await input.uploadFile(fixture(file));

    await setFullRange(page);

    const button = await page.$('form[action$="/logsheets"] button[type=submit]');
    if (!button) {
        fail(`${label}: the upload form has no submit button`);

        return false;
    }

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }),
        button.click(),
    ]);

    const text = await bodyText(page);

    check(/Imported \d+ rows/i.test(text), `${label}: a success message reports the imported row count`);
    check(/Total ₹[\d,]+\.\d{2}/i.test(text), `${label}: the success message reports the total amount`);

    return true;
}

async function main() {
    if (!existsSync(ROOT)) {
        throw new Error(`fixture folder not found: ${ROOT}`);
    }

    const available = readdirSync(ROOT).filter((f) => /\.(xlsx|xls|csv)$/i.test(f));

    if (available.length === 0) {
        throw new Error(`no fixtures in ${ROOT}`);
    }

    mkdirSync(DOWNLOAD_DIR, { recursive: true });

    console.log(`fixtures: ${available.join(', ')}`);
    console.log(`base url: ${BASE_URL}`);

    const browser = await puppeteer.launch({
        headless: HEADLESS,
        args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1000 });

    const client = await page.createCDPSession();
    await client.send('Page.setDownloadBehavior', { behavior: 'allow', downloadPath: DOWNLOAD_DIR });

    page.on('console', (message) => {
        if (message.type() === 'error') {
            fail(`console error: ${message.text()}`);
        }
    });

    page.on('pageerror', (error) => fail(`page exception: ${error.message}`));
    page.on('requestfailed', (request) => {
        // Aborted navigations are not failures; a genuinely broken request is.
        const failure = request.failure();
        if (failure && !/net::ERR_ABORTED/.test(failure.errorText)) {
            fail(`request failed: ${request.method()} ${request.url()} — ${failure.errorText}`);
        }
    });
    page.on('response', (response) => {
        if (response.status() >= 400) {
            fail(`HTTP ${response.status()} for ${response.request().method()} ${response.url()}`);
        }
    });

    try {
        // ---------------------------------------------------------- login
        await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle2', timeout: 60000 });

        check(await page.$('input[name=email]') !== null, 'the login form renders an email field');
        check(await page.$('input[name=password]') !== null, 'the login form renders a password field');

        await page.type('input[name=email]', EMAIL);
        await page.type('input[name=password]', PASSWORD);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }),
            page.click('form[action$="/login"] button[type=submit]'),
        ]);

        check(!page.url().includes('/login'), `login succeeds (landed on ${page.url()})`);

        // --------------------------------------------------------- import
        await page.goto(`${BASE_URL}/logsheets`, { waitUntil: 'networkidle2' });

        check(await bodyText(page).then((t) => /Log Sheet/i.test(t)), 'the log sheets page renders');

        if (await upload(page, 'minimal-4-column.xlsx', 'minimal workbook')) {
            const afterMinimal = await bodyText(page);
            check(
                afterMinimal.includes('minimal-4-column.xlsx'),
                'the import table lists the workbook that was just uploaded'
            );

            const viewLink = await page.$('table a[href*="/logsheets/imports/"]');

            if (viewLink) {
                await Promise.all([
                    page.waitForNavigation({ waitUntil: 'networkidle2' }),
                    viewLink.click(),
                ]);

                const importText = await bodyText(page);
                check(page.url().includes('/logsheets/imports/'), 'the import detail page opens');
                check(importText.length > 200, 'the import detail page renders content');
                check(!/\b500\b|Server Error|Whoops/i.test(importText), 'the import detail page is not an error page');
            } else {
                fail('the import table has no link to the import detail page');
            }

            // Records page lists the imported sheets.
            await page.goto(`${BASE_URL}/logsheets/records`, { waitUntil: 'networkidle2' });
            const recordsText = await bodyText(page);
            check(/FX-/i.test(recordsText), 'the records page lists the imported log sheets');

            // The first log sheet's own page.
            const sheetHref = await page.evaluate(() => {
                const links = Array.from(document.querySelectorAll('a[href]'))
                    .map((a) => a.getAttribute('href') || '');

                return links.find((href) => /\/logsheets\/\d+(\?|$)/.test(href)) || null;
            });

            if (sheetHref) {
                await page.goto(new URL(sheetHref, BASE_URL).href, { waitUntil: 'networkidle2' });

                const sheetText = await bodyText(page);
                check(sheetText.length > 200, 'the log sheet page renders');
                check(!/Server Error|Whoops/i.test(sheetText), 'the log sheet page is not an error page');
            } else {
                fail('the records page has no link to a log sheet');
            }
        }

        // A second, full-width workbook must import just as well.
        await page.goto(`${BASE_URL}/logsheets`, { waitUntil: 'networkidle2' });
        await upload(page, 'full-26-column.xlsx', 'full workbook');

        // A messy real-world workbook, uploaded the way a client would.
        await page.goto(`${BASE_URL}/logsheets`, { waitUntil: 'networkidle2' });
        await upload(page, 'messy-headers-shuffled.xlsx', 'messy workbook');

        // --------------------------------------------------------- export
        // `scope` is the export's own parameter: `status` is a records filter,
        // and passing it here would be rejected as invalid input.
        const exportResponse = await page.evaluate(async (base) => {
            const response = await fetch(`${base}/logsheets/export?scope=all`, { credentials: 'include' });
            const bytes = new Uint8Array(await response.arrayBuffer());

            return {
                status: response.status,
                type: response.headers.get('content-type') || '',
                length: bytes.length,
                magic: bytes.length > 1 ? String.fromCharCode(bytes[0], bytes[1]) : '',
            };
        }, BASE_URL);

        check(
            exportResponse.status === 200,
            `the export responds 200 (got ${exportResponse.status})`
        );
        check(
            /spreadsheet|octet-stream|ms-excel/i.test(exportResponse.type),
            `the export is a spreadsheet (content-type: ${exportResponse.type})`
        );
        check(
            exportResponse.magic === 'PK' && exportResponse.length > 1000,
            `the export is a real xlsx file (${exportResponse.length} bytes, magic "${exportResponse.magic}")`
        );

        // ---------------------------------------------------------- clear
        const number = process.env.CLEAR_NUMBER || 'FX-1001';

        const chipInput = await page.$(CLEAR_PANEL + ' input[aria-label="Log sheet numbers input"]');

        if (chipInput) {
            await chipInput.type(number);
            await page.keyboard.press('Enter');

            // The chip is added by Alpine after the keystroke, so wait for it
            // rather than racing it: a Check clicked too early is a no-op.
            const chipAdded = await page.waitForFunction(
                (wanted) => Array.from(document.querySelectorAll('div[data-preview-url] [role=option]'))
                    .some((chip) => chip.innerText.trim().startsWith(wanted)),
                { timeout: 10000 },
                number
            ).then(() => true).catch(() => false);

            check(chipAdded, `typing ${number} adds it as a chip`);

            const checked = await page.evaluate(() => {
                const button = Array.from(document.querySelectorAll('div[data-preview-url] button'))
                    .find((b) => b.innerText.trim() === 'Check');

                if (!button || button.disabled) {
                    return false;
                }

                button.click();

                return true;
            });

            check(checked, 'the clear panel accepts a Check');

            // The preview arrives over fetch and turns the disabled "Mark 0"
            // button into "Mark 1 as cleared". Wait for that, not for text
            // that is already in the markup.
            const previewReady = await page.waitForFunction(
                () => Array.from(document.querySelectorAll('div[data-preview-url] button')).some(
                    (b) => b.innerText.trim().startsWith('Mark ') && !b.disabled
                ),
                { timeout: 20000 }
            ).then(() => true).catch(() => false);

            check(previewReady, 'the clear preview counts what will be cleared');

            await page.evaluate(() => {
                const button = Array.from(document.querySelectorAll('div[data-preview-url] button'))
                    .find((b) => b.innerText.trim().startsWith('Mark ') && b.innerText.includes('as cleared'));

                button?.click();
            });

            // The modal opens client-side; poll for it rather than guessing at a
            // selector the DOM does not have to offer.
            const modalOpen = await page.waitForFunction(
                () => Array.from(document.querySelectorAll('div[data-preview-url] button'))
                    .some((b) => b.innerText.trim() === 'Confirm' && b.getClientRects().length > 0),
                { timeout: 10000 }
            ).then(() => true).catch(() => false);

            check(modalOpen, 'the clear action asks for confirmation first');

            await page.evaluate(() => {
                const button = Array.from(document.querySelectorAll('div[data-preview-url] button'))
                    .find((b) => b.innerText.trim() === 'Confirm' && b.getClientRects().length > 0);

                button?.click();
            });

            const cleared = await page.waitForFunction(
                () => {
                    const buttons = Array.from(document.querySelectorAll('div[data-preview-url] button'));
                    const done = buttons.find((b) => b.innerText.trim() === 'Confirm');

                    return !!done && done.getClientRects().length === 0;
                },
                { timeout: 20000 }
            ).then(() => true).catch(() => false);

            check(cleared, 'the confirmation closes and the result is reported');

            const summary = await page.evaluate(() => {
                const form = document.querySelector('div[data-preview-url]');
                const match = form?.innerText.match(/Cleared\s+(\d+)/);

                return match ? Number(match[1]) : 0;
            });

            check(summary > 0, `the result summary reports the sheets it cleared (${summary})`);
        } else {
            fail('the clear panel has no log sheet number input');
        }

        // Records page reflects the cleared state.
        await page.goto(`${BASE_URL}/logsheets/records?status=cleared`, { waitUntil: 'networkidle2' });
        check(/Cleared/i.test(await bodyText(page)), 'the records page shows cleared log sheets');
    } finally {
        await browser.close();
    }

    if (problems.length) {
        console.error(`\n${problems.length} problem(s):`);
        problems.forEach((p) => console.error(`  - ${p}`));
        process.exit(1);
    }

    console.log(`\nbrowser checks passed (${notes.length} assertions)`);
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});