# Moved

This guide has been superseded by **[DEPLOYMENT.md](DEPLOYMENT.md)**, the single
source of truth for deploying and operating DFCP COMS.

It covered the same ground but was missing Reverb (chat and live notifications),
mail configuration, and the CI/CD pipeline — and its
`client_max_body_size 15M` was too small for the app's 100 MB file-manager
uploads, causing silent 413s.
