# Tome Static and Azure Storage integration for Drupal.

This module requires:

* Tome: https://drupal.org/project/tome
* Azure Storage: https://drupal.org/project/azure_storage
* A patch for Azure Storage to add blob service support: https://git.drupalcode.org/project/azure_storage/-/merge_requests/1.diff

# Quick Start

Create an Azure Storage account with a `$web` container to hold your static wesbite.

Install this module, and its dependencies, configure the dependencies as as appropriate.

Generate your static site at a location outside the web root using `drush tome:static`.

Run `drush tome:azure-sync`.

Access your new static wesbite.

# Good to Know

The sync command will delete any orphaned files from the storage account.

It's best to store the Azure access keys in files and point the Key module config at those files.

Point `$settings['tome_static_directory']` to a location outside the web root.

This module uses `ralouphie/mimey` to determine the mime type that should be set on the Azure files.
