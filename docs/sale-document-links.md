# Invoice / warehouse dispatch linkage

One outgoing sales invoice can link to one warehouse dispatch. Select the dispatch in the invoice create/edit form or for each PDF in bulk upload. Linking requires both invoice write and warehouse accounting permissions. Warehouse operators can see that a link exists but are not given access to invoice details.

- Customer, currency and rounded total must match; cancelled documents and incoming invoices cannot link. Different document dates require explicit confirmation. No financial values are copied between documents.
- If either document already has an active cari movement, both reference that same movement. Repeated or simultaneous posting does not create a second movement or overwrite the first movement's financial details. SQLite write locking precedes the posting/linking reads.
- If both already have different active movements, linking fails. An authorized user must first resolve the duplicate using the existing reasoned soft-cancel workflow.
- Linked customer, date, amount, currency and direction changes are blocked, including legacy quick-edit routes. Shared movement financial edits are also blocked. Correct financial data by explicitly cancelling the movement, unlinking with a reason, editing the documents, then linking/posting again.
- Unlink/relink requires a reason and is blocked while either movement is active. Cancelled movement references remain available for history. Link changes and shared posting are audited in the same transaction; linkage audit failure rolls back the operation.
- Cancelling a linked invoice cancels its invoice stock lines, detaches the invoice, and keeps the warehouse dispatch and cari movement active. The detach reason is audited. Warehouse cancellation remains blocked while linked (or posted).
- If a shared movement is soft-cancelled, explicit posting may create one replacement active movement and update both references. It does not reactivate the cancelled row.
- Existing records are not automatically matched or financially reconciled. Partial/consolidated invoices are outside this one-to-one feature.

Schema is created idempotently by the invoice and dispatch entry points: `sale_document_links` has unique invoice and dispatch identifiers. SQLite guards protect linked financial fields even through legacy routes. No live data backfill or financial rewrite is performed.

Verification:

```
PHP_BINARY=/path/to/php python3 tools/test-sale-document-link.py
PHP_BINARY=/path/to/php python3 tools/test-depo-cikis.py
PHP_BINARY=/path/to/php python3 tools/test-depo-cikis-bakiye.py
```

The first suite exercises actual HTTP routes against disposable SQLite data, both posting orders, parallel/repeated posts, mismatch rollback, cancellation/reposting, unlink/relink, mandatory audit rollback, permissions/CSRF, quick edits, counterpart rendering and actual bulk/single PDF uploads. PHP requires PDO SQLite; syntax supports PHP 7.4. The balance regression fixture now marks dispatch completion before posting, as required by the existing workflow.
