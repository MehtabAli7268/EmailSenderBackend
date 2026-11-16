# NexGen Email Backend

A small PHP API that receives enquiry and booking submissions from the website as JSON and emails each one to the inbox for its region. It uses PHPMailer over authenticated SMTP and needs no database or framework. It runs on standard cPanel shared hosting without SSH or Composer on the server.

## Contents

1. [API reference](#1-api-reference)
2. [Prepare the files on your computer](#2-prepare-the-files-on-your-computer)
3. [Deploy to cPanel](#3-deploy-to-cpanel)
4. [Test the live API](#4-test-the-live-api)
5. [Connect the website](#5-connect-the-website)
6. [Security behaviour](#6-security-behaviour)
7. [Troubleshooting](#7-troubleshooting)

## 1. API reference

```
POST /api/send-mail.php
Content-Type: application/json
```

```json
{
    "name": "John Doe",
    "email": "john@gmail.com",
    "phone": "+923001234567",
    "region": "PK",
    "subject": "Product Inquiry",
    "message": "I want information about your products."
}
```

### Fields

| Field | Required | Rules |
|---|---|---|
| `name` | Yes | Up to 100 characters |
| `phone` | Yes | Digits, spaces, `-`, `(`, `)` and an optional leading `+`; 10–17 characters with at least 10 digits |
| `region` | Yes | A code from `config/regions.php`: `PK`, `UAE`, `MY` or `OTHER` |
| `message` | Yes, except on bookings | Up to 5000 characters |
| `email` | No | Valid address, up to 254 characters. Becomes the Reply-To |
| `subject` | No | Up to 150 characters. Generated from the service name or booking type if left out |
| `country` | No | Up to 60 characters |
| `city` | No | Up to 100 characters |
| `service` | No | Up to 150 characters (the car type on bookings) |
| `budget` | No | Up to 100 characters |
| `from`, `to` | No | Booking pick-up and return dates, as `YYYY-MM-DD` |
| `driver` | No | Up to 30 characters |
| `booking` | No | `"1"`, `"true"` or `true` marks a car booking |
| `website` | Must be empty | Spam trap (see [section 5](#5-connect-the-website)) |

All values are strings, apart from `booking`, which may also be a boolean. Other fields are ignored. The recipient address can't be set by the request: it always comes from the region code.

### Responses

```json
{ "success": true, "message": "Your inquiry has been sent successfully." }
```

| Status | When | Message |
|---|---|---|
| 200 | Sent | Your inquiry has been sent successfully. |
| 204 | CORS preflight (`OPTIONS`) | No body |
| 400 | Malformed JSON | Invalid request data. |
| 403 | Website origin not in `ALLOWED_ORIGINS` | Origin not allowed. |
| 405 | Method other than `POST` or `OPTIONS` | Method not allowed. |
| 413 | Body over 16 KB | Invalid request data. |
| 415 | `Content-Type` is not `application/json` | Invalid request data. |
| 422 | A field failed validation | Invalid request data. |
| 429 | Rate limit reached (see the `Retry-After` header) | Too many requests. Please try again later. |
| 500 | SMTP or configuration failure | Unable to send your inquiry. Please try again later. |

## 2. Prepare the files on your computer

Do this once on your own computer, because the hosting server doesn't need Composer. The result is a `vendor/` folder that you upload with everything else.

> If `vendor/` and `composer.lock` already exist in this folder, PHPMailer is already installed and you can skip to [section 3](#3-deploy-to-cpanel).

1. Install PHP 8.2 or newer. On Windows, download the "VS16 x64 Non Thread Safe" zip from [windows.php.net](https://windows.php.net/download/), then extract it to `C:\php` and add that folder to your `PATH`.
2. In `C:\php`, copy `php.ini-production` to `php.ini`. Find these two lines, remove the `;` from the start of each, and set the folder as shown:
   ```ini
   extension_dir = "C:\php\ext"
   extension=openssl
   ```
3. Install Composer from [getcomposer.org/download](https://getcomposer.org/download/).
4. Run this in the `email-backend` folder:
   ```
   composer install --no-dev
   ```

## 3. Deploy to cPanel

### Set the PHP version

In cPanel, open **MultiPHP Manager** or **Select PHP Version** and set the domain to PHP 8.2 or newer. If the page lists extensions, make sure `mbstring` and `openssl` are enabled.

### Build the upload package

Fill in `.env` and `config/regions.php` first, then run this in the `email-backend` folder:

```
powershell -ExecutionPolicy Bypass -File build.ps1
```

This creates `build/nexgen-email-backend.zip` and lists anything that still needs attention. The zip is already in the layout below, so upload it to your home folder (the level above `public_html`) and extract it there. That replaces the manual steps in the next section.

The zip includes your `.env`, so don't share it. To leave `.env` out and create it on the server instead, add `-NoEnv` to the command.

### Upload the files

Only `send-mail.php` goes in the public web folder. Everything else, including your SMTP password, stays above it where browsers can't reach it:

```
/home/youraccount/
├── email-backend/          <-- not reachable from the web
│   ├── .env
│   ├── config/
│   ├── helpers/
│   ├── services/
│   └── vendor/
└── public_html/
    └── api/
        └── send-mail.php   <-- the only public file
```

1. On your computer, zip the `email-backend` folder, including `vendor/`.
2. In cPanel **File Manager**, upload the zip to your home folder (the level *above* `public_html`) and extract it there.
3. Create `public_html/api/` and **move** `email-backend/api/send-mail.php` into it.

The script finds `/home/youraccount/email-backend` automatically, so no paths need editing. Use exactly that folder name.

File Manager hides dotfiles by default. Turn on **Settings → Show Hidden Files** to see `.env`.

> **If your site is on an addon domain or subdomain** whose web folder isn't directly inside your home folder, the script won't find the app. Either move the web folder so it is, or add that location to the list of candidate folders near the top of `send-mail.php`.

### Configure SMTP

In `email-backend/`, copy `.env.example` to `.env` and fill it in:

```ini
SMTP_HOST=mail.yourdomain.com
SMTP_PORT=587
SMTP_USERNAME=enquiries@yourdomain.com
SMTP_PASSWORD='your-mailbox-password'
SMTP_ENCRYPTION=tls
MAIL_FROM_ADDRESS=enquiries@yourdomain.com
MAIL_FROM_NAME='NexGen Website'
```

- To use your host's email, create a mailbox under **Email Accounts**, then open **Connect Devices** for the server name and ports. Use port 587 with `tls`, or port 465 with `ssl`.
- Keep `MAIL_FROM_ADDRESS` on your own domain, and turn on SPF and DKIM under **Email Deliverability**, otherwise messages may land in spam.
- Set the `.env` file's permissions to `600`.

### Configure regional recipients

Edit `email-backend/config/regions.php`:

```php
return [
    'PK' => 'pakistan@yourdomain.com',
    'UAE' => 'uae@yourdomain.com',
    'MY' => 'malaysia@yourdomain.com',
    'OTHER' => 'general@yourdomain.com',
];
```

Several codes may share one inbox. To add a region such as UK, add a line here and send that code from the website. The API refuses to send to `@example.com` placeholders and returns a 500, so a missed placeholder shows up in testing.

### Configure CORS

In `.env`, list each website address allowed to call the API, with `https://` and no path:

```ini
ALLOWED_ORIGINS=https://yourdomain.com,https://www.yourdomain.com
```

Include your site's address even when the site and API share a domain, because browsers send the `Origin` header on those POST requests too. `*` is ignored by design.

### Enable HTTPS

1. In cPanel **SSL/TLS Status**, run **AutoSSL** for the domain.
2. In **Domains**, turn on **Force HTTPS Redirect**.
3. Call the API using its `https://` address. A redirect from `http://` can turn the POST into a GET and cause a 405.

## 4. Test the live API

The commands below work in PowerShell (use `curl.exe`) and in other terminals (use `curl`). Save this as `test.json`, using a region whose inbox you can check:

```json
{
    "name": "Test User",
    "email": "you@yourdomain.com",
    "phone": "+923001234567",
    "region": "PK",
    "subject": "API test",
    "message": "Testing the email API."
}
```

Each POST counts toward the rate limit of 5 per 15 minutes, so a 429 during testing is expected. To reset it, delete the files in `email-backend/storage/ratelimit/`.

**Send a real message.** Expect a `200` and an email in the PK inbox, and check that replying addresses the customer:

```
curl.exe -i -X POST https://yourdomain.com/api/send-mail.php -H "Content-Type: application/json" -H "Origin: https://yourdomain.com" --data "@test.json"
```

**Check that CORS blocks other sites.** Expect a `403`:

```
curl.exe -i -X POST https://yourdomain.com/api/send-mail.php -H "Content-Type: application/json" -H "Origin: https://evil.example" --data "@test.json"
```

**Check that the preflight works.** Expect a `204` with `Access-Control-Allow-Origin` showing your domain:

```
curl.exe -i -X OPTIONS https://yourdomain.com/api/send-mail.php -H "Origin: https://yourdomain.com" -H "Access-Control-Request-Method: POST"
```

**Check that secrets are private.** Each of these must not show the file's contents. A 403 or 404 is right:

```
https://yourdomain.com/.env
https://yourdomain.com/email-backend/.env
https://yourdomain.com/api/.env
```

## 5. Connect the website

The website's frontend (`nexgen-website/index.html`) is plain JavaScript, not React, and these are the changes to make there. For a React app, see [the example below](#using-fetch-from-react).

> **Until these changes are made, enquiries aren't delivered.** `submitEnquiryToService()` is currently a placeholder that always reports success, so visitors see the thank-you page and nothing is sent.

### Step 1: Replace the placeholder (line 832)

```js
var MAIL_API = 'https://yourdomain.com/api/send-mail.php';
var REGION_BY_COUNTRY = { Pakistan: 'PK', UAE: 'UAE', Malaysia: 'MY', Other: 'OTHER' };

function submitEnquiryToService(form) {
  var data = {};
  new FormData(form).forEach(function (value, key) { data[key] = String(value); });
  // The booking form has no country field, and car rental is in Pakistan.
  data.region = REGION_BY_COUNTRY[data.country] || 'PK';

  return fetch(MAIL_API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  })
    .then(function (response) {
      return response.json().catch(function () { return { success: false }; });
    })
    .then(function (result) {
      if (!result.success) { throw new Error(result.message || 'Send failed'); }
      return true;
    });
}
```

The form's field names already match the API, so they don't need mapping.

### Step 2: Handle failures (line 838)

The current code always goes to the thank-you page, so **a failed send would still look successful** to the visitor. Replace line 838 with this, which also stops double-clicks sending twice:

```js
var submitBtn = form.querySelector('button[type="submit"]');
submitBtn.disabled = true;
submitEnquiryToService(form)
  .then(function () {
    closeModal();
    location.hash = '#/thank-you';
    toast('Enquiry sent — we’ll be in touch shortly.');
  })
  .catch(function () {
    toast('We couldn’t send your enquiry. Please try again or message us on WhatsApp.');
  })
  .then(function () { submitBtn.disabled = false; });
```

### Step 3: Add the spam trap (lines 599 and 613)

Add this inside both forms, directly after the opening `<form ...>` tag. Visitors never see it, but bots tend to fill it in. The API works without it, but without bot filtering.

```js
+'<div style="position:absolute;left:-9999px" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>'
```

### Using fetch from React

```jsx
async function sendInquiry(values) {
  const response = await fetch('https://yourdomain.com/api/send-mail.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      name: values.name,
      email: values.email,
      phone: values.phone,
      region: 'PK',
      subject: values.subject,
      message: values.message,
      website: values.website // hidden spam-trap input; leave empty
    })
  });

  const result = await response.json();
  if (!result.success) {
    throw new Error(result.message);
  }
  return result;
}
```

## 6. Security behaviour

- **Recipients:** chosen only on the server from a validated region code.
- **Header injection:** line breaks and control characters in single-line fields are rejected. The From address is always your own, and the customer's address is used only as Reply-To.
- **HTML safety:** every value is escaped before it goes in the email.
- **CORS:** exact origin matching from `ALLOWED_ORIGINS`, with no wildcard support. CORS only restricts browsers, so requests with no `Origin` header (such as curl) are still accepted. Validation and rate limiting apply to them as normal.
- **Spam trap:** a filled `website` field gets a normal-looking success response and no email is sent.
- **Rate limit:** 5 POST requests per IP address per 15 minutes by default, including rejected ones. Change it with `RATE_LIMIT_MAX` and `RATE_LIMIT_WINDOW_SECONDS`. If the `storage/` folder can't be written, the API logs this and allows the request instead of losing the enquiry.
- **Errors:** visitors only see the generic messages above. Details go to `email-backend/storage/logs/error.log`, outside the web root.

**If you use Cloudflare or another proxy:** the API sees the proxy's IP address, so visitors could share one rate limit. Ask your host to enable real-IP restoration (`mod_remoteip`). The API deliberately ignores `X-Forwarded-For`, because anyone can forge it.

## 7. Troubleshooting

Start with `email-backend/storage/logs/error.log`, which records the reason for most 500 errors.

| Symptom | Likely cause | Fix |
|---|---|---|
| 500, log says `Recipient ... is not configured` | Placeholder still in `regions.php` | Enter the real address |
| 500, log says `SMTP settings are missing` | `.env` is missing, misnamed or in the wrong folder | It must be `email-backend/.env` |
| 500, log says `Could not authenticate` | Wrong SMTP username or password | Check them, and quote the password if it contains `#` or spaces |
| 500, log says `Could not connect` | Wrong host or port, or the host blocks outgoing SMTP | Try your host's own mail server, or ask them to open port 587 or 465 |
| 500, log mentions `vendor/autoload.php` | `vendor/` wasn't uploaded | Upload the whole folder |
| 500 with no log | PHP version below 8.2, or the app folder wasn't found | Check the PHP version and the folder layout in section 3 |
| 403 "Origin not allowed." | Site address missing from `ALLOWED_ORIGINS` | Add the exact address, including `https://` and any `www` |
| 403 page from the server (not JSON) | `email-backend` was uploaded inside `public_html` | Move it to your home folder as in section 3 |
| Browser reports a CORS error | Same as above, or the wrong API address | Check `ALLOWED_ORIGINS` and the URL in the browser's Network tab |
| 405 from a POST | An `http://` to `https://` redirect changed the method | Use the `https://` address |
| 422 | A field failed validation | Check the field rules in section 1 |
| 429 during testing | Rate limit reached | Wait, or clear `storage/ratelimit/` |
| API returns 200 but no email arrives | Spam filtering, or the spam trap was filled | Check spam, SPF and DKIM, and that `website` is empty |
