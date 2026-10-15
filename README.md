# Posti Shipping module for PrestaShop

## System Requirements

- Server:
    - PHP 7.4 or newer
- PretaShop:
    - 1.7.8.x
    - 8.x
    - 9.x

## Changelog

A list of changes in each module version is available in [CHANGELOG.md](postishipping/CHANGELOG.md).

## Building for Release

To create a production-ready ZIP file, run:

```bash
./create-release-zip.sh
```

This will generate `postishipping.zip` in the project root, excluding development files defined in `.gitattributes` (`export-ignore`).

## Module Custom Parameters

The module allows the use of custom endpoints, which are useful for development and test environments.
The module folder contains the file `posticonfig-dist.json`, which can be copied (or renamed) to `posticonfig.json` and used to specify custom endpoints:
```json
{
    "mode": "", // Mode name
    "baseUrl": "", // API request URL
    "authUrl": "", // API authorization URL
    "pickupUrl": "", // Pickup request URL
    "updateUrl": "" // Module update URL. Set "-" to disable updates check
}
```

## Module Update

The module does not use the PrestaShop update system but has its own update functionality.
When the module settings page is opened, the module checks whether an update is available. If a new module version exists, it displays an `Update to %version%` button. When clicked, the button completely updates the module.
Updates are checked once every hour. To force an update check within this one-hour period, add the parameter `&forceUpdateCheck=1` to the URL of the module settings page.
