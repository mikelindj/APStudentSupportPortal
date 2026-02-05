# Deployment: Azure Static Web Apps + Microsoft Teams

## 1) Azure Static Web Apps (host the UI)

This project is a static site (no build step) and can be deployed as-is.

### Recommended: GitHub → Azure Static Web Apps

1. Put this folder in a git repository and push to GitHub.
2. In Azure Portal: **Create → Static Web App**
3. Settings:
   - **Source**: GitHub
   - **Build preset**: *Custom*
   - **App location**: `/`
   - **Api location**: *(leave blank)*
   - **Output location**: `/`
4. Deploy.

### Important: Teams iframe headers

`staticwebapp.config.json` is included to:
- rewrite SPA routes to `/index.html`
- allow rendering inside Teams via `Content-Security-Policy` `frame-ancestors ...`

## 2) Microsoft Teams app (tab)

### Update the manifest

Edit `teams-app/manifest.json` and replace:
- `<YOUR_AZURE_STATIC_WEB_APPS_HOSTNAME>` with your SWA hostname

Example:
- `myapp.azurestaticapps.net`

### Add icons

Teams requires:
- `teams-app/color.png` (192x192)
- `teams-app/outline.png` (32x32, transparent background, white glyph)

### Zip and upload

Zip these three files (no folders inside the zip):
- `manifest.json`
- `color.png`
- `outline.png`

Upload via:
- Teams Admin Center (org deployment) or
- Teams Developer Portal (test deployment)

## 3) AP Referral API key (production)

The **Review Outcomes** view and other referral features call `apReferral.php` on `parents.acsacademy.edu.sg`. If that API has `AP_REFERRAL_API_KEY` (or `AP_API_KEY`) set in its environment, the frontend must send the same key or requests return **401 Unauthorized**.

For production (GitHub Actions → Azure Static Web Apps):

1. In the API server environment, set `AP_REFERRAL_API_KEY` (or `AP_API_KEY`) to your shared secret.
2. In the GitHub repo: **Settings → Secrets and variables → Actions** → add a secret named **`AP_REFERRAL_API_KEY`** with the same value.
3. On each deploy, the workflow injects this key into `index.html` so the app can call the referral API. If the secret is not set, the placeholder is left in place and referral/outcomes calls will return Unauthorized in production.

## Notes

- This UI calls existing APIs on `parents.acsacademy.edu.sg` (CORS must allow your SWA origin).
- If you later enable Teams SSO, you’ll add `webApplicationInfo` to the manifest and configure an Application ID URI.

