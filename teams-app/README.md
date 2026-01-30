# Teams app packaging

This folder is the Teams app package source.

## What you need to edit

1. Update `teams-app/manifest.json`:
   - Replace `<YOUR_AZURE_STATIC_WEB_APPS_HOSTNAME>` with your deployed Azure Static Web Apps hostname (example: `myapp.azurestaticapps.net`).

2. Add the required icon files next to the manifest:
   - `color.png` (192x192)
   - `outline.png` (32x32, transparent background, white glyph)

## Create the Teams app package (zip)

Create a zip containing **exactly**:

- `manifest.json`
- `color.png`
- `outline.png`

Then upload it in Teams:
- Teams Admin Center (org-wide) **or**
- Teams Developer Portal (for personal testing)

