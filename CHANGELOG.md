# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.7] - 2026-11-15

### Fixed

- A message to the product expert can be sent only about a product the customer can
  see in the shop. A disabled product or one with visibility set to "Nowhere" is
  refused. A product that is visible but not available for order still accepts
  messages.

## [0.4.6] - 2026-11-15

### Security

- Every back-office screen of the module checks the employee's permissions. Before,
  any logged-in employee, whatever their profile, could change the specialists and the
  addresses their messages go to. The screens now belong to the module's own menu tab
  (`AdminQuinceContacts`): grant it to a profile under **Advanced Parameters → Team →
  Permissions**. SuperAdmin needs nothing. A profile that had access through the hidden
  `AdminQuince` tab needs access to this tab now.

## [0.4.5] - 2026-11-15

### Security

- The visitor's name and e-mail address are escaped in the message sent to the
  specialist. Before, a name with HTML put a link of the visitor's choosing into an
  e-mail sent from the shop's address. Use with Quince Engine 0.8.1, which makes the
  form guard mandatory for this form.

## [0.4.4] - 2026-11-13

### Fixed

- The back office no longer sends you to the shop's home page after this module is
  installed from its release ZIP. The index.php file in every folder of the package
  redirected and stopped the process also when PrestaShop loaded it while scanning
  `src/ApiPlatform/Resources`, so the back office could not be built.

## [0.4.3] - 2026-11-10

### Added

- Polish translation of the product page block and the enquiry form.
- Polish version of the e-mail to the specialist.

### Changed

- Polish translation completed: the texts added since the previous translation are now translated.

## [0.4.2] - 2026-11-10

### Fixed

- The first line of the plain-text e-mail to the specialist separates the shop name
  with a colon instead of a long dash, like the subject.

## [0.4.1] - 2026-11-10

### Fixed

- The subject of the e-mail to the specialist separates the shop name and the
  product name with a colon instead of a long dash.

## [0.4.0] - 2026-11-10

First public release.

### Added

- People of the shop built on the native shop contacts: photo, job title, phone
  number and the categories each person looks after, edited on one back office
  screen.
- Phone number added to shop contacts as a PrestaShop extra property, so it shows in
  the native contact form, the contact list and the Admin API `/contacts` resource.
- "Ask an expert" on the product page: the specialist assigned to the product's
  category (or one of its parent categories) with photo, job title and phone, and a
  form to write to them.
- Each enquiry is sent by e-mail to the specialist, reported as a `contact.enquiry`
  event when Quince Events is installed, and stored in a log.
- Read-only Admin API resource `/quince-contacts/messages` for polling the enquiry
  log, with the `quince_contacts_read` scope.
- Polish translation of the back office screen.
