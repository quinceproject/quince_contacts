# Quince Contacts: Admin API

Quince Contacts gives an outside system two things through the PrestaShop Admin API:
the **phone number of a shop contact** on the native `/contacts` resource, and the
**log of "Ask an expert" enquiries** on a read-only resource of the module.

---

## 1. Phone number: a field on the native `/contacts` resource

The module adds a phone number to shop contacts through the PrestaShop 9.2 extra
properties mechanism. The phone therefore has **no endpoint of its own** in this
module. It comes in the response of the native Admin API `Contact` resource, under
`extraProperties`, with the native `contact_read` scope:

```http
GET /admin-api/contacts/{id}?shopId=1
```

```json
{
  "contactId": 1,
  "names": { "en-US": "Webmaster" },
  "email": "...",
  "extraProperties": {
    "quince_contacts": { "phone": "+48 61 222 33 44" }
  }
}
```

When the contact has no phone number, the `quince_contacts` key is missing from
`extraProperties`. The job title and the photo do **not** travel this way; see
section 4.

---

## 2. Authentication (the module's own resource)

PrestaShop Admin API, OAuth2, `client_credentials`:

```bash
php bin/console prestashop:api-client create my-tool \
  --name="My tool" \
  --scopes=quince_contacts_read
```

```http
POST /admin-api/access_token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&client_id=my-tool&client_secret=…&scope=quince_contacts_read
```

| Scope | What for |
|---|---|
| `quince_contacts_read` | reading the enquiry log (section 3) |

The resource is **read-only**: there is no write scope because there is no write
endpoint. `shopId` is required on every request as a query parameter. Without it the
list returns an empty `[]` and a single item returns `404`; the module does not guess
the shop from the request.

---

## 3. Enquiries to a specialist: `/quince-contacts/messages`

A customer fills in the "Ask an expert" form on a product page. The message goes out
**three ways at once, independently of each other**:

1. by e-mail to the specialist, through the shop's mail settings;
2. as a `contact.enquiry` event through
   [Quince Events](https://github.com/quinceproject/quince_events), when that module
   is installed (a signed webhook, push);
3. as a row in this log, for an integration that prefers polling to webhooks. This
   is the way described here.

The three do not depend on each other. With no Quince Events receiver the row is
still written, and a failed e-mail shows in the log as `mailSent: false` while the
row is still there.

### 3.1 List

```http
GET /admin-api/quince-contacts/messages?shopId=1&sinceId=0&limit=50&page=1
```

| Parameter | Meaning |
|---|---|
| `shopId` | required |
| `sinceId` | returns rows with an `id` **greater** than this one; `0` or none means from the start |
| `limit` | 50 by default, 200 at most |
| `page` | 1 by default |

Rows are ordered by `id`, oldest first. The cursor is the primary key, not a date,
so two rows with the same timestamp cannot be missed. **The response is a plain
array.** For the next poll take the `id` of the last row on the page and pass it as
`sinceId`; that row **will not come again** (the filter is strict, `>`, not `>=`).
The module marks nothing as "received"; the polling system remembers where it
stopped.

### 3.2 Single item

```http
GET /admin-api/quince-contacts/messages/{id}?shopId=1
```

`404` when the `id` does not exist or belongs to another shop.

### 3.3 Response shape

```json
{
  "id": 1,
  "shopId": 1,
  "contactId": 3,
  "expertName": "Mark Smith",
  "expertEmail": "mark.smith@example.com",
  "productId": 1,
  "productName": "Hummingbird printed t-shirt",
  "customerId": 0,
  "customerName": "John Test",
  "customerEmail": "customer.test@example.com",
  "message": "A test question about the product",
  "mailSent": false,
  "createdAt": "2026-10-29T15:44:01"
}
```

| Field | Notes |
|---|---|
| `contactId`, `expertName`, `expertEmail` | the specialist the enquiry went to, **as they were when it was sent**, not read live from the contact. The contact may have changed or been deleted since; the log shows who received it then |
| `customerId` | `0` means a guest. The shop does not guess a guest's identity from the e-mail address; matching to an existing customer is up to the polling system |
| `mailSent` | whether `Mail::Send()` reported success when the enquiry was sent. `false` means the mail was not sent, not that the log row is wrong |
| `createdAt` | **without a time zone offset.** The database column is a plain MySQL `DATETIME` in the shop server's time. If the integration needs a time zone, treat it as the shop server's local time, not UTC |

---

## 4. What the module does not do

- **The specialist's job title and photo are not available through any API.** They
  live only in the module's tables and back office screen.
- **No writing through the API.** Messages cannot be created, changed or deleted
  through the Admin API; the only way in is the form on the product page.
- **No handling status.** The log does not know whether the specialist answered, read
  or ignored an enquiry. It records what the customer sent and to whom.
- **No cleanup of old rows.** The table keeps growing. Uninstalling the module removes
  the log together with photos, job titles and category assignments. Phone numbers
  stay with the shop contacts.
