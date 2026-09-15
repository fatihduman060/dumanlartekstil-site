# Production photo import

Run `node tests/production-photo.cjs` for strict number/date parsing, zeroes, duplicate rows, shift selection and low-confidence rejection.
With Playwright and Chrome installed, run `node tests/production-photo-browser.cjs` for form integration and `node tests/production-photo-ocr.cjs` for the real OCR fixture (internet required).

The page uses pinned Tesseract.js 5.1.1 in the browser, consistent with the existing tax receipt OCR. No server credentials, image upload endpoint or database migration is required. The library, worker and language data need internet access on first use. Images stay in browser memory. File limit: 15 MB; decoded image limit: 40 megapixels; processing limit: 90 seconds.

Use a clear, upright image of one shift with A–E rows, production followed by defects, and a numeric date (DD.MM.YYYY or YYYY-MM-DD). Multiple labeled shifts are filtered by the selected card; duplicate groups and reversed column headings are rejected. Handwriting, complex side-by-side tables and damaged screenshots are not reliably recognized. Uncertain or incomplete rows retain existing values with a warning; they are never assumed to be zero. Every result requires manual review and the existing save button.

Actual OCR fixture testing recognized the date and four correct rows; C was read as “Cc” and safely omitted with a warning. This is an explicit OCR limitation, not a guarantee of full extraction for every image. Parser tests cover correct A–E extraction when all five labels are clear.

Photo import never calls the save endpoint or updates stored reports. Changing the date updates the shift form's date only; reports remain for the loaded day until navigation. The other card retains its values and asks for date confirmation on save if its original date differs. Pending initial summary requests cannot overwrite edited cards or a newly selected date.
