PHP 8.5 CI retains the existing TYPO3 support. Public TYPO3 11/12 releases are currently blocked by security advisories.

To run these jobs with a license authorized for extension testing, configure the GitHub Actions repository variable `TYPO3_ELTS_REPOSITORY_URL` with the Composer repository URL supplied by the license provider, and the Actions secret `TYPO3_ELTS_COMPOSER_AUTH` with the Composer auth JSON for that repository. CI does not print credential values. A customer license must be authorized for this use before being configured.

Do not disable Composer security blocking or remove older supported TYPO3 versions to pass CI. Keep the PR as a draft until all retained jobs pass.
