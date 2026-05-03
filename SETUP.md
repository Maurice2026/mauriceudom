# Academic Hub — Setup Guide
## Complete instructions to go live in ~30 minutes

---

## What you're deploying

```
academic-hub/
├── index.html          ← Your full webpage (policy + submission form)
├── submit.php          ← Backend: validates → uploads to Drive → sends emails
├── composer.json       ← Installs the Google Drive library
└── SETUP.md            ← This guide
```

---

## Step 1 — Create a Google Cloud Service Account

This lets the site upload files to YOUR Google Drive automatically.

1. Go to **https://console.cloud.google.com**
2. Create a new project (e.g. "Academic Hub")
3. In the left menu: **APIs & Services → Library**
4. Search for **"Google Drive API"** and click **Enable**
5. Go to **APIs & Services → Credentials**
6. Click **"+ Create Credentials" → Service Account**
7. Fill in a name (e.g. "academic-hub-uploader") and click **Done**
8. Click the service account you just created → **Keys tab → Add Key → JSON**
9. A `.json` file downloads — this is your credentials file. **Keep it private.**

---

## Step 2 — Share your Google Drive folder with the service account

1. In **Google Drive**, create a folder named e.g. "Student Assignments"
2. Right-click the folder → **Share**
3. Open the credentials `.json` file — find the `"client_email"` value
   (it looks like: `academic-hub-uploader@your-project.iam.gserviceaccount.com`)
4. Paste that email address into the Share dialog, give it **Editor** access
5. Copy the folder ID from the URL:
   `https://drive.google.com/drive/folders/`**`1AbCdEfGhIjKlMnOpQrStUvWxYz`**
   ↑ Copy this part

---

## Step 3 — Upload to your web host (cPanel / Hostinger)

### Option A — File Manager (no SSH needed)

1. Log into **cPanel → File Manager**
2. Navigate to your `public_html` folder
3. Upload `index.html` and `submit.php` into `public_html`
4. Upload the credentials `.json` file **one level ABOVE** `public_html`
   (e.g. into `/home/yourusername/`) — this keeps it private

### Option B — FTP (FileZilla)
Upload the same files to the same locations.

---

## Step 4 — Install the Google Drive PHP library

### On cPanel with SSH / Terminal:
```bash
cd ~/public_html
curl -sS https://getcomposer.org/installer | php
php composer.phar install --no-dev --optimize-autoloader
```

### On cPanel without SSH (use PHP Script):
Many hosts let you run Composer through a web-based terminal.
Alternatively, you can use **Hostinger's built-in terminal** in their hPanel.

After running `composer install`, a `vendor/` folder is created — that's correct.

---

## Step 5 — Edit submit.php with your details

Open `submit.php` and update the CONFIG section at the top:

```php
define('LECTURER_EMAIL',         'your@email.com');
define('SITE_NAME',              'Academic Hub');
define('SITE_URL',               'https://yourdomain.com');
define('GOOGLE_DRIVE_FOLDER_ID', '1AbCdEfGhIjKlMnOpQrStUvWxYz');  // from Step 2
define('GOOGLE_CREDENTIALS_PATH', '/home/yourusername/your-credentials.json');
```

---

## Step 6 — Test it

1. Visit your site in a browser
2. Fill in the form and upload a small PDF
3. Check:
   - [ ] The file appears in your Google Drive folder
   - [ ] You received a notification email
   - [ ] The student email address received a confirmation

---

## Step 7 — Enable AdSense

Once the form is working and you have real content + student traffic:

1. Apply at **https://adsense.google.com**
2. Once approved, replace the `<!-- Ad 728x90 -->` comments in `index.html`
   with your actual `<ins class="adsbygoogle" ...>` tags from AdSense
3. Add the AdSense script tag in the `<head>` of `index.html`:
   ```html
   <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-XXXXXXXX" crossorigin="anonymous"></script>
   ```

---

## Troubleshooting

| Problem | Solution |
|---|---|
| "Could not save your file" error | Check the credentials path in `submit.php` and ensure the Drive folder is shared with the service account email |
| Emails not arriving | Check your spam folder; some hosts block `mail()` — use SMTP instead (see below) |
| "vendor/autoload.php not found" | Run `composer install` in the same folder as `composer.json` |
| Form submits but no file in Drive | Verify the folder ID is correct and the service account has Editor access |

### Using SMTP instead of PHP mail() (recommended)

If emails aren't arriving, replace the `sendEmail()` function in `submit.php` with PHPMailer:

1. Add to `composer.json`: `"phpmailer/phpmailer": "^6.8"`
2. Run `composer update`
3. Get SMTP credentials from your host (cPanel → Email Accounts → Connect Devices)
4. See: https://github.com/PHPMailer/PHPMailer/blob/master/examples/smtp.phps

---

## Security notes

- The credentials `.json` file is stored **outside** `public_html` — it cannot be accessed via the web
- All file uploads are validated by MIME type and size before processing
- Form inputs are sanitized to prevent XSS injection
- The `vendor/` folder should not need to be publicly accessible

---

*Questions? Check the Google Drive API docs: https://developers.google.com/drive/api/guides/manage-uploads*
