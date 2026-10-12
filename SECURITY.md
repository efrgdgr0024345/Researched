# Security policy

## Prototype boundary

Version 0.1 is a private, single-user prototype. Its optional shared password is not a replacement for production identity, rate limiting, encrypted backups, or tenant isolation.

## Reporting

Do not open a public issue containing:

- API keys or access tokens;
- private idea text;
- uploaded research papers;
- personal information;
- a working exploit against a deployed private instance.

Report only a sanitised reproduction in the repository. Handle sensitive deployment details privately with the repository owner.

## Required deployment controls

Before exposing the app to the internet:

- use HTTPS;
- set a strong `APP_PASSWORD` or place the app behind a proper identity proxy;
- deny public directory listing;
- back up and test restoration of `data/`;
- restrict filesystem permissions;
- store secrets outside the web root;
- review PHP upload and body-size limits;
- monitor logs without recording idea or paper content unnecessarily.
