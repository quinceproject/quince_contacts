# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
