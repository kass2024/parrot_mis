# MIS deployment and database sync

- For changes intended for the live site, push the verified code to GitHub `main`, update the cPanel checkout, and confirm that local `HEAD`, GitHub `main`, and cPanel `HEAD` point to the same commit. Verify the changed page or behavior on the live site.
- If Git reports changes on cPanel, compare them with local and GitHub before reconciling. Preserve existing work and report any differences that still need a decision.
- Version database schema changes as migrations. Apply them to the intended environments and verify both schemas after deployment.
- Use the cPanel database as the source of truth for MIS records. Back up the local database before a refresh, validate the cPanel snapshot locally, and verify the restored local data against that snapshot.
- Keep database records, dumps, credentials, uploaded documents, and mutable runtime logs out of Git. Store database backups outside the web root.
