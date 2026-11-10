# Quince Contacts

**The people behind the shop, next to the products they know, for PrestaShop 9.2.**

Quince Contacts shows a real person on the product page: the specialist for that
product's category, with a photo, job title and phone number, and an "Ask an expert"
form to write to them. A customer who sees a face and a name asks more readily than
one who sees a "contact us" form. The people are the shop's own contacts from
**Shop Parameters → Contact**, so there is no second list to keep up to date.

> Quince Contacts is a free, open-source module (OSL-3.0). There is nothing to buy.
> If it saves you time, you can support development, see
> [Supporting the project](#supporting-the-project).

---

## Features

- **People from the shop contacts.** Each person is a native PrestaShop contact. The
  module's screen edits the name and e-mail address straight on the contact, and adds
  a photo, a job title, a phone number and the categories the person looks after.
- **Phone number where the shop already works.** The phone is added to contacts as a
  PrestaShop extra property, so it also appears in the native contact form, in the
  contact list and in the Admin API `/contacts` resource.
- **The right specialist for each product.** The module picks the person assigned to
  the product's default category or to the nearest parent category, so one person
  assigned to a department covers all its subcategories. One person can be set for
  the rest of the shop. Products with nobody assigned show no button at all.
- **"Ask an expert" on the product page.** A panel with the specialist's photo, job
  title and phone, and a form with name, e-mail address and message. The answer goes
  back to the address the customer gave.
- **Every enquiry three ways.** An e-mail to the specialist, a `contact.enquiry` event
  through [Quince Events](https://github.com/quinceproject/quince_events) when that
  module is installed, and a row in the enquiry log. None of them depends on the
  others.
- **Admin API for the enquiry log.** A read-only resource for systems that prefer to
  poll, with a cursor that never returns the same row twice. See [API.md](API.md).
- **Form protection.** The form goes through the Quince Engine form protection
  (signed page marker, hidden field, minimum fill time and request limit), and
  through Quince reCAPTCHA when that module is installed.
- **Photos.** JPG, PNG, WEBP or AVIF, up to 2 MB, stored in `img/quince/contacts/`.
  Each upload is re-encoded pixel by pixel, so anything attached to the file besides
  the image itself is dropped.

## Requirements

| Requirement | Version |
|-------------|---------|
| PrestaShop  | 9.2 (tested on 9.2.0) |
| PHP         | 8.4 (tested), 8.1 or newer required |
| Quince Engine | required |
| Quince theme | required for the "Ask an expert" panel on the product page |
| Quince Events | optional, for the `contact.enquiry` event |

Quince Engine hosts the module's settings screen and protects the form. The module
declares it as a dependency, so PrestaShop refuses to install Quince Contacts
without it. The "Ask an expert" panel is placed by the Quince theme's product page
through the `displayQuinceExpert` hook.

The back office screen has a Polish translation. The form in the shop and the e-mail
to the specialist are in English only for now; they can be translated in
**International → Translations**.

## Installation

The release ZIP already contains everything the module needs. Composer and the
command line are not required.

**From the back office**

1. Download the latest **`quince_contacts.zip`** from the
   [**Releases**](https://github.com/quinceproject/quince_contacts/releases) page.
2. In the back office go to **Modules → Module Manager → Upload a module** and
   choose the ZIP. PrestaShop installs it.

**By copying the files to the server**

1. Unpack the release ZIP. It contains a `quince_contacts` folder.
2. Upload that folder to the `modules/` directory of the shop, for example by FTP.
3. In **Modules → Module Manager** find Quince Contacts and click **Install**.

**From Quince Engine** *(coming soon)*

The module list in Quince Engine will install the module straight from its release,
without downloading anything by hand.

**With Composer** *(coming soon)*

Installing with `composer require quinceproject/contacts` will work once the package
is published on Packagist.

> ⚠️ **Do not** use the green **Code → Download ZIP** button on this page. That
> archive contains the source only and is **missing the `vendor/` folder**, so the
> module will not run. Always install from a
> [**Release**](https://github.com/quinceproject/quince_contacts/releases).

Uninstalling removes photos, job titles, category assignments and the enquiry log.
Phone numbers stay with the shop contacts.

## Configuration

Open **Quince project → Contacts** in the back office menu, or click **Configure**
next to the module in the Module Manager.

1. **Add a person to the list.** Pick one of the shop contacts. A new person starts
   as a contact in **Shop Parameters → Contact**.
2. Fill in the e-mail address, phone number and job title, and choose a photo.
3. **Shown in categories.** Pick the categories the person looks after.
   Subcategories count together with the category.
4. **Rest of the shop.** Optionally pick the one person shown for products from
   categories with nobody assigned.

## Architecture

- **`People`** keeps photos, job titles, order and category assignments in the
  module's own tables, keyed by the native contact.
- **`PhotoStore`** checks and re-encodes uploaded photos.
- **`MessageLog`** stores every enquiry for the Admin API.
- **`message` front controller** receives the form as JSON and sends the enquiry the
  three ways.
- **`ContactMessage` Admin API resource** with its provider, scope
  `quince_contacts_read`.
- **Symfony controller and Twig** for the settings screen, inside the Quince Engine
  back office shell.

## Development / building from source

This repository tracks **source only**. The `vendor/` directory is git-ignored, so a
`git clone` is **not** directly installable. **Start with `composer install`**: the
development environment mounts module directories straight from disk, and without
that step a fresh clone gives a broken back office.

```bash
git clone https://github.com/quinceproject/quince_contacts.git
cd quince_contacts
composer install
```

The folder must be called `quince_contacts`, the same as the module. A clone of this
repository already has that name.

To build an installable archive, zip the `quince_contacts/` folder **with its
`vendor/` directory included**, leaving out `.git/` and `.gitignore`.

## License

Code: [Open Software License 3.0 (OSL-3.0)](LICENSE). It is a reciprocal licence:
whoever modifies and distributes this code has to publish the changes under the same
licence and keep the original copyright and author notices.

**The name "Quince" is not covered by the code licence.** A fork may use the code; it
may not use the name.

© 2026 Marcin Gajewski

## Supporting the project

Quince modules are free downloads and there is nothing to buy. If the module is useful
to you, you can support its development by buying me a coffee:

**[buymeacoffee.com/marcingajewski](https://buymeacoffee.com/marcingajewski)**
