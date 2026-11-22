# MIS deployment and database sync

- For changes intended for the live site, push the verified code to GitHub `main`, update the cPanel checkout, and confirm that local `HEAD`, GitHub `main`, and cPanel `HEAD` point to the same commit. Verify the changed page or behavior on the live site.
- If Git reports changes on cPanel, compare them with local and GitHub before reconciling. Preserve existing work and report any differences that still need a decision.
- Version database schema changes as migrations. Apply them to the intended environments and verify both schemas after deployment.
- Keep database records, dumps, credentials, uploaded documents, and mutable runtime logs out of new Git changes. Confirm which database is authoritative and preserve the destination data before any refresh that replaces records.
